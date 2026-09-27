<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Renderer;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Profiler;

class ToolbarInjector
{
    public function __construct(protected Profiler $profiler, protected TemplateRenderer $templates)
    {
    }

    public function supports(Request $request, Response $response): bool
    {
        if ($response->isRedirection() || $response->isEmpty() || $request->isXmlHttpRequest() || $request->getMethod() === 'HEAD') {
            return false;
        }

        $type = strtolower((string) $response->headers->get('Content-Type', 'text/html'));
        $disposition = strtolower((string) $response->headers->get('Content-Disposition', ''));

        if (!str_contains($type, 'text/html') || str_contains($disposition, 'attachment')) {
            return false;
        }

        return stripos($response->getContent(), '</body>') !== false;
    }

    public function inject(Request $request, Response $response, string $token): bool
    {
        if (!$this->supports($request, $response)) {
            return false;
        }

        $content = $response->getContent();
        $position = (int) strripos($content, '</body>');
        $snippet = $this->templates->render('loader', [
            'token' => $token,
            'toolbarUrl' => $this->profiler->getToolbarUrl($token),
            'profilerPath' => $this->profiler->getPath(),
            'ajaxLimit' => (int) $this->profiler->getConfig()['ajax_limit'],
        ]);

        $response->setContent(substr($content, 0, $position) . $snippet . substr($content, $position));

        return true;
    }
}