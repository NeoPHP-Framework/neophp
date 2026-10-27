<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure\Helper\WebProfiler;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Mercure\MercureManagerInterface;
use NeoPHP\Package\Mercure\Provider\MercureProvider;
use NeoPHP\Package\Mercure\Trace\MercureTrace;
use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\KeyValueBlock;
use NeoPHP\Package\WebProfiler\Block\MetricBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Block\TabsBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Model\Metric;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use Throwable;

/**
 * @internal
 */
class MercureProfiler extends AbstractProfiler implements ProfilerInterface
{
    public const PRIORITY = 38;

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        if (!$this->container->bound(MercureManagerInterface::class)) {
            return [];
        }

        $updates = [];
        $authorizations = [];
        $dropped = 0;

        if ($this->container->resolved(MercureTrace::class)) {
            $trace = $this->container->get(MercureTrace::class);

            if ($trace instanceof MercureTrace) {
                $updates = $trace->getUpdates();
                $authorizations = $trace->getAuthorizations();
                $dropped = $trace->getDropped();
                $trace->reset();
            }
        }

        return [
            'updates' => $updates,
            'authorizations' => $authorizations,
            'dropped' => $dropped,
            'config' => $this->config(),
        ];
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        if (!isset($data['updates'])) {
            return null;
        }

        $updates = array_values(array_filter((array) $data['updates'], 'is_array'));
        $authorizations = array_values(array_filter((array) ($data['authorizations'] ?? []), 'is_array'));
        $config = (array) ($data['config'] ?? []);
        $errors = count(array_filter($updates, static fn (array $update): bool => ($update['error'] ?? null) !== null));
        $dispatched = count(array_filter($updates, static fn (array $update): bool => ($update['mode'] ?? '') === 'dispatch'));
        $private = count(array_filter($updates, static fn (array $update): bool => (bool) ($update['private'] ?? false)));
        $duration = array_sum(array_map(static fn (array $update): float => ($update['mode'] ?? '') === 'publish' ? (float) ($update['duration'] ?? 0) : 0.0, $updates));

        $blocks = [
            new MetricBlock([
                new Metric('Published', count($updates) - $dispatched, null, $errors > 0 ? Status::DANGER : Status::DEFAULT),
                new Metric('Dispatched (queue)', $dispatched),
                new Metric('Private', $private),
                new Metric('Authorizations', count($authorizations)),
                new Metric('Hub time', round($duration, 2), 'ms', Status::DEFAULT, 'Time spent waiting for the hub during this request (publish() only)'),
            ]),
        ];

        if ($errors > 0) {
            $blocks[] = new AlertBlock(sprintf('%d update(s) failed: see the Error column.', $errors), Status::DANGER);
        }

        if (($config['url'] ?? '') === '') {
            $blocks[] = new AlertBlock('The URL of the Mercure hub is not configured: set MERCURE_URL.', Status::WARNING);
        }

        if ((int) ($data['dropped'] ?? 0) > 0) {
            $blocks[] = new AlertBlock(sprintf('%d entries not recorded (limit %d per request).', (int) $data['dropped'], MercureTrace::MAX_ENTRIES), Status::WARNING);
        }

        $blocks[] = new TabsBlock([
            sprintf('Updates (%d)', count($updates)) => [new TableBlock(['#', 'Mode', 'Topics', 'Private', 'Type', 'Id', 'Data', 'Size', 'Time', 'Error'], array_map(static fn (int $index, array $update): array => [
                $index + 1,
                (string) ($update['mode'] ?? ''),
                implode(', ', array_map('strval', (array) ($update['topics'] ?? []))),
                (bool) ($update['private'] ?? false) ? 'yes' : 'no',
                (string) ($update['type'] ?? ''),
                (string) ($update['id'] ?? ''),
                (string) ($update['data'] ?? ''),
                (int) ($update['size'] ?? 0) . ' B',
                ($update['mode'] ?? '') === 'publish' ? number_format((float) ($update['duration'] ?? 0), 2) . ' ms' : 'queued',
                (string) ($update['error'] ?? ''),
            ], array_keys($updates), $updates), null, 'No update published during this request.')],
            sprintf('Authorizations (%d)', count($authorizations)) => [new TableBlock(['#', 'Action', 'Topics', 'Payload', 'Lifetime'], array_map(static fn (int $index, array $authorization): array => [
                $index + 1,
                (string) ($authorization['action'] ?? ''),
                implode(', ', array_map('strval', (array) ($authorization['topics'] ?? []))),
                (array) ($authorization['payload'] ?? []) === [] ? '' : (string) json_encode($authorization['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                isset($authorization['lifetime']) ? (int) $authorization['lifetime'] . ' s' : '',
            ], array_keys($authorizations), $authorizations), null, 'No mercureAuthorization cookie set or removed during this request.')],
            'Configuration' => [new KeyValueBlock([
                'Hub URL' => (string) ($config['url'] ?? '') ?: 'not configured',
                'Public URL' => (string) ($config['public_url'] ?? '') ?: 'same as the hub URL',
                'Publisher JWT' => (string) ($config['jwt'] ?? ''),
                'Algorithm' => (string) ($config['algorithm'] ?? ''),
                'Publish topics' => (string) ($config['publish'] ?? ''),
                'Cookie' => (string) ($config['cookie'] ?? ''),
            ])],
        ]);

        return new Panel('Mercure', 'event', $blocks, count($updates) > 0 ? count($updates) : null, $errors > 0 ? Status::DANGER : Status::DEFAULT);
    }

    protected function config(): array
    {
        $config = MercureProvider::config($this->container);
        $jwt = (array) ($config['jwt'] ?? []);
        $cookie = (array) ($config['cookie'] ?? []);
        $publicUrl = '';

        try {
            $publicUrl = $this->container->get(MercureManagerInterface::class)->getPublicUrl();
        } catch (Throwable) {
        }

        return [
            'url' => is_string($config['url'] ?? null) ? trim($config['url']) : '',
            'public_url' => is_string($config['public_url'] ?? null) && trim($config['public_url']) !== '' ? $publicUrl : '',
            'jwt' => match (true) {
                is_string($jwt['secret'] ?? null) && $jwt['secret'] !== '' => 'signed with the secret (MERCURE_JWT_SECRET)',
                is_string($jwt['token'] ?? null) && $jwt['token'] !== '' => 'static token (jwt.token)',
                default => 'missing: set MERCURE_JWT_SECRET',
            },
            'algorithm' => strtoupper((string) ($jwt['algorithm'] ?? 'HS256')),
            'publish' => implode(', ', array_map('strval', (array) ($jwt['publish'] ?? ['*']))),
            'cookie' => sprintf(
                'lifetime %d s, domain %s, secure %s, SameSite %s',
                (int) ($cookie['lifetime'] ?? MercureManagerInterface::DEFAULT_LIFETIME),
                is_string($cookie['domain'] ?? null) && $cookie['domain'] !== '' ? $cookie['domain'] : 'current host',
                is_bool($cookie['secure'] ?? null) ? ($cookie['secure'] ? 'yes' : 'no') : (string) ($cookie['secure'] ?? 'auto'),
                (string) ($cookie['samesite'] ?? 'Strict'),
            ),
        ];
    }
}