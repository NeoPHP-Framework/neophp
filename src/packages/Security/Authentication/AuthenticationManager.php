<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Authentication;

use Closure;
use NeoPHP\Component\Csrf\CsrfManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\Security\Contract\AuthenticatorInterface;
use NeoPHP\Package\Security\Contract\PasswordAuthenticatedUserInterface;
use NeoPHP\Package\Security\Contract\PasswordHasherInterface;
use NeoPHP\Package\Security\Contract\PasswordUpgraderInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\BadCredentialsException;
use NeoPHP\Package\Security\Exception\InvalidCsrfTokenException;
use NeoPHP\Package\Security\Exception\UserNotFoundException;
use NeoPHP\Package\Security\Firewall\Firewall;
use NeoPHP\Package\Security\Hasher\UserPasswordHasher;

class AuthenticationManager
{
    public function __construct(protected UserPasswordHasher $hasher, protected ?Closure $csrf = null)
    {
    }

    public function authenticate(Request $request, Firewall $firewall, AuthenticatorInterface $authenticator): array
    {
        $throttler = $firewall->getThrottler();
        $passport = $authenticator->authenticate($request);

        if ($passport->hasPassword() && $throttler !== null) {
            $throttler->consume($request, $passport->getUserIdentifier());
        }

        $this->checkCsrf($passport);
        $user = $this->loadUser($passport, $firewall);
        $firewall->getUserChecker()?->checkPreAuth($user);
        $this->checkPassword($passport, $user, $firewall);

        foreach ($passport->getChecks() as $check) {
            $check($user);
        }

        $firewall->getUserChecker()?->checkPostAuth($user);
        $token = $authenticator->createToken($passport, $firewall->getName());

        if ($passport->hasPassword() && $throttler !== null) {
            $throttler->reset($request, $passport->getUserIdentifier());
        }

        $passport->erasePassword();

        return [$token, $passport];
    }

    public function loadUser(Passport $passport, Firewall $firewall): UserInterface
    {
        $user = $passport->getUser();

        if ($user !== null) {
            return $user;
        }

        $identifier = $passport->getUserIdentifier();

        try {
            $loader = $passport->getUserLoader();
            $user = $loader !== null ? $loader($identifier) : $firewall->getProvider()->loadUserByIdentifier($identifier);
        } catch (UserNotFoundException $exception) {
            if ($passport->hasPassword()) {
                $this->simulatePasswordCheck((string) $passport->getPassword());

                throw new BadCredentialsException('Invalid credentials.', 0, $exception);
            }

            throw $exception;
        }

        if (!$user instanceof UserInterface) {
            if ($passport->hasPassword()) {
                $this->simulatePasswordCheck((string) $passport->getPassword());
            }

            throw $passport->hasPassword()
                ? new BadCredentialsException('Invalid credentials.')
                : new UserNotFoundException('The user "{identifier}" does not exist.', 0, null, ['identifier' => $identifier]);
        }

        $passport->setUser($user);

        return $user;
    }

    protected function checkPassword(Passport $passport, UserInterface $user, Firewall $firewall): void
    {
        if (!$passport->hasPassword()) {
            return;
        }

        $plain = (string) $passport->getPassword();

        if (!$user instanceof PasswordAuthenticatedUserInterface || $user->getPassword() === null || $user->getPassword() === '') {
            $this->simulatePasswordCheck($plain);

            throw new BadCredentialsException('Invalid credentials.');
        }

        if ($plain === '' || !$this->hasher->isPasswordValid($user, $plain)) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        if ($this->hasher->needsRehash($user)) {
            $provider = $firewall->hasProvider() ? $firewall->getProvider() : null;

            if ($provider instanceof PasswordUpgraderInterface) {
                $provider->upgradePassword($user, $this->hasher->hashPassword($user, $plain));
            }
        }
    }

    protected function simulatePasswordCheck(string $plain): void
    {
        if ($plain !== '' && strlen($plain) <= PasswordHasherInterface::MAX_PASSWORD_LENGTH) {
            $this->hasher->getPasswordHasher()->hash($plain);
        }
    }

    protected function checkCsrf(Passport $passport): void
    {
        $csrf = $passport->getBadge(Passport::BADGE_CSRF);

        if (!is_array($csrf)) {
            return;
        }

        $manager = $this->csrf !== null ? ($this->csrf)() : null;

        if (!$manager instanceof CsrfManagerInterface || !$manager->isTokenValid((string) $csrf['id'], is_string($csrf['token'] ?? null) ? $csrf['token'] : null)) {
            throw new InvalidCsrfTokenException('Invalid CSRF token.');
        }
    }
}