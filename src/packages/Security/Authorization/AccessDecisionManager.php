<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Authorization;

use Closure;
use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Contract\VoterInterface;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\Trace\SecurityTrace;

class AccessDecisionManager
{
    public const STRATEGIES = ['affirmative', 'consensus', 'unanimous', 'priority'];

    protected ?array $voters = null;

    protected ?SecurityTrace $trace = null;

    public function __construct(
        protected Closure|array $voterLoader = [],
        protected string $strategy = 'affirmative',
        protected bool $allowIfAllAbstain = false,
        protected bool $allowIfEqualGrantedDenied = true,
    ) {
        if (!in_array($strategy, self::STRATEGIES, true)) {
            throw new SecurityException('Unknown access decision strategy "{strategy}": use "{strategies}".', 0, null, ['strategy' => $strategy, 'strategies' => implode('", "', self::STRATEGIES)]);
        }
    }

    public function getVoters(): array
    {
        if ($this->voters === null) {
            $voters = $this->voterLoader instanceof Closure ? ($this->voterLoader)() : $this->voterLoader;
            $this->voters = array_values(array_filter((array) $voters, static fn (mixed $voter): bool => $voter instanceof VoterInterface));
        }

        return $this->voters;
    }

    public function addVoter(VoterInterface $voter): static
    {
        $this->voters = [...$this->getVoters(), $voter];

        return $this;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function setTrace(?SecurityTrace $trace): static
    {
        $this->trace = $trace;

        return $this;
    }

    public function getTrace(): ?SecurityTrace
    {
        return $this->trace;
    }

    public function decide(TokenInterface $token, array $attributes, mixed $subject = null): bool
    {
        if ($this->trace === null) {
            return $this->resolve($token, $attributes, $subject);
        }

        $votes = [];
        $result = $this->resolve($token, $attributes, $subject, $votes);
        $this->trace->addDecision($token, $attributes, $subject, $result, $votes, $this->strategy);

        return $result;
    }

    protected function resolve(TokenInterface $token, array $attributes, mixed $subject, ?array &$votes = null): bool
    {
        $granted = 0;
        $denied = 0;

        foreach ($this->getVoters() as $voter) {
            $vote = $voter->vote($token, $subject, $attributes);

            if ($votes !== null) {
                $votes[] = [$voter::class, $vote];
            }

            if ($vote === VoterInterface::ACCESS_GRANTED) {
                if ($this->strategy === 'affirmative' || $this->strategy === 'priority') {
                    return true;
                }

                $granted++;
            } elseif ($vote === VoterInterface::ACCESS_DENIED) {
                if ($this->strategy === 'unanimous' || $this->strategy === 'priority') {
                    return false;
                }

                $denied++;
            }
        }

        return match ($this->strategy) {
            'affirmative' => $denied > 0 ? false : $this->allowIfAllAbstain,
            'unanimous' => $granted > 0 ? true : $this->allowIfAllAbstain,
            'consensus' => match (true) {
                $granted > $denied => true,
                $denied > $granted => false,
                $granted > 0 => $this->allowIfEqualGrantedDenied,
                default => $this->allowIfAllAbstain,
            },
            default => $this->allowIfAllAbstain,
        };
    }
}