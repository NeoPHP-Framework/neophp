<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure;

use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Mercure\Exception\MercureException;
use NeoPHP\Package\Mercure\Model\Update;

interface MercureManagerInterface
{
    public const COOKIE_NAME = 'mercureAuthorization';
    public const DEFAULT_LIFETIME = 3600;

    /**
     * Publishes an update to the hub, immediately.
     *
     * @param Update $update The update
     * @return string The id of the update, returned by the hub
     * @throws MercureException When the hub is not configured, cannot be reached or refuses the update
     */
    public function publish(Update $update): string;

    /**
     * Publishes an update in the background: it is dispatched as a message of the Queue package, then published by a worker.
     *
     * @param Update $update The update
     * @param array<string, mixed> $options The dispatch options of the Queue package (transport, queue, delay, priority)
     * @throws MercureException When the Queue package is disabled
     */
    public function dispatch(Update $update, array $options = []): void;

    /**
     * Returns the URL used by the application to publish the updates.
     *
     * @return string The URL of the hub (MERCURE_URL)
     * @throws MercureException When the URL is not configured
     */
    public function getUrl(): string;

    /**
     * Returns the URL of the hub reached by the browsers.
     *
     * @return string The public URL (MERCURE_PUBLIC_URL), the URL of the hub when it is not configured
     * @throws MercureException When no URL is configured
     */
    public function getPublicUrl(): string;

    /**
     * Returns the public URL of the hub with the topics to subscribe to, for new EventSource(url).
     *
     * @param string|list<string> $topics The topics (URI templates accepted)
     * @return string The URL with a "topic" query parameter per topic
     * @throws MercureException When no URL is configured
     */
    public function getSubscribeUrl(string|array $topics = []): string;

    /**
     * Creates a JWT allowing to publish to the hub.
     *
     * @param list<string>|null $topics The topics allowed, the "jwt.publish" topics of the configuration when null
     * @param int|null $lifetime The lifetime in seconds, no expiration when null
     * @return string The JWT, or the static "jwt.token" of the configuration when no topic is given and no secret is configured
     * @throws MercureException When no secret is configured
     */
    public function createPublisherToken(?array $topics = null, ?int $lifetime = null): string;

    /**
     * Creates a JWT allowing to receive the private updates of some topics.
     *
     * @param list<string> $topics The topics (URI templates accepted, "*" for every topic)
     * @param array<string, mixed> $payload Data shared with the other subscribers (the "payload" of the subscriptions API)
     * @param int|null $lifetime The lifetime in seconds, the "cookie.lifetime" of the configuration when null
     * @return string The JWT
     * @throws MercureException When no secret is configured
     */
    public function createSubscriberToken(array $topics, array $payload = [], ?int $lifetime = null): string;

    /**
     * Authorizes the current visitor to receive the private updates of some topics: queues the mercureAuthorization cookie, added to the response.
     *
     * @param list<string> $topics The topics (URI templates accepted, "*" for every topic)
     * @param array<string, mixed> $payload Data shared with the other subscribers
     * @param int|null $lifetime The lifetime of the cookie and of the JWT in seconds, the "cookie.lifetime" of the configuration when null
     * @return string The JWT stored in the cookie
     * @throws MercureException When no secret is configured or the Cookie component is disabled
     */
    public function authorize(array $topics, array $payload = [], ?int $lifetime = null): string;

    /**
     * Removes the mercureAuthorization cookie of the current visitor, after a logout for example.
     *
     * @throws MercureException When the Cookie component is disabled
     */
    public function clearAuthorization(): void;

    /**
     * Adds the Link header announcing the hub (rel="mercure") to a response, for the discovery of the clients.
     *
     * @param Response $response The response
     * @return Response The same response
     * @throws MercureException When no URL is configured
     */
    public function addDiscoveryLink(Response $response): Response;
}