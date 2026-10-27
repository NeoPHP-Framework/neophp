<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure;

use NeoPHP\Component\Cookie\CookieManagerInterface;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\HttpClient\Exception\HttpClientException;
use NeoPHP\Component\HttpClient\HttpClientManager;
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;
use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Package\Mercure\Exception\MercureException;
use NeoPHP\Package\Mercure\Helper\Queue\PublishUpdateJob;
use NeoPHP\Package\Mercure\Jwt\JwtSigner;
use NeoPHP\Package\Mercure\Model\Update;
use NeoPHP\Package\Mercure\Provider\MercureProvider;
use NeoPHP\Package\Queue\Contract\MessageBusInterface;

#[Package(provider: MercureProvider::class, requires: [HttpClientManager::class])]
final class MercureManager implements MercureManagerInterface
{
    public const CONFIG_FILE = 'config/packages/mercure.yaml';

    public const DEFAULT_CONFIG = [
        'url' => null,
        'public_url' => null,
        'jwt' => [
            'secret' => null,
            'token' => null,
            'algorithm' => 'HS256',
            'publish' => ['*'],
        ],
        'cookie' => [
            'lifetime' => self::DEFAULT_LIFETIME,
            'domain' => null,
            'secure' => 'auto',
            'samesite' => 'Strict',
        ],
        'http_options' => [],
    ];

    protected array $config;

    protected ?JwtSigner $signer = null;

    protected ?string $publisherToken = null;

    public function __construct(
        protected HttpClientManagerInterface $http,
        array $config = [],
        protected ?CookieManagerInterface $cookies = null,
        protected ?MessageBusInterface $bus = null,
    ) {
        $this->config = array_replace(self::DEFAULT_CONFIG, $config);
        $this->config['jwt'] = array_replace(self::DEFAULT_CONFIG['jwt'], (array) $this->config['jwt']);
        $this->config['cookie'] = array_replace(self::DEFAULT_CONFIG['cookie'], (array) $this->config['cookie']);
    }

    public function publish(Update $update): string
    {
        $body = implode('&', array_map(static fn (array $field): string => $field[0] . '=' . rawurlencode($field[1]), $update->toFields()));
        $options = (array) $this->config['http_options'];
        $options['headers'] = array_replace((array) ($options['headers'] ?? []), [
            'Authorization' => 'Bearer ' . $this->createPublisherToken(),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
        $options['body'] = $body;

        try {
            $response = $this->http->post($this->getUrl(), $options);
            $content = $response->getContent(false);
        } catch (HttpClientException $exception) {
            throw new MercureException('The Mercure hub "{url}" cannot be reached: {error}', 0, $exception, ['url' => $this->getUrl(), 'error' => $exception->getMessage()]);
        }

        if (!$response->isSuccessful()) {
            throw new MercureException('The Mercure hub "{url}" refused the update (HTTP {status}): {content}', 0, null, [
                'url' => $this->getUrl(),
                'status' => $response->getStatusCode(),
                'content' => trim($content) === '' ? 'empty response' : trim($content),
            ]);
        }

        return trim($content);
    }

    public function dispatch(Update $update, array $options = []): void
    {
        if ($this->bus === null) {
            throw new MercureException('The Queue package is disabled: publish the update with publish() instead of dispatch().');
        }

        $this->bus->dispatch(new PublishUpdateJob($update), $options);
    }

    public function getUrl(): string
    {
        $url = $this->config['url'];

        if (!is_string($url) || trim($url) === '') {
            throw new MercureException('The URL of the Mercure hub is not configured: set MERCURE_URL (url in {config}).', 0, null, ['config' => self::CONFIG_FILE]);
        }

        return trim($url);
    }

    public function getPublicUrl(): string
    {
        $url = $this->config['public_url'];

        return is_string($url) && trim($url) !== '' ? trim($url) : $this->getUrl();
    }

    public function getSubscribeUrl(string|array $topics = []): string
    {
        $url = $this->getPublicUrl();
        $query = implode('&', array_map(static fn (string $topic): string => 'topic=' . rawurlencode($topic), is_array($topics) ? array_values($topics) : [$topics]));

        return $query === '' ? $url : $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    public function createPublisherToken(?array $topics = null, ?int $lifetime = null): string
    {
        if ($topics === null && $lifetime === null) {
            $token = $this->config['jwt']['token'];

            if (is_string($token) && $token !== '' && !$this->hasSecret()) {
                return $token;
            }

            return $this->publisherToken ??= $this->signer()->sign(['mercure' => ['publish' => array_values((array) $this->config['jwt']['publish'])]]);
        }

        $claims = ['mercure' => ['publish' => array_values($topics ?? (array) $this->config['jwt']['publish'])]];

        if ($lifetime !== null) {
            $claims['exp'] = time() + $lifetime;
        }

        return $this->signer()->sign($claims);
    }

    public function createSubscriberToken(array $topics, array $payload = [], ?int $lifetime = null): string
    {
        $claims = ['mercure' => ['subscribe' => array_values($topics)]];

        if ($payload !== []) {
            $claims['mercure']['payload'] = $payload;
        }

        $claims['exp'] = time() + ($lifetime ?? (int) $this->config['cookie']['lifetime']);

        return $this->signer()->sign($claims);
    }

    public function authorize(array $topics, array $payload = [], ?int $lifetime = null): string
    {
        $lifetime ??= (int) $this->config['cookie']['lifetime'];
        $token = $this->createSubscriberToken($topics, $payload, $lifetime);
        $this->cookies()->set(self::COOKIE_NAME, $token, $this->cookieOptions() + ['lifetime' => $lifetime]);

        return $token;
    }

    public function clearAuthorization(): void
    {
        $this->cookies()->remove(self::COOKIE_NAME, $this->cookieOptions());
    }

    public function addDiscoveryLink(Response $response): Response
    {
        return $response->setHeader('Link', '<' . $this->getPublicUrl() . '>; rel="mercure"', false);
    }

    protected function hasSecret(): bool
    {
        return is_string($this->config['jwt']['secret']) && $this->config['jwt']['secret'] !== '';
    }

    protected function signer(): JwtSigner
    {
        return $this->signer ??= new JwtSigner((string) $this->config['jwt']['secret'], (string) $this->config['jwt']['algorithm']);
    }

    protected function cookies(): CookieManagerInterface
    {
        if ($this->cookies === null) {
            throw new MercureException('The Cookie component is disabled: the mercureAuthorization cookie cannot be set.');
        }

        return $this->cookies;
    }

    protected function cookieOptions(): array
    {
        $path = parse_url($this->getPublicUrl(), PHP_URL_PATH);
        $domain = $this->config['cookie']['domain'];

        return [
            'path' => is_string($path) && $path !== '' ? $path : '/',
            'domain' => is_string($domain) && $domain !== '' ? $domain : null,
            'secure' => $this->config['cookie']['secure'],
            'httponly' => true,
            'samesite' => (string) $this->config['cookie']['samesite'],
            'signed' => false,
        ];
    }
}