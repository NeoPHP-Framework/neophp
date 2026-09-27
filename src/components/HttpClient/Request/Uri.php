<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Request;

use NeoPHP\Component\HttpClient\Exception\InvalidOptionException;

class Uri
{
    public static function resolve(?string $base, string $reference): string
    {
        $reference = trim($reference);

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) === 1) {
            return static::normalizeAbsolute($reference);
        }

        if ($base === null || $base === '') {
            throw new InvalidOptionException('The URL "{url}" is relative and no "base_uri" is configured.', 0, null, ['url' => $reference]);
        }

        $b = static::parse($base);

        if ($b['scheme'] === null) {
            throw new InvalidOptionException('The base URI "{url}" must be absolute.', 0, null, ['url' => $base]);
        }

        $r = static::parse($reference);

        if ($r['authority'] !== null) {
            $t = ['scheme' => $b['scheme'], 'authority' => $r['authority'], 'path' => static::removeDotSegments($r['path']), 'query' => $r['query']];
        } elseif ($r['path'] === '') {
            $t = ['scheme' => $b['scheme'], 'authority' => $b['authority'], 'path' => $b['path'], 'query' => $r['query'] ?? $b['query']];
        } elseif (str_starts_with($r['path'], '/')) {
            $t = ['scheme' => $b['scheme'], 'authority' => $b['authority'], 'path' => static::removeDotSegments($r['path']), 'query' => $r['query']];
        } else {
            $position = strrpos($b['path'], '/');
            $merged = match (true) {
                $b['authority'] !== null && $b['path'] === '' => '/' . $r['path'],
                $position === false => $r['path'],
                default => substr($b['path'], 0, $position + 1) . $r['path'],
            };
            $t = ['scheme' => $b['scheme'], 'authority' => $b['authority'], 'path' => static::removeDotSegments($merged), 'query' => $r['query']];
        }

        $t['fragment'] = $r['fragment'];

        return static::build($t);
    }

    public static function withQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        $fragment = '';

        if (($position = strpos($url, '#')) !== false) {
            $fragment = substr($url, $position);
            $url = substr($url, 0, $position);
        }

        $existing = [];

        if (($position = strpos($url, '?')) !== false) {
            parse_str(substr($url, $position + 1), $existing);
            $url = substr($url, 0, $position);
        }

        $merged = array_replace($existing, $query);
        $merged = array_filter($merged, static fn (mixed $value): bool => $value !== null);
        $string = http_build_query($merged, '', '&', PHP_QUERY_RFC3986);

        return $url . ($string !== '' ? '?' . $string : '') . $fragment;
    }

    public static function withoutCredentials(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@?\#]*@#i', '$1', $url);
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '';
        }

        return strtolower(($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ':' . ($parts['port'] ?? ''));
    }

    protected static function normalizeAbsolute(string $url): string
    {
        $parts = static::parse($url);
        $parts['path'] = static::removeDotSegments($parts['path']);

        if ($parts['authority'] !== null && $parts['path'] === '') {
            $parts['path'] = '/';
        }

        return static::build($parts);
    }

    protected static function parse(string $url): array
    {
        preg_match('#^(?:([^:/?\#]+):)?(?://([^/?\#]*))?([^?\#]*)(?:\?([^\#]*))?(?:\#(.*))?$#', $url, $matches);

        return [
            'scheme' => isset($matches[1]) && $matches[1] !== '' ? strtolower($matches[1]) : null,
            'authority' => isset($matches[2]) && str_contains($url, '//') && $matches[2] !== '' ? $matches[2] : null,
            'path' => $matches[3] ?? '',
            'query' => isset($matches[4]) && str_contains($url, '?') ? $matches[4] : null,
            'fragment' => isset($matches[5]) && str_contains($url, '#') ? $matches[5] : null,
        ];
    }

    protected static function build(array $parts): string
    {
        $url = $parts['scheme'] !== null ? $parts['scheme'] . ':' : '';

        if ($parts['authority'] !== null) {
            $url .= '//' . $parts['authority'];
        }

        $url .= $parts['path'];

        if ($parts['query'] !== null) {
            $url .= '?' . $parts['query'];
        }

        if (($parts['fragment'] ?? null) !== null) {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }

    protected static function removeDotSegments(string $path): string
    {
        $output = [];
        $input = $path;

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./')) {
                $input = substr($input, 2);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (str_starts_with($input, '/../')) {
                $input = substr($input, 3);
                array_pop($output);
            } elseif ($input === '/..') {
                $input = '/';
                array_pop($output);
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                $position = strpos($input, '/', 1);
                $segment = $position === false ? $input : substr($input, 0, $position);
                $output[] = $segment;
                $input = $position === false ? '' : substr($input, $position);
            }
        }

        return implode('', $output);
    }
}