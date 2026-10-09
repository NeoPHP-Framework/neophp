<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Middleware;

use Closure;
use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Middleware\Contract\MiddlewareInterface;
use NeoPHP\Component\Middleware\Contract\RequestHandlerInterface;
use NeoPHP\Package\Security\Attribute\IsGranted;
use NeoPHP\Package\Security\Exception\AccessDeniedException;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\SecurityManagerInterface;
use ReflectionAttribute;
use ReflectionClass;

class IsGrantedMiddleware implements MiddlewareInterface
{
    public function __construct(protected SecurityManagerInterface $security)
    {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $attributes = $this->attributes($request->attributes->get('_controller'));

        foreach ($attributes as $attribute) {
            $subject = $this->subject($attribute->subject, $request);

            if ($this->security->isGranted($attribute->attribute, $subject)) {
                continue;
            }

            $message = $attribute->message ?? 'Access Denied.';

            if ($attribute->statusCode !== null) {
                throw new HttpException($attribute->statusCode, $message);
            }

            throw new AccessDeniedException($message, (array) $attribute->attribute, $subject);
        }

        return $handler->handle($request);
    }

    protected function attributes(mixed $controller): array
    {
        [$class, $method] = match (true) {
            is_string($controller) && str_contains($controller, '::') => explode('::', $controller, 2),
            is_array($controller) && count($controller) === 2 => [is_object($controller[0]) ? $controller[0]::class : (string) $controller[0], (string) $controller[1]],
            is_string($controller) => [$controller, '__invoke'],
            is_object($controller) && !$controller instanceof Closure => [$controller::class, '__invoke'],
            default => [null, null],
        };

        if ($class === null || !class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);
        $attributes = array_map(static fn (ReflectionAttribute $attribute): IsGranted => $attribute->newInstance(), $reflection->getAttributes(IsGranted::class, ReflectionAttribute::IS_INSTANCEOF));

        if ($method !== null && $reflection->hasMethod($method)) {
            foreach ($reflection->getMethod($method)->getAttributes(IsGranted::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $attributes[] = $attribute->newInstance();
            }
        }

        return $attributes;
    }

    protected function subject(string|array|null $subject, Request $request): mixed
    {
        if ($subject === null) {
            return null;
        }

        if (is_array($subject)) {
            $subjects = [];

            foreach ($subject as $key => $name) {
                $subjects[is_string($key) ? $key : (string) $name] = $this->resolve((string) $name, $request);
            }

            return $subjects;
        }

        return $this->resolve($subject, $request);
    }

    protected function resolve(string $name, Request $request): mixed
    {
        if (!$request->attributes->has($name)) {
            throw new SecurityException('The subject "{subject}" of #[IsGranted] is not a route parameter nor a request attribute.', 0, null, ['subject' => $name]);
        }

        return $request->attributes->get($name);
    }
}