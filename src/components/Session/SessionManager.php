<?php

declare(strict_types=1);

namespace NeoPHP\Component\Session;

use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Session\Exception\SessionException;
use NeoPHP\Component\Session\Provider\SessionProvider;

#[Component(provider: SessionProvider::class)]
final class SessionManager implements SessionManagerInterface
{
    public const DEFAULT_OPTIONS = [
        'name' => 'NEOSESSID',
        'lifetime' => 0,
        'gc_maxlifetime' => 1440,
        'save_path' => null,
        'cookie_path' => '/',
        'cookie_domain' => null,
        'cookie_secure' => false,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ];

    protected array $options = self::DEFAULT_OPTIONS;

    protected bool $started = false;

    protected bool $previous = false;

    public function __construct(array $options = [], bool $previous = false)
    {
        $this->options = array_replace(static::DEFAULT_OPTIONS, array_intersect_key($options, static::DEFAULT_OPTIONS));
        $this->previous = $previous;
    }

    public function start(): void
    {
        if ($this->isStarted()) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        if (headers_sent($file, $line)) {
            throw new SessionException('Unable to start the session: headers already sent in "{file}" at line {line}.', 0, null, ['file' => $file, 'line' => $line]);
        }

        $this->configure();

        if (!session_start()) {
            throw new SessionException('Unable to start the session.');
        }

        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started && session_status() === PHP_SESSION_ACTIVE;
    }

    public function getId(): string
    {
        return $this->isStarted() ? (string) session_id() : '';
    }

    public function getName(): string
    {
        return (string) $this->options['name'];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->readable() && array_key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
    }

    public function set(string $key, mixed $value): static
    {
        $this->start();
        $_SESSION[$key] = $value;

        return $this;
    }

    public function has(string $key): bool
    {
        return $this->readable() && array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): mixed
    {
        if (!$this->readable() || !array_key_exists($key, $_SESSION)) {
            return null;
        }

        $value = $_SESSION[$key];
        unset($_SESSION[$key]);

        return $value;
    }

    public function all(): array
    {
        return $this->readable() ? $_SESSION : [];
    }

    public function clear(): static
    {
        if ($this->readable()) {
            $_SESSION = [];
        }

        return $this;
    }

    public function regenerate(bool $destroy = true): static
    {
        $this->start();

        if (!session_regenerate_id($destroy)) {
            throw new SessionException('Unable to regenerate the session id.');
        }

        return $this;
    }

    public function invalidate(): static
    {
        $this->start();
        $_SESSION = [];

        if (!session_regenerate_id(true)) {
            throw new SessionException('Unable to regenerate the session id.');
        }

        return $this;
    }

    public function save(): void
    {
        if ($this->isStarted()) {
            session_write_close();
        }

        $this->started = false;
    }

    protected function readable(): bool
    {
        if ($this->isStarted()) {
            return true;
        }

        if (!$this->previous && session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $this->start();

        return true;
    }

    protected function configure(): void
    {
        $savePath = $this->options['save_path'];

        if ($savePath !== null && $savePath !== '') {
            if (!is_dir($savePath) && !mkdir($savePath, 0700, true) && !is_dir($savePath)) {
                throw new SessionException('Unable to create the session directory "{directory}".', 0, null, ['directory' => $savePath]);
            }

            session_save_path((string) $savePath);
        }

        session_name((string) $this->options['name']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) (int) $this->options['gc_maxlifetime']);

        session_set_cookie_params([
            'lifetime' => (int) $this->options['lifetime'],
            'path' => (string) $this->options['cookie_path'],
            'domain' => (string) ($this->options['cookie_domain'] ?? ''),
            'secure' => (bool) $this->options['cookie_secure'],
            'httponly' => (bool) $this->options['cookie_httponly'],
            'samesite' => (string) $this->options['cookie_samesite'],
        ]);
    }
}