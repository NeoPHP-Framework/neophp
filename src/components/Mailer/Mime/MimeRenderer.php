<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Mime;

use DateTimeInterface;

class MimeRenderer
{
    public const CRLF = "\r\n";

    public const LINE_LENGTH = 78;

    public const ENCODED_WORD_BYTES = 42;

    public const PARAMETER_LENGTH = 60;

    public function render(Email $email, string $messageId): string
    {
        $headers = [
            ['Date', $email->getDate()->format(DateTimeInterface::RFC2822)],
            ['Message-ID', '<' . $messageId . '>'],
            ['Subject', $this->encodeText($email->getSubject())],
            ['From', $this->encodeAddresses($email->getFrom())],
        ];

        if ($email->getSender() !== null) {
            $headers[] = ['Sender', $this->encodeAddresses([$email->getSender()])];
        }

        foreach (['Reply-To' => $email->getReplyTo(), 'To' => $email->getTo(), 'Cc' => $email->getCc()] as $name => $addresses) {
            if ($addresses !== []) {
                $headers[] = [$name, $this->encodeAddresses($addresses)];
            }
        }

        $headers[] = ['MIME-Version', '1.0'];

        if ($email->getPriority() !== Email::PRIORITY_NORMAL) {
            $headers[] = ['X-Priority', $email->getPriority() . ' (' . Email::PRIORITIES[$email->getPriority()] . ')'];
        }

        foreach ($email->getHeaders() as [$name, $value]) {
            $headers[] = [$name, $this->encodeText($value)];
        }

        [$partHeaders, $body] = $this->body($email);

        return $this->headers([...$headers, ...$partHeaders]) . self::CRLF . $body;
    }

    public function generateMessageId(Email $email): string
    {
        $from = $email->getSender() ?? ($email->getFrom()[0] ?? null);
        $domain = $from !== null ? substr($from->getAddress(), (int) strrpos($from->getAddress(), '@') + 1) : 'neophp.local';

        return bin2hex(random_bytes(16)) . '@' . $domain;
    }

    public function encodeAddresses(array $addresses): string
    {
        return implode(', ', array_map(fn (Address $address): string => $this->encodeAddress($address), $addresses));
    }

    public function encodeAddress(Address $address): string
    {
        if ($address->getName() === '') {
            return $address->getAddress();
        }

        $name = $address->getName();

        if (!$this->isAscii($name)) {
            $name = $this->encodeWords($name);
        } elseif (preg_match('/[()<>\[\]:;@\\\\,."]/', $name) === 1) {
            $name = '"' . addcslashes($name, '"\\') . '"';
        }

        return $name . ' <' . $address->getAddress() . '>';
    }

    public function encodeText(string $text): string
    {
        return $this->isAscii($text) ? $text : $this->encodeWords($text);
    }

    public function encodeWords(string $text): string
    {
        $words = [];
        $chunk = '';

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($text) as $character) {
            if (strlen($chunk . $character) > self::ENCODED_WORD_BYTES) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }

            $chunk .= $character;
        }

        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode(' ', $words);
    }

    public function htmlToText(string $html): string
    {
        $text = (string) preg_replace('#<(head|style|script)\b[^>]*>.*?</\1>#is', '', $html);
        $text = (string) preg_replace('#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', '$3 ($2)', $text);
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#</(p|div|h[1-6]|li|tr|table|blockquote)>#i', "\n\n", $text);
        $text = (string) preg_replace('#<li\b[^>]*>#i', '- ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    protected function body(Email $email): array
    {
        $html = $email->getHtml();
        $text = $email->getText() ?? ($html !== null ? $this->htmlToText($html) : null);
        $parts = [];
        $embedded = [];
        $attachments = $email->getAttachments();

        foreach ($email->getEmbedded() as $attachment) {
            if ($html === null) {
                $attachments[] = new Attachment($attachment->getBody(), $attachment->getFilename(), $attachment->getContentType());
                continue;
            }

            $cid = bin2hex(random_bytes(8)) . '@neophp';
            $html = (string) preg_replace('/cid:' . preg_quote($attachment->getFilename(), '/') . '(?=["\'\s)>])/', 'cid:' . $cid, $html);
            $embedded[] = [$attachment, $cid];
        }

        if ($text !== null) {
            $parts[] = $this->textPart($text, 'text/plain');
        }

        if ($html !== null) {
            $parts[] = $this->textPart($html, 'text/html');
        }

        $content = count($parts) > 1 ? $this->multipart('alternative', $parts) : ($parts[0] ?? null);

        if ($content !== null && $embedded !== []) {
            $content = $this->multipart('related', [$content, ...array_map(fn (array $item): array => $this->attachmentPart($item[0], $item[1]), $embedded)], count($parts) > 1 ? 'multipart/alternative' : 'text/html');
        }

        $attachments = array_map(fn (Attachment $attachment): array => $this->attachmentPart($attachment), $attachments);

        if ($attachments !== []) {
            return $this->multipart('mixed', $content !== null ? [$content, ...$attachments] : $attachments);
        }

        return $content ?? $this->textPart('', 'text/plain');
    }

    protected function textPart(string $content, string $type): array
    {
        $content = (string) preg_replace('/\r\n|\r|\n/', self::CRLF, $content);

        return [
            [['Content-Type', $type . '; charset=utf-8'], ['Content-Transfer-Encoding', 'quoted-printable']],
            quoted_printable_encode($content),
        ];
    }

    protected function attachmentPart(Attachment $attachment, ?string $cid = null): array
    {
        $headers = [
            ['Content-Type', $attachment->getContentType() . (str_starts_with($attachment->getContentType(), 'text/') && !str_contains($attachment->getContentType(), 'charset') && preg_match('//u', $attachment->getBody()) === 1 ? '; charset=utf-8' : '') . '; ' . $this->parameter('name', $attachment->getFilename())],
            ['Content-Transfer-Encoding', 'base64'],
            ['Content-Disposition', ($cid !== null ? 'inline' : 'attachment') . '; ' . $this->parameter('filename', $attachment->getFilename())],
        ];

        if ($cid !== null) {
            $headers[] = ['Content-ID', '<' . $cid . '>'];
        }

        return [$headers, rtrim(chunk_split(base64_encode($attachment->getBody()), 76, self::CRLF), self::CRLF)];
    }

    protected function multipart(string $subtype, array $parts, ?string $type = null): array
    {
        $boundary = '=_' . bin2hex(random_bytes(12));
        $body = '';

        foreach ($parts as [$headers, $content]) {
            $body .= '--' . $boundary . self::CRLF . $this->headers($headers) . self::CRLF . $content . self::CRLF;
        }

        return [[['Content-Type', 'multipart/' . $subtype . ($type !== null ? '; type="' . $type . '"' : '') . '; boundary="' . $boundary . '"']], $body . '--' . $boundary . '--'];
    }

    protected function parameter(string $name, string $value): string
    {
        if ($this->isAscii($value) && strlen($value) <= self::PARAMETER_LENGTH) {
            return $name . '="' . addcslashes($value, '"\\') . '"';
        }

        preg_match_all('/%[0-9A-F]{2}|[^%]/', rawurlencode($value), $matches);
        $chunks = [];
        $chunk = '';

        foreach ($matches[0] as $token) {
            if (strlen($chunk . $token) > self::PARAMETER_LENGTH) {
                $chunks[] = $chunk;
                $chunk = '';
            }

            $chunk .= $token;
        }

        $chunks[] = $chunk;

        if (count($chunks) === 1) {
            return $name . "*=utf-8''" . $chunks[0];
        }

        $parameters = [];

        foreach ($chunks as $index => $part) {
            $parameters[] = $name . '*' . $index . '*=' . ($index === 0 ? "utf-8''" : '') . $part;
        }

        return implode('; ', $parameters);
    }

    protected function headers(array $headers): string
    {
        $output = '';

        foreach ($headers as [$name, $value]) {
            $output .= $this->fold($name . ': ' . $value) . self::CRLF;
        }

        return $output;
    }

    protected function fold(string $line): string
    {
        if (strlen($line) <= self::LINE_LENGTH) {
            return $line;
        }

        $lines = [];
        $current = '';

        foreach (explode(' ', $line) as $index => $word) {
            if ($index > 1 && strlen($current) + 1 + strlen($word) > self::LINE_LENGTH) {
                $lines[] = $current;
                $current = $word;
                continue;
            }

            $current .= ($index > 0 ? ' ' : '') . $word;
        }

        $lines[] = $current;

        return implode(self::CRLF . ' ', $lines);
    }

    protected function isAscii(string $value): bool
    {
        return preg_match('/[^\x20-\x7E\t]/', $value) !== 1;
    }
}