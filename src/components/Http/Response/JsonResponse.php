<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Response;

class JsonResponse extends Response
{
    public const DEFAULT_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    protected mixed $data = null;

    public function __construct(mixed $data = null, int $status = 200, array $headers = [], protected int $flags = self::DEFAULT_FLAGS)
    {
        parent::__construct('', $status, $headers);

        if (!$this->headers->has('Content-Type')) {
            $this->headers->set('Content-Type', 'application/json');
        }

        $this->setData($data ?? new \ArrayObject());
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function setData(mixed $data): static
    {
        $this->data = $data;

        return $this->setContent((string) json_encode($data, $this->flags | JSON_THROW_ON_ERROR));
    }
}