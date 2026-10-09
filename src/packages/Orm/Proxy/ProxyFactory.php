<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Proxy;

use Closure;
use NeoPHP\Package\Orm\Contract\ProxyInterface;
use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Package\Orm\Metadata\ClassMetadata;
use ReflectionClass;

class ProxyFactory
{
    protected array $proxyable = [];

    public function __construct(protected string $directory, protected bool $autoGenerate = true, protected ProxyGenerator $generator = new ProxyGenerator())
    {
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function canProxy(string $class): bool
    {
        return $this->proxyable[$class] ??= $this->generator->canProxy($class);
    }

    public function create(ClassMetadata $metadata, mixed $id, Closure $initializer): ProxyInterface
    {
        $proxyClass = $this->load($metadata);
        $proxy = (new ReflectionClass($proxyClass))->newInstanceWithoutConstructor();

        if (!$proxy instanceof ProxyInterface) {
            throw new OrmException('The proxy class "{class}" is invalid.', 0, null, ['class' => $proxyClass]);
        }

        $proxy->__neoUnsetLazyProperties();
        $metadata->setValue($proxy, $metadata->identifier, $id);
        $proxy->__neoSetInitializer($initializer);

        return $proxy;
    }

    public function load(ClassMetadata $metadata): string
    {
        $class = $metadata->name;
        $proxyClass = ProxyGenerator::getProxyClass($class);

        if (class_exists($proxyClass, false)) {
            return $proxyClass;
        }

        $file = $this->directory . DIRECTORY_SEPARATOR . str_replace('\\', '_', $class) . '.php';

        if (!is_file($file) || ($this->autoGenerate && $this->isStale($metadata->reflection, $file))) {
            $this->write($file, $this->generator->generate($class, $metadata->identifier));
        }

        require $file;

        return $proxyClass;
    }

    public function generate(ClassMetadata $metadata): string
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . str_replace('\\', '_', $metadata->name) . '.php';
        $this->write($file, $this->generator->generate($metadata->name, $metadata->identifier));

        return $file;
    }

    protected function isStale(ReflectionClass $reflection, string $file): bool
    {
        $time = (int) filemtime($file);

        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            $source = $current->getFileName();

            if ($source !== false && (int) filemtime($source) > $time) {
                return true;
            }
        }

        return false;
    }

    protected function write(string $file, string $code): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new OrmException('Unable to create the proxy directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, $code) === false || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new OrmException('Unable to write the proxy file "{file}".', 0, null, ['file' => $file]);
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}