<?php

declare(strict_types=1);

namespace NeoPHP\Tests\Component\Kernel;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\Exception\KernelException;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use NeoPHP\Component\Mailer\MailerManager;
use NeoPHP\Component\Mailer\MailerManagerInterface;
use NeoPHP\Component\Routing\RoutingManager;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Package\WebProfiler\WebProfilerManager;
use NeoPHP\Package\WebProfiler\WebProfilerManagerInterface;
use NeoPHP\Tests\KernelTestCase;

final class KernelTest extends KernelTestCase
{
    public function testBootRegistersTheKernelAndTheModules(): void
    {
        $kernel = $this->bootKernel();
        $container = $kernel->getContainer();

        self::assertSame($kernel, $container->get(KernelManagerInterface::class));
        self::assertSame($container, $container->get(ContainerManagerInterface::class));
        self::assertTrue($kernel->isEnabled(RoutingManagerInterface::class));
        self::assertInstanceOf(RoutingManager::class, $container->get(RoutingManagerInterface::class));
        self::assertSame('test', $kernel->getEnvironment());
    }

    public function testHomePageOfTheSkeletonResponds(): void
    {
        $response = $this->request('GET', '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('NeoPHP', (string) $response->getContent());
    }

    public function testUnknownRouteReturnsA404(): void
    {
        self::assertSame(404, $this->request('GET', '/missing-page')->getStatusCode());
    }

    public function testModuleDisabledInConfig(): void
    {
        $kernel = $this->bootKernel([MailerManager::class => false]);

        self::assertFalse($kernel->isEnabled(MailerManagerInterface::class));
        self::assertFalse($kernel->getContainer()->has(MailerManagerInterface::class));
    }

    public function testModuleEnabledOnlyInTheListedEnvironments(): void
    {
        $modules = [WebProfilerManager::class => ['dev' => true]];

        self::assertFalse($this->bootKernel($modules, 'test')->isEnabled(WebProfilerManagerInterface::class));
    }

    public function testRequiredModuleCannotBeDisabled(): void
    {
        $this->expectException(KernelException::class);

        $this->bootKernel([RoutingManager::class => false]);
    }
}