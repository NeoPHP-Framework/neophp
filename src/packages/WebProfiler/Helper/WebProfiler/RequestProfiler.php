<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Helper\WebProfiler;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Block\KeyValueBlock;
use NeoPHP\Package\WebProfiler\Block\TabsBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use Throwable;

/**
 * @internal
 */
class RequestProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface
{
    public const PRIORITY = 300;

    public const SERVER_KEYS = [
        'SERVER_SOFTWARE', 'SERVER_NAME', 'SERVER_ADDR', 'SERVER_PORT', 'SERVER_PROTOCOL', 'REMOTE_ADDR', 'REMOTE_PORT',
        'REQUEST_METHOD', 'REQUEST_URI', 'QUERY_STRING', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'DOCUMENT_ROOT', 'HTTPS',
        'REQUEST_TIME_FLOAT', 'APP_ENV', 'APP_DEBUG',
    ];

    public const HIDDEN = '******';

    public const SENSITIVE = '/pass(word)?|secret|token|authorization|cookie|api[_-]?key|csrf/i';

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        $server = [];

        foreach (self::SERVER_KEYS as $key) {
            if ($request->server->has($key)) {
                $server[$key] = $request->server->get($key);
            }
        }

        $controller = $request->attributes->get('_controller');

        return [
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
            'path' => $request->getPath(),
            'route' => $request->attributes->get('_route'),
            'controller' => is_string($controller) ? $controller : (is_array($controller) ? implode('::', array_map(static fn (mixed $part): string => is_object($part) ? $part::class : (string) $part, $controller)) : get_debug_type($controller)),
            'status' => $response->getStatusCode(),
            'status_text' => $response->getReasonPhrase(),
            'content_type' => $request->getContentType(),
            'response_type' => $response->headers->get('Content-Type'),
            'query' => $request->query->all(),
            'request' => $this->mask($request->request->all()),
            'attributes' => array_filter($request->attributes->all(), static fn (string|int $key): bool => !str_starts_with((string) $key, '_security'), ARRAY_FILTER_USE_KEY),
            'request_headers' => $this->headers($request->headers->all()),
            'response_headers' => $this->headers($response->headers->all()),
            'server' => $server,
            'body_size' => strlen($request->getContent()),
            'response_size' => strlen($response->getContent()),
        ];
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        $status = (int) ($data['status'] ?? $profile->getStatus());

        return new ToolbarItem(
            (string) ($data['route'] ?? 'no route'),
            (string) $status,
            'request',
            Status::fromHttpCode($status),
            [
                'Status' => $status . ' ' . ($data['status_text'] ?? ''),
                'Method' => $data['method'] ?? $profile->getMethod(),
                'Route' => $data['route'] ?? 'n/a',
                'Controller' => $data['controller'] ?? 'n/a',
                'Content type' => $data['response_type'] ?? 'n/a',
                'Token' => $profile->getToken(),
            ],
        );
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        $status = (int) ($data['status'] ?? $profile->getStatus());

        return new Panel('Request / Response', 'request', [
            new KeyValueBlock([
                'Method' => $data['method'] ?? '',
                'URI' => $data['uri'] ?? '',
                'Route' => $data['route'] ?? null,
                'Controller' => $data['controller'] ?? null,
                'Status' => $status . ' ' . ($data['status_text'] ?? ''),
                'Request content type' => $data['content_type'] ?? null,
                'Response content type' => $data['response_type'] ?? null,
                'Request body' => ($data['body_size'] ?? 0) . ' bytes',
                'Response body' => ($data['response_size'] ?? 0) . ' bytes',
            ], 'Summary'),
            new TabsBlock([
                'Request' => [
                    new KeyValueBlock((array) ($data['query'] ?? []), 'Query parameters (GET)', 'No query parameter.'),
                    new KeyValueBlock((array) ($data['request'] ?? []), 'Request parameters (POST)', 'No request parameter.'),
                    new KeyValueBlock((array) ($data['attributes'] ?? []), 'Attributes', 'No attribute.'),
                    new KeyValueBlock((array) ($data['request_headers'] ?? []), 'Headers'),
                ],
                'Response' => [
                    new KeyValueBlock((array) ($data['response_headers'] ?? []), 'Headers'),
                ],
                'Server' => [new KeyValueBlock((array) ($data['server'] ?? []), 'Server parameters')],
            ]),
        ], $status, Status::fromHttpCode($status));
    }

    protected function headers(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $values) {
            $values = (array) $values;
            $result[(string) $name] = preg_match(self::SENSITIVE, (string) $name) === 1 ? self::HIDDEN : implode(', ', array_map('strval', $values));
        }

        ksort($result);

        return $result;
    }

    protected function mask(array $values): array
    {
        foreach ($values as $key => $value) {
            if (preg_match(self::SENSITIVE, (string) $key) === 1) {
                $values[$key] = self::HIDDEN;
            } elseif (is_array($value)) {
                $values[$key] = $this->mask($value);
            }
        }

        return $values;
    }
}