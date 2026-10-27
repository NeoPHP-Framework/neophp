<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Helper\Controller;

use NeoPHP\Component\Http\Exception\AccessDeniedHttpException;
use NeoPHP\Component\Http\Exception\NotFoundHttpException;
use NeoPHP\Component\Http\HttpManagerInterface;
use NeoPHP\Component\Http\Response\BinaryFileResponse;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\Http\Response\StreamedResponse;
use NeoPHP\Component\Serializer\SerializerManagerInterface;
use SplFileInfo;

trait HttpController
{
    abstract protected function get(string $id): mixed;

    abstract protected function has(string $id): bool;

    protected function json(mixed $data, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        if (($context !== [] || $this->jsonContainsObjects($data)) && $this->has(SerializerManagerInterface::class)) {
            $data = $this->get(SerializerManagerInterface::class)->normalize($data, 'json', $context);
        }

        return $this->get(HttpManagerInterface::class)->json($data, $status, $headers);
    }

    protected function redirect(string $url, int $status = 302): RedirectResponse
    {
        return $this->get(HttpManagerInterface::class)->redirect($url, $status);
    }

    protected function streamResponse(callable $callback, int $status = 200, array $headers = []): StreamedResponse
    {
        return $this->get(HttpManagerInterface::class)->stream($callback, $status, $headers);
    }

    protected function fileResponse(string|SplFileInfo $file, ?string $filename = null, string $disposition = BinaryFileResponse::DISPOSITION_ATTACHMENT, array $headers = []): BinaryFileResponse
    {
        return $this->get(HttpManagerInterface::class)->file($file, $filename, $disposition, $headers);
    }

    protected function createNotFoundException(string $message = 'Not Found', array $context = []): NotFoundHttpException
    {
        return new NotFoundHttpException($message, $context);
    }

    protected function createAccessDeniedException(string $message = 'Forbidden', array $context = []): AccessDeniedHttpException
    {
        return new AccessDeniedHttpException($message, $context);
    }

    protected function jsonContainsObjects(mixed $data): bool
    {
        if (is_object($data)) {
            return true;
        }

        if (!is_array($data)) {
            return false;
        }

        foreach ($data as $value) {
            if ($this->jsonContainsObjects($value)) {
                return true;
            }
        }

        return false;
    }
}