<?php

declare(strict_types=1);

namespace NeoPHP\Process\Doctor;

use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Exception\DoctorException;
use NeoPHP\Process\Doctor\Model\DoctorReport;

interface DoctorManagerInterface
{
    /**
     * Runs the checks and gathers their results. A check that cannot be built or that throws is reported as an error, the others still run.
     *
     * @param list<string> $only Names of the checks to run (case insensitive, e.g. ['database', 'php']), all of them when empty
     * @return DoctorReport The results, grouped by check
     * @throws DoctorException When a name of $only matches no check
     */
    public function run(array $only = []): DoctorReport;

    /**
     * Adds a check, run after the discovered ones.
     *
     * @param CheckInterface|class-string<CheckInterface> $check The check or its class (built by the container)
     */
    public function addCheck(CheckInterface|string $check): static;

    /**
     * Returns the checks: the Helper/Doctor classes of the enabled modules, then the checks of the application.
     *
     * @return list<CheckInterface|class-string<CheckInterface>> The checks and the classes of the checks not built yet
     */
    public function getChecks(): array;
}