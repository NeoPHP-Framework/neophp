<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Controller;

use NeoPHP\Component\Api\OpenApi\OpenApiGenerator;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\Response;

class OpenApiController
{
    public const TEMPLATE = __DIR__ . '/../Resources/views/openapi.html';

    public function __construct(protected OpenApiGenerator $generator)
    {
    }

    public function json(): JsonResponse
    {
        return new JsonResponse($this->generator->generate(), 200, [], JsonResponse::DEFAULT_FLAGS | JSON_PRETTY_PRINT);
    }

    public function html(): Response
    {
        $document = $this->generator->generate();
        $path = rtrim((string) ($this->generator->getConfig()['route']['path'] ?? '/api/doc'), '/');
        $html = strtr((string) file_get_contents(self::TEMPLATE), [
            '{{ title }}' => htmlspecialchars((string) $document['info']['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{ json_url }}' => htmlspecialchars($path . '.json', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{ spec }}' => (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}