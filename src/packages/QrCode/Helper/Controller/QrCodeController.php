<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Helper\Controller;

use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\QrCode\Model\QrCode;
use NeoPHP\Package\QrCode\Model\ReadResult;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;

trait QrCodeController
{
    abstract protected function get(string $id): mixed;

    protected function qrCode(string $content, array $options = []): QrCode
    {
        return $this->get(QrCodeManagerInterface::class)->create($content, $options);
    }

    protected function signedQrCode(mixed $data, ?int $rotate = null, ?int $ttl = null, bool $once = false, array $options = []): QrCode
    {
        return $this->get(QrCodeManagerInterface::class)->signed($data, $rotate, $ttl, $once, $options);
    }

    /**
     * @param string|null $source qr (scanned), code (typed) or null (guessed): the "source" sent by <neo-qr-reader>
     */
    protected function readQrCode(string $input, ?string $source = null): ReadResult
    {
        return $this->get(QrCodeManagerInterface::class)->read($input, $source);
    }

    /**
     * The image of a QR code as a response (svg or png).
     */
    protected function qrCodeResponse(QrCode|string $qrCode, string $format = 'svg'): Response
    {
        $qrCode = $qrCode instanceof QrCode ? $qrCode : $this->qrCode($qrCode);
        $format = QrCode::format($format);

        return new Response($qrCode->render($format), 200, ['Content-Type' => QrCode::MIME_TYPES[$format], 'Cache-Control' => 'no-store']);
    }

    /**
     * The result of a read as JSON: 200 when valid, 422 otherwise.
     */
    protected function qrCodeReadResponse(ReadResult|string $result): JsonResponse
    {
        $result = $result instanceof ReadResult ? $result : $this->readQrCode($result);

        return new JsonResponse($result->toArray(), $result->isValid() ? 200 : 422, ['Cache-Control' => 'no-store']);
    }
}