<?php

declare(strict_types=1);

namespace NeoPHP\Component\Csrf\Middleware;

use Closure;
use NeoPHP\Component\Csrf\Attribute\Csrf;
use NeoPHP\Component\Csrf\CsrfManagerInterface;
use NeoPHP\Component\Csrf\Exception\InvalidCsrfTokenException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Middleware\Contract\MiddlewareInterface;
use NeoPHP\Component\Middleware\Contract\RequestHandlerInterface;
use ReflectionAttribute;
use ReflectionClass;

class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(protected CsrfManagerInterface $csrf)
    {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $attribute = $this->findAttribute($request->attributes->get('_controller'));

        if ($attribute === null || !in_array($request->getMethod(), array_map('strtoupper', $attribute->methods), true)) {
            return $handler->handle($request);
        }

        $id = $this->resolveId($attribute->id, $request);
        $field = $attribute->field ?? $this->csrf->getFieldName();
        $token = $request->headers->get($attribute->header ?? $this->csrf->getHeaderName()) ?? $request->request->get($field) ?? $request->query->get($field);

        if (!$this->csrf->isTokenValid($id, is_string($token) ? $token : null)) {
            throw new InvalidCsrfTokenException('Invalid CSRF token.');
        }

        return $handler->handle($request);
    }

    protected function findAttribute(mixed $controller): ?Csrf
    {
        [$class, $method] = match (true) {
            is_string($controller) && str_contains($controller, '::') => explode('::', $controller, 2),
            is_array($controller) && count($controller) === 2 => [is_object($controller[0]) ? $controller[0]::class : (string) $controller[0], (string) $controller[1]],
            is_string($controller) => [$controller, '__invoke'],
            is_object($controller) && !$controller instanceof Closure => [$controller::class, '__invoke'],
            default => [null, null],
        };

        if ($class === null || !class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($method !== null && $reflection->hasMethod($method)) {
            $attributes = $reflection->getMethod($method)->getAttributes(Csrf::class, ReflectionAttribute::IS_INSTANCEOF);

            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }

        $attributes = $reflection->getAttributes(Csrf::class, ReflectionAttribute::IS_INSTANCEOF);

        return $attributes !== [] ? $attributes[0]->newInstance() : null;
    }

    protected function resolveId(string $id, Request $request): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($request): string {
            $value = $request->attributes->get($m[1]);

            return is_scalar($value) ? (string) $value : $m[0];
        }, $id);
    }
}