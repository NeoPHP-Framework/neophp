<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Model;

/**
 * @internal
 */
class ContentParser
{
    /**
     * @return array{0: string, 1: mixed} The type and the decoded data
     */
    public static function parse(string $content): array
    {
        $trimmed = trim($content);

        return match (true) {
            preg_match('#^https?://#i', $trimmed) === 1 => ['url', $trimmed],
            stripos($trimmed, 'mailto:') === 0 => ['email', self::email($trimmed)],
            stripos($trimmed, 'tel:') === 0 => ['phone', substr($trimmed, 4)],
            stripos($trimmed, 'smsto:') === 0, stripos($trimmed, 'sms:') === 0 => ['sms', self::sms($trimmed)],
            stripos($trimmed, 'WIFI:') === 0 => ['wifi', self::wifi($trimmed)],
            stripos($trimmed, 'BEGIN:VCARD') === 0 => ['vcard', self::vcard($trimmed)],
            stripos($trimmed, 'geo:') === 0 => ['geo', self::geo($trimmed)],
            self::isJson($trimmed) => ['json', json_decode($trimmed, true)],
            default => ['text', $content],
        };
    }

    /**
     * Escapes a value of a WIFI: content (\ ; , : and ").
     */
    public static function escapeWifi(string $value): string
    {
        return addcslashes($value, '\\;,:"');
    }

    /**
     * @return array{to: string, subject: string|null, body: string|null}
     */
    private static function email(string $content): array
    {
        $parts = parse_url($content) ?: [];
        parse_str($parts['query'] ?? '', $query);

        return ['to' => rawurldecode($parts['path'] ?? ''), 'subject' => isset($query['subject']) ? (string) $query['subject'] : null, 'body' => isset($query['body']) ? (string) $query['body'] : null];
    }

    /**
     * @return array{number: string, message: string|null}
     */
    private static function sms(string $content): array
    {
        $parts = explode(':', substr($content, (int) strpos($content, ':') + 1), 2);

        return ['number' => $parts[0], 'message' => $parts[1] ?? null];
    }

    /**
     * @return array{ssid: string, password: string|null, encryption: string, hidden: bool}
     */
    private static function wifi(string $content): array
    {
        $fields = [];
        preg_match_all('/([A-Z]):((?:\\\\.|[^;\\\\])*);/', substr($content, 5), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $fields[$match[1]] = stripcslashes($match[2]);
        }

        return [
            'ssid' => $fields['S'] ?? '',
            'password' => $fields['P'] ?? null,
            'encryption' => $fields['T'] ?? 'nopass',
            'hidden' => strtolower($fields['H'] ?? '') === 'true',
        ];
    }

    /**
     * @return array<string, string|list<string>>
     */
    private static function vcard(string $content): array
    {
        $names = ['FN' => 'name', 'N' => 'structured_name', 'ORG' => 'organization', 'TITLE' => 'title', 'TEL' => 'phone', 'EMAIL' => 'email', 'URL' => 'url', 'ADR' => 'address', 'NOTE' => 'note'];
        $result = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (preg_match('/^([A-Z]+)(?:;[^:]*)?:(.*)$/', $line, $match) !== 1 || !isset($names[$match[1]])) {
                continue;
            }

            $key = $names[$match[1]];
            $value = str_replace(['\\,', '\\;', '\\n'], [',', ';', "\n"], $match[2]);

            if (isset($result[$key])) {
                $result[$key] = [...(array) $result[$key], $value];
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    private static function geo(string $content): array
    {
        [$latitude, $longitude] = array_pad(explode(',', (string) strtok(substr($content, 4), '?')), 2, '0');

        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }

    private static function isJson(string $content): bool
    {
        if ($content === '' || ($content[0] !== '{' && $content[0] !== '[')) {
            return false;
        }

        json_decode($content);

        return json_last_error() === JSON_ERROR_NONE;
    }
}