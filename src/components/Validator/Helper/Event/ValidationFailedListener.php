<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Helper\Event;

use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Kernel\Event\ExceptionEvent;
use NeoPHP\Component\Validator\Exception\ValidationFailedException;

/**
 * @internal
 */
#[AsListener]
class ValidationFailedListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        if (!$exception instanceof ValidationFailedException || (!$request->wantsJson() && !$request->isJson())) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => [
                'status' => 422,
                'message' => $exception->getMessage(),
                'violations' => $exception->getViolations()->toArray(),
            ],
        ], 422));
    }
}