<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\User;

use NeoPHP\Package\Security\Contract\EmailVerifiedUserInterface;
use NeoPHP\Package\Security\Contract\UserCheckerInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\CustomUserMessageAuthenticationException;

class EmailVerifiedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if ($user instanceof EmailVerifiedUserInterface && !$user->isEmailVerified()) {
            throw new CustomUserMessageAuthenticationException('Your email address is not verified.');
        }
    }
}