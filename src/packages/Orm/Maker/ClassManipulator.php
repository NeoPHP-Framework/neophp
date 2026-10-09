<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Maker;

use NeoPHP\Package\Orm\Exception\OrmException;

class ClassManipulator
{
    protected string $namespace = '';

    protected string $className = '';

    protected array $imports = [];

    protected int $bodyStart = 0;

    protected int $bodyEnd = 0;

    protected array $members = [];

    public function __construct(protected string $source)
    {
        $this->parse();
    }

    public static function fromFile(string $file): self
    {
        $source = is_file($file) ? file_get_contents($file) : false;

        if ($source === false) {
            throw new OrmException('Unable to read the file "{file}".', 0, null, ['file' => $file]);
        }

        return new self($source);
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getClass(): string
    {
        return ltrim($this->namespace . '\\' . $this->className, '\\');
    }

    public function getImports(): array
    {
        return $this->imports;
    }

    public function hasProperty(string $name): bool
    {
        foreach ($this->members as $member) {
            if ($member['kind'] === 'property' && in_array($name, $member['names'], true)) {
                return true;
            }

            if ($member['kind'] === 'method' && strtolower($member['names'][0]) === '__construct') {
                $code = substr($this->source, $member['start'], $member['end'] - $member['start']);

                if (preg_match('/(?:public|protected|private)[^,()]*\$' . preg_quote($name, '/') . '\b/', (string) strstr($code, '{', true)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasMethod(string $name): bool
    {
        foreach ($this->members as $member) {
            if ($member['kind'] === 'method' && strcasecmp($member['names'][0], $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function import(string $class, ?string $alias = null): string
    {
        $class = ltrim(trim($class), '\\');
        $short = $alias ?? substr($class, (int) strrpos('\\' . $class, '\\'));

        foreach ($this->imports as $name => $imported) {
            if (strcasecmp($imported, $class) === 0) {
                return $name;
            }
        }

        $namespace = str_contains($class, '\\') ? substr($class, 0, (int) strrpos($class, '\\')) : '';
        $taken = isset($this->imports[$short]) || (strcasecmp($short, $this->className) === 0 && strcasecmp($class, $this->getClass()) !== 0);

        if ($alias === null && strcasecmp($namespace, $this->namespace) === 0) {
            return $taken ? '\\' . $class : $short;
        }

        if ($taken || ($this->namespace !== '' && strcasecmp($class, $this->namespace . '\\' . $short) !== 0 && (class_exists($this->namespace . '\\' . $short) || interface_exists($this->namespace . '\\' . $short) || enum_exists($this->namespace . '\\' . $short)))) {
            return '\\' . $class;
        }

        $this->addUseStatement($class . ($alias !== null ? ' as ' . $alias : ''));
        $this->imports[$short] = $class;

        return $short;
    }

    public function addProperty(string $code): static
    {
        $offset = null;

        foreach ($this->members as $member) {
            if (in_array($member['kind'], ['property', 'const', 'trait', 'case'], true)) {
                $offset = $this->lineEnd($member['end']);
            }
        }

        if ($offset === null) {
            $offset = $this->lineEnd($this->bodyStart);
            $next = $this->members[0] ?? null;
            $this->insert($offset, rtrim($code) . "\n" . ($next !== null ? "\n" : ''));

            return $this;
        }

        $this->insert($offset, "\n" . rtrim($code) . "\n" . (substr($this->source, $offset, 1) === "\n" || $this->isClosingLine($offset) ? '' : "\n"));

        return $this;
    }

    public function addMethod(string $code): static
    {
        $before = rtrim(substr($this->source, 0, $this->bodyEnd));
        $separator = str_ends_with($before, '{') ? "\n" : "\n\n";
        $this->source = $before . $separator . rtrim($code) . "\n" . substr($this->source, $this->bodyEnd);
        $this->parse();

        return $this;
    }

    public function addConstructorLines(array $lines, bool $callParent = false): static
    {
        if ($lines === []) {
            return $this;
        }

        foreach ($this->members as $member) {
            if ($member['kind'] !== 'method' || strtolower($member['names'][0]) !== '__construct') {
                continue;
            }

            $close = strrpos(substr($this->source, 0, $member['end']), '}');
            $open = strpos($this->source, '{', $member['start']);

            if ($close === false || $open === false || $open > $close) {
                throw new OrmException('Unable to find the body of the constructor.');
            }

            if (trim(substr($this->source, $open + 1, $close - $open - 1)) === '') {
                $this->source = substr($this->source, 0, $open) . "{\n" . implode("\n", $lines) . "\n    }" . substr($this->source, $close + 1);
                $this->parse();

                return $this;
            }

            $lineStart = strrpos(substr($this->source, 0, $close), "\n");
            $this->insert($lineStart === false ? $close : $lineStart + 1, implode("\n", $lines) . "\n");

            return $this;
        }

        if ($callParent) {
            array_unshift($lines, '        parent::__construct();', '');
        }

        $constructor = "    public function __construct()\n    {\n" . implode("\n", $lines) . "\n    }";

        foreach ($this->members as $member) {
            if ($member['kind'] === 'method') {
                $this->insert($this->lineStart($member['start']), $constructor . "\n\n");

                return $this;
            }
        }

        return $this->addMethod($constructor);
    }

    protected function addUseStatement(string $statement): void
    {
        $prefix = substr($this->source, 0, $this->classStart());

        if (preg_match_all('/^use\s+(?!function\b|const\b)([^;{]+);[^\n]*\n/m', $prefix, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                if (strcasecmp(trim($match[1][0]), $statement) > 0) {
                    $this->insert($match[0][1], 'use ' . $statement . ";\n");

                    return;
                }
            }

            $last = end($matches);
            $this->insert($last[0][1] + strlen($last[0][0]), 'use ' . $statement . ";\n");

            return;
        }

        if (preg_match('/^use\s+[^;]+;[^\n]*\n/m', $prefix, $any, PREG_OFFSET_CAPTURE) === 1) {
            $this->insert($any[0][1], 'use ' . $statement . ";\n");

            return;
        }

        if (preg_match('/^namespace\s+[^;]+;[^\n]*\n/m', $prefix, $match, PREG_OFFSET_CAPTURE) === 1) {
            $this->insert($match[0][1] + strlen($match[0][0]), "\nuse " . $statement . ";\n");

            return;
        }

        throw new OrmException('Unable to add the import "{class}": the file has no namespace.', 0, null, ['class' => $statement]);
    }

    protected function insert(int $offset, string $code): void
    {
        $this->source = substr($this->source, 0, $offset) . $code . substr($this->source, $offset);
        $this->parse();
    }

    protected function lineEnd(int $offset): int
    {
        $newline = strpos($this->source, "\n", $offset);

        return $newline === false ? strlen($this->source) : $newline + 1;
    }

    protected function lineStart(int $offset): int
    {
        $newline = strrpos(substr($this->source, 0, $offset), "\n");

        return $newline === false ? 0 : $newline + 1;
    }

    protected function isClosingLine(int $offset): bool
    {
        return preg_match('/^\s*\}/', substr($this->source, $offset)) === 1 && $this->lineStart($this->bodyEnd) === $offset;
    }

    protected function classStart(): int
    {
        return (int) strrpos(substr($this->source, 0, $this->bodyStart), 'class');
    }

    protected function parse(): void
    {
        $tokens = token_get_all($this->source);
        $offsets = [];
        $offset = 0;

        foreach ($tokens as $index => $token) {
            $offsets[$index] = $offset;
            $offset += strlen(is_array($token) ? $token[1] : $token);
        }

        $this->namespace = '';
        $this->className = '';
        $this->imports = [];
        $this->members = [];
        $count = count($tokens);
        $classIndex = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE && $classIndex === null) {
                $name = '';

                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    $name .= is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE ? $tokens[$j][1] : '';
                }

                $this->namespace = trim($name, '\\');
            } elseif ($token[0] === T_USE && $classIndex === null) {
                $statement = '';

                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
                    $statement .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }

                $this->registerImports(trim($statement));
                $i = $j;
            } elseif ($token[0] === T_CLASS && $classIndex === null && !$this->isClassConstant($tokens, $i)) {
                $classIndex = $i;

                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $this->className = $tokens[$j][1];
                        break;
                    }
                }
            }
        }

        if ($classIndex === null) {
            throw new OrmException('Unable to find the class in the source.');
        }

        $i = $classIndex;

        while ($i < $count && $tokens[$i] !== '{') {
            $i++;
        }

        if ($i >= $count) {
            throw new OrmException('Unable to find the class body in the source.');
        }

        $this->bodyStart = $offsets[$i] + 1;
        $depth = 1;
        $memberStart = null;
        $kind = null;
        $names = [];
        $brackets = 0;

        for ($i++; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;

            if ($depth === 1 && $brackets === 0 && $id === T_WHITESPACE) {
                continue;
            }

            if ($depth === 1 && $memberStart === null && $text !== '}') {
                $memberStart = $offsets[$i];
                $kind = null;
                $names = [];
            }

            if ($id === T_ATTRIBUTE) {
                $brackets++;
                continue;
            }

            if ($brackets > 0) {
                $brackets += $text === '[' ? 1 : ($text === ']' ? -1 : 0);
                continue;
            }

            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }

            if ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    $this->bodyEnd = $offsets[$i];

                    return;
                }

                if ($depth === 1 && $kind === 'method') {
                    $this->members[] = ['kind' => $kind, 'names' => $names, 'start' => (int) $memberStart, 'end' => $offsets[$i] + 1];
                    $memberStart = null;
                }

                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if ($id === T_FUNCTION && $kind === null) {
                $kind = 'method';

                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING], true)) {
                        $names = [$tokens[$j][1]];
                        break;
                    }
                }
            } elseif ($id === T_CONST && $kind === null) {
                $kind = 'const';
            } elseif ($id === T_USE && $kind === null) {
                $kind = 'trait';
            } elseif ($id === T_CASE && $kind === null) {
                $kind = 'case';
            } elseif ($id === T_VARIABLE && $kind === null) {
                $kind = 'property';
                $names[] = substr($text, 1);
            } elseif ($id === T_VARIABLE && $kind === 'property' && ($tokens[$i - 1] === ',' || (is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_WHITESPACE && ($tokens[$i - 2] ?? null) === ','))) {
                $names[] = substr($text, 1);
            } elseif ($text === ';') {
                if ($kind !== null) {
                    $this->members[] = ['kind' => $kind, 'names' => $names, 'start' => (int) $memberStart, 'end' => $offsets[$i] + 1];
                }

                $memberStart = null;
            } elseif (($id === T_COMMENT || $id === T_DOC_COMMENT) && $kind !== null) {
                continue;
            }
        }

        throw new OrmException('Unable to find the end of the class body in the source.');
    }

    protected function registerImports(string $statement): void
    {
        if (preg_match('/^(function|const)\s/i', $statement) === 1) {
            return;
        }

        $statement = ltrim($statement, '\\');

        if (preg_match('/^([^{]*)\{([^}]*)\}$/', $statement, $group) === 1) {
            foreach (explode(',', $group[2]) as $item) {
                if (trim($item) !== '') {
                    $this->registerImport(rtrim(ltrim(trim($group[1]), '\\'), '\\') . '\\' . trim($item));
                }
            }

            return;
        }

        foreach (explode(',', $statement) as $item) {
            $this->registerImport(ltrim(trim($item), '\\'));
        }
    }

    protected function registerImport(string $item): void
    {
        if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $item, $matches) === 1) {
            $this->imports[$matches[2]] = ltrim($matches[1], '\\');

            return;
        }

        $this->imports[substr($item, (int) strrpos('\\' . $item, '\\'))] = $item;
    }

    protected function isClassConstant(array $tokens, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return (is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) || (is_array($tokens[$j]) && $tokens[$j][0] === T_NEW);
        }

        return false;
    }
}