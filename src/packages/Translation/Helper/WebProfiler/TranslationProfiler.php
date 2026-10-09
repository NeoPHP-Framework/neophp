<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Helper\WebProfiler;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Translation\Helper\Event\LocaleListener;
use NeoPHP\Package\Translation\Trace\TranslationTrace;
use NeoPHP\Package\Translation\TranslationManager;
use NeoPHP\Package\Translation\TranslationManagerInterface;
use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\KeyValueBlock;
use NeoPHP\Package\WebProfiler\Block\MetricBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Block\TabsBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Model\Metric;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use Throwable;

/**
 * @internal
 */
class TranslationProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface
{
    public const PRIORITY = 60;

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        if (!$this->container->bound(TranslationManagerInterface::class) || !$this->container->resolved(TranslationManagerInterface::class)) {
            return [];
        }

        $translator = $this->container->get(TranslationManagerInterface::class);

        if (!$translator instanceof TranslationManagerInterface) {
            return [];
        }

        $locale = $translator->getLocale();
        $source = $request->attributes->get(LocaleListener::SOURCE_ATTRIBUTE);
        $data = [
            'locale' => $locale,
            'default_locale' => $translator->getDefaultLocale(),
            'fallbacks' => $translator->getFallbackLocales($locale),
            'locales' => $translator->getLocales(),
            'default_domain' => $translator->getDefaultDomain(),
            'detection' => is_string($source) ? $source : (count($translator->getLocales()) < 2 ? 'default (single locale)' : 'default'),
            'detection_order' => (array) ($translator->getConfig()['detection']['order'] ?? []),
            'path' => $translator->getPath(),
            'catalogues' => [],
            'messages' => [],
            'dropped' => 0,
            'tracing' => false,
        ];

        if (!$translator instanceof TranslationManager) {
            return $data;
        }

        $data['catalogues'] = $this->catalogues($translator);
        $trace = $translator->getTrace();

        if ($trace !== null) {
            $data['tracing'] = true;
            $data['messages'] = $trace->getMessages();
            $data['dropped'] = $trace->getDropped();
            $trace->reset();
        }

        return $data;
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        if (!isset($data['locale'])) {
            return null;
        }

        $counts = $this->counts($data);
        $status = match (true) {
            $counts[TranslationTrace::STATE_MISSING] > 0 => Status::WARNING,
            $counts[TranslationTrace::STATE_FALLBACK] > 0 => Status::INFO,
            default => Status::DEFAULT,
        };

        return new ToolbarItem('Translation', (string) $data['locale'], 'info', $status, [
            'Locale' => (string) $data['locale'],
            'Fallbacks' => implode(', ', (array) ($data['fallbacks'] ?? [])) ?: 'none',
            'Messages' => (string) array_sum($counts),
            'Defined' => (string) $counts[TranslationTrace::STATE_DEFINED],
            'Fallback' => (string) $counts[TranslationTrace::STATE_FALLBACK],
            'Missing' => (string) $counts[TranslationTrace::STATE_MISSING],
        ]);
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        if (!isset($data['locale'])) {
            return null;
        }

        $counts = $this->counts($data);
        $messages = (array) ($data['messages'] ?? []);
        $missing = $counts[TranslationTrace::STATE_MISSING];
        $blocks = [
            new MetricBlock([
                new Metric('Locale', (string) $data['locale']),
                new Metric('Defined', $counts[TranslationTrace::STATE_DEFINED], null, Status::SUCCESS),
                new Metric('Fallback', $counts[TranslationTrace::STATE_FALLBACK], null, $counts[TranslationTrace::STATE_FALLBACK] > 0 ? Status::INFO : Status::DEFAULT),
                new Metric('Missing', $missing, null, $missing > 0 ? Status::WARNING : Status::DEFAULT),
            ]),
        ];

        if (!(bool) ($data['tracing'] ?? false)) {
            $blocks[] = new AlertBlock('Translations are not traced (the web profiler was disabled when the translator was built).', Status::INFO);
        }

        if ($missing > 0) {
            $blocks[] = new AlertBlock(sprintf('%d message(s) are missing: php bin/neo translation:generate adds the missing keys.', $missing), Status::WARNING);
        }

        if ((int) ($data['dropped'] ?? 0) > 0) {
            $blocks[] = new AlertBlock(sprintf('%d messages were not recorded (limit of %d reached).', (int) $data['dropped'], TranslationTrace::MAX_MESSAGES), Status::WARNING);
        }

        $catalogues = (array) ($data['catalogues'] ?? []);
        $headers = ['Locale', 'Domain', 'Id', 'Count', 'Result', 'Parameters'];

        $blocks[] = new TabsBlock([
            sprintf('Missing (%d)', $missing) => [new TableBlock($headers, $this->rows($messages, TranslationTrace::STATE_MISSING, false), null, 'No missing message.')],
            sprintf('Fallback (%d)', $counts[TranslationTrace::STATE_FALLBACK]) => [new TableBlock(['Locale', 'Resolved', 'Domain', 'Id', 'Count', 'Result', 'Parameters'], $this->rows($messages, TranslationTrace::STATE_FALLBACK, true), null, 'No message resolved from a fallback locale.')],
            sprintf('Defined (%d)', $counts[TranslationTrace::STATE_DEFINED]) => [new TableBlock($headers, $this->rows($messages, TranslationTrace::STATE_DEFINED, false), null, 'No translated message.')],
            'Locale' => [new KeyValueBlock([
                'Current' => (string) $data['locale'],
                'Default' => (string) ($data['default_locale'] ?? ''),
                'Fallbacks' => implode(', ', (array) ($data['fallbacks'] ?? [])) ?: 'none',
                'Enabled' => implode(', ', (array) ($data['locales'] ?? [])) ?: 'none',
                'Detected from' => (string) ($data['detection'] ?? 'default'),
                'Detection order' => implode(', ', (array) ($data['detection_order'] ?? [])) ?: 'none',
                'Default domain' => (string) ($data['default_domain'] ?? ''),
                'Path' => (string) ($data['path'] ?? ''),
            ])],
            sprintf('Catalogues (%d)', count($catalogues)) => [new TableBlock(['Locale', 'Domain', 'Messages', 'Files'], array_map(static fn (array $catalogue): array => [
                $catalogue['locale'],
                $catalogue['domain'],
                $catalogue['messages'],
                implode(', ', $catalogue['files']) ?: 'runtime (addMessages)',
            ], $catalogues), null, 'No catalogue loaded during this request.')],
        ]);

        return new Panel('Translation', 'info', $blocks, $missing > 0 ? $missing : null);
    }

    protected function catalogues(TranslationManager $translator): array
    {
        $catalogues = [];

        foreach ($translator->getLoadedCatalogues() as $locale => $domains) {
            $files = [];

            foreach ($translator->getResources((string) $locale) as $resource) {
                $files[$resource['domain']][] = $resource['file'];
            }

            foreach ($domains as $domain => $count) {
                $catalogues[] = ['locale' => (string) $locale, 'domain' => (string) $domain, 'messages' => $count, 'files' => $files[$domain] ?? []];
            }
        }

        return $catalogues;
    }

    protected function rows(array $messages, string $state, bool $resolved): array
    {
        $rows = [];

        foreach ($messages as $message) {
            if (!is_array($message) || ($message['state'] ?? null) !== $state) {
                continue;
            }

            $parameters = [];

            foreach ((array) ($message['parameters'] ?? []) as $key => $value) {
                $parameters[] = $key . ': ' . $value;
            }

            $row = [(string) ($message['locale'] ?? '')];

            if ($resolved) {
                $row[] = (string) ($message['resolved_locale'] ?? '');
            }

            $rows[] = [...$row, (string) ($message['domain'] ?? ''), (string) ($message['id'] ?? ''), (int) ($message['count'] ?? 1), (string) ($message['result'] ?? ''), implode(', ', $parameters)];
        }

        return $rows;
    }

    protected function counts(array $data): array
    {
        $counts = [TranslationTrace::STATE_DEFINED => 0, TranslationTrace::STATE_FALLBACK => 0, TranslationTrace::STATE_MISSING => 0];

        foreach ((array) ($data['messages'] ?? []) as $message) {
            if (is_array($message) && isset($counts[$message['state'] ?? ''])) {
                $counts[$message['state']]++;
            }
        }

        return $counts;
    }
}