<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Exception;

use NeoPHP\Component\HttpClient\Contract\ResponseInterface;
use Throwable;

class HttpException extends HttpClientException
{
    protected ResponseInterface $response;

    public function __construct(ResponseInterface $response, string $message = '', int $code = 0, ?Throwable $previous = null, array $context = [])
    {
        $this->response = $response;

        parent::__construct($message, $code, $previous, $context);
    }

    public static function create(ResponseInterface $response): self
    {
        $status = $response->getStatusCode();
        $class = match (true) {
            $status >= 500 => ServerException::class,
            $status >= 400 => ClientException::class,
            default => RedirectionException::class,
        };

        return new $class($response, 'HTTP {status} returned for "{url}".', $status, null, [
            'status' => $status,
            'url' => (string) $response->getInfo('url'),
        ]);
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}