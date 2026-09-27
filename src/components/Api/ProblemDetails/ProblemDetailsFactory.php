<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\ProblemDetails;

use NeoPHP\Component\Exception\Contract\ExceptionInterface;
use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Validator\Exception\ValidationFailedException;
use Throwable;

class ProblemDetailsFactory
{
    public const DEFAULTS = [
        'enabled' => false,
        'paths' => ['^/api'],
        'json_requests' => true,
        'type_base_uri' => null,
    ];

    protected array $config;

    protected array $patterns = [];

    public function __construct(array $config = [], protected bool $debug = false)
    {
        $this->config = array_replace(self::DEFAULTS, $config);

        foreach ((array) $this->config['paths'] as $path) {
            $path = (string) $path;
            $this->patterns[] = str_starts_with($path, '^') ? '#' . str_replace('#', '\#', $path) . '#' : '#^' . preg_quote($path, '#') . '#';
        }
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config['enabled'];
    }

    public function supports(Request $request): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        foreach ($this->patterns as $pattern) {
            if (@preg_match($pattern, $request->getPath()) === 1) {
                return true;
            }
        }

        if (str_contains(strtolower((string) $request->headers->get('Accept', '')), ProblemDetails::CONTENT_TYPE)) {
            return true;
        }

        return (bool) $this->config['json_requests'] && ($request->wantsJson() || $request->isJson());
    }

    public function create(Throwable $exception, ?Request $request = null): ProblemDetails
    {
        $status = $exception instanceof ExceptionInterface ? $exception->getStatusCode() : 500;
        $status = $status >= 400 && $status <= 599 ? $status : 500;
        $problem = new ProblemDetails($status, null, $this->detail($exception, $status), $this->type($status), $request?->getPath());
        $problem->setHeaders($exception instanceof ExceptionInterface ? $exception->getHeaders() : []);

        if ($exception instanceof ValidationFailedException) {
            $problem->setExtension('violations', $this->violations($exception));
        }

        if ($this->debug) {
            $problem->setExtension('exception', [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => array_map(
                    static fn (array $frame): string => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '() ' . ($frame['file'] ?? '[internal]') . ':' . ($frame['line'] ?? '?'),
                    array_slice($exception->getTrace(), 0, 20),
                ),
            ]);
        }

        if ($exception instanceof ProblemDetailsProviderInterface) {
            $problem = $exception->toProblemDetails($problem);
        }

        return $problem;
    }

    public function createResponse(Throwable $exception, Request $request): JsonResponse
    {
        return $this->create($exception, $request)->toResponse();
    }

    public function type(int $status): string
    {
        $base = (string) ($this->config['type_base_uri'] ?? '');

        return $base === '' ? 'about:blank' : rtrim($base, '/') . '/' . $status;
    }

    protected function detail(Throwable $exception, int $status): ?string
    {
        if ($status < 500 || $this->debug || $exception instanceof HttpException) {
            $message = $exception->getMessage();

            return $message === '' ? null : $message;
        }

        return null;
    }

    protected function violations(ValidationFailedException $exception): array
    {
        $violations = [];

        foreach ($exception->getViolations() as $violation) {
            $item = [
                'propertyPath' => $violation->getPropertyPath(),
                'title' => $violation->getMessage(),
                'template' => $violation->getMessageTemplate(),
            ];
            $parameters = array_filter($violation->getParameters(), static fn (mixed $value): bool => is_scalar($value) || $value === null);

            if ($parameters !== []) {
                $item['parameters'] = $parameters;
            }

            $violations[] = $item;
        }

        return $violations;
    }
}