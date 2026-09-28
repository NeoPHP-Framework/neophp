# Http

The Http component models the HTTP request and response: parameter bags, uploaded files, HTML, JSON and redirect responses, and HTTP exceptions.
It has no dependency and is used by the kernel, the routing and the controllers.

## Summary

- [Request](#request)
- [Reverse proxies and trusted hosts](#reverse-proxies-and-trusted-hosts)
- [Bags](#bags)
- [Uploaded files](#uploaded-files)
- [Responses](#responses)
- [HTTP service](#http-service)
- [Controller helpers](#controller-helpers)
- [HTTP exceptions](#http-exceptions)
- [Changelog](#changelog)

## Request

`NeoPHP\Component\Http\Request\Request` is injected in a controller action by its type.

```php
public function search(Request $request): Response
{
    $query = $request->query->getString('q');
    $page = $request->query->getInt('page', 1);

    return $this->render('search.html.twig', ['query' => $query, 'page' => $page]);
}
```

| Property | Content |
|---|---|
| `query` | GET parameters (`ParameterBag`) |
| `request` | POST parameters, and the JSON body for POST, PUT, PATCH, DELETE (`ParameterBag`) |
| `attributes` | route parameters, `_route`, `_controller` (`ParameterBag`) |
| `cookies` | cookies (`ParameterBag`) |
| `files` | uploaded files (`FileBag`) |
| `server` | `$_SERVER` (`ParameterBag`) |
| `headers` | headers (`HeaderBag`) |

| Method | Returns |
|---|---|
| `Request::fromGlobals()` | a request built from the PHP globals |
| `Request::create($uri, $method, $parameters, $server, $content)` | a request built by hand (tests, sub-requests) |
| `new Request($query, $request, $attributes, $cookies, $files, $server, $content)` | a request |
| `getMethod()` | HTTP method; a POST can send `_method` or `X-HTTP-Method-Override` with `PUT`, `PATCH` or `DELETE` |
| `getRealMethod()`, `setMethod($method)`, `isMethod($method)` | real method, override, comparison |
| `getPath()`, `getQueryString()`, `getUri()` | URL parts |
| `getScheme()`, `isSecure()`, `getHost()`, `getPort()`, `getSchemeAndHttpHost()` | server information (`X-Forwarded-Proto`, `-Host`, `-Port` when the request comes from a trusted proxy) |
| `getClientIp()` | client IP: `REMOTE_ADDR`, or the client of `X-Forwarded-For` behind a trusted proxy; `null` when unknown |
| `isFromTrustedProxy()` | the request was sent by a trusted proxy |
| `getContent()`, `toArray()` | raw body, decoded JSON body |
| `get($key, $default)` | value from `attributes`, then `query`, then `request` |
| `getContentType()`, `isJson()`, `wantsJson()`, `isXmlHttpRequest()` | content negotiation |

`Request::METHODS` lists the accepted methods.

## Reverse proxies and trusted hosts

Behind Nginx, a load balancer, Cloudflare or a PaaS, PHP sees the IP of the proxy and plain `http`. Declare the proxies in `config/framework/app.yaml`, their `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host` and `X-Forwarded-Port` headers are then used by `getClientIp()`, `isSecure()`, `getHost()` and `getPort()` (secure cookies, absolute URLs, login throttling and rate limiting per IP):

```yaml
# config/framework/app.yaml
trusted_proxies: '%env(TRUSTED_PROXIES)%'   # e.g. TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8
trusted_hosts: '%env(TRUSTED_HOSTS)%'       # e.g. TRUSTED_HOSTS=example.com,*.example.com
```

| Option | Values |
|---|---|
| `trusted_proxies` | list or comma separated string of IPs and CIDR ranges (`127.0.0.1`, `10.0.0.0/8`, `::1`); `REMOTE_ADDR` trusts the machine that sends the request (only when the application cannot be reached without the proxy) |
| `trusted_hosts` | list or comma separated string of host names (`example.com`, `*.example.com`) or regular expressions (`^(www\.)?example\.com$`); any other `Host` is refused with a 400 error. Empty: every host is accepted |

The headers of a request that does not come from a trusted proxy are ignored: a client cannot fake its IP or the HTTPS scheme. Without `trusted_hosts`, a forged `Host` header can end up in the absolute URLs generated during the request (password reset emails): set it in production.

The same can be done in PHP with `Request::setTrustedProxies(['10.0.0.0/8'])` and `Request::setTrustedHosts(['example.com'])`.

## Bags

`ParameterBag` (query, request, attributes, cookies, server) is iterable and countable:

| Method | Description |
|---|---|
| `all()`, `keys()` | all values, all keys |
| `get($key, $default)`, `has($key)`, `set($key, $value)`, `remove($key)` | access |
| `replace($parameters)`, `add($parameters)` | replace or merge values |
| `getString($key, $default)`, `getInt($key, $default)`, `getBoolean($key, $default)` | typed values |

`FileBag` extends `ParameterBag` and holds `UploadedFile` objects (or lists of them).

`HeaderBag` is case-insensitive: `all()`, `get($name, $default)` (first value), `getValues($name)`, `set($name, $values, $replace)`, `has()`, `remove()`, `HeaderBag::fromServer($server)`.

## Uploaded files

```php
$file = $request->files->get('avatar');

if ($file instanceof UploadedFile && $file->isValid() && in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
    // random name + extension detected from the content: e.g. var/uploads/avatars/3f9c...e1.png
    $path = $file->move($this->get('kernel.root_path') . '/var/uploads/avatars');
}
```

Without a name, `move()` generates a random name and adds the extension detected from the file content (`guessExtension()`), never the one sent by the client. A name containing a directory, starting with a dot or ending with an executable extension (`.php`, `.phtml`, `.phar`, `.htaccess`...) is refused with a 400 error.

Store the uploaded files outside `public/` (for example `var/uploads/`) and serve them through a controller. If they must be public, validate their MIME type with `getMimeType()` first: `getClientOriginalExtension()` and `getClientMimeType()` are sent by the client and cannot be trusted.

| Method | Returns |
|---|---|
| `getPath()` | temporary path |
| `getClientOriginalName()`, `getClientOriginalExtension()`, `getClientMimeType()` | information sent by the client (not trusted) |
| `getMimeType()`, `guessExtension()` | MIME type detected from the content (`fileinfo`) and its extension |
| `getSize()` | size in bytes |
| `getError()`, `getErrorMessage()`, `isValid()` | upload status (`UPLOAD_ERR_*`) |
| `move($directory, $name = null)` | moves the file (random name by default) and returns its new path |

## Responses

```php
$response = new Response('<h1>Hello</h1>', 200, ['X-Custom' => 'value']);
$response->setStatusCode(201);
$response->setHeader('Cache-Control', 'no-cache');
$response->setCookie('theme', 'dark', time() + 3600);

new JsonResponse(['ok' => true]);
new RedirectResponse('/login');
```

`Response`:

| Method | Description |
|---|---|
| `getContent()`, `setContent($content)` | body |
| `getStatusCode()`, `setStatusCode($code)`, `getReasonPhrase()` | status |
| `getProtocolVersion()`, `setProtocolVersion($version)` | protocol (`1.1`) |
| `$response->headers`, `setHeader($name, $values, $replace)` | headers |
| `setCookie($name, $value, $expires, $path, $domain, $secure, $httpOnly, $sameSite)` | adds a `Set-Cookie` header (`$expires`: timestamp or `DateTimeInterface`, `0` for a session cookie) |
| `clearCookie($name, $path, $domain)` | expires a cookie |
| `isInformational()`, `isSuccessful()`, `isRedirection()`, `isClientError()`, `isServerError()`, `isEmpty()` | status checks |
| `prepare($request)` | fixes the headers for the request (default `Content-Type`, `HEAD`, empty responses) |
| `send()`, `sendHeaders()`, `sendContent()` | sends the response |

`JsonResponse($data, $status, $headers, $flags)` encodes `$data` (`getData()`, `setData()`); `JsonResponse::DEFAULT_FLAGS` keeps slashes and unicode unescaped. `RedirectResponse($url, $status = 302, $headers)` sets the `Location` header (`getTargetUrl()`).

## HTTP service

`NeoPHP\Component\Http\Contract\HttpInterface` (implemented by `HttpManager`) can be injected in a service:

| Method | Returns |
|---|---|
| `createRequestFromGlobals()` | the current `Request` |
| `createResponse($content, $status, $headers)` | a `Response` |
| `json($data, $status, $headers)` | a `JsonResponse` |
| `redirect($url, $status, $headers)` | a `RedirectResponse` |
| `send($response, $request)` | prepares and sends the response |

## Controller helpers

The trait `HttpController` of `AbstractController` provides:

```php
return $this->json(['id' => $post->getId()], 201);
return $this->json($post, 200, [], ['groups' => ['read']]);
return $this->redirect('/posts');
throw $this->createNotFoundException('Post {id} not found.', ['id' => $id]);
throw $this->createAccessDeniedException();
```

`json($data, $status = 200, $headers = [], $context = [])`: when `$data` contains objects, or when a `$context` is given, the data is normalized with the Serializer (`#[Groups]`, `#[SerializedName]`, `#[Ignore]`, dates, enums, entities...) before the `JsonResponse` is built. Arrays of scalars are encoded as before. See the Serializer documentation for the context options.

The trait declares `abstract protected function get(string $id): mixed;` and `abstract protected function has(string $id): bool;` (both provided by `ContainerController`).

To redirect to a route, use `redirectToRoute()` (see the Routing documentation).

## HTTP exceptions

All extend `NeoPHP\Component\Exception\FrameworkException` (see the Exception documentation):

| Exception | Status | Constructor |
|---|---|---|
| `TooManyRequestsHttpException` | 429 | `($retryAfter = null, $message = 'Too Many Requests', $headers = [], $context = [], $previous = null)`: sets the `Retry-After` header, `getRetryAfter(): ?int` |
| `HttpException` | any | `($statusCode = 500, $message = '', $headers = [], $context = [], $previous = null)` |
| `BadRequestHttpException` | 400 | `($message = 'Bad Request', $context = [], $previous = null)` |
| `AccessDeniedHttpException` | 403 | `($message = 'Forbidden', $context = [], $previous = null)` |
| `NotFoundHttpException` | 404 | `($message = 'Not Found', $context = [], $previous = null)` |

```php
throw new HttpException(503, 'Maintenance in progress.', ['Retry-After' => '3600']);
```

Errors are rendered as HTML, or as JSON when the request sends `Accept: application/json`. With the Api component, API errors can be rendered as RFC 7807 problem details (`application/problem+json`, see the Api documentation).

## Changelog

- v1.30.0 — trusted proxies (`trusted_proxies`: `X-Forwarded-For`, `-Proto`, `-Host`, `-Port`) and trusted hosts (`trusted_hosts`), `isFromTrustedProxy()`, `Request::ipMatches()`.
- v1.29.1 (bugfix) — `UploadedFile::move()` generates a random name by default, refuses executable extensions and path separators, creates directories in `0775`; `getMimeType()` and `guessExtension()` detect the type from the content.
- v1.24.0 — `TooManyRequestsHttpException` (429, `Retry-After`).
- v1.23.0 — `json()` accepts a serializer context and normalizes objects with the Serializer
- v1.17.x (bugfix) — absolute URLs fall back on `getSchemeAndHttpHost()` of the request when `APP_URL` is not set.
- v1.0.0 — `Request`, `Response`, `JsonResponse`, `RedirectResponse`, bags, uploaded files, HTTP exceptions and JSON errors.