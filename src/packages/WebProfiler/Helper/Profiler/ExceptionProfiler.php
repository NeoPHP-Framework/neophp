<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Helper\Profiler;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\CodeBlock;
use NeoPHP\Package\WebProfiler\Block\KeyValueBlock;
use NeoPHP\Package\WebProfiler\Block\SectionBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use SplFileObject;
use Throwable;

class ExceptionProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface
{
    public const PRIORITY = 250;

    public const CONTEXT_LINES = 6;

    public const MAX_TRACE = 50;

    public const MAX_PREVIOUS = 5;

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        if ($exception === null) {
            return [];
        }

        $chain = [];

        for ($current = $exception, $depth = 0; $current !== null && $depth <= self::MAX_PREVIOUS; $current = $current->getPrevious(), $depth++) {
            $chain[] = $this->describe($current);
        }

        return ['exceptions' => $chain];
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        $exception = $data['exceptions'][0] ?? null;

        if (!is_array($exception)) {
            return null;
        }

        return new ToolbarItem('Exception', $this->shortName((string) $exception['class']), 'exception', Status::DANGER, [
            'Class' => $exception['class'],
            'Message' => $exception['message'],
            'Location' => $exception['file'] . ':' . $exception['line'],
        ]);
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        $exceptions = (array) ($data['exceptions'] ?? []);

        if ($exceptions === []) {
            return null;
        }

        $panel = new Panel('Exception', 'exception', [], count($exceptions), Status::DANGER);

        foreach ($exceptions as $index => $exception) {
            $blocks = [
                new AlertBlock((string) $exception['message'], Status::DANGER, (string) $exception['class']),
                new KeyValueBlock(['Class' => $exception['class'], 'Code' => $exception['code'], 'File' => $exception['file'], 'Line' => $exception['line']]),
            ];

            if (($exception['source'] ?? '') !== '') {
                $blocks[] = new CodeBlock((string) $exception['source'], 'Source', 'php', (int) $exception['source_start'], (int) $exception['line']);
            }

            $blocks[] = new TableBlock(['#', 'Call', 'File', 'Line'], array_map(static fn (array $frame): array => [
                $frame['index'],
                $frame['call'],
                $frame['file'],
                $frame['line'],
            ], (array) $exception['trace']), 'Stack trace', 'Empty stack trace.');

            $panel->add($index === 0 ? new SectionBlock('Exception', $blocks) : new SectionBlock('Previous: ' . $exception['class'], $blocks, true));
        }

        return $panel;
    }

    protected function describe(Throwable $exception): array
    {
        $trace = [];

        foreach (array_slice($exception->getTrace(), 0, self::MAX_TRACE) as $index => $frame) {
            $trace[] = [
                'index' => $index,
                'call' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()',
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
            ];
        }

        [$start, $source] = $this->source($exception->getFile(), $exception->getLine());

        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'source_start' => $start,
            'source' => $source,
            'trace' => $trace,
        ];
    }

    protected function source(string $file, int $line): array
    {
        if (!is_file($file) || !is_readable($file) || $line <= 0) {
            return [0, ''];
        }

        $start = max(1, $line - self::CONTEXT_LINES);
        $lines = [];
        $handle = new SplFileObject($file);
        $handle->seek($start - 1);

        while (!$handle->eof() && count($lines) <= self::CONTEXT_LINES * 2) {
            $lines[] = rtrim((string) $handle->current(), "\r\n");
            $handle->next();
        }

        return [$start, implode("\n", $lines)];
    }

    protected function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}