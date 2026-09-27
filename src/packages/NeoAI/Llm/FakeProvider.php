<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Llm;

use NeoPHP\Package\NeoAI\Contract\AbstractProvider;
use NeoPHP\Package\NeoAI\Model\ChatResponse;
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\Model\Message;

class FakeProvider extends AbstractProvider
{
    public const TYPE = 'fake';

    public const REQUIRES_KEY = false;

    protected static array $queue = [];

    protected static array $requests = [];

    public static function queue(string|callable ...$responses): void
    {
        array_push(self::$queue, ...$responses);
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$requests = [];
    }

    public static function getRequests(): array
    {
        return self::$requests;
    }

    public static function getLastRequest(): ?array
    {
        return self::$requests === [] ? null : self::$requests[array_key_last(self::$requests)];
    }

    public function isLocal(): bool
    {
        return true;
    }

    public function chat(Conversation|array $messages, array $options = []): ChatResponse
    {
        $messages = $this->messages($messages);
        self::$requests[] = array_map(static fn (Message $message): array => $message->toArray(), $messages);
        $next = self::$queue !== [] ? array_shift(self::$queue) : null;

        if ($next === null) {
            $configured = (array) ($this->config['responses'] ?? []);
            $next = $configured !== [] ? (string) $configured[(count(self::$requests) - 1) % count($configured)] : 'OK (fake provider).';
        }

        $content = is_callable($next) ? (string) $next($messages, $options) : $next;
        $prompt = (int) ceil(array_sum(array_map(static fn (Message $message): int => strlen($message->getContent()), $messages)) / 4);

        return new ChatResponse($content, (string) ($this->config['model'] ?: 'fake-model'), 'stop', $prompt, (int) ceil(strlen($content) / 4));
    }
}