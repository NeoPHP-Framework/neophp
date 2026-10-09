<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Migration;

use NeoPHP\Package\Orm\Contract\AbstractMigration;
use NeoPHP\Package\Orm\Exception\MigrationException;

class MigrationGenerator
{
    public function __construct(protected string $directory, protected string $namespace = 'Migrations')
    {
    }

    public static function generateVersion(): string
    {
        return sprintf('%012x', (int) floor(microtime(true) * 1000)) . bin2hex(random_bytes(2));
    }

    public function generate(array $up, array $down, ?string $platform = null, string $description = '', ?string $version = null): string
    {
        $version ??= self::generateVersion();
        $class = AbstractMigration::PREFIX . $version;
        $file = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $class . '.php';

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new MigrationException('Unable to create the migrations directory "{directory}".', 0, null, ['directory' => $this->directory]);
        }

        $code = '<?php' . "\n\n"
            . 'declare(strict_types=1);' . "\n\n"
            . 'namespace ' . trim($this->namespace, '\\') . ';' . "\n\n"
            . 'use ' . AbstractMigration::class . ';' . "\n\n"
            . 'class ' . $class . ' extends AbstractMigration' . "\n"
            . "{\n"
            . "    public function getDescription(): string\n"
            . "    {\n"
            . '        return ' . var_export($description, true) . ";\n"
            . "    }\n\n"
            . "    public function up(): void\n"
            . "    {\n"
            . $this->body($up, $platform)
            . "    }\n\n"
            . "    public function down(): void\n"
            . "    {\n"
            . $this->body($down, $platform)
            . "    }\n"
            . "}\n";

        if (file_put_contents($file, $code) === false) {
            throw new MigrationException('Unable to write the migration file "{file}".', 0, null, ['file' => $file]);
        }

        return $file;
    }

    protected function body(array $statements, ?string $platform): string
    {
        $lines = [];

        if ($platform !== null && $statements !== []) {
            $lines[] = '        $this->abortIf($this->getPlatformName() !== ' . var_export($platform, true) . ', ' . var_export('This migration was generated for ' . $platform . '.', true) . ');';
            $lines[] = '';
        }

        foreach ($statements as $sql) {
            $lines[] = '        $this->addSql(' . var_export((string) $sql, true) . ');';
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }
}