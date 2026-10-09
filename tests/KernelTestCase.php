<?php

declare(strict_types=1);

namespace NeoPHP\Tests;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Contract\AbstractKernel;
use NeoPHP\Process\Installer\InstallerManager;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Boots a real application generated from the skeleton of the Installer in a temporary directory.
 */
abstract class KernelTestCase extends TestCase
{
    protected static ?string $autoloadedProject = null;

    protected ?string $projectDir = null;

    protected ?AbstractKernel $kernel = null;

    protected array $server = [];

    protected array $env = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $_SERVER;
        $this->env = $_ENV;
    }

    protected function tearDown(): void
    {
        if ($this->kernel !== null) {
            restore_error_handler();
            $this->kernel = null;
        }

        // Releases the services of the kernel (PDO connections, open files): Windows cannot delete an open file.
        gc_collect_cycles();

        if ($this->projectDir !== null) {
            self::removeDirectory($this->projectDir);
            $this->projectDir = null;
        }

        $_SERVER = $this->server;
        $_ENV = $this->env;

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $modules content of config/config.php (module class => false or environments)
     * @param array<string, string> $files extra files of the project (relative path => content)
     */
    protected function createProject(array $modules = [], array $files = []): string
    {
        $this->projectDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neophp-test-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0775, true);

        (new InstallerManager())->install($this->projectDir);

        $files['config/config.php'] = '<?php return ' . var_export($modules, true) . ';';

        foreach ($files as $path => $content) {
            $file = $this->projectDir . DIRECTORY_SEPARATOR . $path;

            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }

            file_put_contents($file, $content);
        }

        self::registerAppAutoloader($this->projectDir);

        return $this->projectDir;
    }

    /**
     * @param array<string, mixed> $modules content of config/config.php
     */
    protected function bootKernel(array $modules = [], string $environment = 'test', bool $debug = true): AbstractKernel
    {
        $projectDir = $this->projectDir ?? $this->createProject($modules);
        $class = 'App\\Kernel';

        $this->kernel = new $class($environment, $debug, $projectDir);
        $this->kernel->boot();

        return $this->kernel;
    }

    protected function getContainer(): ContainerManagerInterface
    {
        return ($this->kernel ?? $this->bootKernel())->getContainer();
    }

    protected function request(string $method, string $uri, array $parameters = [], array $server = [], ?string $content = null): Response
    {
        $kernel = $this->kernel ?? $this->bootKernel();

        return $kernel->handle(Request::create($uri, $method, $parameters, $server, $content));
    }

    /**
     * The classes of the application (App\) are loaded from the last created project.
     */
    protected static function registerAppAutoloader(string $projectDir): void
    {
        if (self::$autoloadedProject === null) {
            spl_autoload_register(static function (string $class): void {
                if (self::$autoloadedProject === null || !str_starts_with($class, 'App\\')) {
                    return;
                }

                $file = self::$autoloadedProject . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';

                if (is_file($file)) {
                    require $file;
                }
            });
        }

        self::$autoloadedProject = $projectDir;
    }

    protected static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}