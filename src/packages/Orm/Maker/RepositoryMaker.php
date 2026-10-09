<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Maker;

use NeoPHP\Package\Orm\Contract\AbstractRepository;

class RepositoryMaker extends AbstractMaker
{
    public function make(string $entityClass, bool $force = false, ?string $relative = null): array
    {
        $entityShort = self::shortName($entityClass);
        [$class, $file] = $this->resolve($relative ?? $entityShort, 'Repository');
        $code = '<?php' . "\n\n"
            . 'declare(strict_types=1);' . "\n\n"
            . 'namespace ' . self::namespaceOf($class) . ';' . "\n\n"
            . 'use ' . ltrim($entityClass, '\\') . ';' . "\n"
            . 'use ' . AbstractRepository::class . ';' . "\n\n"
            . 'class ' . self::shortName($class) . ' extends AbstractRepository' . "\n"
            . "{\n"
            . '    protected string $entityClass = ' . $entityShort . '::class;' . "\n"
            . "}\n";

        $this->write($file, $code, $force);

        return [$class, $file];
    }

    public function getRepositoryClass(string $entityClass, ?string $relative = null): string
    {
        return $this->resolve($relative ?? self::shortName($entityClass), 'Repository')[0];
    }
}