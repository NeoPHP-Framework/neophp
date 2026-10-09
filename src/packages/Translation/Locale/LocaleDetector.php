<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Locale;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Session\SessionManagerInterface;
use NeoPHP\Package\Translation\TranslationManagerInterface;

class LocaleDetector
{
    public function __construct(protected TranslationManagerInterface $translator)
    {
    }

    public function detect(Request $request, ?array $routeParameters = null, ?SessionManagerInterface $session = null): ?array
    {
        $detection = (array) ($this->translator->getConfig()['detection'] ?? []);

        foreach ((array) ($detection['order'] ?? []) as $source) {
            $locale = match ($source) {
                'route' => $routeParameters !== null ? $this->match($routeParameters[TranslationManagerInterface::ATTRIBUTE] ?? null) : null,
                'query' => $this->match($request->query->get((string) ($detection['query_parameter'] ?? 'lang'))),
                'session' => $this->fromSession($request, $session, (string) ($detection['session_key'] ?? '_locale')),
                'cookie' => $this->match($request->cookies->get((string) ($detection['cookie_name'] ?? 'locale'))),
                'header' => $this->fromHeader((string) $request->headers->get('Accept-Language', '')),
                default => null,
            };

            if ($locale !== null) {
                return [$locale, (string) $source];
            }
        }

        return null;
    }

    public function fromHeader(string $header): ?string
    {
        $candidates = [];

        foreach (explode(',', $header) as $index => $part) {
            $segments = array_map('trim', explode(';', $part));
            $tag = $segments[0];
            $quality = 1.0;

            foreach (array_slice($segments, 1) as $segment) {
                if (preg_match('/^q\s*=\s*([0-9.]+)$/i', $segment, $match) === 1) {
                    $quality = (float) $match[1];
                }
            }

            if ($tag === '' || $tag === '*' || $quality <= 0) {
                continue;
            }

            $candidates[] = [$tag, $quality, $index];
        }

        usort($candidates, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        foreach ($candidates as [$tag]) {
            $locale = $this->match($tag);

            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }

    protected function fromSession(Request $request, ?SessionManagerInterface $session, string $key): ?string
    {
        if ($session === null || (!$session->isStarted() && !$request->cookies->has($session->getName()))) {
            return null;
        }

        return $this->match($session->get($key));
    }

    protected function match(mixed $locale): ?string
    {
        return is_string($locale) && $locale !== '' ? $this->translator->matchLocale($locale) : null;
    }
}