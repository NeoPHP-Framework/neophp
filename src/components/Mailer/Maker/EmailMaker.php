<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Maker;

use NeoPHP\Component\Mailer\Exception\MailerException;

class EmailMaker
{
    public function __construct(protected string $path, protected string $namespace = 'App\\Email')
    {
    }

    public function resolve(string $name): array
    {
        $name = trim(str_replace('/', '\\', $name), '\\');

        if (str_starts_with($name, trim($this->namespace, '\\') . '\\')) {
            $name = substr($name, strlen(trim($this->namespace, '\\')) + 1);
        }

        if (str_ends_with($name, 'Email') && $name !== 'Email') {
            $name = substr($name, 0, -5);
        }

        if (preg_match('/^([A-Z][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new MailerException('The name "{name}" is not a valid class name: use StudlyCase (Welcome, Order\Shipped).', 0, null, ['name' => $name]);
        }

        return [
            trim($this->namespace, '\\') . '\\' . $name . 'Email',
            rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $name . 'Email') . '.php',
        ];
    }

    public function make(string $name, bool $force = false): array
    {
        [$class, $file] = $this->resolve($name);

        if (is_file($file) && !$force) {
            throw new MailerException('The file "{file}" already exists: use --force to overwrite it.', 0, null, ['file' => $file]);
        }

        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $shortClass = substr($class, (int) strrpos($class, '\\') + 1);
        $subject = ucfirst(strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', substr($shortClass, 0, -5)))));
        $code = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use NeoPHP\\Component\\Mailer\\Mime\\Email;

            class {$shortClass} extends Email
            {
                public function __construct(string \$to, string \$name = '')
                {
                    \$this->to(\$to)
                        ->subject('{$subject}')
                        ->text('Hello ' . \$name . '!')
                        ->html('<p>Hello <strong>' . htmlspecialchars(\$name, ENT_QUOTES) . '</strong>!</p>');
                }
            }

            PHP;

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new MailerException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if (file_put_contents($file, $code) === false) {
            throw new MailerException('Unable to write the file "{file}".', 0, null, ['file' => $file]);
        }

        return [$class, $file];
    }
}