<?php

declare(strict_types=1);

namespace NeoPHP\Component\Middleware\Pipeline;

use Closure;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Middleware\Contract\MiddlewareInterface;
use NeoPHP\Component\Middleware\Contract\RequestHandlerInterface;
use NeoPHP\Component\Middleware\Exception\MiddlewareException;

class Pipeline implements RequestHandlerInterface
{
    protected Closure $handler;

    protected Closure $resolver;

    public function __construct(protected array $middlewares, callable $handler, callable $resolver)
    {
        $this->middlewares = array_values($middlewares);
        $this->handler = Closure::fromCallable($handler);
        $this->resolver = Closure::fromCallable($resolver);
    }

    public function handle(Request $request): Response
    {
        if ($this->middlewares === []) {
            $response = ($this->handler)($request);

            if (!$response instanceof Response) {
                throw new MiddlewareException('The final request handler must return a Response ({type} returned).', 0, null, ['type' => get_debug_type($response)]);
            }

            return $response;
        }

        $middlewares = $this->middlewares;
        $middleware = ($this->resolver)(array_shift($middlewares));

        if (!$middleware instanceof MiddlewareInterface) {
            throw new MiddlewareException('The middleware "{middleware}" must implement {interface}.', 0, null, [
                'middleware' => get_debug_type($middleware),
                'interface' => MiddlewareInterface::class,
            ]);
        }

        return $middleware->process($request, new static($middlewares, $this->handler, $this->resolver));
    }
}