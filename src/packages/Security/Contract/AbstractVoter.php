<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

abstract class AbstractVoter implements VoterInterface
{
    public function vote(TokenInterface $token, mixed $subject, array $attributes): int
    {
        $vote = self::ACCESS_ABSTAIN;

        foreach ($attributes as $attribute) {
            if (!is_string($attribute) || !$this->supports($attribute, $subject)) {
                continue;
            }

            if ($this->voteOnAttribute($attribute, $subject, $token)) {
                return self::ACCESS_GRANTED;
            }

            $vote = self::ACCESS_DENIED;
        }

        return $vote;
    }

    abstract protected function supports(string $attribute, mixed $subject): bool;

    abstract protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool;
}