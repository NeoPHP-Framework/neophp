<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\IO;

use NeoPHP\Process\Console\Contract\OutputInterface;

class ProgressBar
{
    public const WIDTH = 28;

    protected int $current = 0;

    protected float $lastRender = 0.0;

    protected float $startedAt;

    protected bool $finished = false;

    public function __construct(protected OutputInterface $output, protected int $max = 0)
    {
        $this->max = max(0, $max);
        $this->startedAt = microtime(true);
    }

    public function getCurrent(): int
    {
        return $this->current;
    }

    public function getMax(): int
    {
        return $this->max;
    }

    public function start(): void
    {
        $this->render(true);
    }

    public function advance(int $step = 1): void
    {
        $this->current = $this->max > 0 ? min($this->max, $this->current + $step) : $this->current + $step;
        $this->render(false);
    }

    public function setProgress(int $current): void
    {
        $this->current = max(0, $current);
        $this->render(false);
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        if ($this->max > 0) {
            $this->current = $this->max;
        }

        $this->finished = true;

        if ($this->output->isQuiet()) {
            return;
        }

        if ($this->output->isDecorated()) {
            $this->output->write("\r\033[2K" . $this->line());
            $this->output->newLine();

            return;
        }

        $this->output->writeln($this->line());
    }

    public function line(): string
    {
        $elapsed = (int) round(microtime(true) - $this->startedAt);
        $time = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);

        if ($this->max === 0) {
            $position = $this->current % self::WIDTH;
            $bar = str_repeat('-', $position) . '<info>=</info>' . str_repeat('-', self::WIDTH - $position - 1);

            return sprintf(' %d [%s] %s', $this->current, $bar, $time);
        }

        $ratio = $this->current / $this->max;
        $done = (int) floor($ratio * self::WIDTH);
        $bar = '<info>' . str_repeat('=', $done) . ($done < self::WIDTH ? '>' : '') . '</info>' . str_repeat('-', max(0, self::WIDTH - $done - 1));
        $digits = strlen((string) $this->max);

        return sprintf(' %' . $digits . 'd/%d [%s] %3d%% %s', $this->current, $this->max, $bar, (int) floor($ratio * 100), $time);
    }

    protected function render(bool $force): void
    {
        if ($this->finished || $this->output->isQuiet() || !$this->output->isDecorated()) {
            return;
        }

        $now = microtime(true);

        if (!$force && $now - $this->lastRender < 0.1 && ($this->max === 0 || $this->current < $this->max)) {
            return;
        }

        $this->lastRender = $now;
        $this->output->write("\r\033[2K" . $this->line());
    }
}