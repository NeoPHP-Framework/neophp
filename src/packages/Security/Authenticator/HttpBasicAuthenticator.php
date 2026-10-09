<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Authenticator;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Authentication\Passport;
use NeoPHP\Package\Security\Contract\AbstractAuthenticator;
use NeoPHP\Package\Security\Contract\EntryPointInterface;
use NeoPHP\Package\Security\Exception\AuthenticationException;
use NeoPHP\Package\Security\Exception\BadCredentialsException;

class HttpBasicAuthenticator extends AbstractAuthenticator implements EntryPointInterface
{
    public function __construct(protected string $realm = 'Secured Area')
    {
    }

    public function supports(Request $request): bool
    {
        return stripos((string) $request->headers->get('Authorization'), 'Basic ') === 0;
    }

    public function authenticate(Request $request): Passport
    {
        $decoded = base64_decode(trim(substr((string) $request->headers->get('Authorization'), 6)), true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            throw new BadCredentialsException('The HTTP Basic credentials are malformed.');
        }

        [$username, $password] = explode(':', $decoded, 2);

        if ($username === '' || $password === '') {
            throw new BadCredentialsException('The HTTP Basic credentials are empty.');
        }

        return new Passport($username, $password);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->start($request, $exception);
    }

    public function start(Request $request, ?AuthenticationException $exception = null): Response
    {
        $status = $exception?->getStatusCode() === 429 ? 429 : 401;
        $headers = ['WWW-Authenticate' => sprintf('Basic realm="%s"', addcslashes($this->realm, '"\\'))] + ($exception?->getHeaders() ?? []);
        $message = $exception?->getSafeMessage() ?? 'Authentication required.';

        if ($request->wantsJson() || $request->isJson()) {
            return new JsonResponse(['error' => $message], $status, $headers);
        }

        return new Response($message, $status, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}