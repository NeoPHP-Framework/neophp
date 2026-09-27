<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Scan;

use Closure;
use NeoPHP\Package\NeoAI\Agent\PromptBuilder;
use NeoPHP\Package\NeoAI\Contract\ProviderInterface;
use NeoPHP\Package\NeoAI\Exception\AuthenticationException;
use NeoPHP\Package\NeoAI\Exception\ConfigurationException;
use NeoPHP\Package\NeoAI\Exception\NeoAiException;
use NeoPHP\Package\NeoAI\Model\Finding;
use NeoPHP\Package\NeoAI\Model\Message;
use NeoPHP\Package\NeoAI\Security\Redactor;
use NeoPHP\Package\NeoAI\Security\Sandbox;

class Scanner
{
    public const FOCUSES = ['all', 'security', 'performance', 'bugs', 'conventions'];

    public const DEFAULTS = [
        'paths' => ['src', 'templates', 'config'],
        'extensions' => ['php', 'twig', 'yaml', 'yml', 'js', 'html', 'phtml'],
        'max_files' => 200,
        'batch_chars' => 60000,
    ];

    protected array $options;

    public function __construct(
        protected ProviderInterface $provider,
        protected Sandbox $sandbox,
        protected Redactor $redactor,
        protected PromptBuilder $prompts,
        array $options = [],
    ) {
        $this->options = array_replace(self::DEFAULTS, array_filter($options, static fn (mixed $value): bool => $value !== null));
    }

    public function collect(array $paths = []): array
    {
        $paths = $paths !== [] ? $paths : (array) $this->options['paths'];
        $extensions = array_map('strtolower', (array) $this->options['extensions']);
        $max = max(1, (int) $this->options['max_files']);
        $files = [];
        $skipped = [];

        foreach ($paths as $path) {
            try {
                $absolute = $this->sandbox->resolve((string) $path);
            } catch (NeoAiException $exception) {
                $skipped[(string) $path] = $exception->getMessage();
                continue;
            }

            $candidates = is_dir($absolute) ? $this->sandbox->listFiles($this->sandbox->relative($absolute), '*', $max * 5) : [$this->sandbox->relative($absolute)];

            sort($candidates);

            foreach ($candidates as $candidate) {
                if (str_ends_with($candidate, '/') || !in_array(strtolower(pathinfo($candidate, PATHINFO_EXTENSION)), $extensions, true)) {
                    continue;
                }

                $files[$candidate] = true;
            }
        }

        $files = array_keys($files);

        return ['files' => array_slice($files, 0, $max), 'skipped' => $skipped, 'truncated' => count($files) > $max];
    }

    public function batches(array $files): array
    {
        $limit = max(2000, (int) $this->options['batch_chars']);
        $batches = [];
        $current = [];
        $size = 0;

        foreach ($files as $file) {
            try {
                $read = $this->sandbox->read($file);
            } catch (NeoAiException) {
                continue;
            }

            $content = sprintf("=== %s (%d lines%s) ===\n%s", $read['path'], $read['total'], $read['truncated'] ? ', truncated' : '', $this->redactor->redact($read['content']));

            if (strlen($content) > $limit) {
                $content = mb_strcut($content, 0, $limit) . "\n[... truncated]";
            }

            if ($current !== [] && $size + strlen($content) > $limit) {
                $batches[] = $current;
                $current = [];
                $size = 0;
            }

            $current[$read['path']] = $content;
            $size += strlen($content);
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    public function scan(array $paths = [], string $focus = 'all', ?Closure $progress = null): array
    {
        $focus = in_array($focus, self::FOCUSES, true) ? $focus : 'all';
        $collected = $this->collect($paths);
        $batches = $this->batches($collected['files']);
        $findings = [];
        $errors = [];
        $usage = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        $started = microtime(true);

        foreach ($batches as $index => $batch) {
            if ($progress !== null) {
                $progress($index + 1, count($batches), array_keys($batch));
            }

            try {
                $response = $this->provider->chat([
                    Message::system($this->prompts->audit($focus)),
                    Message::user("Audit these files:\n\n" . implode("\n\n", $batch)),
                ]);
            } catch (AuthenticationException|ConfigurationException $exception) {
                throw $exception;
            } catch (NeoAiException $exception) {
                $errors[] = sprintf('Batch %d: %s', $index + 1, $exception->getMessage());
                continue;
            }

            $usage['prompt'] += $response->getPromptTokens();
            $usage['completion'] += $response->getCompletionTokens();
            $usage['total'] += $response->getTotalTokens();
            $parsed = $this->parseFindings($response->getContent());

            if ($parsed === null) {
                $errors[] = sprintf('Batch %d: the model did not return a valid JSON array.', $index + 1);
                continue;
            }

            array_push($findings, ...$parsed);
        }

        usort($findings, static fn (Finding $a, Finding $b): int => [$b->getRank(), $a->getFile(), $a->getLine() ?? 0] <=> [$a->getRank(), $b->getFile(), $b->getLine() ?? 0]);

        return [
            'focus' => $focus,
            'files' => $collected['files'],
            'skipped' => $collected['skipped'],
            'truncated' => $collected['truncated'],
            'batches' => count($batches),
            'findings' => $findings,
            'errors' => $errors,
            'usage' => $usage,
            'duration' => round(microtime(true) - $started, 2),
            'provider' => $this->provider->getType(),
            'connection' => $this->provider->getName(),
            'model' => $this->provider->getModel(),
        ];
    }

    public function parseFindings(string $content): ?array
    {
        $json = null;

        if (preg_match('/```(?:json)?[ \t]*\r?\n(.*?)```/s', $content, $matches) === 1) {
            $json = trim($matches[1]);
        } else {
            $start = strpos($content, '[');
            $end = strrpos($content, ']');
            $json = $start !== false && $end !== false && $end > $start ? substr($content, $start, $end - $start + 1) : trim($content);
        }

        $data = json_decode($json, true);

        if (is_array($data) && isset($data['findings']) && is_array($data['findings'])) {
            $data = $data['findings'];
        }

        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }

        $findings = [];

        foreach ($data as $item) {
            $finding = is_array($item) ? Finding::fromArray($item) : null;

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}