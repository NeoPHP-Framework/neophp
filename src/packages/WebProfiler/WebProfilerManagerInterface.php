<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Contract\ProfilerElementInterface;
use NeoPHP\Package\WebProfiler\Contract\ProfileStorageInterface;
use NeoPHP\Package\WebProfiler\Exception\InvalidElementException;
use NeoPHP\Package\WebProfiler\Exception\StorageException;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use NeoPHP\Package\WebProfiler\Stopwatch\Stopwatch;
use Throwable;

interface WebProfilerManagerInterface
{
    /**
     * Tells whether the requests are profiled.
     *
     * @return bool True when the profiler is enabled
     */
    public function isEnabled(): bool;

    /**
     * Enables the profiling of the requests.
     *
     * @return static The profiler
     */
    public function enable(): static;

    /**
     * Disables the profiling of the requests (for a download or a stream, for example).
     *
     * @return static The profiler
     */
    public function disable(): static;

    /**
     * Tells whether the toolbar is injected in the HTML responses.
     *
     * @return bool True when the profiler and the toolbar are enabled
     */
    public function isToolbarEnabled(): bool;

    /**
     * Returns the configuration of web_profiler.yaml with its defaults.
     *
     * @return array<string, mixed> The configuration
     */
    public function getConfig(): array;

    /**
     * Returns the storage of the profiles.
     *
     * @return ProfileStorageInterface The storage (var/profiler by default)
     */
    public function getStorage(): ProfileStorageInterface;

    /**
     * Returns the stopwatch measuring the events shown in the Performance timeline.
     *
     * @return Stopwatch The stopwatch
     */
    public function getStopwatch(): Stopwatch;

    /**
     * Returns the path prefix of the profiler pages, without the base path.
     *
     * @return string The path (/_profiler by default)
     */
    public function getPath(): string;

    /**
     * Returns the path prefix of the toolbar fragment, without the base path.
     *
     * @return string The path (/_wdt by default)
     */
    public function getToolbarPath(): string;

    /**
     * Sets the sub-directory of the application, added to the profiler URLs.
     *
     * @param string $basePath The base path of the request
     * @return static The profiler
     */
    public function setBasePath(string $basePath): static;

    /**
     * Returns the sub-directory of the application.
     *
     * @return string The base path, empty when the application is at the root
     */
    public function getBasePath(): string;

    /**
     * Returns the URL of the profiler pages, with the base path.
     *
     * @return string The public path of the profiler
     */
    public function getPublicPath(): string;

    /**
     * Returns the URL of a profile.
     *
     * @param string $token Token of the profile
     * @param string|null $panel Name of the panel to open, the first panel when null
     * @return string The URL of the profile page
     */
    public function getProfileUrl(string $token, ?string $panel = null): string;

    /**
     * Returns the URL of the toolbar fragment of a profile.
     *
     * @param string $token Token of the profile
     * @return string The URL of the toolbar
     */
    public function getToolbarUrl(string $token): string;

    /**
     * Tells whether the client of a request may see the toolbar and the profiles (allowed_ips).
     *
     * @param Request $request The request
     * @return bool True when the client IP is allowed
     */
    public function isAllowed(Request $request): bool;

    /**
     * Tells whether a request is not profiled: the profiler and toolbar paths, and the excluded_paths.
     *
     * @param Request $request The request
     * @return bool True when the request is not profiled
     */
    public function isExcluded(Request $request): bool;

    /**
     * Registers a profiler element in addition to the discovered ones.
     *
     * @param ProfilerElementInterface $element The element
     * @param int|null $priority Priority of the element, highest first; its getPriority() when null
     * @return static The profiler
     */
    public function addElement(ProfilerElementInterface $element, ?int $priority = null): static;

    /**
     * Returns the profiler elements, discovered and built on first use, sorted by priority.
     *
     * @return array<string, ProfilerElementInterface> The elements, by name
     * @throws InvalidElementException When an element does not implement ProfilerElementInterface or two elements share the same name
     */
    public function getElements(): array;

    /**
     * Returns a profiler element.
     *
     * @param string $name Name of the element
     * @return ProfilerElementInterface|null The element, or null when it does not exist
     * @throws InvalidElementException When an element does not implement ProfilerElementInterface or two elements share the same name
     */
    public function getElement(string $name): ?ProfilerElementInterface;

    /**
     * Keeps the exception of the current request for the elements.
     *
     * @param Throwable|null $exception The exception, or null to forget it
     * @return static The profiler
     */
    public function setException(?Throwable $exception): static;

    /**
     * Returns the exception of the current request.
     *
     * @return Throwable|null The exception, or null when the request did not fail
     */
    public function getException(): ?Throwable;

    /**
     * Builds the profile of a request: every element collects its data; an element that throws stores its error instead.
     *
     * @param Request $request The request
     * @param Response $response The response
     * @param Throwable|null $exception The exception of the request, the kept exception when null
     * @return Profile The profile, not yet saved
     * @throws InvalidElementException When an element does not implement ProfilerElementInterface or two elements share the same name
     */
    public function collect(Request $request, Response $response, ?Throwable $exception = null): Profile;

    /**
     * Saves a profile in the storage, then purges the old profiles.
     *
     * @param Profile $profile The profile
     * @return void
     * @throws StorageException When the profile or the index cannot be written
     */
    public function save(Profile $profile): void;

    /**
     * Loads a profile from the storage.
     *
     * @param string $token Token of the profile
     * @return Profile|null The profile, or null when it does not exist
     * @throws StorageException When the token is invalid
     */
    public function load(string $token): ?Profile;

    /**
     * Returns the summaries of the last profiles, newest first.
     *
     * @param int $limit Maximum number of profiles
     * @param array<string, string> $filters Filters: ip, url (contains), method, status (404 or 4xx), token (prefix), route
     * @return list<array<string, mixed>> The summaries of the profiles
     */
    public function find(int $limit = 50, array $filters = []): array;

    /**
     * Returns the toolbar items of a profile; an element that throws is skipped.
     *
     * @param Profile $profile The profile
     * @return array<string, ToolbarItem> The items, by element name
     * @throws InvalidElementException When an element does not implement ProfilerElementInterface or two elements share the same name
     */
    public function getToolbarItems(Profile $profile): array;

    /**
     * Returns the CSS and JavaScript added to the toolbar by the elements.
     *
     * @param Profile $profile The profile
     * @return array{css: array<string, string>, js: array<string, string>} The assets of every element, by type and element name
     * @throws InvalidElementException When an element does not implement ProfilerElementInterface or two elements share the same name
     */
    public function getToolbarAssets(Profile $profile): array;

    /**
     * Returns the panels of a profile; an element that throws gets an error panel.
     *
     * @param Profile $profile The profile
     * @return array<string, Panel> The panels, by element name
     * @throws InvalidElementException When an element does not implement ProfilerElementInterface or two elements share the same name
     */
    public function getPanels(Profile $profile): array;
}