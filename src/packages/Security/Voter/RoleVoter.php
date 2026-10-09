<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Voter;

use NeoPHP\Package\Security\Authorization\RoleHierarchy;
use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Contract\VoterInterface;

class RoleVoter implements VoterInterface
{
    public function __construct(protected RoleHierarchy $hierarchy, protected string $prefix = 'ROLE_')
    {
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes): int
    {
        $vote = self::ACCESS_ABSTAIN;
        $roles = null;

        foreach ($attributes as $attribute) {
            if (!is_string($attribute) || !str_starts_with($attribute, $this->prefix)) {
                continue;
            }

            $roles ??= $this->hierarchy->getReachableRoleNames($token->getRoleNames());

            if (in_array($attribute, $roles, true)) {
                return self::ACCESS_GRANTED;
            }

            $vote = self::ACCESS_DENIED;
        }

        return $vote;
    }
}