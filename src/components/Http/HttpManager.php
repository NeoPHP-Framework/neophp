<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http;

use NeoPHP\Component\Http\Provider\HttpProvider;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\BinaryFileResponse;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Http\Response\StreamedResponse;
use NeoPHP\Component\Kernel\Attribute\Component;
use SplFileInfo;

#[Component(provider: HttpProvider::class)]
final class HttpManager implements HttpManagerInterface
{
    public function createRequestFromGlobals(): Request
    {
        return Request::fromGlobals();
    }

    public function createResponse(string $content = '', int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }

    public function json(mixed $data = null, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    public function redirect(string $url, int $status = 302, array $headers = []): RedirectResponse
    {
        return new RedirectResponse($url, $status, $headers);
    }

    public function stream(callable $callback, int $status = 200, array $headers = []): StreamedResponse
    {
        return new StreamedResponse($callback, $status, $headers);
    }

    public function file(string|SplFileInfo $file, ?string $filename = null, string $disposition = BinaryFileResponse::DISPOSITION_ATTACHMENT, array $headers = []): BinaryFileResponse
    {
        return new BinaryFileResponse($file, 200, $headers, $disposition, $filename);
    }

    public function send(Response $response, ?Request $request = null): Response
    {
        if ($request !== null) {
            $response->prepare($request);
        }

        return $response->send();
    }
}