<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Helper\Profiler;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\HtmlBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use NeoPHP\Package\WebProfiler\Profiler;
use Throwable;

class AjaxProfiler extends AbstractProfiler implements ProfilerInterface
{
    public const PRIORITY = 280;

    public const HEADER_PARENT = 'X-Debug-Parent';

    public const TOKEN_PATTERN = '/^[a-zA-Z0-9]{1,64}$/';

    public const SCAN_LIMIT = 300;

    public const MAX_CHILDREN = 100;

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        $parent = (string) $request->headers->get(self::HEADER_PARENT, '');
        $requestedWith = $request->headers->get('X-Requested-With');
        $destination = $request->headers->get('Sec-Fetch-Dest');
        $referer = $request->headers->get('Referer');
        $ajax = $parent !== '' || strcasecmp((string) $requestedWith, 'XMLHttpRequest') === 0 || $destination === 'empty';
        $source = null;

        if (preg_match(self::TOKEN_PATTERN, $parent) === 1) {
            $source = 'toolbar';
        } else {
            $parent = '';
        }

        if ($ajax && $parent === '' && is_string($referer) && $referer !== '') {
            $parent = $this->findPage($referer, (string) ($request->getClientIp() ?? ''));
            $source = $parent !== '' ? 'referer' : null;
        }

        return [
            'ajax' => $ajax,
            'parent' => $parent !== '' ? $parent : null,
            'parent_source' => $source,
            'referer' => $referer,
            'requested_with' => $requestedWith,
            'fetch_dest' => $destination,
        ];
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        $profiler = $this->profiler();

        if ($profiler === null) {
            return null;
        }

        $blocks = [];

        if ($data['ajax'] ?? false) {
            $parent = $data['parent'] ?? null;
            $blocks[] = is_string($parent)
                ? new HtmlBlock('<p>This request is an Ajax call of the page <a href="' . $this->e($profiler->getProfileUrl($parent)) . '">' . $this->e($parent) . '</a>' . (($data['parent_source'] ?? null) === 'referer' ? ' (found with the Referer header)' : '') . '.</p>')
                : new AlertBlock('This request is an Ajax call, but its page could not be found (no toolbar on the page and no matching Referer).', Status::INFO);
        }

        $children = $this->children($profiler, $profile);

        if ($children === []) {
            $blocks[] = new AlertBlock(($data['ajax'] ?? false)
                ? 'This request made no Ajax call.'
                : 'No Ajax request recorded for this page yet. Calls made with fetch() or XMLHttpRequest after the page was loaded are listed here: reload this panel after using the page.', Status::DEFAULT);
        } else {
            $blocks[] = new HtmlBlock($this->table($profiler, $children));
        }

        $errors = count(array_filter($children, static fn (array $child): bool => (int) ($child['status'] ?? 0) >= 400));

        return new Panel('Ajax', 'ajax', $blocks, $children !== [] ? count($children) : null, $errors > 0 ? Status::DANGER : Status::DEFAULT);
    }

    protected function children(Profiler $profiler, Profile $profile): array
    {
        $children = [];

        try {
            $entries = $profiler->find(self::SCAN_LIMIT, ['ip' => $profile->getIp()]);
        } catch (Throwable) {
            return [];
        }

        foreach (array_reverse($entries) as $entry) {
            $token = (string) ($entry['token'] ?? '');

            if ($token === $profile->getToken() || (int) ($entry['time'] ?? 0) < $profile->getTime()) {
                continue;
            }

            $child = $profiler->load($token);

            if ($child !== null && ($child->getData($this->getName())['parent'] ?? null) === $profile->getToken()) {
                $children[] = $entry;

                if (count($children) >= self::MAX_CHILDREN) {
                    break;
                }
            }
        }

        return $children;
    }

    protected function table(Profiler $profiler, array $children): string
    {
        $html = '<div class="neo-table-wrap"><table class="neo-table"><thead><tr><th>Time</th><th>Method</th><th>Status</th><th>URL</th><th>Duration</th><th>Profile</th></tr></thead><tbody>';

        foreach ($children as $child) {
            $token = (string) $child['token'];
            $status = (int) ($child['status'] ?? 0);
            $html .= '<tr>'
                . '<td>' . $this->e(date('H:i:s', (int) ($child['time'] ?? 0))) . '</td>'
                . '<td>' . $this->e((string) ($child['method'] ?? '')) . '</td>'
                . '<td class="neo-status neo-status-code-' . intdiv($status, 100) . '">' . $status . '</td>'
                . '<td>' . $this->e((string) ($child['url'] ?? '')) . '</td>'
                . '<td>' . $this->e(sprintf('%.1f ms', (float) ($child['duration'] ?? 0))) . '</td>'
                . '<td><a href="' . $this->e($profiler->getProfileUrl($token)) . '">' . $this->e($token) . '</a></td>'
                . '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    protected function findPage(string $referer, string $ip): string
    {
        $profiler = $this->profiler();

        if ($profiler === null) {
            return '';
        }

        try {
            foreach ($profiler->find(20, ['ip' => $ip, 'method' => 'GET', 'url' => $referer]) as $entry) {
                if (($entry['url'] ?? null) === $referer) {
                    return (string) $entry['token'];
                }
            }
        } catch (Throwable) {
            return '';
        }

        return '';
    }

    protected function profiler(): ?Profiler
    {
        try {
            $profiler = $this->container->get(Profiler::class);
        } catch (Throwable) {
            return null;
        }

        return $profiler instanceof Profiler ? $profiler : null;
    }

    protected function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}