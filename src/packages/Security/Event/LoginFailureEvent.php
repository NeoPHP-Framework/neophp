<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Exception\AuthenticationException;

class LoginFailureEvent extends AbstractEvent
{
    public function __construct(
        protected AuthenticationException $exception,
        protected string $firewall,
        protected Request $request,
        protected ?Response $response = null,
        protected ?string $authenticator = null,
    ) {
    }

    public function getException(): AuthenticationException
    {
        return $this->exception;
    }

    public function getFirewall(): string
    {
        return $this->firewall;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getAuthenticator(): ?string
    {
        return $this->authenticator;
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }

    public function setResponse(?Response $response): void
    {
        $this->response = $response;
    }
}