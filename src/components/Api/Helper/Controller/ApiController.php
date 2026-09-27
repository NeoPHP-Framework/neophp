<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Helper\Controller;

use NeoPHP\Component\Api\Helper\Listener\RateLimitListener;
use NeoPHP\Component\Api\Pagination\Contract\PaginatorInterface;
use NeoPHP\Component\Api\Pagination\Page;
use NeoPHP\Component\Api\Pagination\PageRequest;
use NeoPHP\Component\Api\ProblemDetails\ProblemDetails;
use NeoPHP\Component\Api\ProblemDetails\ProblemDetailsFactory;
use NeoPHP\Component\Api\RateLimiter\Contract\LimiterInterface;
use NeoPHP\Component\Api\RateLimiter\RateLimit;
use NeoPHP\Component\Api\RateLimiter\RateLimiterFactory;
use NeoPHP\Component\Api\RateLimiter\RequestKeyResolver;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;

trait ApiController
{
    abstract protected function get(string $id): mixed;

    abstract protected function has(string $id): bool;

    abstract protected function json(mixed $data, int $status = 200, array $headers = [], array $context = []): JsonResponse;

    protected function paginate(mixed $target, PageRequest|Request|null $request = null): Page
    {
        return $this->get(PaginatorInterface::class)->paginate($target, $request);
    }

    protected function jsonPage(Page $page, array $context = [], int $status = 200, array $headers = []): JsonResponse
    {
        return $this->json($page, $status, [...$page->getHeaders(), ...$headers], $context);
    }

    protected function createRateLimiter(string $limiter, ?string $key = null): LimiterInterface
    {
        return $this->get(RateLimiterFactory::class)->create($limiter, $key ?? $this->rateLimitKey('ip'));
    }

    protected function rateLimit(string $limiter, ?string $key = null, int $tokens = 1): RateLimit
    {
        $limit = $this->createRateLimiter($limiter, $key)->consume($tokens)->ensureAccepted();

        if ($this->has(Request::class)) {
            $request = $this->get(Request::class);
            $current = $request->attributes->get(RateLimitListener::ATTRIBUTE);

            if (!$current instanceof RateLimit || $limit->getRemaining() < $current->getRemaining()) {
                $request->attributes->set(RateLimitListener::ATTRIBUTE, $limit);
            }
        }

        return $limit;
    }

    protected function problemJson(int $status, ?string $detail = null, array $extensions = [], ?string $title = null, ?string $type = null, array $headers = []): JsonResponse
    {
        $request = $this->has(Request::class) ? $this->get(Request::class) : null;
        $problem = new ProblemDetails($status, $title, $detail, $type ?? ($this->has(ProblemDetailsFactory::class) ? $this->get(ProblemDetailsFactory::class)->type($status) : 'about:blank'), $request?->getPath());

        foreach ($extensions as $name => $value) {
            $problem->setExtension((string) $name, $value);
        }

        return $problem->toResponse($headers);
    }

    protected function rateLimitKey(string $key): string
    {
        return $this->get(RequestKeyResolver::class)->resolve($key, $this->get(Request::class));
    }
}