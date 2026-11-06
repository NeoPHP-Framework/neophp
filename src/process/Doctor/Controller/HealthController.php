<?php

declare(strict_types=1);

namespace NeoPHP\Process\Doctor\Controller;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Process\Doctor\DoctorManagerInterface;
use NeoPHP\Process\Doctor\Helper\Event\HealthListener;
use Throwable;

/**
 * @internal
 */
class HealthController
{
    public function __construct(protected DoctorManagerInterface $doctor, protected ConfigManagerInterface $config)
    {
    }

    public function check(): JsonResponse
    {
        $options = (array) $this->config->get(HealthListener::CONFIG_KEY, []);

        try {
            $healthy = !$this->doctor->run(array_values(array_map('strval', (array) ($options['checks'] ?? []))))->hasErrors();
        } catch (Throwable) {
            $healthy = false;
        }

        return new JsonResponse(['status' => $healthy ? 'ok' : 'error'], $healthy ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}