<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Signature;

use NeoPHP\Package\Security\Contract\PasswordAuthenticatedUserInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\User\UserClass;

class UserTokenSigner
{
    public const PURPOSE_RESET_PASSWORD = 'reset_password';

    public const PURPOSE_VERIFY_EMAIL = 'verify_email';

    public const DEFAULT_LIFETIME = 3600;

    public function __construct(protected string $secret)
    {
        if ($secret === '') {
            throw new SecurityException('The signed user tokens need a secret: define APP_SECRET in .env.');
        }
    }

    public function create(UserInterface $user, string $purpose, int $lifetime = self::DEFAULT_LIFETIME, string $state = ''): string
    {
        $expires = time() + max(1, $lifetime);

        return self::encode($user->getUserIdentifier()) . '.' . $expires . '.' . $this->signature($user, $purpose, $expires, $state);
    }

    public function getIdentifier(string $token): ?string
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || !ctype_digit($parts[1]) || (int) $parts[1] <= time()) {
            return null;
        }

        $identifier = base64_decode(strtr($parts[0], '-_', '+/'), true);

        return $identifier === false || $identifier === '' ? null : $identifier;
    }

    public function isValid(UserInterface $user, string $token, string $purpose, string $state = ''): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || !ctype_digit($parts[1]) || (int) $parts[1] <= time() || $this->getIdentifier($token) !== $user->getUserIdentifier()) {
            return false;
        }

        return hash_equals($this->signature($user, $purpose, (int) $parts[1], $state), $parts[2]);
    }

    protected function signature(UserInterface $user, string $purpose, int $expires, string $state): string
    {
        $password = $user instanceof PasswordAuthenticatedUserInterface ? (string) $user->getPassword() : '';

        return hash_hmac('sha256', implode("\0", [$purpose, UserClass::of($user), $user->getUserIdentifier(), (string) $expires, $password, $state]), $this->secret);
    }

    protected static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}