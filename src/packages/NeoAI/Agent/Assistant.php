<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Agent;

use Closure;
use NeoPHP\Package\NeoAI\Contract\ProviderInterface;
use NeoPHP\Package\NeoAI\Exception\NeoAiException;
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\Model\Message;
use NeoPHP\Package\NeoAI\Model\Reply;
use NeoPHP\Package\NeoAI\Patch\PatchParser;
use NeoPHP\Package\NeoAI\Security\Redactor;
use NeoPHP\Package\NeoAI\Tool\ToolRunner;

class Assistant
{
    public const DIFF_BLOCK = '/```(?:diff|patch)[ \t]*\r?\n(.*?)```/s';

    public const HISTORY = 20;

    protected ?Closure $onStep = null;

    public function __construct(
        protected ProviderInterface $provider,
        protected ToolRunner $tools,
        protected PromptBuilder $prompts,
        protected Redactor $redactor,
        protected int $maxIterations = 8,
        protected int $maxContextChars = 120000,
        protected PatchParser $parser = new PatchParser(),
    ) {
    }

    public function getProvider(): ProviderInterface
    {
        return $this->provider;
    }

    public function getTools(): ToolRunner
    {
        return $this->tools;
    }

    public function onStep(?callable $callback): static
    {
        $this->onStep = $callback === null ? null : Closure::fromCallable($callback);

        return $this;
    }

    public function ask(Conversation $conversation, string $question, array $attachments = []): Reply
    {
        $question = trim($question);

        if ($question === '') {
            throw new NeoAiException('The question cannot be empty.');
        }

        $this->tools->reset();
        $user = $question;

        foreach ($attachments as $title => $content) {
            $user .= "\n\n### " . $title . "\n" . $this->tools->truncate((string) $content, (int) ($this->maxContextChars / 3));
        }

        $messages = [Message::system($this->prompts->assistant($this->tools, $this->maxIterations))];

        foreach ($conversation->getLastMessages(self::HISTORY) as $message) {
            if ($message->getRole() !== Message::SYSTEM) {
                $messages[] = $message;
            }
        }

        $messages[] = Message::user($user);
        $usage = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        $model = '';
        $content = '';
        $iteration = 0;

        while (true) {
            $iteration++;
            $response = $this->provider->chat($this->prepare($messages));
            $usage['prompt'] += $response->getPromptTokens();
            $usage['completion'] += $response->getCompletionTokens();
            $usage['total'] += $response->getTotalTokens();
            $model = $response->getModel();
            $content = $response->getContent();
            $calls = $this->tools->parse($content);

            if ($calls === []) {
                break;
            }

            if ($iteration > $this->maxIterations) {
                $content = $this->tools->strip($content);
                break;
            }

            $results = [];

            foreach ($calls as $call) {
                $this->step($call);
                $results[] = sprintf("```neo-tool-result %s\n%s\n```", (string) $call['tool'], $this->tools->run($call));
            }

            $messages[] = Message::assistant($content);
            $remaining = $this->maxIterations - $iteration;
            $messages[] = Message::user(implode("\n\n", $results) . "\n\n" . ($remaining > 0 ? sprintf('(%d tool round(s) left.)', $remaining) : 'No tool round left: answer now WITHOUT any neo-tool block.'));
        }

        $content = $this->tools->strip($content);
        $patches = $this->tools->getPatches();

        foreach ($this->inlinePatches($content) as $patch) {
            $patches[] = $patch;
        }

        if ($content === '') {
            $content = $patches !== [] ? 'See the proposed patch(es).' : '(empty answer)';
        }

        $conversation->add(Message::user($this->redactor->redact($question)));
        $conversation->add(Message::assistant($content));

        return new Reply($content, $patches, $usage, $this->tools->getHistory(), $iteration, $model);
    }

    protected function prepare(array $messages): array
    {
        $prepared = array_map(fn (Message $message): Message => $message->getRole() === Message::SYSTEM ? $message : $message->withContent($this->redactor->redact($message->getContent())), $messages);
        $size = array_sum(array_map(static fn (Message $message): int => strlen($message->getContent()), $prepared));

        while ($size > $this->maxContextChars && count($prepared) > 3) {
            $removed = array_splice($prepared, 1, 1);
            $size -= strlen($removed[0]->getContent());
        }

        return $prepared;
    }

    protected function inlinePatches(string $content): array
    {
        if (preg_match_all(self::DIFF_BLOCK, $content, $matches) === false) {
            return [];
        }

        $patches = [];

        foreach ($matches[1] as $diff) {
            try {
                $patches[] = $this->parser->toPatch($diff, 'Patch suggested in the answer', $this->tools);
            } catch (NeoAiException) {
                continue;
            }
        }

        return $patches;
    }

    protected function step(array $call): void
    {
        if ($this->onStep !== null) {
            ($this->onStep)((string) $call['tool'], (array) ($call['arguments'] ?? []));
        }
    }
}