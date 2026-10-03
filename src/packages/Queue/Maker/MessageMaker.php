<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Maker;

use NeoPHP\Package\Queue\Exception\QueueException;

class MessageMaker
{
    public function __construct(protected string $path, protected string $namespace = 'App')
    {
    }

    public function resolve(string $name): array
    {
        $name = trim(str_replace('/', '\\', $name), '\\');

        foreach (['Message\\', 'App\\Message\\'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
            }
        }

        if (preg_match('/^([A-Z][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new QueueException('The name "{name}" is not a valid class name: use StudlyCase (SendNewsletter, Order\Ship).', 0, null, ['name' => $name]);
        }

        $root = trim($this->namespace, '\\');
        $file = str_replace('\\', DIRECTORY_SEPARATOR, $name);

        return [
            $root . '\\Message\\' . $name,
            rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . 'Message' . DIRECTORY_SEPARATOR . $file . '.php',
            $root . '\\MessageHandler\\' . $name . 'Handler',
            rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . 'MessageHandler' . DIRECTORY_SEPARATOR . $file . 'Handler.php',
        ];
    }

    public function make(string $name, bool $force = false): array
    {
        [$message, $messageFile, $handler, $handlerFile] = $this->resolve($name);

        foreach ([$messageFile, $handlerFile] as $file) {
            if (is_file($file) && !$force) {
                throw new QueueException('The file "{file}" already exists: use --force to overwrite it.', 0, null, ['file' => $file]);
            }
        }

        $messageNamespace = substr($message, 0, (int) strrpos($message, '\\'));
        $messageShort = substr($message, (int) strrpos($message, '\\') + 1);
        $handlerNamespace = substr($handler, 0, (int) strrpos($handler, '\\'));
        $handlerShort = substr($handler, (int) strrpos($handler, '\\') + 1);

        $this->write($messageFile, <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$messageNamespace};

            use NeoPHP\\Package\\Queue\\Attribute\\AsMessage;

            #[AsMessage(queue: 'default')]
            class {$messageShort}
            {
                public function __construct(public int \$id = 0)
                {
                }
            }

            PHP);

        $this->write($handlerFile, <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$handlerNamespace};

            use {$message};
            use NeoPHP\\Package\\Queue\\Attribute\\AsMessageHandler;

            #[AsMessageHandler]
            class {$handlerShort}
            {
                public function __invoke({$messageShort} \$message): void
                {
                }
            }

            PHP);

        return [$message, $messageFile, $handler, $handlerFile];
    }

    protected function write(string $file, string $code): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new QueueException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if (file_put_contents($file, $code) === false) {
            throw new QueueException('Unable to write the file "{file}".', 0, null, ['file' => $file]);
        }
    }
}