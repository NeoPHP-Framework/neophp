<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Security;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\NeoAI\Exception\SecurityException;

class RequestGuard
{
    public const HEADER = 'X-Neo-AI-Token';

    public const PERIOD = 43200;

    public function __construct(protected string $secretFile)
    {
    }

    public function token(?int $time = null): string
    {
        return hash_hmac('sha256', 'neo_ai|' . intdiv($time ?? time(), self::PERIOD), $this->secret());
    }

    public function isValidToken(?string $token): bool
    {
        if ($token === null || $token === '' || strlen($token) !== 64) {
            return false;
        }

        $now = time();

        return hash_equals($this->token($now), $token) || hash_equals($this->token($now - self::PERIOD), $token);
    }

    public function isSameOrigin(Request $request): bool
    {
        $site = strtolower((string) $request->headers->get('Sec-Fetch-Site', ''));

        if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
            return false;
        }

        $expected = strtolower($request->getSchemeAndHttpHost());
        $origin = (string) $request->headers->get('Origin', '');

        if ($origin !== '' && $origin !== 'null') {
            return strtolower(rtrim($origin, '/')) === $expected;
        }

        $referer = (string) $request->headers->get('Referer', '');

        if ($referer !== '') {
            $parts = parse_url($referer);
            $host = strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));

            return $host === $expected || $host . ':' . ($request->isSecure() ? '443' : '80') === $expected;
        }

        return true;
    }

    public function check(Request $request): void
    {
        if (!$this->isSameOrigin($request)) {
            throw new SecurityException('Cross-origin requests are not allowed on the AI endpoint.', 403);
        }

        if (!$this->isValidToken($request->headers->get(self::HEADER))) {
            throw new SecurityException('Invalid or missing AI token (header {header}): reload the page.', 403, null, ['header' => self::HEADER]);
        }
    }

    protected function secret(): string
    {
        if (is_file($this->secretFile)) {
            $secret = trim((string) @file_get_contents($this->secretFile));

            if (strlen($secret) >= 32) {
                return $secret;
            }
        }

        $directory = dirname($this->secretFile);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new SecurityException('The directory "{directory}" cannot be created.', 0, null, ['directory' => $directory]);
        }

        $secret = bin2hex(random_bytes(32));

        if (@file_put_contents($this->secretFile, $secret, LOCK_EX) === false) {
            throw new SecurityException('The AI secret file "{file}" cannot be written.', 0, null, ['file' => $this->secretFile]);
        }

        @chmod($this->secretFile, 0600);

        return $secret;
    }
}