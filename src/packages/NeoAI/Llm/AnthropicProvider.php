<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Llm;

use NeoPHP\Package\NeoAI\Contract\AbstractProvider;
use NeoPHP\Package\NeoAI\Model\ChatResponse;
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\Model\Message;

class AnthropicProvider extends AbstractProvider
{
    public const TYPE = 'anthropic';

    public const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';

    public const VERSION = '2023-06-01';

    public function chat(Conversation|array $messages, array $options = []): ChatResponse
    {
        $payload = $this->payload($this->messages($messages), $options);
        $headers = ['x-api-key' => $this->apiKey(), 'anthropic-version' => (string) ($this->config['version'] ?? self::VERSION)];

        return $this->parse($this->post($this->getBaseUrl() . '/messages', $payload, $headers));
    }

    public function payload(array $messages, array $options = []): array
    {
        $system = [];
        $turns = [];

        foreach ($messages as $message) {
            if ($message->getRole() === Message::SYSTEM) {
                $system[] = $message->getContent();
                continue;
            }

            $last = array_key_last($turns);

            if ($last !== null && $turns[$last]['role'] === $message->getRole()) {
                $turns[$last]['content'] .= "\n\n" . $message->getContent();
                continue;
            }

            $turns[] = ['role' => $message->getRole(), 'content' => $message->getContent()];
        }

        if ($turns === [] || $turns[0]['role'] !== Message::USER) {
            array_unshift($turns, ['role' => Message::USER, 'content' => '(start)']);
        }

        $payload = [
            'model' => $this->requireModel($options),
            'max_tokens' => max(1, (int) $this->option($options, 'max_tokens') ?: 2048),
            'messages' => $turns,
            'temperature' => (float) $this->option($options, 'temperature'),
        ];

        if ($system !== []) {
            $payload['system'] = implode("\n\n", $system);
        }

        return $payload;
    }

    public function parse(array $data): ChatResponse
    {
        $text = '';

        foreach ((array) ($data['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? 'text') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return new ChatResponse(
            $text,
            (string) ($data['model'] ?? $this->getModel()),
            isset($data['stop_reason']) ? (string) $data['stop_reason'] : null,
            (int) ($data['usage']['input_tokens'] ?? 0),
            (int) ($data['usage']['output_tokens'] ?? 0),
            $data,
        );
    }
}