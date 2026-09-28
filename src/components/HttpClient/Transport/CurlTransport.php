<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Transport;

use CurlHandle;
use NeoPHP\Component\HttpClient\Contract\AbstractTransport;
use NeoPHP\Component\HttpClient\Exception\TransportException;
use NeoPHP\Component\HttpClient\Request\Request;
use NeoPHP\Component\HttpClient\Response\Response;

class CurlTransport extends AbstractTransport
{
    protected array $states = [];

    public function __construct()
    {
        if (!extension_loaded('curl')) {
            throw new TransportException('The "curl" PHP extension is required by the curl transport of the HttpClient component.');
        }
    }

    public function getName(): string
    {
        return 'curl';
    }

    public function send(Request $request): Response
    {
        $handle = $this->createHandle($request);
        curl_exec($handle);

        return $this->finish($handle, curl_errno($handle), curl_error($handle));
    }

    public function sendMany(array $requests): array
    {
        if (count($requests) < 2) {
            return parent::sendMany($requests);
        }

        $multi = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($requests as $key => $request) {
            try {
                $handle = $this->createHandle($request);
            } catch (TransportException $exception) {
                $results[$key] = $exception;
                continue;
            }

            $handles[spl_object_id($handle)] = [$key, $handle];
            curl_multi_add_handle($multi, $handle);
        }

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0 && curl_multi_select($multi, 1.0) === -1) {
                usleep(1000);
            }

            while (($message = curl_multi_info_read($multi)) !== false) {
                $handle = $message['handle'];
                [$key] = $handles[spl_object_id($handle)];
                $code = (int) $message['result'];

                try {
                    $results[$key] = $this->finish($handle, $code, $code !== 0 ? curl_error($handle) : '');
                } catch (TransportException $exception) {
                    $results[$key] = $exception;
                }

                curl_multi_remove_handle($multi, $handle);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as [$key, $handle]) {
            if (!isset($results[$key])) {
                curl_multi_remove_handle($multi, $handle);
                $results[$key] = $this->fail($handle, 'The request was aborted.');
            }
        }

        curl_multi_close($multi);
        $ordered = [];

        foreach (array_keys($requests) as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    protected function createHandle(Request $request): CurlHandle
    {
        $sink = $this->openSink($request);
        $handle = curl_init();
        $id = spl_object_id($handle);
        $this->states[$id] = ['request' => $request, 'headers' => [], 'body' => '', 'sink' => $sink, 'start' => microtime(true), 'downloaded' => 0];
        $method = $request->getMethod();
        $headers = $request->getHeaderLines();
        $headers[] = 'Expect:';

        $options = [
            CURLOPT_URL => $request->getUrl(),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => (bool) $request->getOption('verify_peer', true),
            CURLOPT_SSL_VERIFYHOST => $request->getOption('verify_peer', true) ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function (CurlHandle $handle, string $line) use ($id): int {
                $this->states[$id]['headers'][] = $line;

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function (CurlHandle $handle, string $data) use ($id): int {
                $state = &$this->states[$id];

                if ($state['sink'] !== null) {
                    if (fwrite($state['sink'], $data) === false) {
                        return 0;
                    }
                } else {
                    $state['body'] .= $data;
                }

                $state['downloaded'] += strlen($data);
                $this->progress($state['request'], $state['downloaded'], $this->contentLength($state['headers']));

                return strlen($data);
            },
        ];

        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } elseif ($request->getBody() !== '' || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $options[CURLOPT_POSTFIELDS] = $request->getBody();
        }

        if (($timeout = $this->timeout($request)) !== null) {
            $options[CURLOPT_TIMEOUT_MS] = (int) ceil($timeout * 1000);
        }

        $connect = $request->getOption('connect_timeout');

        if (is_numeric($connect) && (float) $connect > 0) {
            $options[CURLOPT_CONNECTTIMEOUT_MS] = (int) ceil((float) $connect * 1000);
        }

        $proxy = $request->getOption('proxy');

        if (is_string($proxy) && $proxy !== '') {
            $options[CURLOPT_PROXY] = $proxy;
        }

        curl_setopt_array($handle, $options);

        return $handle;
    }

    protected function finish(CurlHandle $handle, int $errno, string $error): Response
    {
        $id = spl_object_id($handle);

        if ($errno !== 0) {
            throw $this->fail($handle, $error !== '' ? $error : curl_strerror($errno), $errno);
        }

        $state = $this->states[$id];
        unset($this->states[$id]);

        if ($state['sink'] !== null) {
            fclose($state['sink']);
        }

        $ip = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);

        return Response::fromRaw($state['headers'], $state['sink'] !== null ? null : $state['body'], $this->info($state['request'], $state['start'], [
            'total_time' => (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME),
            'primary_ip' => $ip !== '' ? $ip : null,
        ]));
    }

    protected function fail(CurlHandle $handle, string $error, int $errno = 0): TransportException
    {
        $id = spl_object_id($handle);
        $request = $this->states[$id]['request'] ?? null;

        if (($this->states[$id]['sink'] ?? null) !== null) {
            fclose($this->states[$id]['sink']);
        }

        unset($this->states[$id]);

        return new TransportException('{method} "{url}" failed: {error}', $errno, null, [
            'method' => $request?->getMethod() ?? '',
            'url' => $request?->getUrl() ?? '',
            'error' => $error,
        ]);
    }

    protected function contentLength(array $lines): ?int
    {
        $length = null;

        foreach ($lines as $line) {
            if (preg_match('#^HTTP/#i', $line) === 1) {
                $length = null;
            } elseif (preg_match('#^content-length:\s*(\d+)#i', $line, $matches) === 1) {
                $length = (int) $matches[1];
            }
        }

        return $length;
    }
}