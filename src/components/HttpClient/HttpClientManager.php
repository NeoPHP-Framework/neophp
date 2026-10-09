<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient;

use Closure;
use JsonException;
use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Component\HttpClient\Contract\ResponseInterface;
use NeoPHP\Component\HttpClient\Contract\TransportInterface;
use NeoPHP\Component\HttpClient\Event\ExceptionEvent;
use NeoPHP\Component\HttpClient\Event\RequestEvent;
use NeoPHP\Component\HttpClient\Event\ResponseEvent;
use NeoPHP\Component\HttpClient\Exception\HttpClientException;
use NeoPHP\Component\HttpClient\Exception\HttpException;
use NeoPHP\Component\HttpClient\Exception\InvalidOptionException;
use NeoPHP\Component\HttpClient\Exception\TransportException;
use NeoPHP\Component\HttpClient\Part\FormDataPart;
use NeoPHP\Component\HttpClient\Provider\HttpClientProvider;
use NeoPHP\Component\HttpClient\Request\Request;
use NeoPHP\Component\HttpClient\Request\Uri;
use NeoPHP\Component\HttpClient\Response\Response;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\Logger\LoggerManagerInterface;

#[Component(provider: HttpClientProvider::class)]
final class HttpClientManager implements HttpClientManagerInterface
{
    public const LOG_CHANNEL = 'http_client';

    public const REDIRECT_CODES = [301, 302, 303, 307, 308];

    public const DEFAULT_CONFIG = [
        'transport' => 'auto',
        'default_options' => [],
        'clients' => [],
    ];

    public const DEFAULT_RETRY = [
        'max_retries' => 0,
        'delay' => 1000,
        'multiplier' => 2.0,
        'max_delay' => 30000,
        'status_codes' => [423, 425, 429, 500, 502, 503, 504, 507, 510],
    ];

    public const DEFAULT_OPTIONS = [
        'base_uri' => null,
        'query' => [],
        'headers' => [],
        'json' => null,
        'body' => null,
        'multipart' => null,
        'auth_basic' => null,
        'auth_bearer' => null,
        'timeout' => 30.0,
        'connect_timeout' => null,
        'max_redirects' => 5,
        'verify_peer' => true,
        'proxy' => null,
        'retry' => [],
        'sink' => null,
        'on_progress' => null,
        'cache' => false,
    ];

    public const CACHEABLE_METHODS = ['GET', 'HEAD'];

    public const CACHE_REVALIDATION_TTL = 86400;

    protected TransportInterface $transport;

    protected ?EventManagerInterface $events = null;

    protected ?LoggerInterface $logger = null;

    protected ?Closure $cache = null;

    protected array $config = self::DEFAULT_CONFIG;

    protected array $options = self::DEFAULT_OPTIONS;

    public function __construct(TransportInterface $transport, ?EventManagerInterface $events = null, ?LoggerInterface $logger = null, array $config = [], ?Closure $cache = null)
    {
        $this->transport = $transport;
        $this->events = $events;
        $this->cache = $cache;
        $this->logger = $logger instanceof LoggerManagerInterface && $logger->hasChannel(self::LOG_CHANNEL) ? $logger->channel(self::LOG_CHANNEL) : $logger;
        $this->config = array_replace(self::DEFAULT_CONFIG, $config);
        $this->options = $this->mergeOptions(self::DEFAULT_OPTIONS, (array) ($this->config['default_options'] ?? []));
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->requestMany(['request' => [$method, $url, $options]])['request'];
    }

    public function get(string $url, array $options = []): ResponseInterface
    {
        return $this->request('GET', $url, $options);
    }

    public function post(string $url, array $options = []): ResponseInterface
    {
        return $this->request('POST', $url, $options);
    }

    public function put(string $url, array $options = []): ResponseInterface
    {
        return $this->request('PUT', $url, $options);
    }

    public function patch(string $url, array $options = []): ResponseInterface
    {
        return $this->request('PATCH', $url, $options);
    }

    public function delete(string $url, array $options = []): ResponseInterface
    {
        return $this->request('DELETE', $url, $options);
    }

    public function head(string $url, array $options = []): ResponseInterface
    {
        return $this->request('HEAD', $url, $options);
    }

    public function download(string $url, string $target, array $options = []): ResponseInterface
    {
        if ($target === '') {
            throw new InvalidOptionException('The download target path cannot be empty.');
        }

        return $this->request((string) ($options['method'] ?? 'GET'), $url, ['sink' => $target] + array_diff_key($options, ['method' => true]));
    }

    public function requestMany(array $requests): array
    {
        $states = [];

        foreach ($requests as $key => $definition) {
            $states[$key] = $this->prepare($definition);
        }

        $pending = [];
        $results = [];

        foreach ($states as $key => $state) {
            if (isset($state['cached'])) {
                $results[$key] = $state['cached'];
            } else {
                $pending[] = $key;
            }
        }

        while ($pending !== []) {
            $batch = [];

            foreach ($pending as $key) {
                $batch[$key] = $states[$key]['request'];
            }

            $outcomes = $this->transport->sendMany($batch);
            $pending = [];
            $delay = 0;

            foreach ($outcomes as $key => $outcome) {
                $state = &$states[$key];
                $retry = $state['retry'];
                $status = $outcome instanceof Response ? $outcome->getStatusCode() : 0;

                if ($state['retries'] < (int) $retry['max_retries'] && ($outcome instanceof TransportException || in_array($status, array_map('intval', (array) $retry['status_codes']), true))) {
                    $state['retries']++;
                    $delay = max($delay, $this->retryDelay($retry, $state['retries'], $outcome instanceof Response ? $outcome : null));
                    $pending[] = $key;
                    unset($state);
                    continue;
                }

                if ($outcome instanceof Response && in_array($status, self::REDIRECT_CODES, true) && $outcome->getHeader('location') !== null && $state['redirects'] < (int) $state['options']['max_redirects']) {
                    $state['request'] = $this->redirect($state['request'], $outcome);
                    $state['redirects']++;
                    $pending[] = $key;
                    unset($state);
                    continue;
                }

                $results[$key] = $outcome;
                unset($state);
            }

            if ($pending !== [] && $delay > 0) {
                usleep($delay * 1000);
            }
        }

        $responses = [];
        $failure = null;

        foreach (array_keys($states) as $key) {
            $outcome = $this->complete($states[$key], $results[$key]);

            if ($outcome instanceof TransportException) {
                $failure ??= $outcome;
                continue;
            }

            $responses[$key] = $outcome;
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $responses;
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->options = $this->mergeOptions($this->options, $options);

        return $clone;
    }

    public function client(string $name): HttpClientManagerInterface
    {
        if (!$this->hasClient($name)) {
            throw new HttpClientException('The HTTP client "{name}" is not configured. Available clients: {clients}.', 0, null, [
                'name' => $name,
                'clients' => implode(', ', array_keys((array) $this->config['clients'])) ?: 'none',
            ]);
        }

        $clone = clone $this;
        $clone->options = $this->mergeOptions($this->mergeOptions(self::DEFAULT_OPTIONS, (array) ($this->config['default_options'] ?? [])), (array) $this->config['clients'][$name]);

        return $clone;
    }

    public function hasClient(string $name): bool
    {
        return is_array($this->config['clients'][$name] ?? null);
    }

    public function getClientNames(): array
    {
        return array_keys(array_filter((array) $this->config['clients'], 'is_array'));
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setCache(?Closure $cache): static
    {
        $this->cache = $cache;

        return $this;
    }

    protected function prepare(mixed $definition): array
    {
        if (!is_array($definition)) {
            throw new InvalidOptionException('Each request of requestMany() must be an array [method, url, options], {type} given.', 0, null, ['type' => get_debug_type($definition)]);
        }

        $method = strtoupper((string) ($definition['method'] ?? $definition[0] ?? 'GET'));
        $url = (string) ($definition['url'] ?? $definition[1] ?? '');
        $options = $this->mergeOptions($this->options, (array) ($definition['options'] ?? $definition[2] ?? []));

        if ($this->events !== null) {
            $event = $this->events->dispatch(new RequestEvent($method, $url, $options));
            $method = $event->getMethod();
            $url = $event->getUrl();
            $options = $this->mergeOptions(self::DEFAULT_OPTIONS, $event->getOptions());
        }

        $request = $this->build($method, $url, $options);

        return $this->cacheLookup([
            'request' => $request,
            'options' => $options,
            'original_url' => $request->getUrl(),
            'retry' => $this->retryOptions($options['retry']),
            'retries' => 0,
            'redirects' => 0,
            'start' => microtime(true),
        ]);
    }

    protected function build(string $method, string $url, array $options): Request
    {
        if ($url === '') {
            throw new InvalidOptionException('The URL of the request cannot be empty.');
        }

        $url = Uri::resolve(is_string($options['base_uri']) && $options['base_uri'] !== '' ? $options['base_uri'] : null, $url);

        if (!preg_match('#^https?://#i', $url)) {
            throw new InvalidOptionException('The URL "{url}" is not supported: only http:// and https:// URLs are allowed.', 0, null, ['url' => Uri::withoutCredentials($url)]);
        }

        $user = parse_url($url, PHP_URL_USER);

        if (is_string($user) && $options['auth_basic'] === null && $options['auth_bearer'] === null) {
            $options['auth_basic'] = [rawurldecode($user), rawurldecode((string) parse_url($url, PHP_URL_PASS))];
        }

        $url = Uri::withQuery(Uri::withoutCredentials($url), (array) $options['query']);
        $headers = $options['headers'];
        $body = '';

        if ($options['auth_basic'] !== null) {
            $credentials = is_array($options['auth_basic']) ? implode(':', array_slice(array_map('strval', array_values($options['auth_basic'])), 0, 2)) : (string) $options['auth_basic'];
            $headers = $this->setHeader($headers, 'Authorization', 'Basic ' . base64_encode($credentials));
        } elseif ($options['auth_bearer'] !== null && $options['auth_bearer'] !== '') {
            $headers = $this->setHeader($headers, 'Authorization', 'Bearer ' . $options['auth_bearer']);
        }

        if ($options['json'] !== null) {
            try {
                $body = json_encode($options['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } catch (JsonException $exception) {
                throw new InvalidOptionException('The "json" option cannot be encoded: {error}.', 0, $exception, ['error' => $exception->getMessage()]);
            }

            $headers = $this->defaultHeader($headers, 'Content-Type', 'application/json');
            $headers = $this->defaultHeader($headers, 'Accept', 'application/json');
        } elseif ($options['multipart'] !== null) {
            $form = $options['multipart'] instanceof FormDataPart ? $options['multipart'] : new FormDataPart((array) $options['multipart']);
            $body = $form->getBody();
            $headers = $this->setHeader($headers, 'Content-Type', $form->getContentType());
        } elseif (is_array($options['body'])) {
            $body = http_build_query($options['body'], '', '&');
            $headers = $this->defaultHeader($headers, 'Content-Type', 'application/x-www-form-urlencoded');
        } elseif ($options['body'] !== null) {
            $body = (string) $options['body'];
        }

        return new Request($method, $url, $headers, $body, [
            'timeout' => $options['timeout'],
            'connect_timeout' => $options['connect_timeout'],
            'verify_peer' => (bool) $options['verify_peer'],
            'proxy' => $options['proxy'],
            'sink' => $options['sink'],
            'on_progress' => $options['on_progress'],
        ]);
    }

    protected function redirect(Request $request, Response $response): Request
    {
        $status = $response->getStatusCode();
        $url = Uri::resolve($request->getUrl(), (string) $response->getHeader('location'));
        $method = $request->getMethod();
        $body = $request->getBody();
        $headers = $request->getHeaders();

        if (($status === 303 && $method !== 'HEAD') || (in_array($status, [301, 302], true) && $method === 'POST')) {
            $method = 'GET';
            $body = '';
            $headers = $this->removeHeader($this->removeHeader($headers, 'Content-Type'), 'Content-Length');
        }

        if (Uri::origin($url) !== Uri::origin($request->getUrl())) {
            $headers = $this->removeHeader($this->removeHeader($headers, 'Authorization'), 'Cookie');
        }

        return $request->with($method, $url, $headers, $body);
    }

    protected function complete(array $state, Response|TransportException $outcome): Response|TransportException
    {
        $request = $state['request'];
        $duration = (int) round((microtime(true) - $state['start']) * 1000);
        $context = ['method' => $request->getMethod(), 'url' => $state['original_url'], 'duration' => $duration];

        if ($outcome instanceof TransportException) {
            $this->logger?->warning('{method} {url} failed: {error} ({duration} ms)', $context + ['error' => $outcome->getMessage()]);
            $this->events?->dispatch(new ExceptionEvent($request->getMethod(), $state['original_url'], $outcome));

            return $outcome;
        }

        $outcome = $this->cacheStore($state, $outcome);

        $response = $outcome->withInfo([
            'from_cache' => (bool) $outcome->getInfo('from_cache'),
            'original_url' => $state['original_url'],
            'url' => $request->getUrl(),
            'http_method' => $request->getMethod(),
            'redirect_count' => $state['redirects'],
            'retry_count' => $state['retries'],
            'total_time' => $duration / 1000,
        ]);

        if ($this->events !== null) {
            $event = $this->events->dispatch(new ResponseEvent($request->getMethod(), $state['original_url'], $response));
            $response = $event->getResponse();
        }

        $context['status'] = $response->getStatusCode();

        if ($response->getStatusCode() >= 400) {
            $this->logger?->warning('{method} {url} {status} ({duration} ms)', $context);
        } else {
            $this->logger?->info('{method} {url} {status} ({duration} ms)', $context);
        }

        if ($response->getStatusCode() >= 300) {
            $this->events?->dispatch(new ExceptionEvent($request->getMethod(), $state['original_url'], HttpException::create($response)));
        }

        return $response;
    }

    protected function cacheLookup(array $state): array
    {
        $cache = $this->cacheOptions($state['options']['cache']);
        $request = $state['request'];

        if ($cache === null || !in_array($request->getMethod(), self::CACHEABLE_METHODS, true) || $request->getBody() !== '' || $state['options']['sink'] !== null) {
            return $state;
        }

        $pool = $this->cachePool($cache['pool']);
        $key = $this->cacheKey($request);
        $entry = $pool->get($key);
        $entry = is_array($entry) && isset($entry['status'], $entry['headers'], $entry['fresh_until']) ? $entry : null;
        $state['cache'] = ['pool' => $pool, 'key' => $key, 'ttl' => $cache['ttl'], 'entry' => $entry];

        if ($entry === null) {
            return $state;
        }

        if ((int) $entry['fresh_until'] > time()) {
            $state['cached'] = new Response((int) $entry['status'], (array) $entry['headers'], (string) $entry['content'], ['from_cache' => true]);

            return $state;
        }

        $headers = $request->getHeaders();

        if (is_string($entry['etag'] ?? null)) {
            $headers = $this->defaultHeader($headers, 'If-None-Match', $entry['etag']);
        }

        if (is_string($entry['last_modified'] ?? null)) {
            $headers = $this->defaultHeader($headers, 'If-Modified-Since', $entry['last_modified']);
        }

        $state['request'] = $request->with($request->getMethod(), $request->getUrl(), $headers, '');

        return $state;
    }

    protected function cacheStore(array $state, Response $response): Response
    {
        if (!isset($state['cache']) || isset($state['cached'])) {
            return $response;
        }

        $cache = $state['cache'];
        $entry = $cache['entry'];

        if ($response->getStatusCode() === 304 && $entry !== null) {
            $refreshed = array_intersect_key($response->getHeaders(), array_flip(['cache-control', 'expires', 'etag', 'last-modified', 'date', 'age']));
            $entry['headers'] = array_replace((array) $entry['headers'], $refreshed);
            $this->cacheSave($cache, $entry, $this->freshness($entry['headers'], $cache['ttl']) ?? 0);

            return new Response((int) $entry['status'], (array) $entry['headers'], (string) $entry['content'], array_replace($response->getInfo(), ['from_cache' => true]));
        }

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $freshness = $this->freshness($response->getHeaders(), $cache['ttl']);

        if ($freshness !== null) {
            $this->cacheSave($cache, [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'content' => $response->getContent(false),
            ], $freshness);
        }

        return $response;
    }

    protected function cacheSave(array $cache, array $entry, int $freshness): void
    {
        $headers = (array) $entry['headers'];
        $entry['etag'] = $headers['etag'][0] ?? null;
        $entry['last_modified'] = $headers['last-modified'][0] ?? null;
        $entry['fresh_until'] = time() + $freshness;
        $validator = $entry['etag'] !== null || $entry['last_modified'] !== null;

        if ($freshness <= 0 && !$validator) {
            $cache['pool']->delete($cache['key']);

            return;
        }

        $cache['pool']->set($cache['key'], $entry, $validator ? max($freshness, self::CACHE_REVALIDATION_TTL) : $freshness);
    }

    protected function freshness(array $headers, ?int $forced): ?int
    {
        $directives = [];

        foreach ((array) ($headers['cache-control'] ?? []) as $line) {
            foreach (explode(',', (string) $line) as $directive) {
                $parts = explode('=', trim($directive), 2);
                $directives[strtolower(trim($parts[0]))] = trim($parts[1] ?? '', " \t\"");
            }
        }

        if (isset($directives['no-store'])) {
            return null;
        }

        if ($forced !== null) {
            return max(0, $forced);
        }

        if (isset($directives['no-cache'])) {
            return 0;
        }

        $age = (int) ($headers['age'][0] ?? 0);

        foreach (['max-age', 's-maxage'] as $name) {
            if (isset($directives[$name]) && ctype_digit($directives[$name])) {
                return max(0, (int) $directives[$name] - $age);
            }
        }

        $expires = isset($headers['expires'][0]) ? strtotime((string) $headers['expires'][0]) : false;

        if ($expires !== false) {
            $date = isset($headers['date'][0]) ? strtotime((string) $headers['date'][0]) : false;

            return max(0, $expires - ($date !== false ? $date : time()));
        }

        return 0;
    }

    protected function cacheOptions(mixed $cache): ?array
    {
        if ($cache === null || $cache === false) {
            return null;
        }

        if ($cache === true) {
            return ['pool' => null, 'ttl' => null];
        }

        if (is_int($cache)) {
            return ['pool' => null, 'ttl' => $cache];
        }

        if (is_string($cache) && $cache !== '') {
            return ['pool' => $cache, 'ttl' => null];
        }

        if (!is_array($cache)) {
            throw new InvalidOptionException('The "cache" option must be a boolean, an integer (TTL), a pool name or an array, {type} given.', 0, null, ['type' => get_debug_type($cache)]);
        }

        $unknown = array_diff(array_keys($cache), ['pool', 'ttl']);

        if ($unknown !== []) {
            throw new InvalidOptionException('Unknown "cache" option(s): {options}. Allowed: pool, ttl.', 0, null, ['options' => implode(', ', $unknown)]);
        }

        return [
            'pool' => isset($cache['pool']) && $cache['pool'] !== '' ? (string) $cache['pool'] : null,
            'ttl' => isset($cache['ttl']) ? (int) $cache['ttl'] : null,
        ];
    }

    protected function cachePool(?string $pool): CacheInterface
    {
        if ($this->cache === null) {
            throw new InvalidOptionException('The "cache" option requires the Cache component (no cache pool resolver is configured on the HTTP client).');
        }

        return ($this->cache)($pool);
    }

    protected function cacheKey(Request $request): string
    {
        $headers = array_change_key_case($request->getHeaders(), CASE_LOWER);
        $authorization = (string) ($headers['authorization'] ?? '');

        return 'http_client.' . sha1(implode("\n", [
                $request->getMethod(),
                $request->getUrl(),
                (string) ($headers['accept'] ?? ''),
                $authorization === '' ? '' : sha1($authorization),
            ]));
    }

    protected function retryOptions(mixed $retry): array
    {
        if ($retry === null || $retry === false || $retry === []) {
            return self::DEFAULT_RETRY;
        }

        if (is_int($retry)) {
            $retry = ['max_retries' => $retry];
        }

        if (!is_array($retry)) {
            throw new InvalidOptionException('The "retry" option must be an array or an integer, {type} given.', 0, null, ['type' => get_debug_type($retry)]);
        }

        $unknown = array_diff(array_keys($retry), array_keys(self::DEFAULT_RETRY));

        if ($unknown !== []) {
            throw new InvalidOptionException('Unknown "retry" option(s): {options}. Allowed: {allowed}.', 0, null, ['options' => implode(', ', $unknown), 'allowed' => implode(', ', array_keys(self::DEFAULT_RETRY))]);
        }

        return array_replace(self::DEFAULT_RETRY, $retry);
    }

    protected function retryDelay(array $retry, int $attempt, ?Response $response): int
    {
        $after = $response?->getHeader('retry-after');

        if ($after !== null && ctype_digit($after)) {
            return min((int) $retry['max_delay'], (int) $after * 1000);
        }

        return (int) min((float) $retry['max_delay'], (float) $retry['delay'] * ((float) $retry['multiplier'] ** ($attempt - 1)));
    }

    protected function mergeOptions(array $base, array $options): array
    {
        $unknown = array_diff(array_keys($options), array_keys(self::DEFAULT_OPTIONS));

        if ($unknown !== []) {
            throw new InvalidOptionException('Unknown HTTP client option(s): {options}. Allowed: {allowed}.', 0, null, ['options' => implode(', ', $unknown), 'allowed' => implode(', ', array_keys(self::DEFAULT_OPTIONS))]);
        }

        $merged = array_replace(self::DEFAULT_OPTIONS, $base);

        foreach ($options as $name => $value) {
            $merged[$name] = match ($name) {
                'headers' => $this->mergeHeaders((array) $merged['headers'], (array) $value),
                'query' => array_replace((array) $merged['query'], (array) $value),
                'retry' => is_array($value) && is_array($merged['retry']) ? array_replace($merged['retry'], $value) : $value,
                default => $value,
            };

            if ($name === 'json' && $value !== null) {
                $merged['body'] = null;
                $merged['multipart'] = null;
            } elseif (in_array($name, ['body', 'multipart'], true) && $value !== null) {
                $merged['json'] = null;
                $merged[$name === 'body' ? 'multipart' : 'body'] = null;
            } elseif ($name === 'auth_bearer' && $value !== null) {
                $merged['auth_basic'] = null;
            } elseif ($name === 'auth_basic' && $value !== null) {
                $merged['auth_bearer'] = null;
            }
        }

        return $merged;
    }

    protected function mergeHeaders(array $headers, array $extra): array
    {
        foreach ($extra as $name => $value) {
            if (is_int($name) && is_string($value) && str_contains($value, ':')) {
                [$name, $value] = array_map('trim', explode(':', $value, 2));
            }

            if ($value === null) {
                $headers = $this->removeHeader($headers, (string) $name);
                continue;
            }

            $headers = $this->setHeader($headers, (string) $name, is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value);
        }

        return $headers;
    }

    protected function setHeader(array $headers, string $name, string $value): array
    {
        $headers = $this->removeHeader($headers, $name);
        $headers[$name] = str_replace(["\r", "\n"], '', $value);

        return $headers;
    }

    protected function defaultHeader(array $headers, string $name, string $value): array
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $headers;
            }
        }

        return $this->setHeader($headers, $name, $value);
    }

    protected function removeHeader(array $headers, string $name): array
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                unset($headers[$key]);
            }
        }

        return $headers;
    }
}