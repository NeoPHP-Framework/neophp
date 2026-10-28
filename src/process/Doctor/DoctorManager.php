<?php

declare(strict_types=1);

namespace NeoPHP\Process\Doctor;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\Attribute\Process;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Exception\DoctorException;
use NeoPHP\Process\Doctor\Model\CheckResult;
use NeoPHP\Process\Doctor\Model\DoctorReport;
use NeoPHP\Process\Doctor\Provider\DoctorProvider;
use Throwable;

#[Process(provider: DoctorProvider::class)]
final class DoctorManager implements DoctorManagerInterface
{
    /**
     * @var list<CheckInterface|class-string<CheckInterface>>
     */
    protected array $checks = [];

    /**
     * @param iterable<CheckInterface|class-string<CheckInterface>> $checks
     */
    public function __construct(protected ContainerManagerInterface $container, iterable $checks = [])
    {
        foreach ($checks as $check) {
            $this->addCheck($check);
        }
    }

    public function run(array $only = []): DoctorReport
    {
        $wanted = array_fill_keys(array_map(static fn (string $name): string => strtolower(trim($name)), $only), false);
        $report = new DoctorReport();

        foreach ($this->checks as $check) {
            try {
                $check = is_string($check) ? $this->container->get($check) : $check;

                if (!$check instanceof CheckInterface) {
                    throw new DoctorException('A check must implement {interface} ({type} given).', 0, null, ['interface' => CheckInterface::class, 'type' => get_debug_type($check)]);
                }
            } catch (Throwable $exception) {
                if ($wanted === []) {
                    $report->add(is_string($check) ? self::shortName($check) : get_debug_type($check), CheckResult::error('Check', 'cannot be created: ' . $exception->getMessage()));
                }

                continue;
            }

            $name = $check->getName();
            $key = strtolower($name);

            if ($wanted !== []) {
                if (!array_key_exists($key, $wanted)) {
                    continue;
                }

                $wanted[$key] = true;
            }

            try {
                foreach ($check->run() as $result) {
                    $report->add($name, $result);
                }
            } catch (Throwable $exception) {
                $report->add($name, CheckResult::error('Check', 'failed: ' . $exception->getMessage()));
            }
        }

        $unknown = array_keys(array_filter($wanted, static fn (bool $found): bool => !$found));

        if ($unknown !== []) {
            throw new DoctorException('The check "{name}" does not exist: use {checks}.', 0, null, ['name' => implode('", "', $unknown), 'checks' => implode(', ', $this->names())]);
        }

        return $report;
    }

    public function addCheck(CheckInterface|string $check): static
    {
        $this->checks[] = $check;

        return $this;
    }

    public function getChecks(): array
    {
        return $this->checks;
    }

    /**
     * @return list<string>
     */
    protected function names(): array
    {
        $names = [];

        foreach ($this->checks as $check) {
            try {
                $check = is_string($check) ? $this->container->get($check) : $check;
                $names[] = $check instanceof CheckInterface ? strtolower($check->getName()) : null;
            } catch (Throwable) {
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    protected static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return preg_replace('/Check$/', '', $position === false ? $class : substr($class, $position + 1)) ?: $class;
    }
}