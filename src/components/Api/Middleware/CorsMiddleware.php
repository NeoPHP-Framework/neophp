<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Middleware;

use NeoPHP\Component\Api\Cors\CorsManager;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Middleware\Contract\MiddlewareInterface;
use NeoPHP\Component\Middleware\Contract\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(protected CorsManager $cors)
    {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $options = $this->cors->resolve($request, $request->attributes->get('_controller')) ?? $this->cors->forPath($request->getPath()) ?? $this->cors->getDefaults();

        if ($this->cors->isPreflight($request)) {
            $request->attributes->set(CorsManager::HANDLED_ATTRIBUTE, true);

            return $this->cors->preflight($request, $options);
        }

        $response = $handler->handle($request);
        $request->attributes->set(CorsManager::HANDLED_ATTRIBUTE, true);

        return $this->cors->apply($request, $response, $options);
    }
}