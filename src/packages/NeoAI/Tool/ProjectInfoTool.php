<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Tool;

use Closure;
use NeoPHP\Package\NeoAI\Contract\ToolInterface;

class ProjectInfoTool implements ToolInterface
{
    public function __construct(protected Closure $collector)
    {
    }

    public function getName(): string
    {
        return 'project_info';
    }

    public function getUsage(): string
    {
        return 'project_info {} : framework version, PHP version, environment, installed features, routes and a configuration summary (secrets removed).';
    }

    public function execute(array $arguments, ToolRunner $runner): string
    {
        $info = $runner->getRedactor()->redactArray((array) ($this->collector)());

        return (string) json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}