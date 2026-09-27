<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Tool;

use JsonException;
use NeoPHP\Package\NeoAI\Contract\ToolInterface;
use NeoPHP\Package\NeoAI\Exception\NeoAiException;
use NeoPHP\Package\NeoAI\Model\Patch;
use NeoPHP\Package\NeoAI\Security\Redactor;
use NeoPHP\Package\NeoAI\Security\Sandbox;
use Throwable;

class ToolRunner
{
    public const BLOCK = '/```neo-tool[ \t]*\r?\n(.*?)\r?\n?```/s';

    protected array $tools = [];

    protected array $patches = [];

    protected array $hashes = [];

    protected array $history = [];

    public function __construct(protected Sandbox $sandbox, protected Redactor $redactor, iterable $tools = [], protected int $maxOutput = 12000)
    {
        foreach ($tools as $tool) {
            $this->add($tool);
        }
    }

    public function add(ToolInterface $tool): static
    {
        $this->tools[$tool->getName()] = $tool;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function getSandbox(): Sandbox
    {
        return $this->sandbox;
    }

    public function getRedactor(): Redactor
    {
        return $this->redactor;
    }

    public function describe(): string
    {
        $lines = [];

        foreach ($this->tools as $tool) {
            $lines[] = '- ' . $tool->getUsage();
        }

        return implode("\n", $lines);
    }

    public function parse(string $content): array
    {
        if (preg_match_all(self::BLOCK, $content, $matches) === false) {
            return [];
        }

        $calls = [];

        foreach ($matches[1] as $json) {
            try {
                $data = json_decode(trim($json), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $calls[] = ['tool' => '_invalid', 'arguments' => [], 'error' => 'Invalid JSON in neo-tool block: ' . $exception->getMessage()];
                continue;
            }

            if (!is_array($data) || !is_string($data['tool'] ?? null)) {
                $calls[] = ['tool' => '_invalid', 'arguments' => [], 'error' => 'A neo-tool block must be a JSON object with a "tool" key.'];
                continue;
            }

            $arguments = $data;
            unset($arguments['tool']);

            if (isset($data['args']) && is_array($data['args'])) {
                $arguments = $data['args'];
            }

            $calls[] = ['tool' => $data['tool'], 'arguments' => $arguments];
        }

        return $calls;
    }

    public function strip(string $content): string
    {
        return trim((string) preg_replace(self::BLOCK, '', $content));
    }

    public function run(array $call): string
    {
        $name = (string) ($call['tool'] ?? '');
        $arguments = json_decode($this->redactor->redact((string) json_encode((array) ($call['arguments'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)), true);
        $this->history[] = ['tool' => $name, 'arguments' => is_array($arguments) ? $arguments : []];

        if (isset($call['error'])) {
            return 'ERROR: ' . $call['error'];
        }

        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            return sprintf('ERROR: unknown tool "%s". Available tools: %s.', $name, implode(', ', array_keys($this->tools)));
        }

        try {
            $output = $tool->execute((array) ($call['arguments'] ?? []), $this);
        } catch (NeoAiException $exception) {
            $output = 'ERROR: ' . $exception->getMessage();
        } catch (Throwable $exception) {
            $output = 'ERROR: ' . $exception::class . ': ' . $exception->getMessage();
        }

        return $this->truncate($this->redactor->redact($output));
    }

    public function truncate(string $output, ?int $limit = null): string
    {
        $limit ??= $this->maxOutput;

        if (strlen($output) <= $limit) {
            return $output;
        }

        return mb_strcut($output, 0, $limit) . "\n[... output truncated to " . $limit . ' bytes]';
    }

    public function remember(string $path, string $hash): static
    {
        $this->hashes[$path] ??= $hash;

        return $this;
    }

    public function getHash(string $path): ?string
    {
        return $this->hashes[$path] ?? null;
    }

    public function getHashes(): array
    {
        return $this->hashes;
    }

    public function addPatch(Patch $patch): static
    {
        $this->patches[] = $patch;

        return $this;
    }

    public function getPatches(): array
    {
        return $this->patches;
    }

    public function getHistory(): array
    {
        return $this->history;
    }

    public function reset(): static
    {
        $this->patches = [];
        $this->history = [];

        return $this;
    }
}