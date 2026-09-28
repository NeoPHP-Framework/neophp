<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Contract;

use JsonException;
use NeoPHP\Component\HttpClient\Contract\HttpClientInterface;
use NeoPHP\Component\HttpClient\Exception\HttpClientException;
use NeoPHP\Package\NeoAI\Exception\AuthenticationException;
use NeoPHP\Package\NeoAI\Exception\ConfigurationException;
use NeoPHP\Package\NeoAI\Exception\NetworkException;
use NeoPHP\Package\NeoAI\Exception\ProviderException;
use NeoPHP\Package\NeoAI\Exception\RateLimitException;
use NeoPHP\Package\NeoAI\Model\ChatResponse;
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\Model\Message;

abstract class AbstractProvider implements ProviderInterface
{
    public const TYPE = 'custom';

    public const DEFAULT_BASE_URL = '';

    public const REQUIRES_KEY = true;

    public const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0', 'host.docker.internal'];

    public const DEFAULTS = [
        'name' => 'default',
        'model' => '',
        'api_key' => '',
        'base_url' => '',
        'temperature' => 0.2,
        'max_tokens' => 2048,
        'timeout' => 60,
        'retries' => 2,
        'retry_delay' => 1000,
        'headers' => [],
    ];

    protected array $config;

    public function __construct(array $config = [], protected ?HttpClientInterface $http = null)
    {
        $this->config = array_replace(self::DEFAULTS, array_filter($config, static fn (mixed $value): bool => $value !== null));
    }

    public function getName(): string
    {
        return (string) $this->config['name'];
    }

    public function getType(): string
    {
        return static::TYPE;
    }

    public function getModel(): string
    {
        return (string) $this->config['model'];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getBaseUrl(): string
    {
        $url = trim((string) $this->config['base_url']);

        return rtrim($url !== '' ? $url : static::DEFAULT_BASE_URL, '/');
    }

    public function isLocal(): bool
    {
        $host = strtolower((string) parse_url($this->getBaseUrl(), PHP_URL_HOST));

        return $host !== '' && (in_array($host, self::LOCAL_HOSTS, true) || str_ends_with($host, '.local') || str_ends_with($host, '.localhost'));
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function stream(Conversation|array $messages, callable $onChunk, array $options = []): ChatResponse
    {
        $response = $this->chat($messages, $options);
        $onChunk($response->getContent());

        return $response;
    }

    protected function messages(Conversation|array $messages): array
    {
        $list = $messages instanceof Conversation ? $messages->getMessages() : $messages;
        $result = [];

        foreach ($list as $message) {
            $result[] = $message instanceof Message ? $message : Message::fromArray((array) $message);
        }

        if ($result === []) {
            throw new ProviderException('Cannot send an empty conversation to the connection "{name}".', 0, null, ['name' => $this->getName()]);
        }

        return $result;
    }

    protected function option(array $options, string $key): mixed
    {
        return $options[$key] ?? $this->config[$key] ?? null;
    }

    protected function requireModel(array $options): string
    {
        $model = trim((string) $this->option($options, 'model'));

        if ($model === '') {
            throw new ConfigurationException('No model configured for the AI connection "{name}" ({type}): set "model" in config/packages/neo_ai.yaml (e.g. NEO_AI_MODEL in .env.local).', 0, null, ['name' => $this->getName(), 'type' => $this->getType()]);
        }

        return $model;
    }

    protected function apiKey(): string
    {
        $key = trim((string) $this->config['api_key']);

        if ($key === '' && static::REQUIRES_KEY) {
            throw new ConfigurationException('Missing API key for the AI connection "{name}" ({type}): define it in .env.local and reference it with %env(...)% in config/packages/neo_ai.yaml.', 0, null, ['name' => $this->getName(), 'type' => $this->getType()]);
        }

        return $key;
    }

    protected function post(string $url, array $payload, array $headers = []): array
    {
        if ($this->http === null) {
            throw new ConfigurationException('The HttpClient component is required by the AI connection "{name}".', 0, null, ['name' => $this->getName()]);
        }

        $attempts = max(0, (int) $this->config['retries']) + 1;
        $delay = max(0, (int) $this->config['retry_delay']);
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->http->post($url, [
                    'json' => $payload,
                    'headers' => [...(array) $this->config['headers'], ...$headers, 'Accept' => 'application/json'],
                    'timeout' => (float) $this->config['timeout'],
                    'retry' => ['max_retries' => 0],
                ]);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
            } catch (HttpClientException $exception) {
                $last = new NetworkException('Network error while calling the AI connection "{name}" ({url}): {error}', 0, $exception, [
                    'name' => $this->getName(),
                    'url' => $this->safeUrl($url),
                    'error' => $exception->getMessage(),
                ]);
                $this->wait($attempt, $attempts, $delay);
                continue;
            }

            if ($status >= 200 && $status < 300) {
                return $this->decode($body);
            }

            $last = $this->error($status, $body, $url);

            if (!$last instanceof RateLimitException && $status < 500) {
                throw $last;
            }

            $this->wait($attempt, $attempts, $delay);
        }

        throw $last ?? new ProviderException('The AI connection "{name}" did not answer.', 0, null, ['name' => $this->getName()]);
    }

    protected function wait(int $attempt, int $attempts, int $delay): void
    {
        if ($attempt < $attempts && $delay > 0) {
            usleep(min(30000, $delay * (2 ** ($attempt - 1))) * 1000);
        }
    }

    protected function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException('The AI connection "{name}" returned an invalid JSON response: {error}', 0, $exception, ['name' => $this->getName(), 'error' => $exception->getMessage()]);
        }

        if (!is_array($data)) {
            throw new ProviderException('The AI connection "{name}" returned an unexpected response.', 0, null, ['name' => $this->getName()]);
        }

        return $data;
    }

    protected function error(int $status, string $body, string $url): ProviderException
    {
        $data = json_decode($body, true);
        $detail = is_array($data) ? $this->errorMessage($data) : '';
        $detail = $detail !== '' ? $detail : mb_substr(trim(strip_tags($body)), 0, 300);
        $context = ['name' => $this->getName(), 'type' => $this->getType(), 'status' => $status, 'url' => $this->safeUrl($url), 'detail' => $detail];

        return match (true) {
            $status === 401, $status === 403 => new AuthenticationException('Authentication failed for the AI connection "{name}" ({type}, HTTP {status}): check the API key. {detail}', $status, null, $context),
            $status === 429 => new RateLimitException('Rate limit or quota exceeded for the AI connection "{name}" ({type}, HTTP 429). {detail}', $status, null, $context),
            $status === 404 => new ProviderException('The AI endpoint or model was not found for the connection "{name}" ({type}, HTTP 404, {url}). {detail}', $status, null, $context),
            default => new ProviderException('The AI connection "{name}" ({type}) failed with HTTP {status}. {detail}', $status, null, $context),
        };
    }

    protected function errorMessage(array $data): string
    {
        $error = $data['error'] ?? null;

        if (is_array($error)) {
            return (string) ($error['message'] ?? $error['type'] ?? '');
        }

        if (is_string($error)) {
            return $error;
        }

        return (string) ($data['message'] ?? '');
    }

    protected function safeUrl(string $url): string
    {
        return (string) preg_replace('/([?&](key|api_key|token)=)[^&]+/i', '$1***', $url);
    }
}