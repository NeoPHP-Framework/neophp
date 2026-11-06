<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

use NeoPHP\Package\Security\OAuth\OAuthUser;

interface OAuthUserResolverInterface
{
    public function resolve(OAuthUser $user): UserInterface;
}