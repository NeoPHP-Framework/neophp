<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Discovery;

use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Package\Security\Attribute\AsVoter;
use NeoPHP\Package\Security\Contract\VoterInterface;
use NeoPHP\Package\Security\Exception\SecurityException;
use ReflectionClass;

/**
 * @internal
 */
class VoterDiscovery
{
    protected ClassFinder $finder;

    public function __construct(protected array $paths = [])
    {
        $this->finder = new ClassFinder();
    }

    public function discover(): array
    {
        $voters = [];

        foreach ($this->paths as $path) {
            foreach ($this->finder->find((string) $path, 'AsVoter') as $class) {
                if (!class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
                $attributes = $reflection->getAttributes(AsVoter::class);

                if ($attributes === [] || !$reflection->isInstantiable()) {
                    continue;
                }

                if (!$reflection->implementsInterface(VoterInterface::class)) {
                    throw new SecurityException('The voter "{voter}" must implement {interface} (or extend AbstractVoter).', 0, null, [
                        'voter' => $class,
                        'interface' => VoterInterface::class,
                    ]);
                }

                $voters[] = ['class' => $class, 'priority' => $attributes[0]->newInstance()->priority];
            }
        }

        usort($voters, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return array_column($voters, 'class');
    }

    public function getResources(): array
    {
        return $this->finder->getResources();
    }
}