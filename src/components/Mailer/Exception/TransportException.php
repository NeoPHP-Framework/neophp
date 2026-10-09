<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Exception;

use Throwable;

class TransportException extends MailerException
{
    protected string $debug = '';

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, array $context = [], string $debug = '')
    {
        parent::__construct($message, $code, $previous, $context);

        $this->debug = $debug;
    }

    public function getDebug(): string
    {
        return $this->debug;
    }
}