<?php

declare(strict_types=1);

namespace NeoPHP\Component\Flash\Helper\WebProfiler;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Flash\FlashManager;
use NeoPHP\Component\Flash\FlashManagerInterface;
use NeoPHP\Component\Flash\Provider\FlashProvider;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use Throwable;

/**
 * @internal
 */
class FlashProfiler extends AbstractProfiler implements ProfilerInterface
{
    public const PRIORITY = 285;

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        $flash = $this->flash();
        $key = $flash?->getKey() ?? FlashProvider::key($this->container);
        $id = session_id();
        $pending = is_string($id) && $id !== '' && isset($_SESSION) && is_array($_SESSION[$key] ?? null) ? $_SESSION[$key] : [];
        $trace = $flash?->getTrace();
        $data = ['key' => $key, 'pending' => $pending, 'traced' => $flash === null || $trace !== null, 'added' => [], 'read' => []];

        if ($trace !== null) {
            $data['added'] = $trace->getAdded();
            $data['read'] = $trace->getRead();
            $trace->reset();
        }

        return $data;
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        $pending = (array) ($data['pending'] ?? []);
        $added = (array) ($data['added'] ?? []);
        $read = (array) ($data['read'] ?? []);
        $count = array_sum(array_map(static fn (mixed $messages): int => is_array($messages) ? count($messages) : 1, $pending)) + count($added) + count($read);
        $rows = [];

        foreach ($pending as $type => $messages) {
            foreach ((array) $messages as $message) {
                $rows[] = [(string) $type, $message];
            }
        }

        $blocks = [];

        if (($data['traced'] ?? false) === true) {
            $blocks[] = new TableBlock(['Type', 'Message'], array_map(static fn (mixed $flash): array => is_array($flash) ? [(string) ($flash['type'] ?? ''), (string) ($flash['message'] ?? '')] : [], $added), 'Added during this request', 'No flash message added during this request.');
            $blocks[] = new TableBlock(['Type', 'Message', 'Read with'], array_map(static fn (mixed $flash): array => is_array($flash) ? [(string) ($flash['type'] ?? ''), (string) ($flash['message'] ?? ''), (string) ($flash['method'] ?? '') . '()'] : [], $read), 'Read (displayed) during this request', 'No flash message read during this request.');
        }

        $blocks[] = new TableBlock(['Type', 'Message'], $rows, 'Pending at the end of the request', 'No pending flash message.');
        $blocks[] = new AlertBlock(sprintf('Pending messages are still stored in the session (key "%s"): they will be displayed by the next page that reads them.', (string) ($data['key'] ?? FlashManager::DEFAULT_KEY)), Status::INFO);

        return new Panel('Flash messages', 'info', $blocks, $count > 0 ? $count : null, $count > 0 ? Status::INFO : Status::DEFAULT);
    }

    protected function flash(): ?FlashManager
    {
        try {
            if (!$this->container->resolved(FlashManagerInterface::class)) {
                return null;
            }

            $flash = $this->container->get(FlashManagerInterface::class);
        } catch (Throwable) {
            return null;
        }

        return $flash instanceof FlashManager ? $flash : null;
    }
}