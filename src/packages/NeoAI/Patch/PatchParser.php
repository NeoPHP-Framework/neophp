<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Patch;

use NeoPHP\Package\NeoAI\Exception\PatchException;
use NeoPHP\Package\NeoAI\Model\Patch;
use NeoPHP\Package\NeoAI\Tool\ToolRunner;

class PatchParser
{
    public const NEW_FILE = '__new__';

    public const MAX_DIFF_BYTES = 200000;

    public function parse(string $diff): array
    {
        $diff = str_replace("\r\n", "\n", trim($diff, "\n"));

        if ($diff === '' || strlen($diff) > self::MAX_DIFF_BYTES) {
            throw new PatchException('The patch is empty or too large.');
        }

        $lines = explode("\n", $diff);
        $files = [];
        $current = null;
        $hunk = null;
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];

            if (str_starts_with($line, '--- ') && isset($lines[$index + 1]) && str_starts_with($lines[$index + 1], '+++ ')) {
                if ($current !== null) {
                    $files[] = $this->close($current, $hunk);
                }

                $current = ['old' => $this->path(substr($line, 4)), 'new' => $this->path(substr($lines[$index + 1], 4)), 'hunks' => []];
                $hunk = null;
                $index++;
                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $matches) === 1) {
                if ($hunk !== null) {
                    $current['hunks'][] = $hunk;
                }

                $hunk = ['old_start' => (int) $matches[1], 'new_start' => (int) $matches[3], 'lines' => []];
                continue;
            }

            if ($hunk === null || str_starts_with($line, '\\')) {
                continue;
            }

            $type = $line === '' ? ' ' : $line[0];

            if (!in_array($type, [' ', '-', '+'], true)) {
                if (str_starts_with($line, 'diff ') || str_starts_with($line, 'index ')) {
                    continue;
                }

                throw new PatchException('Invalid line in the patch hunk: "{line}".', 0, null, ['line' => mb_substr($line, 0, 80)]);
            }

            $hunk['lines'][] = [$type, (string) substr($line, 1)];
        }

        if ($current !== null) {
            $files[] = $this->close($current, $hunk);
        }

        if ($files === []) {
            throw new PatchException('No file found in the patch: a unified diff with "--- a/path" and "+++ b/path" headers is expected.');
        }

        return $files;
    }

    public function toPatch(string $diff, string $description, ToolRunner $runner): Patch
    {
        $sandbox = $runner->getSandbox();
        $files = [];
        $hashes = [];

        foreach ($this->parse($diff) as $file) {
            $path = $sandbox->normalize((string) ($file['new'] ?? $file['old']));
            $absolute = $sandbox->resolve($path, $file['old'] !== null);

            if ($file['old'] === null) {
                if (is_file($absolute)) {
                    throw new PatchException('The patch creates "{path}" but the file already exists.', 0, null, ['path' => $path]);
                }

                $hashes[$path] = self::NEW_FILE;
            } else {
                $content = (string) file_get_contents($absolute);
                $this->apply($content, $file);
                $hashes[$path] = $runner->getHash($path) ?? sha1($content);
            }

            $files[] = $path;
        }

        return new Patch(trim($diff, "\n") . "\n", $files, trim($description), $hashes);
    }

    public function apply(string $content, array $file): string
    {
        if ($file['new'] === null) {
            return '';
        }

        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = $content === '' ? [] : explode("\n", str_replace("\r\n", "\n", $content));
        $trailing = $lines !== [] && end($lines) === '';

        if ($trailing) {
            array_pop($lines);
        }

        $offset = 0;

        foreach ($file['hunks'] as $number => $hunk) {
            $old = [];
            $new = [];

            foreach ($hunk['lines'] as [$type, $text]) {
                if ($type !== '+') {
                    $old[] = $text;
                }

                if ($type !== '-') {
                    $new[] = $text;
                }
            }

            $expected = max(0, $hunk['old_start'] - 1 + $offset);

            if ($old === []) {
                $position = $file['old'] === null ? count($lines) : min(count($lines), max(0, $hunk['old_start'] + $offset));
            } else {
                $position = $this->locate($lines, $old, $expected);

                if ($position === null) {
                    throw new PatchException('Hunk #{hunk} of "{path}" does not match the current file content (context lines differ).', 0, null, ['hunk' => $number + 1, 'path' => (string) ($file['new'] ?? $file['old'])]);
                }
            }

            array_splice($lines, $position, count($old), $new);
            $offset += count($new) - count($old);
        }

        return implode($eol, $lines) . ($trailing || $file['old'] === null ? $eol : '');
    }

    protected function locate(array $lines, array $block, int $expected): ?int
    {
        $max = count($lines) - count($block);

        if ($max < 0) {
            return null;
        }

        for ($distance = 0; $distance <= max($expected, $max - $expected); $distance++) {
            foreach ([$expected - $distance, $expected + $distance] as $candidate) {
                if ($candidate >= 0 && $candidate <= $max && $this->matches($lines, $block, $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    protected function matches(array $lines, array $block, int $position): bool
    {
        foreach ($block as $index => $text) {
            if (rtrim($lines[$position + $index]) !== rtrim($text)) {
                return false;
            }
        }

        return true;
    }

    protected function close(array $file, ?array $hunk): array
    {
        if ($hunk !== null) {
            $file['hunks'][] = $hunk;
        }

        if ($file['old'] === null && $file['new'] === null) {
            throw new PatchException('A patch entry has neither a source nor a target file.');
        }

        if ($file['hunks'] === [] && $file['new'] !== null) {
            throw new PatchException('The patch of "{path}" has no hunk.', 0, null, ['path' => (string) $file['new']]);
        }

        return $file;
    }

    protected function path(string $header): ?string
    {
        $path = trim((string) preg_replace('/\t.*$/', '', $header));

        if ($path === '/dev/null' || $path === '') {
            return null;
        }

        if (str_starts_with($path, '"') && str_ends_with($path, '"')) {
            $path = substr($path, 1, -1);
        }

        return (string) preg_replace('#^[ab]/#', '', $path);
    }
}