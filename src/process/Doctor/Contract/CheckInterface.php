<?php

declare(strict_types=1);

namespace NeoPHP\Process\Doctor\Contract;

interface CheckInterface
{
    /**
     * Returns the title of the group, also the name given to --check (e.g. Database).
     */
    public function getName(): string;

    /**
     * Runs the checks. An exception thrown here is reported as an error of the group.
     *
     * @return iterable<\NeoPHP\Process\Doctor\Model\CheckResult> One result per checked point; nothing when the group does not apply to the project
     */
    public function run(): iterable;
}