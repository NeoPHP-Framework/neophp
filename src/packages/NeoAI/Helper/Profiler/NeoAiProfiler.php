<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Helper\Profiler;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\NeoAI\NeoAiManager;
use NeoPHP\Package\NeoAI\Provider\NeoAiProvider;
use NeoPHP\Package\NeoAI\Security\RequestGuard;
use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\HtmlBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarAssetInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use Throwable;

class NeoAiProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface, ToolbarAssetInterface
{
    public const PRIORITY = -200;

    public const ASSETS = __DIR__ . '/../../Resources/assets';

    public const ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8L12 3z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15z"/></svg>';

    public const SUGGESTIONS = [
        'Explain this request and its response.',
        'Explain the exception and propose a fix.',
        'Why is this request slow? Any N+1 queries?',
    ];

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        $manager = $this->manager();

        if ($manager === null) {
            return [];
        }

        return ['enabled' => true, ...$manager->describe()];
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        if (!($data['enabled'] ?? false) || $this->manager() === null) {
            return null;
        }

        $item = new ToolbarItem('AI', (string) ($data['model'] ?? ''), self::ICON, ($data['error'] ?? null) !== null ? Status::WARNING : Status::DEFAULT, [], null, false);
        $item->addDetail('Connection', (string) ($data['connection'] ?? ''));
        $item->addDetail('Provider', (string) ($data['provider'] ?? ''));
        $item->addDetail('Data sent to', ($data['remote'] ?? true) ? 'remote server' : 'local server');

        if (($data['error'] ?? null) !== null) {
            $item->addDetail('Error', (string) $data['error']);
        }

        return $item;
    }

    public function getToolbarAssets(Profile $profile, array $data): array
    {
        $manager = $this->manager();

        if (!($data['enabled'] ?? false) || $manager === null) {
            return [];
        }

        return [
            'css' => $this->asset('chat.css'),
            'js' => $this->script($this->config($manager, $profile, 'toolbar')),
        ];
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        if (!($data['enabled'] ?? false)) {
            return null;
        }

        $manager = $this->manager();

        if ($manager === null) {
            return new Panel('AI assistant', self::ICON, [new AlertBlock('The AI assistant is disabled (neo_ai.enabled, kernel.debug or web.enabled).', Status::WARNING)], null, Status::DEFAULT, true);
        }

        $info = $manager->describe();
        $mount = 'neo-ai-panel-' . preg_replace('/[^a-zA-Z0-9]/', '', $profile->getToken());
        $config = $this->config($manager, $profile, 'panel') + ['mount' => $mount];
        $html = '<style>' . $this->asset('chat.css') . '</style><div id="' . htmlspecialchars($mount, ENT_QUOTES) . '"></div><script>' . $this->script($config) . '</script>';
        $privacy = sprintf(
            'Questions and context (this profile, files read by the assistant) are sent to %s / %s on a %s server after secret redaction. The assistant can read the project and propose patches, it never writes files from the browser.',
            $info['provider'],
            $info['model'],
            $info['remote'] === false ? 'local' : 'remote',
        );

        return new Panel('AI assistant', self::ICON, [
            new AlertBlock($info['error'] ?? $privacy, $info['error'] !== null ? Status::WARNING : Status::INFO),
            new HtmlBlock($html),
        ], $info['remote'] === false ? 'local' : null);
    }

    protected function config(NeoAiManager $manager, Profile $profile, string $mode): array
    {
        $info = $manager->describe();

        return [
            'mode' => $mode,
            'item' => $this->getName(),
            'endpoint' => $manager->getWebPath() . '/chat',
            'header' => RequestGuard::HEADER,
            'token' => $manager->guard()->token(),
            'profileToken' => $profile->getToken(),
            'connection' => $info['connection'],
            'provider' => $info['provider'],
            'model' => $info['model'],
            'remote' => $info['remote'] !== false,
            'error' => $info['error'],
            'suggestions' => $mode === 'panel' ? self::SUGGESTIONS : [],
        ];
    }

    protected function script(array $config): string
    {
        return $this->asset('chat.js') . '(' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR) . ');';
    }

    protected function asset(string $name): string
    {
        $file = self::ASSETS . DIRECTORY_SEPARATOR . basename($name);

        return is_file($file) ? trim((string) file_get_contents($file)) : '';
    }

    protected function manager(): ?NeoAiManager
    {
        if (!$this->container->bound(NeoAiProvider::CONFIG_ID)) {
            return null;
        }

        $manager = $this->container->get(NeoAiManager::class);

        return $manager instanceof NeoAiManager && $manager->isWebEnabled() && $manager->getConnectionNames() !== [] ? $manager : null;
    }
}