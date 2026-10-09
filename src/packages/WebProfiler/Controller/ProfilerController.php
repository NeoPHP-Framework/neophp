<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Controller;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Renderer\TemplateRenderer;
use NeoPHP\Package\WebProfiler\WebProfilerManagerInterface;

class ProfilerController
{
    public const FILTERS = ['ip', 'url', 'method', 'status', 'token'];

    public const HEADERS = ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store'];

    public function __construct(protected WebProfilerManagerInterface $profiler, protected TemplateRenderer $templates)
    {
    }

    public function index(Request $request): Response
    {
        $filters = [];

        foreach (self::FILTERS as $filter) {
            $filters[$filter] = trim($request->query->getString($filter));
        }

        $limit = max(1, min(500, $request->query->getInt('limit', 50)));

        return $this->html('index', [
            'title' => 'Profiles',
            'profiles' => $this->profiler->find($limit, $filters),
            'filters' => $filters,
            'limit' => $limit,
        ]);
    }

    public function latest(): Response
    {
        $latest = $this->profiler->find(1)[0] ?? null;

        return new RedirectResponse($latest === null ? $this->profiler->getPublicPath() : $this->profiler->getProfileUrl((string) $latest['token']));
    }

    public function show(string $token, Request $request): Response
    {
        $profile = $this->profiler->load($token);

        if ($profile === null) {
            return $this->notFound($token);
        }

        $panels = $this->profiler->getPanels($profile);
        $current = $request->query->getString('panel');

        if (!isset($panels[$current])) {
            $current = (string) (array_key_first($panels) ?? '');
        }

        return $this->html('profile', [
            'title' => $profile->getMethod() . ' ' . $profile->getUrl(),
            'profile' => $profile,
            'panels' => $panels,
            'current' => $current,
        ]);
    }

    public function toolbar(string $token): Response
    {
        $profile = $this->profiler->load($token);

        if ($profile === null) {
            return new Response('', 404, self::HEADERS);
        }

        return new Response($this->templates->render('toolbar', [
            'profile' => $profile,
            'items' => $this->profiler->getToolbarItems($profile),
            'assets' => $this->profiler->getToolbarAssets($profile),
            'profilerPath' => $this->profiler->getPublicPath(),
        ]), 200, self::HEADERS);
    }

    public function json(string $token): Response
    {
        $profile = $this->profiler->load($token);

        if ($profile === null) {
            return new JsonResponse(['error' => sprintf('Profile "%s" not found.', $token)], 404);
        }

        return new JsonResponse($profile->toArray(), 200, ['X-Robots-Tag' => 'noindex, nofollow'], JsonResponse::DEFAULT_FLAGS | JSON_PRETTY_PRINT);
    }

    protected function notFound(string $token): Response
    {
        return $this->html('not_found', ['title' => 'Profile not found', 'token' => $token], 404);
    }

    protected function html(string $template, array $parameters, int $status = 200): Response
    {
        $content = $this->templates->render($template, [...$parameters, 'profilerPath' => $this->profiler->getPublicPath()]);

        return new Response($this->templates->render('layout', [
            'title' => (string) ($parameters['title'] ?? 'Profiler'),
            'content' => $content,
            'profilerPath' => $this->profiler->getPublicPath(),
            'profile' => $parameters['profile'] ?? null,
        ]), $status, self::HEADERS);
    }
}