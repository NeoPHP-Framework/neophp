<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

interface VoterInterface
{
    public const ACCESS_GRANTED = 1;

    public const ACCESS_ABSTAIN = 0;

    public const ACCESS_DENIED = -1;

    public function vote(TokenInterface $token, mixed $subject, array $attributes): int;
}