<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Renderer;

class Icons
{
    public const PATHS = [
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'request' => '<path d="M4 12h12M12 6l6 6-6 6"/>',
        'response' => '<path d="M20 12H8M12 6l-6 6 6 6"/>',
        'time' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'theme' => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"/>',
        'memory' => '<rect x="4" y="7" width="16" height="10" rx="1"/><path d="M8 7V4M12 7V4M16 7V4M8 20v-3M12 20v-3M16 20v-3"/>',
        'exception' => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17h.01"/>',
        'config' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/>',
        'database' => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3"/>',
        'route' => '<circle cx="6" cy="18" r="2"/><circle cx="18" cy="6" r="2"/><path d="M8 18h6a4 4 0 0 0 0-8h-4a4 4 0 0 1 0-8h6"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'cache' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'event' => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/>',
        'log' => '<path d="M6 3h9l4 4v14H6z"/><path d="M9 12h7M9 16h7"/>',
        'view' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'form' => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 13h8M8 17h4"/>',
        'security' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3z"/>',
        'ajax' => '<path d="M4 12a8 8 0 0 1 14-5l2 2M20 12a8 8 0 0 1-14 5l-2-2"/><path d="M20 4v5h-5M4 20v-5h5"/>',
        'php' => '<ellipse cx="12" cy="12" rx="10" ry="6"/><path d="M8 9v6M8 9h2a1.5 1.5 0 0 1 0 3H8M15 9v6M15 9h2a1.5 1.5 0 0 1 0 3h-2"/>',
        'logo' => '<path d="M4 20V4l16 16V4"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    ];

    public static function svg(string $icon, int $size = 16): string
    {
        if (str_starts_with(ltrim($icon), '<svg')) {
            return $icon;
        }

        $paths = self::PATHS[$icon] ?? self::PATHS['info'];

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%2$s</svg>',
            $size,
            $paths,
        );
    }

    public static function has(string $icon): bool
    {
        return isset(self::PATHS[$icon]);
    }
}