<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Exception;

use NeoPHP\Package\Queue\Contract\UnrecoverableExceptionInterface;

class NoHandlerException extends QueueException implements UnrecoverableExceptionInterface
{
}