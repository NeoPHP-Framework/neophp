<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Model;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

class ReadResult implements JsonSerializable
{
    public const SOURCE_QR = 'qr';

    public const SOURCE_CODE = 'code';

    public const ERROR_EMPTY = 'empty';

    public const ERROR_UNKNOWN_CODE = 'unknown_code';

    public const ERROR_INVALID_SIGNATURE = 'invalid_signature';

    public const ERROR_EXPIRED = 'expired';

    public const ERROR_ALREADY_USED = 'already_used';

    public const MESSAGES = [
        self::ERROR_EMPTY => 'Nothing was scanned or typed.',
        self::ERROR_UNKNOWN_CODE => 'This code is unknown or has expired.',
        self::ERROR_INVALID_SIGNATURE => 'This QR code was not issued by this site or was modified.',
        self::ERROR_EXPIRED => 'This QR code has expired: refresh it and scan it again.',
        self::ERROR_ALREADY_USED => 'This QR code was already used.',
    ];

    private function __construct(
        private bool $valid,
        private string $source,
        private string $type,
        private string $content,
        private mixed $data,
        private ?string $code,
        private bool $signed,
        private ?DateTimeImmutable $expiresAt,
        private ?string $error,
    ) {
    }

    public static function success(string $source, string $type, string $content, mixed $data, ?string $code = null, bool $signed = false, ?DateTimeImmutable $expiresAt = null): self
    {
        return new self(true, $source, $type, $content, $data, $code, $signed, $expiresAt, null);
    }

    public static function failure(string $error, string $source, string $content = '', ?string $code = null, bool $signed = false, ?DateTimeImmutable $expiresAt = null): self
    {
        return new self(false, $source, $signed ? 'signed' : 'unknown', $content, null, $code, $signed, $expiresAt, $error);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * qr (scanned) or code (typed short code).
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * signed, url, email, phone, sms, wifi, vcard, geo, json or text.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Raw content of the QR code.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Decoded content: the data given to signed(), the decoded JSON, the fields of a Wi-Fi network or a vCard...
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Short code of the QR code (typed, or registered for the scanned content).
     */
    public function getCode(): ?string
    {
        return $this->code;
    }

    public function isSigned(): bool
    {
        return $this->signed;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * One of the ERROR_* constants, null when the result is valid.
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Message of the error in English, to translate with the error code.
     */
    public function getMessage(): ?string
    {
        return $this->error !== null ? self::MESSAGES[$this->error] ?? $this->error : null;
    }

    /**
     * @return array{valid: bool, source: string, type: string, signed: bool, data: mixed, content: string, code: string|null, expires_at: string|null, error: string|null, message: string|null}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'source' => $this->source,
            'type' => $this->type,
            'signed' => $this->signed,
            'data' => $this->data,
            'content' => $this->content,
            'code' => $this->code,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
            'error' => $this->error,
            'message' => $this->getMessage(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}