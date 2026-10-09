<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Contract;

interface OutputInterface
{
    public const VERBOSITY_QUIET = 16;

    public const VERBOSITY_NORMAL = 32;

    public const VERBOSITY_VERBOSE = 64;

    public const VERBOSITY_VERY_VERBOSE = 128;

    public const VERBOSITY_DEBUG = 256;

    public function write(string $message, int $verbosity = self::VERBOSITY_NORMAL): void;

    public function writeln(string $message = '', int $verbosity = self::VERBOSITY_NORMAL): void;

    public function newLine(int $count = 1): void;

    public function getVerbosity(): int;

    public function setVerbosity(int $verbosity): static;

    public function isQuiet(): bool;

    public function isVerbose(): bool;

    public function isVeryVerbose(): bool;

    public function isDebug(): bool;

    public function isDecorated(): bool;

    public function setDecorated(bool $decorated): static;

    public function isInteractive(): bool;

    public function setInteractive(bool $interactive): static;

    public function title(string $title): void;

    public function section(string $title): void;

    public function text(string|array $messages): void;

    public function comment(string|array $messages): void;

    public function listing(array $items): void;

    public function table(array $headers, array $rows): void;

    public function definitionList(array $definitions): void;

    public function success(string|array $messages): void;

    public function error(string|array $messages): void;

    public function warning(string|array $messages): void;

    public function caution(string|array $messages): void;

    public function info(string|array $messages): void;

    public function note(string|array $messages): void;

    public function ask(string $question, ?string $default = null, ?callable $validator = null): mixed;

    public function confirm(string $question, bool $default = true): bool;

    public function choice(string $question, array $choices, int|string|null $default = null): mixed;

    public function select(string $question, array $choices, int|string|null $default = null, bool $strict = true, ?callable $validator = null): mixed;

    public function secret(string $question, ?callable $validator = null): mixed;

    public function progressStart(int $max = 0): void;

    public function progressAdvance(int $step = 1): void;

    public function progressFinish(): void;

    public function progressIterate(iterable $iterable, ?int $max = null): iterable;
}