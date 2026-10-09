<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Voter;

use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Contract\VoterInterface;

class AuthenticatedVoter implements VoterInterface
{
    public const PUBLIC_ACCESS = 'PUBLIC_ACCESS';

    public const IS_AUTHENTICATED = 'IS_AUTHENTICATED';

    public const IS_AUTHENTICATED_FULLY = 'IS_AUTHENTICATED_FULLY';

    public const IS_AUTHENTICATED_REMEMBERED = 'IS_AUTHENTICATED_REMEMBERED';

    public const IS_REMEMBERED = 'IS_REMEMBERED';

    public const ATTRIBUTES = [self::PUBLIC_ACCESS, self::IS_AUTHENTICATED, self::IS_AUTHENTICATED_FULLY, self::IS_AUTHENTICATED_REMEMBERED, self::IS_REMEMBERED];

    public function vote(TokenInterface $token, mixed $subject, array $attributes): int
    {
        $vote = self::ACCESS_ABSTAIN;

        foreach ($attributes as $attribute) {
            if (!in_array($attribute, self::ATTRIBUTES, true)) {
                continue;
            }

            $granted = match ($attribute) {
                self::PUBLIC_ACCESS => true,
                self::IS_AUTHENTICATED, self::IS_AUTHENTICATED_REMEMBERED => $token->isAuthenticated(),
                self::IS_AUTHENTICATED_FULLY => $token->isAuthenticated() && !$token->isRemembered(),
                self::IS_REMEMBERED => $token->isAuthenticated() && $token->isRemembered(),
            };

            if ($granted) {
                return self::ACCESS_GRANTED;
            }

            $vote = self::ACCESS_DENIED;
        }

        return $vote;
    }
}