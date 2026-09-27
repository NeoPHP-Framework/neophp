<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Controller;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Package\NeoAI\Exception\AuthenticationException;
use NeoPHP\Package\NeoAI\Exception\ConfigurationException;
use NeoPHP\Package\NeoAI\Exception\NeoAiException;
use NeoPHP\Package\NeoAI\Exception\RateLimitException;
use NeoPHP\Package\NeoAI\Exception\SecurityException;
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\NeoAiManager;
use Throwable;

class NeoAiController
{
    public const HEADERS = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(protected NeoAiManager $manager)
    {
    }

    public function chat(Request $request): JsonResponse
    {
        if (!$this->manager->isWebEnabled()) {
            return $this->error('Not found.', 404);
        }

        if (strlen($request->getContent()) > (int) $this->manager->getConfig()['web']['max_request_bytes']) {
            return $this->error('Request too large.', 413);
        }

        try {
            $this->manager->guard()->check($request);
        } catch (SecurityException $exception) {
            return $this->error($exception->getMessage(), 403);
        }

        if (!str_contains(strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
            return $this->error('A JSON body is expected.', 415);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON body.', 400);
        }

        $message = trim((string) ($data['message'] ?? ''));
        $max = (int) $this->manager->getConfig()['web']['max_message_chars'];

        if ($message === '' || mb_strlen($message) > $max) {
            return $this->error(sprintf('The message is required and limited to %d characters.', $max), 400);
        }

        $connection = is_string($data['connection'] ?? null) && $this->manager->hasConnection($data['connection']) ? $data['connection'] : null;
        $storage = $this->manager->conversations();
        $id = is_string($data['conversation_id'] ?? null) ? $data['conversation_id'] : '';
        $conversation = ($id !== '' ? $storage->load($id) : null) ?? Conversation::create(['source' => 'web']);

        try {
            $assistant = $this->manager->assistant($connection);
            $reply = $assistant->ask($conversation, $message, $this->attachments($data));
            $storage->save($conversation);
        } catch (AuthenticationException|ConfigurationException|SecurityException $exception) {
            return $this->error($this->manager->redactor()->redact($exception->getMessage()), 503);
        } catch (RateLimitException $exception) {
            return $this->error($this->manager->redactor()->redact($exception->getMessage()), 429);
        } catch (NeoAiException $exception) {
            return $this->error($this->manager->redactor()->redact($exception->getMessage()), 502);
        } catch (Throwable $exception) {
            return $this->error($this->manager->redactor()->redact($exception::class . ': ' . $exception->getMessage()), 500);
        }

        $provider = $assistant->getProvider();

        return new JsonResponse([
            ...$reply->toArray(),
            'conversation_id' => $conversation->getId(),
            'connection' => $provider->getName(),
            'provider' => $provider->getType(),
            'remote' => !$provider->isLocal(),
        ], 200, self::HEADERS);
    }

    protected function attachments(array $data): array
    {
        $attachments = [];
        $token = $data['profile_token'] ?? null;

        if (is_string($token) && preg_match('/^[a-zA-Z0-9]{1,64}$/', $token) === 1) {
            $profile = $this->manager->loadProfile($token);

            if ($profile !== null) {
                $summary = $profile['summary'];
                $exception = $profile['data']['exception'] ?? [];
                $attachments['Profile ' . $token . ' (use the profile tool with this token for details: ' . implode(', ', array_keys($profile['data'])) . ')'] = (string) json_encode([
                    'summary' => $summary,
                    'exception' => $exception !== [] ? array_slice((array) $exception, 0, 6, true) : null,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
        }

        $page = $data['page'] ?? null;

        if (is_array($page)) {
            $attachments['Current page (captured in the browser)'] = (string) json_encode($this->page($page), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return $attachments;
    }

    protected function page(array $page): array
    {
        $text = static fn (mixed $value, int $limit): string => mb_substr(is_scalar($value) ? (string) $value : '', 0, $limit);
        $list = static fn (mixed $value, int $count, int $limit): array => array_map(static fn (mixed $item): string => mb_substr(is_scalar($item) ? (string) $item : (string) json_encode($item), 0, $limit), array_slice(is_array($value) ? array_values($value) : [], 0, $count));

        return [
            'url' => $text($page['url'] ?? '', 500),
            'title' => $text($page['title'] ?? '', 200),
            'headings' => $list($page['headings'] ?? [], 30, 160),
            'forms' => $list($page['forms'] ?? [], 10, 600),
            'javascript_errors' => $list($page['errors'] ?? [], 20, 500),
            'dom' => array_map(static fn (mixed $value): string|int => is_int($value) ? $value : mb_substr(is_scalar($value) ? (string) $value : '', 0, 40), array_slice(is_array($page['dom'] ?? null) ? $page['dom'] : [], 0, 12, true)),
            'visible_text' => $text($page['text'] ?? '', 6000),
        ];
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status, self::HEADERS);
    }
}