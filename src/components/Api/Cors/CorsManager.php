<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Cors;

use NeoPHP\Component\Api\Attribute\Cors;
use NeoPHP\Component\Api\Exception\InvalidConfigurationException;
use NeoPHP\Component\Api\Reflection\ControllerReflector;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;

class CorsManager
{
    public const HANDLED_ATTRIBUTE = '_cors';

    public const DEFAULTS = [
        'allow_origin' => [],
        'allow_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        'expose_headers' => [],
        'allow_credentials' => false,
        'max_age' => 0,
    ];

    protected bool $enabled;

    protected array $defaults;

    protected array $paths = [];

    public function __construct(array $config = [])
    {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->defaults = $this->normalize(array_replace(self::DEFAULTS, (array) ($config['defaults'] ?? [])));

        foreach ((array) ($config['paths'] ?? []) as $path => $options) {
            $this->paths[(string) $path] = [$this->pattern((string) $path), $this->normalize(array_replace($this->defaults, (array) ($options ?? [])))];
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getDefaults(): array
    {
        return $this->defaults;
    }

    public function getPaths(): array
    {
        return array_map(static fn (array $path): array => $path[1], $this->paths);
    }

    public function isPreflight(Request $request): bool
    {
        return $request->getRealMethod() === 'OPTIONS'
            && $request->headers->has('Origin')
            && $request->headers->has('Access-Control-Request-Method');
    }

    public function resolve(Request $request, mixed $controller = null): ?array
    {
        $options = $this->enabled ? $this->forPath($request->getPath()) : null;
        $attributes = $controller === null ? [] : ControllerReflector::attributes($controller, Cors::class);

        foreach ($attributes as $attribute) {
            $options = $this->normalize(array_replace($options ?? $this->defaults, $attribute->toArray()));
        }

        return $options;
    }

    public function forPath(string $path): ?array
    {
        foreach ($this->paths as [$pattern, $options]) {
            if (preg_match($pattern, $path) === 1) {
                return $options;
            }
        }

        return null;
    }

    public function isAllowedOrigin(string $origin, array $options): bool
    {
        foreach ((array) $options['allow_origin'] as $allowed) {
            $allowed = (string) $allowed;

            if ($allowed === '*' || strcasecmp($allowed, $origin) === 0) {
                return true;
            }

            if ($this->isRegex($allowed) && @preg_match($allowed, $origin) === 1) {
                return true;
            }
        }

        return false;
    }

    public function preflight(Request $request, array $options): Response
    {
        $response = new Response('', 204);
        $this->vary($response, ['Origin', 'Access-Control-Request-Method', 'Access-Control-Request-Headers']);
        $origin = (string) $request->headers->get('Origin', '');

        if (!$this->isAllowedOrigin($origin, $options)) {
            return $response;
        }

        $this->allowOrigin($response, $origin, $options);
        $methods = array_map('strtoupper', (array) $options['allow_methods']);
        $requestedMethod = strtoupper((string) $request->headers->get('Access-Control-Request-Method', ''));

        if (in_array('*', $methods, true)) {
            $methods = [$requestedMethod];
        }

        $response->headers->set('Access-Control-Allow-Methods', implode(', ', array_unique($methods)));
        $headers = (array) $options['allow_headers'];
        $requestedHeaders = trim((string) $request->headers->get('Access-Control-Request-Headers', ''));

        if (in_array('*', $headers, true)) {
            if ($requestedHeaders !== '') {
                $response->headers->set('Access-Control-Allow-Headers', $requestedHeaders);
            }
        } elseif ($headers !== []) {
            $response->headers->set('Access-Control-Allow-Headers', implode(', ', $headers));
        }

        if ((int) $options['max_age'] > 0) {
            $response->headers->set('Access-Control-Max-Age', (string) (int) $options['max_age']);
        }

        return $response;
    }

    public function apply(Request $request, Response $response, array $options): Response
    {
        if ($response->headers->has('Access-Control-Allow-Origin')) {
            return $response;
        }

        $this->vary($response, ['Origin']);
        $origin = (string) $request->headers->get('Origin', '');

        if ($origin === '' || !$this->isAllowedOrigin($origin, $options)) {
            return $response;
        }

        $this->allowOrigin($response, $origin, $options);

        if ((array) $options['expose_headers'] !== []) {
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', (array) $options['expose_headers']));
        }

        return $response;
    }

    protected function allowOrigin(Response $response, string $origin, array $options): void
    {
        $wildcard = in_array('*', (array) $options['allow_origin'], true) && !$options['allow_credentials'];
        $response->headers->set('Access-Control-Allow-Origin', $wildcard ? '*' : $origin);

        if ($options['allow_credentials']) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
    }

    protected function vary(Response $response, array $names): void
    {
        $values = [];

        foreach ($response->headers->getValues('Vary') as $value) {
            foreach (explode(',', (string) $value) as $item) {
                $item = trim($item);

                if ($item !== '') {
                    $values[strtolower($item)] = $item;
                }
            }
        }

        foreach ($names as $name) {
            $values[strtolower($name)] ??= $name;
        }

        $response->headers->set('Vary', implode(', ', $values));
    }

    protected function normalize(array $options): array
    {
        foreach (['allow_origin', 'allow_methods', 'allow_headers', 'expose_headers'] as $key) {
            $options[$key] = array_values(array_map('strval', is_array($options[$key] ?? null) ? $options[$key] : (($options[$key] ?? null) === null ? [] : [$options[$key]])));
        }

        foreach ($options['allow_origin'] as $origin) {
            if ($this->isRegex($origin) && @preg_match($origin, '') === false) {
                throw new InvalidConfigurationException('The CORS origin pattern "{pattern}" of config/framework/api.yaml is not a valid regular expression.', 0, null, ['pattern' => $origin]);
            }
        }

        $options['allow_credentials'] = (bool) ($options['allow_credentials'] ?? false);
        $options['max_age'] = (int) ($options['max_age'] ?? 0);

        if ($options['allow_credentials'] && in_array('*', $options['allow_origin'], true)) {
            throw new InvalidConfigurationException('The CORS option "allow_credentials: true" cannot be combined with "allow_origin: \'*\'" (config/framework/api.yaml or #[Cors]): it would let any website read the responses with the cookies of the user. List the allowed origins instead.');
        }

        return $options;
    }

    protected function isRegex(string $value): bool
    {
        return strlen($value) > 2 && ($value[0] === '#' || $value[0] === '/' || $value[0] === '~');
    }

    protected function pattern(string $path): string
    {
        $pattern = str_starts_with($path, '^') ? '#' . str_replace('#', '\#', $path) . '#' : '#^' . preg_quote($path, '#') . '#';

        if (@preg_match($pattern, '') === false) {
            throw new InvalidConfigurationException('The CORS path "{path}" of config/framework/api.yaml is not a valid regular expression.', 0, null, ['path' => $path]);
        }

        return $pattern;
    }
}