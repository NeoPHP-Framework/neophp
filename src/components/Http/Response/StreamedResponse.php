<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Response;

use Closure;
use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Request\Request;

class StreamedResponse extends Response
{
    protected ?Closure $callback = null;

    protected bool $streamed = false;

    protected bool $headOnly = false;

    /**
     * @param callable|null $callback Writes the body with echo, printf() or fwrite(STDOUT); called once by sendContent()
     * @param int $status HTTP status code
     * @param array<string, mixed> $headers HTTP headers, by name
     */
    public function __construct(?callable $callback = null, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);

        if ($callback !== null) {
            $this->setCallback($callback);
        }
    }

    public function setCallback(callable $callback): static
    {
        $this->callback = Closure::fromCallable($callback);

        return $this;
    }

    public function getCallback(): ?Closure
    {
        return $this->callback;
    }

    /**
     * The body of a streamed response is only known while it is sent.
     */
    public function getContent(): string
    {
        return '';
    }

    /**
     * @throws HttpException When a non-empty content is given: the body is written by the callback
     */
    public function setContent(string $content): static
    {
        if ($content !== '') {
            throw new HttpException(500, 'The content of a streamed response cannot be set: write it in the callback.');
        }

        return $this;
    }

    public function prepare(Request $request): static
    {
        parent::prepare($request);

        $this->headOnly = $request->isMethod('HEAD');
        $this->headers->remove('Content-Length');

        return $this;
    }

    /**
     * Runs the callback once, flushing the output buffers so that every chunk reaches the client.
     *
     * @throws HttpException When no callback is set
     */
    public function sendContent(): static
    {
        if ($this->streamed) {
            return $this;
        }

        $this->streamed = true;

        if ($this->headOnly || $this->isEmpty()) {
            return $this;
        }

        if ($this->callback === null) {
            throw new HttpException(500, 'The streamed response has no callback.');
        }

        ($this->callback)();
        static::flushOutput();

        return $this;
    }

    public function isStreamed(): bool
    {
        return $this->streamed;
    }

    /**
     * Sends what was written so far to the client: call it in the callback after each chunk.
     */
    public static function flushOutput(): void
    {
        if (ob_get_level() > 0 && PHP_SAPI !== 'cli') {
            @ob_flush();
        }

        flush();
    }
}