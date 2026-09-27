<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Security;

class Redactor
{
    public const MASK = '[REDACTED]';

    public const SECRET_NAMES = '(?:PASSWORD|PASSWD|PASSPHRASE|SECRET|API_?KEY|ACCESS_?KEY|PRIVATE_?KEY|TOKEN|CREDENTIALS?|AUTH|DSN|DATABASE_URL)';

    public const PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        '/\bsk-(?:ant-|proj-)?[A-Za-z0-9_\-]{16,}/',
        '/\bAIza[0-9A-Za-z_\-]{30,}/',
        '/\bgsk_[A-Za-z0-9]{20,}/',
        '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{30,}/',
        '/\bxox[abprs]-[A-Za-z0-9\-]{10,}/',
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        '/\beyJ[A-Za-z0-9_\-]{8,}\.eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/',
        '/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{16,}/',
    ];

    protected array $patterns = [];

    protected array $secrets = [];

    public function __construct(array $patterns = [], array $secrets = [], bool $environment = true)
    {
        $this->patterns = [...self::PATTERNS, ...array_values(array_filter(array_map('strval', $patterns), static fn (string $pattern): bool => @preg_match($pattern, '') !== false))];

        if ($environment) {
            $this->addEnvironmentSecrets();
        }

        foreach ($secrets as $secret) {
            $this->addSecret((string) $secret);
        }
    }

    public function addSecret(string $secret): static
    {
        $secret = trim($secret);

        if (strlen($secret) >= 6 && !in_array(strtolower($secret), ['localhost', 'default', 'null://null', 'file://default', 'true', 'false'], true)) {
            $this->secrets[$secret] = true;
        }

        return $this;
    }

    public function getSecretCount(): int
    {
        return count($this->secrets);
    }

    public function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $secrets = array_keys($this->secrets);
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($secrets as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        foreach ($this->patterns as $pattern) {
            $text = (string) (preg_replace($pattern, self::MASK, $text) ?? $text);
        }

        $text = (string) (preg_replace('#\b([a-z][a-z0-9+.\-]*://)([^:@/\s"\']+):([^@/\s"\']+)@#i', '$1$2:' . self::MASK . '@', $text) ?? $text);
        $text = (string) (preg_replace('/^([ \t]*(?:export\s+)?[A-Z0-9_]*' . self::SECRET_NAMES . '[A-Z0-9_]*[ \t]*=[ \t]*)(?![ \t]*$)(["\']?)[^\r\n]*$/m', '$1' . self::MASK, $text) ?? $text);
        $text = (string) (preg_replace('/^([ \t]*[\w.\-]*(?:password|passwd|secret|api_key|apikey|token|private_key|client_secret)[\w.\-]*[ \t]*:[ \t]*)(?![ \t]*[\'"]?%env\()(?![ \t]*$)(?![|>][ \t]*$)(?![ \t]*[\$\[{(])(?![ \t]*(?:null|true|false|string|int|bool|array)\b)[^\r\n#]+/mi', '$1' . self::MASK, $text) ?? $text);

        return (string) (preg_replace('/(\b[\w\-]*(?:password|passwd|secret|api_?key|token)[\w\-]*[\'"]?[ \t]*(?:=>|=|:)[ \t]*)([\'"])(?!%env\()([^\'"\r\n]{4,})\2/i', '$1$2' . self::MASK . '$2', $text) ?? $text);
    }

    public function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/password|passwd|secret|api_?key|token|credential|private|authorization|cookie|dsn/i', $key) === 1 && is_scalar($value) && (string) $value !== '') {
                $result[$key] = self::MASK;
                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                is_string($value) => $this->redact($value),
                default => $value,
            };
        }

        return $result;
    }

    protected function addEnvironmentSecrets(): void
    {
        $variables = [...(array) getenv(), ...$_ENV, ...$_SERVER];

        foreach ($variables as $name => $value) {
            if (!is_string($name) || !is_scalar($value) || in_array($name, ['APP_URL', 'APP_ENV', 'APP_NAME', 'APP_DEBUG'], true)) {
                continue;
            }

            if (preg_match('/' . self::SECRET_NAMES . '/i', $name) === 1 && !preg_match('/^(HTTP_|REQUEST_|SCRIPT_|PHP_SELF|DOCUMENT_|SERVER_|PATH_)/', $name)) {
                $value = (string) $value;
                $this->addSecret($value);

                if (preg_match('#://[^:@/\s]+:([^@/\s]+)@#', $value, $matches) === 1) {
                    $this->addSecret(rawurldecode($matches[1]));
                }
            }
        }
    }
}