<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\Http\Response\Response;

interface HttpManagerInterface
{
    public function createRequestFromGlobals(): Request;

    public function createResponse(string $content = '', int $status = 200, array $headers = []): Response;

    public function json(mixed $data = null, int $status = 200, array $headers = []): JsonResponse;

    public function redirect(string $url, int $status = 302, array $headers = []): RedirectResponse;

    public function send(Response $response, ?Request $request = null): Response;
}