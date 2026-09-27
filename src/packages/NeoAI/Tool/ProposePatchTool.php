<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Tool;

use NeoPHP\Package\NeoAI\Contract\ToolInterface;
use NeoPHP\Package\NeoAI\Patch\PatchParser;

class ProposePatchTool implements ToolInterface
{
    public function __construct(protected PatchParser $parser = new PatchParser())
    {
    }

    public function getName(): string
    {
        return 'propose_patch';
    }

    public function getUsage(): string
    {
        return 'propose_patch {"diff": "--- a/src/File.php\\n+++ b/src/File.php\\n@@ -10,3 +10,3 @@\\n ...", "description": "why"} : records a unified diff proposal (never applied automatically, the developer reviews it).';
    }

    public function execute(array $arguments, ToolRunner $runner): string
    {
        $patch = $this->parser->toPatch((string) ($arguments['diff'] ?? ''), (string) ($arguments['description'] ?? ''), $runner);
        $runner->addPatch($patch);

        return sprintf('Patch #%d recorded for: %s. It will be shown to the developer; do not repeat the diff in your answer.', count($runner->getPatches()), implode(', ', $patch->getFiles()));
    }
}