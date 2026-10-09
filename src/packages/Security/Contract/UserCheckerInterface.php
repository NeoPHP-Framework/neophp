<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

interface UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void;

    public function checkPostAuth(UserInterface $user): void;
}