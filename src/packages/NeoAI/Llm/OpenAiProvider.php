<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Llm;

use NeoPHP\Package\NeoAI\Contract\AbstractProvider;
use NeoPHP\Package\NeoAI\Model\ChatResponse;
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\Model\Message;

class OpenAiProvider extends AbstractProvider
{
    public const TYPE = 'openai';

    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public const COMPATIBLE = [
        'openai_compatible' => '',
        'mistral' => 'https://api.mistral.ai/v1',
        'groq' => 'https://api.groq.com/openai/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'lmstudio' => 'http://localhost:1234/v1',
        'vllm' => 'http://localhost:8000/v1',
    ];

    public function getType(): string
    {
        return (string) ($this->config['type'] ?? static::TYPE);
    }

    public function getBaseUrl(): string
    {
        $url = trim((string) $this->config['base_url']);

        if ($url === '') {
            $url = self::COMPATIBLE[$this->getType()] ?? static::DEFAULT_BASE_URL;
        }

        return rtrim($url !== '' ? $url : static::DEFAULT_BASE_URL, '/');
    }

    public function chat(Conversation|array $messages, array $options = []): ChatResponse
    {
        $key = $this->requiresKey() ? $this->apiKey() : trim((string) $this->config['api_key']);
        $payload = $this->payload($this->messages($messages), $options);
        $headers = $key !== '' ? ['Authorization' => 'Bearer ' . $key] : [];

        return $this->parse($this->post($this->getBaseUrl() . '/chat/completions', $payload, $headers));
    }

    public function payload(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $this->requireModel($options),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $messages),
            'temperature' => (float) $this->option($options, 'temperature'),
        ];

        $max = (int) $this->option($options, 'max_tokens');

        if ($max > 0) {
            $payload['max_tokens'] = $max;
        }

        return $payload;
    }

    public function parse(array $data): ChatResponse
    {
        $choice = (array) ($data['choices'][0] ?? []);
        $content = $choice['message']['content'] ?? '';

        if (is_array($content)) {
            $content = implode('', array_map(static fn (mixed $part): string => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part, $content));
        }

        return new ChatResponse(
            (string) $content,
            (string) ($data['model'] ?? $this->getModel()),
            isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : null,
            (int) ($data['usage']['prompt_tokens'] ?? 0),
            (int) ($data['usage']['completion_tokens'] ?? 0),
            $data,
        );
    }

    protected function requiresKey(): bool
    {
        return in_array($this->getType(), ['openai', 'mistral', 'groq', 'openrouter'], true) && !$this->isLocal();
    }
}