<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\TwoFactor;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\Security\Contract\TwoFactorUserInterface;
use NeoPHP\Package\Security\Firewall\HttpUtils;

class TwoFactorManager
{
    public const PENDING = '_security.two_factor.pending';

    public const ATTEMPTS = '_security.two_factor.attempts';

    public const LAST_STEP = '_security.two_factor.last_step';

    public const DEFAULT_OPTIONS = ['path' => '/2fa', 'window' => 1, 'max_attempts' => 5];

    protected array $options;

    public function __construct(protected HttpUtils $http, protected Totp $totp, protected bool $enabled = false, array $options = [])
    {
        $this->options = array_replace(self::DEFAULT_OPTIONS, $options);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getPath(): string
    {
        return (string) $this->options['path'];
    }

    public function start(): void
    {
        $session = $this->http->getSession();
        $session->set(self::PENDING, true);
        $session->remove(self::ATTEMPTS);
    }

    public function isPending(Request $request): bool
    {
        $session = $this->http->getSession();

        if (!$session->isStarted() && !$request->cookies->has($session->getName())) {
            return false;
        }

        return (bool) $session->get(self::PENDING, false);
    }

    public function verify(TwoFactorUserInterface $user, string $code): bool
    {
        $secret = $user->getTotpSecret();

        if ($secret === null || !$this->canAttempt()) {
            return false;
        }

        $session = $this->http->getSession();
        $step = $this->totp->verify($secret, $code, (int) $this->options['window']);

        if ($step === null || $step <= (int) $session->get(self::LAST_STEP, 0)) {
            $this->fail();

            return false;
        }

        $session->set(self::LAST_STEP, $step);
        $this->complete();

        return true;
    }

    public function verifyBackupCode(TwoFactorUserInterface $user, string $code): ?string
    {
        if (!$this->canAttempt()) {
            return null;
        }

        $hash = $this->totp->hashBackupCode($code);

        foreach ($user->getBackupCodeHashes() as $candidate) {
            if (hash_equals((string) $candidate, $hash)) {
                $this->complete();

                return $hash;
            }
        }

        $this->fail();

        return null;
    }

    protected function canAttempt(): bool
    {
        $session = $this->http->getSession();

        if ((int) $session->get(self::ATTEMPTS, 0) < (int) $this->options['max_attempts']) {
            return true;
        }

        $session->invalidate();

        return false;
    }

    protected function fail(): void
    {
        $session = $this->http->getSession();
        $session->set(self::ATTEMPTS, (int) $session->get(self::ATTEMPTS, 0) + 1);
    }

    protected function complete(): void
    {
        $session = $this->http->getSession();
        $session->remove(self::PENDING);
        $session->remove(self::ATTEMPTS);
        $session->regenerate(true);
    }
}