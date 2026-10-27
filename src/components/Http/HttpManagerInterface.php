<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http;

use JsonException;
use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Exception\NotFoundHttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\BinaryFileResponse;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Http\Response\StreamedResponse;
use SplFileInfo;

interface HttpManagerInterface
{
    /**
     * Creates the request from $_GET, $_POST, $_COOKIE, $_FILES and $_SERVER; a JSON body or a form body of a PUT, PATCH or DELETE request fills the request parameters.
     *
     * @return Request The current request
     */
    public function createRequestFromGlobals(): Request;

    /**
     * Creates a response.
     *
     * @param string $content Body of the response
     * @param int $status HTTP status code
     * @param array<string, mixed> $headers HTTP headers, by name
     * @return Response The response
     * @throws HttpException When the status code is not a valid HTTP status
     */
    public function createResponse(string $content = '', int $status = 200, array $headers = []): Response;

    /**
     * Creates a JSON response, encoding the data with json_encode().
     *
     * @param mixed $data The data to encode
     * @param int $status HTTP status code
     * @param array<string, mixed> $headers HTTP headers, by name
     * @return JsonResponse The response
     * @throws HttpException When the status code is not a valid HTTP status
     * @throws JsonException When the data cannot be encoded
     */
    public function json(mixed $data = null, int $status = 200, array $headers = []): JsonResponse;

    /**
     * Creates a redirection response.
     *
     * @param string $url URL of the redirection
     * @param int $status Redirection status code (301, 302, 303, 307 or 308)
     * @param array<string, mixed> $headers HTTP headers, by name
     * @return RedirectResponse The response
     * @throws HttpException When the URL is empty or the status code is not a redirection
     */
    public function redirect(string $url, int $status = 302, array $headers = []): RedirectResponse;

    /**
     * Creates a response whose body is written by a callback while it is sent (large exports, server-sent events).
     *
     * @param callable $callback Writes the body with echo; StreamedResponse::flushOutput() sends what was written so far
     * @param int $status HTTP status code
     * @param array<string, mixed> $headers HTTP headers, by name
     * @return StreamedResponse The response
     * @throws HttpException When the status code is not a valid HTTP status
     */
    public function stream(callable $callback, int $status = 200, array $headers = []): StreamedResponse;

    /**
     * Creates a response sending a file of the disk by chunks, with the support of the Range and conditional requests.
     *
     * @param string|SplFileInfo $file The file to send
     * @param string|null $filename Name proposed to the user, the name of the file by default
     * @param string $disposition "attachment" (download) or "inline" (displayed by the browser)
     * @param array<string, mixed> $headers HTTP headers, by name
     * @return BinaryFileResponse The response
     * @throws NotFoundHttpException When the file does not exist or is not readable
     * @throws HttpException When the disposition or the name is invalid
     */
    public function file(string|SplFileInfo $file, ?string $filename = null, string $disposition = BinaryFileResponse::DISPOSITION_ATTACHMENT, array $headers = []): BinaryFileResponse;

    /**
     * Sends the headers and the body of a response, prepared for the request first when it is given.
     *
     * @param Response $response The response
     * @param Request|null $request The request the response answers, used to adapt it: no body for an empty status or a HEAD request, text/html; charset=UTF-8 by default
     * @return Response The sent response
     */
    public function send(Response $response, ?Request $request = null): Response;
}