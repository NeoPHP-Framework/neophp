<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Tool;

use Closure;
use NeoPHP\Package\NeoAI\Contract\ToolInterface;

class ProfileTool implements ToolInterface
{
    public function __construct(protected Closure $loader)
    {
    }

    public function getName(): string
    {
        return 'profile';
    }

    public function getUsage(): string
    {
        return 'profile {"token": "a1b2c3", "panel": "db"} : reads a WebProfiler profile (request, exception, queries, logs...); "panel" optional to read one collector only.';
    }

    public function execute(array $arguments, ToolRunner $runner): string
    {
        $token = (string) ($arguments['token'] ?? '');

        if (preg_match('/^[a-zA-Z0-9]{1,64}$/', $token) !== 1) {
            return 'ERROR: a valid profile "token" is required.';
        }

        $profile = ($this->loader)($token);

        if (!is_array($profile)) {
            return sprintf('ERROR: profile "%s" not found (WebProfiler disabled or profile expired).', $token);
        }

        $panel = (string) ($arguments['panel'] ?? '');

        if ($panel !== '') {
            $data = $profile['data'][$panel] ?? null;

            if ($data === null) {
                return sprintf('ERROR: no "%s" data in the profile. Available: %s.', $panel, implode(', ', array_keys((array) ($profile['data'] ?? []))));
            }

            $profile = ['summary' => $profile['summary'] ?? [], 'data' => [$panel => $data]];
        }

        return (string) json_encode($runner->getRedactor()->redactArray($profile), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}