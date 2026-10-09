<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Contract\TokenInterface;

class LogoutEvent extends AbstractEvent
{
    public function __construct(
        protected Request $request,
        protected ?TokenInterface $token,
        protected Response $response,
        protected string $firewall,
    ) {
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getToken(): ?TokenInterface
    {
        return $this->token;
    }

    public function getFirewall(): string
    {
        return $this->firewall;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }
}