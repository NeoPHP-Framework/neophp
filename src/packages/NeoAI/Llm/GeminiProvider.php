<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Llm;

use NeoPHP\Package\NeoAI\Contract\AbstractProvider;
use NeoPHP\Package\NeoAI\Model\ChatResponse;
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\Model\Message;

class GeminiProvider extends AbstractProvider
{
    public const TYPE = 'gemini';

    public const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function chat(Conversation|array $messages, array $options = []): ChatResponse
    {
        $model = $this->requireModel($options);
        $url = $this->getBaseUrl() . '/models/' . rawurlencode($model) . ':generateContent';

        return $this->parse($this->post($url, $this->payload($this->messages($messages), $options), ['x-goog-api-key' => $this->apiKey()]));
    }

    public function payload(array $messages, array $options = []): array
    {
        $system = [];
        $contents = [];

        foreach ($messages as $message) {
            if ($message->getRole() === Message::SYSTEM) {
                $system[] = ['text' => $message->getContent()];
                continue;
            }

            $role = $message->getRole() === Message::ASSISTANT ? 'model' : 'user';
            $last = array_key_last($contents);

            if ($last !== null && $contents[$last]['role'] === $role) {
                $contents[$last]['parts'][] = ['text' => $message->getContent()];
                continue;
            }

            $contents[] = ['role' => $role, 'parts' => [['text' => $message->getContent()]]];
        }

        $generation = ['temperature' => (float) $this->option($options, 'temperature')];
        $max = (int) $this->option($options, 'max_tokens');

        if ($max > 0) {
            $generation['maxOutputTokens'] = $max;
        }

        $payload = ['contents' => $contents, 'generationConfig' => $generation];

        if ($system !== []) {
            $payload['systemInstruction'] = ['parts' => $system];
        }

        return $payload;
    }

    public function parse(array $data): ChatResponse
    {
        $candidate = (array) ($data['candidates'][0] ?? []);
        $text = '';

        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            $text .= is_array($part) ? (string) ($part['text'] ?? '') : '';
        }

        return new ChatResponse(
            $text,
            (string) ($data['modelVersion'] ?? $this->getModel()),
            isset($candidate['finishReason']) ? (string) $candidate['finishReason'] : null,
            (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
            (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            $data,
        );
    }
}