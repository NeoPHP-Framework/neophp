<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Controller;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\QrCode\Model\QrCode;
use NeoPHP\Package\QrCode\Model\ReadResult;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;
use Throwable;

/**
 * @internal
 */
class QrCodeRouteController
{
    public function __construct(protected QrCodeManagerInterface $qrCodes)
    {
    }

    public function image(string $key, string $format): Response
    {
        $qr = $this->qrCodes->fromImageKey($key);

        if ($qr === null) {
            return new Response('QR code not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response($qr->render($format), 200, [
            'Content-Type' => QrCode::MIME_TYPES[QrCode::format($format)],
            'Cache-Control' => 'public, max-age=' . max(0, (int) $this->qrCodes->getConfig()['image_ttl']) . ', immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function script(): Response
    {
        $file = $this->qrCodes->getReaderScript();

        return new Response((string) file_get_contents($file), 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => '"' . md5_file($file) . '"',
        ]);
    }

    /**
     * Reads {"content": "..."} (or a content form field) and returns the ReadResult in JSON: 200 when valid, 422 otherwise.
     */
    public function read(Request $request): JsonResponse
    {
        try {
            $payload = str_contains((string) $request->getContentType(), 'json') ? $request->toArray() : [];
        } catch (Throwable) {
            $payload = [];
        }

        $source = (string) ($payload['source'] ?? $request->get('source', ''));
        $result = $this->qrCodes->read((string) ($payload['content'] ?? $request->get('content', '')), in_array($source, [ReadResult::SOURCE_QR, ReadResult::SOURCE_CODE], true) ? $source : null);

        return new JsonResponse($result->toArray(), $result->isValid() ? 200 : 422, ['Cache-Control' => 'no-store']);
    }
}