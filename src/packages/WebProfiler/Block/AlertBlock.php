<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

use NeoPHP\Package\WebProfiler\Model\Status;

class AlertBlock extends AbstractBlock
{
    public const TYPE = 'alert';

    protected string $status;

    public function __construct(protected string $message, string $status = Status::INFO, ?string $title = null)
    {
        $this->title = $title;
        $this->status = Status::normalize($status);
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}