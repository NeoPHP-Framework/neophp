<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Transport;

use NeoPHP\Component\HttpClient\Contract\AbstractTransport;
use NeoPHP\Component\HttpClient\Exception\TransportException;
use NeoPHP\Component\HttpClient\Request\Request;
use NeoPHP\Component\HttpClient\Response\Response;

class StreamTransport extends AbstractTransport
{
    public const CHUNK_SIZE = 8192;

    public function getName(): string
    {
        return 'stream';
    }

    public function send(Request $request): Response
    {
        $start = microtime(true);
        $timeout = $this->timeout($request);
        $connect = $request->getOption('connect_timeout');
        $context = stream_context_create($this->contextOptions($request, $timeout));
        $error = '';

        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = (string) preg_replace('#^fopen\([^)]*\):\s*#', '', $message);

            return true;
        });

        try {
            $socketTimeout = ini_get('default_socket_timeout');

            if (is_numeric($connect) && (float) $connect > 0) {
                ini_set('default_socket_timeout', (string) (float) $connect);
            } elseif ($timeout !== null) {
                ini_set('default_socket_timeout', (string) $timeout);
            }

            $stream = fopen($request->getUrl(), 'rb', false, $context);
            ini_set('default_socket_timeout', (string) $socketTimeout);
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            if ($timeout !== null && microtime(true) - $start >= $timeout) {
                $error = sprintf('Operation timed out after %d milliseconds', (int) ((microtime(true) - $start) * 1000));
            }

            throw $this->exception($request, $error !== '' ? $error : 'unknown error');
        }

        $meta = stream_get_meta_data($stream);
        $lines = array_values(array_filter((array) ($meta['wrapper_data'] ?? []), 'is_string'));
        $response = Response::fromRaw($lines, '');
        $total = $response->getHeader('content-length');
        $total = $total !== null && ctype_digit($total) ? (int) $total : null;
        $sink = null;
        $body = '';
        $downloaded = 0;

        try {
            $sink = $this->openSink($request);

            if ($request->getMethod() !== 'HEAD') {
                while (!feof($stream)) {
                    if ($timeout !== null) {
                        $remaining = $timeout - (microtime(true) - $start);

                        if ($remaining <= 0) {
                            throw $this->exception($request, sprintf('Operation timed out after %d milliseconds', (int) ((microtime(true) - $start) * 1000)));
                        }

                        stream_set_timeout($stream, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
                    }

                    $chunk = fread($stream, self::CHUNK_SIZE);

                    if ($chunk === false || stream_get_meta_data($stream)['timed_out']) {
                        throw $this->exception($request, 'Operation timed out while reading the response');
                    }

                    if ($chunk === '') {
                        continue;
                    }

                    if ($sink !== null) {
                        if (fwrite($sink, $chunk) === false) {
                            throw $this->exception($request, 'the response cannot be written to the sink');
                        }
                    } else {
                        $body .= $chunk;
                    }

                    $downloaded += strlen($chunk);
                    $this->progress($request, $downloaded, $total);
                }
            }
        } finally {
            fclose($stream);

            if ($sink !== null) {
                fclose($sink);
            }
        }

        $body = $this->decode($body, (string) $response->getHeader('content-encoding'));

        return Response::fromRaw($lines, $sink !== null ? null : $body, $this->info($request, $start));
    }

    protected function contextOptions(Request $request, ?float $timeout): array
    {
        $headers = $request->getHeaderLines();

        if ($request->getHeader('connection') === null) {
            $headers[] = 'Connection: close';
        }

        $http = [
            'method' => $request->getMethod(),
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 1,
            'protocol_version' => 1.1,
        ];

        if ($request->getBody() !== '') {
            $http['content'] = $request->getBody();
        }

        if ($timeout !== null) {
            $http['timeout'] = $timeout;
        }

        $proxy = $request->getOption('proxy');

        if (is_string($proxy) && $proxy !== '') {
            $http['proxy'] = (string) preg_replace('#^https?://#i', 'tcp://', $proxy);
            $http['request_fulluri'] = true;
        }

        $verify = (bool) $request->getOption('verify_peer', true);

        return [
            'http' => $http,
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => !$verify,
            ],
        ];
    }

    protected function decode(string $body, string $encoding): string
    {
        $encoding = strtolower(trim($encoding));

        if ($body === '' || !in_array($encoding, ['gzip', 'deflate'], true) || !function_exists('zlib_decode')) {
            return $body;
        }

        $decoded = @zlib_decode($body);

        return $decoded === false ? $body : $decoded;
    }

    protected function exception(Request $request, string $error): TransportException
    {
        return new TransportException('{method} "{url}" failed: {error}', 0, null, [
            'method' => $request->getMethod(),
            'url' => $request->getUrl(),
            'error' => $error,
        ]);
    }
}