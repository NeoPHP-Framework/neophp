<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient;

use NeoPHP\Component\HttpClient\Contract\ResponseInterface;
use NeoPHP\Component\HttpClient\Contract\TransportInterface;
use NeoPHP\Component\HttpClient\Exception\HttpClientException;
use NeoPHP\Component\HttpClient\Exception\InvalidOptionException;
use NeoPHP\Component\HttpClient\Exception\TransportException;

interface HttpClientManagerInterface
{
    /**
     * Sends a request and returns its response, after the retries and the redirections; a 4xx or 5xx response is returned, not thrown.
     *
     * @param string $method HTTP method
     * @param string $url Absolute http:// or https:// URL, or a path relative to the base_uri option
     * @param array<string, mixed> $options Options of the request: base_uri, query, headers, json, body, multipart, auth_basic, auth_bearer, timeout, connect_timeout, max_redirects, verify_peer, proxy, retry, sink, on_progress, cache
     * @return ResponseInterface The response
     * @throws InvalidOptionException When an option is unknown or invalid, or the URL is empty or not an HTTP URL
     * @throws TransportException When the request cannot be sent (connection, DNS, timeout...)
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface;

    /**
     * Sends a GET request.
     *
     * @param string $url Absolute URL, or a path relative to the base_uri option
     * @param array<string, mixed> $options Options of the request
     * @return ResponseInterface The response
     * @throws InvalidOptionException When an option is unknown or invalid, or the URL is empty or not an HTTP URL
     * @throws TransportException When the request cannot be sent
     */
    public function get(string $url, array $options = []): ResponseInterface;

    /**
     * Sends a POST request.
     *
     * @param string $url Absolute URL, or a path relative to the base_uri option
     * @param array<string, mixed> $options Options of the request
     * @return ResponseInterface The response
     * @throws InvalidOptionException When an option is unknown or invalid, or the URL is empty or not an HTTP URL
     * @throws TransportException When the request cannot be sent
     */
    public function post(string $url, array $options = []): ResponseInterface;

    /**
     * Sends a PUT request.
     *
     * @param string $url Absolute URL, or a path relative to the base_uri option
     * @param array<string, mixed> $options Options of the request
     * @return ResponseInterface The response
     * @throws InvalidOptionException When an option is unknown or invalid, or the URL is empty or not an HTTP URL
     * @throws TransportException When the request cannot be sent
     */
    public function put(string $url, array $options = []): ResponseInterface;

    /**
     * Sends a PATCH request.
     *
     * @param string $url Absolute URL, or a path relative to the base_uri option
     * @param array<string, mixed> $options Options of the request
     * @return ResponseInterface The response
     * @throws InvalidOptionException When an option is unknown or invalid, or the URL is empty or not an HTTP URL
     * @throws TransportException When the request cannot be sent
     */
    public function patch(string $url, array $options = []): ResponseInterface;

    /**
     * Sends a DELETE request.
     *
     * @param string $url Absolute URL, or a path relative to the base_uri option
     * @param array<string, mixed> $options Options of the request
     * @return ResponseInterface The response
     * @throws InvalidOptionException When an option is unknown or invalid, or the URL is empty or not an HTTP URL
     * @throws TransportException When the request cannot be sent
     */
    public function delete(string $url, array $options = []): ResponseInterface;

    /**
     * Sends a HEAD request.
     *
     * @param string $url Absolute URL, or a path relative to the base_uri option
     * @param array<string, mixed> $options Options of the request
     * @return ResponseInterface The response
     * @throws InvalidOptionException When an option is unknown or invalid, or the URL is empty or not an HTTP URL
     * @throws TransportException When the request cannot be sent
     */
    public function head(string $url, array $options = []): ResponseInterface;

    /**
     * Sends several requests in parallel and waits for every response.
     *
     * @param array<array<mixed>> $requests The requests, each one [method, url, options], by key
     * @return array<ResponseInterface> The responses, with the keys of the requests
     * @throws InvalidOptionException When a request is not an array, or one of its options or its URL is invalid
     * @throws TransportException When a request cannot be sent, after every request is completed
     */
    public function requestMany(array $requests): array;

    /**
     * Downloads the body of a response into a file, without loading it in memory.
     *
     * @param string $url Absolute URL, or a path relative to the base_uri option
     * @param string $target Path of the file to write
     * @param array<string, mixed> $options Options of the request, and method (GET by default)
     * @return ResponseInterface The response, without its body
     * @throws InvalidOptionException When the target is empty, an option is unknown or invalid, or the URL is empty or not an HTTP URL
     * @throws TransportException When the request cannot be sent or the file cannot be written
     */
    public function download(string $url, string $target, array $options = []): ResponseInterface;

    /**
     * Returns a copy of the client with options merged into its default options.
     *
     * @param array<string, mixed> $options Options of the requests
     * @return static The new client
     * @throws InvalidOptionException When an option is unknown
     */
    public function withOptions(array $options): static;

    /**
     * Returns a named client configured in http_client.yaml, its options merged into the default options.
     *
     * @param string $name Name of the client
     * @return HttpClientManagerInterface The client
     * @throws HttpClientException When the client is not configured
     * @throws InvalidOptionException When an option of the client is unknown
     */
    public function client(string $name): HttpClientManagerInterface;

    /**
     * Tells whether a named client is configured.
     *
     * @param string $name Name of the client
     * @return bool True when the client is configured
     */
    public function hasClient(string $name): bool;

    /**
     * Returns the default options of the client.
     *
     * @return array<string, mixed> The options, by name
     */
    public function getOptions(): array;

    /**
     * Returns the transport sending the requests (curl or stream).
     *
     * @return TransportInterface The transport
     */
    public function getTransport(): TransportInterface;
}