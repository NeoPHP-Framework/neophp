<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http;

use NeoPHP\Component\Http\Provider\HttpProvider;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Attribute\Component;

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

    public function send(Response $response, ?Request $request = null): Response
    {
        if ($request !== null) {
            $response->prepare($request);
        }

        return $response->send();
    }
}