# HttpClient

The HttpClient component calls external APIs: JSON and form requests, multipart uploads, redirects, retries, parallel requests, streamed downloads and named clients configured in YAML.
No external library is used: requests go through `ext-curl` when it is loaded (parallel requests with `curl_multi`), and through PHP streams otherwise.

## Summary

- [Module](#module)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Requests and options](#requests-and-options)
- [Responses](#responses)
- [Errors](#errors)
- [Named clients](#named-clients)
- [Parallel requests](#parallel-requests)
- [Downloads and progress](#downloads-and-progress)
- [Retry](#retry)
- [Caching](#caching)
- [Events](#events)
- [Logging](#logging)
- [Controllers](#controllers)
- [Console](#console)
- [Testing with MockTransport](#testing-with-mocktransport)
- [HttpClientManagerInterface reference](#httpclientmanagerinterface-reference)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Component\HttpClient\HttpClientManager` (`final`) |
| Interface | `NeoPHP\Component\HttpClient\HttpClientManagerInterface` |
| Attribute | `#[Component(provider: HttpClientProvider::class)]` |
| Requires | nothing |

Inject `HttpClientManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;

public function __construct(private HttpClientManagerInterface $httpClient)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Component\HttpClient\HttpClientManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` (provider, discoveries, traces, `Helper/View`, `Helper/Console`, `Helper/Event`, `Helper/WebProfiler`) are used by the framework only.

## Quick start

```php
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;

class WeatherService
{
    public function __construct(protected HttpClientManagerInterface $http)
    {
    }

    public function today(string $city): array
    {
        return $this->http->get('https://api.example.com/weather', [
            'query' => ['city' => $city],
            'timeout' => 5,
        ])->toArray();
    }
}
```

`toArray()` decodes the JSON body and throws an exception when the status is 3xx / 4xx / 5xx (see [Errors](#errors)).

## Configuration

`config/framework/http_client.yaml`

```yaml
transport: auto

default_options:
  timeout: 30
  max_redirects: 5
  headers:
    User-Agent: 'NeoPHP HttpClient'

clients:
  github:
    base_uri: 'https://api.github.com/'
    headers:
      Accept: 'application/vnd.github+json'
    auth_bearer: '%env(GITHUB_TOKEN)%'
    timeout: 10
    retry:
      max_retries: 3
      delay: 500
      multiplier: 2
      status_codes: [429, 500, 502, 503, 504]
```

| Key | Default | Description |
|---|---|---|
| `transport` | `auto` | `auto` (curl when `ext-curl` is loaded, streams otherwise), `curl`, `stream` or `mock` |
| `default_options` | see below | options applied to every request of every client |
| `clients` | `{}` | named clients: each entry is a set of options merged over `default_options` |

## Requests and options

```php
$response = $http->request('POST', 'https://api.example.com/users', [
    'json' => ['name' => 'Neo'],
    'auth_bearer' => $token,
]);

$http->get($url, $options);
$http->post($url, $options);
$http->put($url, $options);
$http->patch($url, $options);
$http->delete($url, $options);
$http->head($url, $options);
```

| Option | Default | Description |
|---|---|---|
| `base_uri` | `~` | base of relative URLs, resolved with RFC 3986 (`https://api.github.com/` + `users/neo` → `https://api.github.com/users/neo`; a leading `/` replaces the whole path) |
| `query` | `[]` | array merged into the query string of the URL (`null` values are removed) |
| `headers` | `[]` | `['Name' => 'value']` (or `'Name: value'` strings); names are case-insensitive, `null` removes a default header |
| `json` | `~` | value encoded as JSON; sets `Content-Type: application/json` and `Accept: application/json` |
| `body` | `~` | raw string, or array sent as `application/x-www-form-urlencoded` |
| `multipart` | `~` | array of fields or a `FormDataPart`, sent as `multipart/form-data` |
| `auth_basic` | `~` | `['user', 'password']` or `'user:password'` (credentials in the URL, `https://user:pass@host`, are used too) |
| `auth_bearer` | `~` | token sent as `Authorization: Bearer …` |
| `timeout` | `30` | total time allowed for each attempt, in seconds (float) |
| `connect_timeout` | `~` | connection time allowed, in seconds (float) |
| `max_redirects` | `5` | redirects followed (`0` = none) |
| `verify_peer` | `true` | check the TLS certificate of the server |
| `proxy` | `~` | proxy URL, e.g. `http://proxy.local:3128` |
| `retry` | `[]` | see [Retry](#retry) |
| `sink` | `~` | file path: the body is written to this file instead of memory (see [Downloads](#downloads-and-progress)) |
| `on_progress` | `~` | `callable(int $downloaded, ?int $total)` called while the body is received |
| `cache` | `false` | cache GET / HEAD responses in a Cache pool: `true`, a TTL in seconds, a pool name or `['pool' => 'name', 'ttl' => 60]` (see [Caching](#caching)) |

`json`, `body` and `multipart` are mutually exclusive: the last one set wins. An unknown option throws `InvalidOptionException`.

Redirects `301`, `302`, `303`, `307` and `308` are followed up to `max_redirects`: `303` turns the request into a `GET` without body (except `HEAD`), `301` / `302` turn a `POST` into a `GET`, `307` / `308` keep the method and the body. The `Authorization` and `Cookie` headers are removed when the redirect goes to another host.

### Multipart and files

```php
use NeoPHP\Component\HttpClient\Part\FilePart;

$http->post('https://api.example.com/documents', [
    'multipart' => [
        'title' => 'Invoice',
        'tags' => ['pdf', 'invoice'],
        'file' => new FilePart('/path/invoice.pdf'),
        'meta' => FilePart::fromData('{"id":1}', 'meta.json', 'application/json'),
    ],
]);
```

| Class | API |
|---|---|
| `Part\FilePart` | `__construct(string $path, ?string $filename = null, ?string $contentType = null)`, `fromPath()`, `fromData(string $content, string $filename, ?string $contentType = null)`; the content type is detected with `ext-fileinfo` when available |
| `Part\FormDataPart` | `__construct(array $fields = [], ?string $boundary = null)`, `getBody()`, `getContentType()`, `getBoundary()`; nested arrays become `tags[]` / `meta[key]` fields |

### Default options

`withOptions()` returns a new client with merged default options; the original client is unchanged:

```php
$api = $http->withOptions([
    'base_uri' => 'https://api.example.com/v2/',
    'headers' => ['X-Api-Key' => $key],
]);

$api->get('orders');
```

## Responses

```php
$response = $http->get('https://api.example.com/users/1');

$response->getStatusCode();          // 200
$response->getHeaders();             // ['content-type' => ['application/json'], ...]
$response->getHeader('Content-Type');// 'application/json' (first value, case-insensitive)
$response->getContent();             // body as string
$response->toArray();                // decoded JSON
$response->isSuccessful();           // 2xx
$response->getInfo('url');           // final URL after redirects
```

| `getInfo()` key | Description |
|---|---|
| `original_url` | URL requested (after `base_uri` and `query`) |
| `url` | final URL, after redirects |
| `http_method` | method of the last request (a `303` changes it) |
| `redirect_count` | redirects followed |
| `retry_count` | retries done |
| `from_cache` | `true` when the response comes from the cache (see [Caching](#caching)) |
| `total_time` | total duration in seconds, retries and redirects included |
| `primary_ip` | IP of the server (curl transport only) |
| `sink` | file of the body, when `sink` / `download()` is used |

`getInfo()` without argument returns every key.

## Errors

| Exception | When |
|---|---|
| `TransportException` | network error: DNS, connection refused, timeout, TLS; thrown by `request()` |
| `ClientException` | `getContent()` / `toArray()` on a 4xx response |
| `ServerException` | `getContent()` / `toArray()` on a 5xx response |
| `RedirectionException` | `getContent()` / `toArray()` on a 3xx response (redirects disabled or exhausted) |
| `DecodingException` | `toArray()` on an empty body or invalid JSON |
| `InvalidOptionException` | unknown option, relative URL without `base_uri`, unreadable upload |

All of them are in `NeoPHP\Component\HttpClient\Exception` and extend `HttpClientException` (a `FrameworkException`). `ClientException`, `ServerException` and `RedirectionException` extend `HttpException`, which carries the response:

```php
use NeoPHP\Component\HttpClient\Exception\ClientException;

try {
    $user = $http->get('https://api.example.com/users/42')->toArray();
} catch (ClientException $exception) {
    $status = $exception->getResponse()->getStatusCode();
    $error = $exception->getResponse()->toArray(false);
}
```

The status is only checked when the body is read: pass `false` to read an error response without exception.

```php
$response = $http->get($url);

if (!$response->isSuccessful()) {
    return $response->getContent(false);
}
```

## Named clients

Each entry of `clients` is registered in the container as `http_client.<name>`:

```php
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;

class GithubService
{
    public function __construct(#[Autowire(service: 'http_client.github')] protected HttpClientManagerInterface $github)
    {
    }

    public function user(string $login): array
    {
        return $this->github->get('users/' . rawurlencode($login))->toArray();
    }
}
```

The client is also available with `$http->client('github')` (`hasClient()` checks that it exists). A named client uses `default_options` merged with its own options.

## Parallel requests

`requestMany()` takes an array of `[method, url, options]` (or `['method' => …, 'url' => …, 'options' => …]`) and returns the responses with the same keys:

```php
$responses = $http->requestMany([
    'user' => ['GET', 'https://api.example.com/users/1'],
    'orders' => ['GET', 'https://api.example.com/orders', ['query' => ['user' => 1]]],
    'log' => ['POST', 'https://api.example.com/log', ['json' => ['event' => 'view']]],
]);

$user = $responses['user']->toArray();
```

With the curl transport, the requests are sent at the same time (`curl_multi`), redirects and retries included; the stream transport sends them one after the other. A network error on one of the requests throws its `TransportException` once all the requests are done.

## Downloads and progress

`download()` writes the body directly to a file, chunk by chunk, without loading it in memory; missing directories are created:

```php
$response = $http->download('https://example.com/archive.zip', $projectDir . '/var/downloads/archive.zip', [
    'on_progress' => function (int $downloaded, ?int $total): void {
        printf("%d / %s bytes\n", $downloaded, $total ?? '?');
    },
]);
```

`$total` is `null` when the server does not send `Content-Length`. The `sink` option does the same with any method. When the status is an error, the file contains the error body: check `$response->isSuccessful()`.

## Retry

```yaml
retry:
  max_retries: 3
  delay: 500
  multiplier: 2
  max_delay: 30000
  status_codes: [429, 500, 502, 503, 504]
```

| Key | Default | Description |
|---|---|---|
| `max_retries` | `0` | retries after the first attempt (`retry: 3` is a shortcut) |
| `delay` | `1000` | delay before the first retry, in milliseconds |
| `multiplier` | `2` | the delay is multiplied by this value after each retry |
| `max_delay` | `30000` | maximum delay, in milliseconds |
| `status_codes` | `[423, 425, 429, 500, 502, 503, 504, 507, 510]` | statuses that are retried |

Network errors (`TransportException`) are retried too. A numeric `Retry-After` header replaces the computed delay. Every method is retried: do not enable retries for non-idempotent calls that must not be repeated.

## Caching

The `cache` option stores the responses of `GET` and `HEAD` requests (without body, without `sink`) in a pool of the [Cache component](../../../Cache/Docs/v2.x/README.md):

```php
$http->get('https://api.example.com/countries', ['cache' => true]);                   // default pool, TTL from the response headers
$http->get('https://api.example.com/countries', ['cache' => 300]);                    // forced TTL (seconds)
$http->get('https://api.example.com/countries', ['cache' => 'http']);                 // pool "http", TTL from the headers
$http->get('https://api.example.com/countries', ['cache' => ['pool' => 'http', 'ttl' => 300]]);
```

| Value | Behaviour |
|---|---|
| `false` / `~` | disabled (default) |
| `true` | default pool, freshness from `Cache-Control` / `Expires` |
| `int` | default pool, the response is fresh for this number of seconds (the headers are ignored, except `no-store`) |
| `string` | pool name |
| `array` | `pool` (name, default pool when omitted) and `ttl` (forced TTL or `~`) |

Rules:

- only `200` responses are stored; the cache key is the method, the final URL (with `base_uri` and `query`), the `Accept` header and a hash of the `Authorization` header;
- `Cache-Control: no-store` is never stored; `no-cache` is stored but revalidated on each request; `max-age` (then `s-maxage`, minus `Age`) or `Expires` give the freshness when the TTL is not forced; `private` is accepted (the cache belongs to the client);
- a response without freshness information is only stored when it has an `ETag` or a `Last-Modified` header;
- when a stored response is stale and has a validator, the request is sent with `If-None-Match` / `If-Modified-Since`; on `304 Not Modified`, the stored body is returned and its freshness refreshed;
- responses served from the cache (fresh, or revalidated with a `304`) have the info `from_cache` set to `true` (`$response->getInfo('from_cache')`), other responses `false`.

Named clients can enable it in YAML:

```yaml
clients:
  github:
    base_uri: 'https://api.github.com/'
    cache: true
```

The option requires the Cache component: `HttpClientProvider` gives the client a pool resolver when `CacheManagerInterface` is in the container. A client created by hand receives it as last constructor argument (`new HttpClientManager($transport, null, null, [], fn (?string $pool) => $cache->pool($pool))`) or with `setCache()`. Without resolver, a request with the `cache` option throws `InvalidOptionException`.

## Events

Dispatched through the [Event](../../../Event/Docs/v2.x/README.md) component when it is available:

| Event | When | API |
|---|---|---|
| `Event\RequestEvent` | before a request is sent (once, not for redirects and retries) | `getMethod()`, `setMethod()`, `getUrl()`, `setUrl()`, `getOptions()`, `setOptions()`, `setOption()`, `setHeader()` |
| `Event\ResponseEvent` | after the final response | `getMethod()`, `getUrl()`, `getResponse()`, `setResponse()` |
| `Event\ExceptionEvent` | after a network error, or a final status >= 300 | `getMethod()`, `getUrl()`, `getException()` (`TransportException` or `HttpException`), `getResponse()` |

```php
use NeoPHP\Component\HttpClient\Event\RequestEvent;

class TraceListener
{
    public function __invoke(RequestEvent $event): void
    {
        $event->setHeader('X-Request-Id', bin2hex(random_bytes(8)));
    }
}
```

## Logging

When the Logger component is available, each request is logged: `info` for `GET https://api.example.com/users 200 (84 ms)`, `warning` for a 4xx / 5xx status or a network error. The `http_client` channel is used when it is defined in `config/framework/logger.yaml`, the default channel otherwise:

```yaml
channels:
  http_client:
    enabled: true
    extension: log
```

Headers and bodies are never logged, and credentials are removed from the URL (`https://user:pass@host` is logged as `https://host`). The query string is logged: prefer headers for API keys.

## Controllers

`AbstractController` provides `httpClient()`:

```php
public function show(string $login): Response
{
    $user = $this->httpClient('github')->get('users/' . $login)->toArray();

    return $this->render('github/show', ['user' => $user]);
}
```

`httpClient()` returns the default client, `httpClient('name')` a named client.

## Console

```bash
php bin/neo http:request https://api.github.com/zen
php bin/neo http:request users/octocat --client=github -i
php bin/neo http:request https://httpbin.org/post -X POST --json='{"name":"neo"}' -H 'X-Trace: 1'
php bin/neo http:request https://httpbin.org/post -X POST -d 'a=1&b=2'
php bin/neo http:request https://example.com/file.zip --output=var/file.zip
```

| Argument / option | Description |
|---|---|
| `url` | URL, relative to the `base_uri` of the client if any (asked when missing) |
| `--method`, `-X` | HTTP method (`GET` by default) |
| `--header`, `-H` | `Name: value` header, repeatable |
| `--json` | JSON body |
| `--data`, `-d` | raw body; `a=1&b=2` is sent as a form |
| `--client`, `-c` | named client |
| `--include`, `-i` | display the response headers |
| `--output`, `-o` | write the body to a file |

The command displays the status, the headers with `-i`, and the body (JSON is pretty-printed). With `-v`, the redirects, retries and transport are displayed. The exit code is `1` on a 4xx / 5xx status or a network error.

## Testing with MockTransport

```php
use NeoPHP\Component\HttpClient\HttpClientManager;
use NeoPHP\Component\HttpClient\Transport\MockResponse;
use NeoPHP\Component\HttpClient\Transport\MockTransport;

$transport = new MockTransport([
    new MockResponse('down', 503),
    MockResponse::json(['id' => 1], 201, ['X-Id' => '1']),
]);

$client = new HttpClientManager($transport, null, null, [
    'default_options' => ['base_uri' => 'https://api.example.com/', 'retry' => ['max_retries' => 1, 'delay' => 0]],
]);

$client->post('users', ['json' => ['name' => 'Neo']])->toArray(); // ['id' => 1]

$request = $transport->getLastRequest();
$request->getMethod();                 // 'POST'
$request->getUrl();                    // 'https://api.example.com/users'
$request->getHeader('Content-Type');   // 'application/json'
$request->getBody();                   // '{"name":"Neo"}'
count($transport->getRequests());      // 2
```

The constructor also accepts a callable `fn (string $method, string $url, array $options): MockResponse` (`$options` contains `headers` and `body`). A `TransportException` in the list simulates a network error. `addResponse()` appends a response, `reset()` clears the recorded requests.

In the application, replace the transport service:

```yaml
# config/framework/http_client.yaml (test environment)
transport: mock
```

```php
$container->instance(TransportInterface::class, new MockTransport([...]));
```

## HttpClientManagerInterface reference

`NeoPHP\Component\HttpClient\HttpClientManagerInterface`, implemented by `HttpClientManager`:

| Method | Description |
|---|---|
| `request(string $method, string $url, array $options = []): ResponseInterface` | sends a request |
| `get()`, `post()`, `put()`, `patch()`, `delete()`, `head()` | `(string $url, array $options = []): ResponseInterface` |
| `requestMany(array $requests): array` | sends requests in parallel, returns the responses with the same keys |
| `download(string $url, string $target, array $options = []): ResponseInterface` | writes the body to `$target` |
| `withOptions(array $options): static` | new client with merged default options |
| `client(string $name): HttpClientManagerInterface` | named client |
| `hasClient(string $name): bool` | is the named client configured |
| `getOptions(): array` | default options of the client |
| `getTransport(): TransportInterface` | transport used |

`NeoPHP\Component\HttpClient\Contract\ResponseInterface`: `getStatusCode()`, `getHeaders()`, `getHeader(string $name)`, `getContent(bool $throw = true)`, `toArray(bool $throw = true)`, `getInfo(?string $key = null)`, `isSuccessful()`.

`NeoPHP\Component\HttpClient\Contract\TransportInterface`: `send(Request $request): Response`, `sendMany(array $requests): array` (responses or `TransportException` by key), `getName()`. Transports: `Transport\CurlTransport`, `Transport\StreamTransport`, `Transport\MockTransport`; `Transport\TransportFactory::create('auto'|'curl'|'stream'|'mock')`.

### Services

| Id | Service |
|---|---|
| `HttpClientManagerInterface`, `HttpClientManager`, `http_client` | default client |
| `http_client.<name>` | named client |
| `TransportInterface`, `http_client.transport` | transport |
| `TransportFactory` | transport factory |

## Changelog

- v2.0.0 — `HttpClientManager` is the `final` entry point of the module, declared with `#[Component]`; `HttpClientManagerInterface` replaces `Contract\HttpClientInterface`; `Contract\AbstractHttpClient` is merged into the manager; the internal classes are marked `@internal`.
- v1.22.0 — `cache` option (responses cached in a Cache pool, Cache-Control, ETag / Last-Modified revalidation)
- v1.21.0 — HttpClient component: `HttpClientInterface` (`request()`, `get()` / `post()` / `put()` / `patch()` / `delete()` / `head()`, `requestMany()`, `download()`, `withOptions()`), curl, stream and mock transports, options (`base_uri`, `query`, `headers`, `json`, `body`, `multipart`, `auth_basic`, `auth_bearer`, timeouts, redirects, `verify_peer`, `proxy`, `retry`, `sink`, `on_progress`), HTTP exceptions carrying the response, named clients `http_client.<name>`, `RequestEvent` / `ResponseEvent` / `ExceptionEvent`, request logging, `httpClient()` in controllers, `http:request` command, `config/framework/http_client.yaml`.