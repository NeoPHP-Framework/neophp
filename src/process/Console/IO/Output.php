<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\IO;

use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\ConsoleException;
use NeoPHP\Process\Console\Exception\InvalidInputException;
use Throwable;

class Output implements OutputInterface
{
    public const MAX_WIDTH = 120;

    public const TRUE_ANSWERS = ['y', 'yes', 'o', 'oui', 'true', '1'];

    public const FALSE_ANSWERS = ['n', 'no', 'non', 'false', '0'];

    protected mixed $stream;

    protected mixed $inputStream;

    protected bool $decorated;

    protected bool $interactive = true;

    protected int $verbosity = self::VERBOSITY_NORMAL;

    protected bool $broken = false;

    protected Formatter $formatter;

    protected ?ProgressBar $progress = null;

    protected int $newLines = 1;

    public function __construct(mixed $stream = null, ?bool $decorated = null, mixed $inputStream = null, ?Formatter $formatter = null)
    {
        $this->stream = $stream ?? (defined('STDOUT') ? STDOUT : fopen('php://output', 'w'));
        $this->inputStream = $inputStream ?? (defined('STDIN') ? STDIN : null);
        $this->decorated = $decorated ?? $this->detectColors();
        $this->formatter = $formatter ?? new Formatter();
    }

    public function getFormatter(): Formatter
    {
        return $this->formatter;
    }

    public function write(string $message, int $verbosity = self::VERBOSITY_NORMAL): void
    {
        if ($this->broken || !$this->shouldWrite($verbosity)) {
            return;
        }

        $formatted = $this->formatter->format($message, $this->decorated);

        if (@fwrite($this->stream, $formatted) === false) {
            $this->broken = true;

            return;
        }

        $trimmed = rtrim($formatted, "\r\n");
        $trailing = substr_count(substr($formatted, strlen($trimmed)), "\n");
        $this->newLines = $trimmed === '' ? $this->newLines + $trailing : $trailing;
    }

    public function writeln(string $message = '', int $verbosity = self::VERBOSITY_NORMAL): void
    {
        $this->write($message . PHP_EOL, $verbosity);
    }

    public function newLine(int $count = 1): void
    {
        $this->write(str_repeat(PHP_EOL, max(1, $count)));
    }

    public function getVerbosity(): int
    {
        return $this->verbosity;
    }

    public function setVerbosity(int $verbosity): static
    {
        $this->verbosity = $verbosity;

        return $this;
    }

    public function isQuiet(): bool
    {
        return $this->verbosity === self::VERBOSITY_QUIET;
    }

    public function isVerbose(): bool
    {
        return $this->verbosity >= self::VERBOSITY_VERBOSE;
    }

    public function isVeryVerbose(): bool
    {
        return $this->verbosity >= self::VERBOSITY_VERY_VERBOSE;
    }

    public function isDebug(): bool
    {
        return $this->verbosity >= self::VERBOSITY_DEBUG;
    }

    public function isDecorated(): bool
    {
        return $this->decorated;
    }

    public function setDecorated(bool $decorated): static
    {
        $this->decorated = $decorated;

        return $this;
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    public function setInteractive(bool $interactive): static
    {
        $this->interactive = $interactive;

        return $this;
    }

    public function title(string $title): void
    {
        $this->blankLine();
        $this->writeln('<title>' . $title . '</title>');
        $this->writeln('<title>' . str_repeat('=', Formatter::width($this->formatter->strip($title))) . '</title>');
        $this->blankLine();
    }

    public function section(string $title): void
    {
        $this->blankLine();
        $this->writeln('<comment>' . $title . '</comment>');
        $this->writeln('<comment>' . str_repeat('-', Formatter::width($this->formatter->strip($title))) . '</comment>');
        $this->blankLine();
    }

    public function text(string|array $messages): void
    {
        foreach ((array) $messages as $message) {
            $this->writeln(' ' . $message);
        }
    }

    public function comment(string|array $messages): void
    {
        foreach ((array) $messages as $message) {
            $this->writeln(' <muted>// ' . $message . '</muted>');
        }
    }

    public function listing(array $items): void
    {
        foreach ($items as $item) {
            $this->writeln(' * ' . str_replace("\n", "\n   ", (string) $item));
        }

        $this->blankLine();
    }

    public function table(array $headers, array $rows): void
    {
        $headers = array_values(array_map('strval', $headers));
        $widths = array_map(fn (string $header): int => Formatter::width($this->formatter->strip($header)), $headers);

        foreach ($rows as $row) {
            foreach (array_values((array) $row) as $index => $cell) {
                foreach (explode("\n", (string) $cell) as $line) {
                    $widths[$index] = max($widths[$index] ?? 0, Formatter::width($this->formatter->strip($line)));
                }
            }
        }

        $separator = '+' . implode('+', array_map(static fn (int $width): string => str_repeat('-', $width + 2), $widths)) . '+';
        $this->writeln($separator);

        if ($headers !== []) {
            $this->writeln($this->row($headers, $widths, true));
            $this->writeln($separator);
        }

        foreach ($rows as $row) {
            $cells = array_values(array_map('strval', (array) $row));
            $lines = array_map(static fn (string $cell): array => explode("\n", $cell), $cells);
            $height = max(1, ...array_map('count', $lines ?: [[]]));

            for ($line = 0; $line < $height; $line++) {
                $this->writeln($this->row(array_map(static fn (array $cell): string => $cell[$line] ?? '', $lines), $widths, false));
            }
        }

        $this->writeln($separator);
    }

    public function definitionList(array $definitions): void
    {
        $width = 0;

        foreach ($definitions as $label => $value) {
            $width = max($width, Formatter::width($this->formatter->strip((string) $label)));
        }

        foreach ($definitions as $label => $value) {
            $label = (string) $label;
            $this->writeln(' <info>' . $label . '</info>' . str_repeat(' ', $width - Formatter::width($this->formatter->strip($label))) . '  ' . $this->stringify($value));
        }

        $this->blankLine();
    }

    public function success(string|array $messages): void
    {
        $this->block($messages, 'OK', 'block-success');
    }

    public function error(string|array $messages): void
    {
        $this->block($messages, 'ERROR', 'block-error', self::VERBOSITY_QUIET);
    }

    public function warning(string|array $messages): void
    {
        $this->block($messages, 'WARNING', 'block-warning');
    }

    public function caution(string|array $messages): void
    {
        $this->block($messages, 'CAUTION', 'block-caution', self::VERBOSITY_QUIET);
    }

    public function info(string|array $messages): void
    {
        $this->block($messages, 'INFO', 'info', self::VERBOSITY_NORMAL, false);
    }

    public function note(string|array $messages): void
    {
        $this->block($messages, 'NOTE', 'comment', self::VERBOSITY_NORMAL, false);
    }

    public function ask(string $question, ?string $default = null, ?callable $validator = null): mixed
    {
        $label = $default !== null && $default !== '' ? sprintf(' <question>%s</question> [<comment>%s</comment>]:', $question, Formatter::escape($default)) : sprintf(' <question>%s</question>:', $question);

        return $this->prompt($label, $default, $validator, false);
    }

    public function confirm(string $question, bool $default = true): bool
    {
        $label = sprintf(' <question>%s</question> (yes/no) [<comment>%s</comment>]:', $question, $default ? 'yes' : 'no');

        return (bool) $this->prompt($label, $default ? 'yes' : 'no', static function (?string $answer) use ($default): bool {
            $answer = strtolower(trim((string) $answer));

            if ($answer === '') {
                return $default;
            }

            if (in_array($answer, self::TRUE_ANSWERS, true)) {
                return true;
            }

            if (in_array($answer, self::FALSE_ANSWERS, true)) {
                return false;
            }

            throw new InvalidInputException('Please answer yes or no.');
        }, false);
    }

    public function choice(string $question, array $choices, int|string|null $default = null): mixed
    {
        if ($choices === []) {
            throw new ConsoleException('The question "{question}" has no choice.', 0, null, ['question' => $question]);
        }

        $list = array_is_list($choices);
        $defaultLabel = $default !== null && array_key_exists($default, $choices) ? ($list ? (string) $choices[$default] : (string) $default) : null;
        $lines = [$defaultLabel !== null ? sprintf(' <question>%s</question> [<comment>%s</comment>]:', $question, Formatter::escape($defaultLabel)) : sprintf(' <question>%s</question>:', $question)];

        foreach ($choices as $key => $value) {
            $lines[] = sprintf('  [<comment>%s</comment>] %s', $key, $value);
        }

        return $this->prompt(implode(PHP_EOL, $lines), $default !== null ? (string) $default : null, static function (?string $answer) use ($choices, $list): mixed {
            $answer = trim((string) $answer);

            foreach ($choices as $key => $value) {
                if ((string) $key === $answer || (string) $value === $answer) {
                    return $list ? $value : $key;
                }
            }

            throw new InvalidInputException('The value "{value}" is not a valid choice.', 0, null, ['value' => $answer]);
        }, false);
    }

    public function select(string $question, array $choices, int|string|null $default = null, bool $strict = true, ?callable $validator = null): mixed
    {
        if ($choices === [] && $strict) {
            throw new ConsoleException('The question "{question}" has no choice.', 0, null, ['question' => $question]);
        }

        $list = array_is_list($choices);
        $defaultLabel = $default === null ? null : (string) ($list && is_int($default) && array_key_exists($default, $choices) ? $choices[$default] : $default);
        $label = sprintf(' <question>%s</question>%s%s:', $question, $defaultLabel !== null && $defaultLabel !== '' ? ' [<comment>' . Formatter::escape($defaultLabel) . '</comment>]' : '', $choices !== [] && !str_contains($question, ' ? ') ? ' <muted>(? to list)</muted>' : '');

        return $this->prompt($label, $defaultLabel, function (?string $answer) use ($choices, $list, $strict, $validator): mixed {
            $value = $this->matchChoice(trim((string) $answer), $choices, $list, $strict);

            return $validator !== null ? $validator($value) : $value;
        }, false, function () use ($choices, $list): void {
            $this->listChoices($choices, $list);
        });
    }

    public function secret(string $question, ?callable $validator = null): mixed
    {
        return $this->prompt(sprintf(' <question>%s</question>:', $question), null, $validator, true);
    }

    public function progressStart(int $max = 0): void
    {
        $this->progress = new ProgressBar($this, $max);
        $this->progress->start();
    }

    public function progressAdvance(int $step = 1): void
    {
        if ($this->progress === null) {
            $this->progressStart();
        }

        $this->progress?->advance($step);
    }

    public function progressFinish(): void
    {
        $this->progress?->finish();
        $this->progress = null;
    }

    public function progressIterate(iterable $iterable, ?int $max = null): iterable
    {
        $this->progressStart($max ?? (is_countable($iterable) ? count($iterable) : 0));

        foreach ($iterable as $key => $value) {
            yield $key => $value;

            $this->progressAdvance();
        }

        $this->progressFinish();
    }

    protected function matchChoice(string $answer, array $choices, bool $list, bool $strict): mixed
    {
        if ($answer === '') {
            if ($strict) {
                throw new InvalidInputException('A value is required: type ? to list the choices.');
            }

            return null;
        }

        $keys = array_keys($choices);

        if (ctype_digit($answer) && (int) $answer >= 1 && (int) $answer <= count($keys) && !array_key_exists($answer, $list ? [] : $choices)) {
            $key = $keys[(int) $answer - 1];

            return $list ? $choices[$key] : $key;
        }

        $matches = [];

        foreach ($choices as $key => $value) {
            if (strcasecmp((string) $value, $answer) === 0 || (!$list && strcasecmp((string) $key, $answer) === 0)) {
                return $list ? $value : $key;
            }

            if ($strict && (stripos((string) $value, $answer) === 0 || (!$list && stripos((string) $key, $answer) === 0))) {
                $matches[] = $key;
            }
        }

        if (count($matches) === 1) {
            return $list ? $choices[$matches[0]] : $matches[0];
        }

        if (count($matches) > 1) {
            throw new InvalidInputException('"{value}" is ambiguous: {matches}.', 0, null, ['value' => $answer, 'matches' => implode(', ', array_map(static fn (int|string $key): string => (string) ($list ? $choices[$key] : $key), $matches))]);
        }

        if ($strict) {
            throw new InvalidInputException('The value "{value}" is not a valid choice: type ? to list the choices.', 0, null, ['value' => $answer]);
        }

        return $answer;
    }

    protected function listChoices(array $choices, bool $list): void
    {
        $width = strlen((string) count($choices));
        $keyWidth = $list ? 0 : max(array_map(static fn (int|string $key): int => Formatter::width((string) $key), array_keys($choices)) ?: [0]);
        $index = 0;

        foreach ($choices as $key => $value) {
            $index++;
            $line = sprintf('  <comment>%s</comment>) ', str_pad((string) $index, $width, ' ', STR_PAD_LEFT));
            $line .= $list ? Formatter::escape((string) $value) : '<info>' . Formatter::escape((string) $key) . '</info>' . str_repeat(' ', $keyWidth - Formatter::width((string) $key)) . '  <muted>' . Formatter::escape((string) $value) . '</muted>';
            $this->writeln($line, self::VERBOSITY_QUIET);
        }
    }

    protected function prompt(string $label, ?string $default, ?callable $validator, bool $hidden, ?callable $help = null): mixed
    {
        if (!$this->interactive || !is_resource($this->inputStream)) {
            return $validator !== null ? $validator($default) : $default;
        }

        while (true) {
            if ($label !== '') {
                $this->writeln($label, self::VERBOSITY_QUIET);
            }

            $this->write(' > ', self::VERBOSITY_QUIET);
            $answer = $hidden ? $this->readHidden() : fgets($this->inputStream);

            if (!$this->isTty($this->inputStream)) {
                $this->newLine();
            }

            if ($answer === false) {
                return $validator !== null ? $validator($default) : $default;
            }

            $answer = rtrim($answer, "\r\n");

            if ($help !== null && trim($answer) === '?') {
                $help();
                continue;
            }

            if ($answer === '' && $default !== null) {
                $answer = $default;
            }

            if ($validator === null) {
                return $answer;
            }

            try {
                return $validator($answer);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());
            }
        }
    }

    protected function readHidden(): string|false
    {
        if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && $this->isTty($this->inputStream)) {
            $mode = shell_exec('stty -g');
            shell_exec('stty -echo');
            $answer = fgets($this->inputStream);

            if (is_string($mode)) {
                shell_exec('stty ' . escapeshellarg(trim($mode)));
            }

            $this->newLine();

            return $answer;
        }

        return fgets($this->inputStream);
    }

    protected function blankLine(): void
    {
        if ($this->newLines < 2) {
            $this->write(str_repeat(PHP_EOL, 2 - $this->newLines));
        }
    }

    protected function isTty(mixed $stream): bool
    {
        return is_resource($stream) && function_exists('stream_isatty') && @stream_isatty($stream);
    }

    protected function block(string|array $messages, string $type, string $style, int $verbosity = self::VERBOSITY_NORMAL, bool $background = true): void
    {
        if (!$this->shouldWrite($verbosity)) {
            return;
        }

        $width = $this->terminalWidth();
        $prefix = '[' . $type . '] ';
        $lines = [];

        foreach ((array) $messages as $index => $message) {
            if ($index > 0) {
                $lines[] = '';
            }

            foreach (explode("\n", wordwrap((string) $message, max(20, $width - strlen($prefix) - 2), "\n", false)) as $line) {
                $lines[] = $line;
            }
        }

        $this->blankLine();

        foreach ($lines as $index => $line) {
            $text = ($index === 0 ? $prefix : str_repeat(' ', strlen($prefix))) . $line;

            if ($background && $this->decorated) {
                $padding = max(0, $width - Formatter::width($this->formatter->strip($text)) - 2);
                $this->writeln('<' . $style . '> ' . $text . str_repeat(' ', $padding) . ' </' . $style . '>', $verbosity);
            } else {
                $this->writeln(' <' . $style . '>' . $text . '</' . $style . '>', $verbosity);
            }
        }

        $this->blankLine();
    }

    protected function row(array $cells, array $widths, bool $header): string
    {
        $output = '|';

        foreach ($widths as $index => $width) {
            $cell = (string) ($cells[$index] ?? '');
            $padding = str_repeat(' ', max(0, $width - Formatter::width($this->formatter->strip($cell))));
            $output .= ' ' . ($header ? '<info>' . $cell . '</info>' : $cell) . $padding . ' |';
        }

        return $output;
    }

    protected function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    protected function shouldWrite(int $verbosity): bool
    {
        return $verbosity === self::VERBOSITY_QUIET || ($this->verbosity !== self::VERBOSITY_QUIET && $verbosity <= $this->verbosity);
    }

    protected function terminalWidth(): int
    {
        $columns = (int) getenv('COLUMNS');

        return $columns > 0 ? min($columns, self::MAX_WIDTH) : self::MAX_WIDTH;
    }

    protected function detectColors(): bool
    {
        if (getenv('NO_COLOR') !== false || !is_resource($this->stream)) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\' && function_exists('sapi_windows_vt100_support')) {
            return @sapi_windows_vt100_support($this->stream, true);
        }

        return function_exists('stream_isatty') && @stream_isatty($this->stream);
    }
}