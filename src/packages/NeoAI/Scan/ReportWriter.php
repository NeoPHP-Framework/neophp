<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Scan;

use NeoPHP\Package\NeoAI\Exception\NeoAiException;
use NeoPHP\Package\NeoAI\Model\Finding;

class ReportWriter
{
    public function markdown(array $result, string $project = 'project'): string
    {
        $counts = array_fill_keys(array_keys(Finding::SEVERITIES), 0);

        foreach ($result['findings'] as $finding) {
            $counts[$finding->getSeverity()]++;
        }

        $lines = [
            '# NeoAI scan report - ' . $project,
            '',
            '- Date: ' . date('Y-m-d H:i:s'),
            sprintf('- Model: %s (%s, connection "%s")', $result['model'], $result['provider'], $result['connection']),
            '- Focus: ' . $result['focus'],
            sprintf('- Files: %d in %d batch(es)%s', count($result['files']), $result['batches'], $result['truncated'] ? ' (limited by max_files)' : ''),
            sprintf('- Tokens: %d (prompt %d, completion %d)', $result['usage']['total'], $result['usage']['prompt'], $result['usage']['completion']),
            '- Duration: ' . $result['duration'] . ' s',
            '',
            '## Summary',
            '',
            '| Severity | Count |',
            '|---|---|',
        ];

        foreach ($counts as $severity => $count) {
            $lines[] = sprintf('| %s | %d |', $severity, $count);
        }

        $lines[] = '';
        $lines[] = '## Findings';
        $lines[] = '';

        if ($result['findings'] === []) {
            $lines[] = 'No finding.';
            $lines[] = '';
        }

        foreach ($result['findings'] as $index => $finding) {
            $lines[] = sprintf('### %d. [%s] %s', $index + 1, strtoupper($finding->getSeverity()), $this->inline($finding->getTitle()));
            $lines[] = '';
            $lines[] = sprintf('- Location: `%s`', $finding->getLocation());
            $lines[] = '- Category: ' . $this->inline($finding->getCategory());
            $lines[] = '';

            if ($finding->getExplanation() !== '') {
                $lines[] = $finding->getExplanation();
                $lines[] = '';
            }

            if ($finding->getFix() !== '') {
                $lines[] = '**Suggested fix:** ' . $finding->getFix();
                $lines[] = '';
            }

            if ($finding->getDiff() !== '') {
                $lines[] = '```diff';
                $lines[] = rtrim(str_replace('```', '` ` `', $finding->getDiff()));
                $lines[] = '```';
                $lines[] = '';
            }
        }

        if ($result['errors'] !== [] || $result['skipped'] !== []) {
            $lines[] = '## Warnings';
            $lines[] = '';

            foreach ($result['errors'] as $error) {
                $lines[] = '- ' . $this->inline((string) $error);
            }

            foreach ($result['skipped'] as $path => $reason) {
                $lines[] = sprintf('- Skipped `%s`: %s', $path, $this->inline((string) $reason));
            }

            $lines[] = '';
        }

        $lines[] = '## Scanned files';
        $lines[] = '';

        foreach ($result['files'] as $file) {
            $lines[] = '- `' . $file . '`';
        }

        return implode("\n", $lines) . "\n";
    }

    public function write(string $file, string $markdown): string
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new NeoAiException('The directory "{directory}" cannot be created.', 0, null, ['directory' => $directory]);
        }

        if (@file_put_contents($file, $markdown, LOCK_EX) === false) {
            throw new NeoAiException('The report "{file}" cannot be written.', 0, null, ['file' => $file]);
        }

        return $file;
    }

    protected function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}