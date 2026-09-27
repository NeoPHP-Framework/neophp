<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Trace;

use BackedEnum;
use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Contract\VoterInterface;
use NeoPHP\Package\Security\Event\LoginFailureEvent;
use NeoPHP\Package\Security\Event\LoginSuccessEvent;
use NeoPHP\Package\Security\Event\LogoutEvent;
use ReflectionClass;
use Stringable;
use UnitEnum;

class SecurityTrace
{
    public const MAX_DECISIONS = 500;

    public const MAX_SUBJECT_LENGTH = 120;

    protected array $decisions = [];

    protected array $events = [];

    protected ?array $accessControl = null;

    protected int $dropped = 0;

    public function addDecision(TokenInterface $token, array $attributes, mixed $subject, bool $granted, array $votes, string $strategy): void
    {
        if (count($this->decisions) >= self::MAX_DECISIONS) {
            $this->dropped++;

            return;
        }

        $this->decisions[] = [
            'attributes' => array_values(array_map(static fn (mixed $attribute): string => is_scalar($attribute) || $attribute instanceof Stringable ? (string) $attribute : get_debug_type($attribute), $attributes)),
            'subject' => self::describe($subject),
            'granted' => $granted,
            'strategy' => $strategy,
            'user' => $token->getUserIdentifier(),
            'votes' => array_map(static fn (array $vote): array => ['voter' => $vote[0], 'vote' => self::voteName($vote[1])], $votes),
        ];
    }

    public function addEvent(object $event): void
    {
        $entry = match (true) {
            $event instanceof LoginSuccessEvent => ['type' => 'login_success', 'firewall' => $event->getFirewall(), 'authenticator' => $event->getAuthenticator(), 'user' => $event->getToken()->getUserIdentifier(), 'message' => null],
            $event instanceof LoginFailureEvent => ['type' => 'login_failure', 'firewall' => $event->getFirewall(), 'authenticator' => $event->getAuthenticator(), 'user' => null, 'message' => $event->getException()->getMessage()],
            $event instanceof LogoutEvent => ['type' => 'logout', 'firewall' => $event->getFirewall(), 'authenticator' => $event->getToken()?->getAuthenticator(), 'user' => $event->getToken()?->getUserIdentifier(), 'message' => null],
            default => null,
        };

        if ($entry !== null) {
            $this->events[] = $entry;
        }
    }

    public function setAccessControl(?array $roles, ?bool $granted): void
    {
        $this->accessControl = ['roles' => $roles, 'granted' => $granted];
    }

    public function getDecisions(): array
    {
        return $this->decisions;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getAccessControl(): ?array
    {
        return $this->accessControl;
    }

    public function getDropped(): int
    {
        return $this->dropped;
    }

    public function reset(): void
    {
        $this->decisions = [];
        $this->events = [];
        $this->accessControl = null;
        $this->dropped = 0;
    }

    public static function voteName(int $vote): string
    {
        return match ($vote) {
            VoterInterface::ACCESS_GRANTED => 'granted',
            VoterInterface::ACCESS_DENIED => 'denied',
            default => 'abstain',
        };
    }

    public static function describe(mixed $subject): ?string
    {
        $description = match (true) {
            $subject === null => null,
            $subject instanceof BackedEnum => (new ReflectionClass($subject))->getShortName() . '::' . $subject->name,
            $subject instanceof UnitEnum => (new ReflectionClass($subject))->getShortName() . '::' . $subject->name,
            is_object($subject) => self::describeObject($subject),
            is_array($subject) => sprintf('array(%d)', count($subject)),
            is_bool($subject) => $subject ? 'true' : 'false',
            is_scalar($subject) => (string) $subject,
            default => get_debug_type($subject),
        };

        return $description === null || strlen($description) <= self::MAX_SUBJECT_LENGTH ? $description : substr($description, 0, self::MAX_SUBJECT_LENGTH) . '…';
    }

    protected static function describeObject(object $subject): string
    {
        $name = (new ReflectionClass($subject))->getShortName();

        if (method_exists($subject, 'getId')) {
            $id = $subject->getId();

            if (is_scalar($id) || $id instanceof Stringable) {
                return sprintf('%s #%s', $name, (string) $id);
            }
        }

        return $name;
    }
}