<?php

declare(strict_types=1);

namespace NeoPHP\Component\Flash;

use NeoPHP\Component\Flash\Provider\FlashProvider;
use NeoPHP\Component\Flash\Trace\FlashTrace;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Session\SessionManager;
use NeoPHP\Component\Session\SessionManagerInterface;

#[Component(provider: FlashProvider::class, requires: [SessionManager::class])]
final class FlashManager implements FlashManagerInterface
{
    public const DEFAULT_KEY = '_flashes';

    protected SessionManagerInterface $session;

    protected string $key = self::DEFAULT_KEY;

    protected ?FlashTrace $trace = null;

    public function __construct(SessionManagerInterface $session, string $key = self::DEFAULT_KEY)
    {
        $this->session = $session;
        $this->key = $key;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setTrace(?FlashTrace $trace): static
    {
        $this->trace = $trace;

        return $this;
    }

    public function getTrace(): ?FlashTrace
    {
        return $this->trace;
    }

    public function add(string $type, string $message): static
    {
        $flashes = $this->peekAll();
        $flashes[$type][] = $message;
        $this->session->set($this->key, $flashes);
        $this->trace?->add($type, $message);

        return $this;
    }

    public function get(string $type): array
    {
        $flashes = $this->peekAll();
        $messages = $flashes[$type] ?? [];

        if ($messages !== []) {
            unset($flashes[$type]);
            $this->store($flashes);
            $this->trace?->read([$type => $messages], 'get');
        }

        return $messages;
    }

    public function peek(string $type): array
    {
        return $this->peekAll()[$type] ?? [];
    }

    public function all(): array
    {
        $flashes = $this->peekAll();

        if ($flashes !== []) {
            $this->store([]);
            $this->trace?->read($flashes, 'all');
        }

        return $flashes;
    }

    public function peekAll(): array
    {
        $flashes = $this->session->get($this->key, []);

        return is_array($flashes) ? $flashes : [];
    }

    public function has(string $type): bool
    {
        return $this->peek($type) !== [];
    }

    public function clear(): static
    {
        if ($this->session->has($this->key)) {
            $this->session->remove($this->key);
        }

        return $this;
    }

    protected function store(array $flashes): void
    {
        if ($flashes === []) {
            $this->session->remove($this->key);

            return;
        }

        $this->session->set($this->key, $flashes);
    }
}