<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Contract\UserInterface;

class LoginSuccessEvent extends AbstractEvent
{
    public function __construct(
        protected TokenInterface $token,
        protected string $firewall,
        protected ?Request $request = null,
        protected ?Response $response = null,
        protected ?string $authenticator = null,
    ) {
    }

    public function getToken(): TokenInterface
    {
        return $this->token;
    }

    public function getUser(): ?UserInterface
    {
        return $this->token->getUser();
    }

    public function getFirewall(): string
    {
        return $this->firewall;
    }

    public function getRequest(): ?Request
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