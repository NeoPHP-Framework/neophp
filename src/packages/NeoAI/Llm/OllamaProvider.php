<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Llm;

use NeoPHP\Package\NeoAI\Contract\AbstractProvider;
use NeoPHP\Package\NeoAI\Model\ChatResponse;
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\Model\Message;

class OllamaProvider extends AbstractProvider
{
    public const TYPE = 'ollama';

    public const DEFAULT_BASE_URL = 'http://localhost:11434';

    public const REQUIRES_KEY = false;

    public function chat(Conversation|array $messages, array $options = []): ChatResponse
    {
        $key = trim((string) $this->config['api_key']);

        return $this->parse($this->post($this->getBaseUrl() . '/api/chat', $this->payload($this->messages($messages), $options), $key !== '' ? ['Authorization' => 'Bearer ' . $key] : []));
    }

    public function payload(array $messages, array $options = []): array
    {
        $settings = ['temperature' => (float) $this->option($options, 'temperature')];
        $max = (int) $this->option($options, 'max_tokens');

        if ($max > 0) {
            $settings['num_predict'] = $max;
        }

        return [
            'model' => $this->requireModel($options),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $messages),
            'stream' => false,
            'options' => $settings,
        ];
    }

    public function parse(array $data): ChatResponse
    {
        return new ChatResponse(
            (string) ($data['message']['content'] ?? ''),
            (string) ($data['model'] ?? $this->getModel()),
            isset($data['done_reason']) ? (string) $data['done_reason'] : (($data['done'] ?? false) ? 'stop' : null),
            (int) ($data['prompt_eval_count'] ?? 0),
            (int) ($data['eval_count'] ?? 0),
            $data,
        );
    }
}