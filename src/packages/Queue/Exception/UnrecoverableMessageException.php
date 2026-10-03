<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Exception;

use NeoPHP\Package\Queue\Contract\UnrecoverableExceptionInterface;

class UnrecoverableMessageException extends QueueException implements UnrecoverableExceptionInterface
{
}