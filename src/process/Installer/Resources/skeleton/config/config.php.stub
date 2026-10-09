<?php

declare(strict_types=1);

/*
 * Every module of the framework (#[Component], #[Package], #[Process]) and of the
 * installed packages is enabled. List here only the modules this project changes:
 *
 * Module::class => false                        disabled
 * Module::class => ['dev' => true]              enabled only in the listed environments
 * Module::class => ['all' => true, 'prod' => false]
 *
 * A module required by an enabled module cannot be disabled.
 * Run "php bin/neo cache:clear" after a change in production.
 */

return [
    NeoPHP\Package\WebProfiler\WebProfilerManager::class => ['dev' => true],
    NeoPHP\Package\NeoAI\NeoAiManager::class => ['dev' => true],
];