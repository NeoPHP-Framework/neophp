<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Authenticator;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Authentication\Passport;
use NeoPHP\Package\Security\Contract\AbstractAuthenticator;
use NeoPHP\Package\Security\Contract\AccessTokenHandlerInterface;
use NeoPHP\Package\Security\Contract\EntryPointInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\AuthenticationException;
use NeoPHP\Package\Security\Exception\BadCredentialsException;

class AccessTokenAuthenticator extends AbstractAuthenticator implements EntryPointInterface
{
    public const DEFAULT_OPTIONS = [
        'header' => 'Authorization',
        'token_type' => 'Bearer',
        'query_parameter' => null,
        'realm' => null,
    ];

    protected array $options;

    public function __construct(protected AccessTokenHandlerInterface $handler, array $options = [])
    {
        $this->options = array_replace(static::DEFAULT_OPTIONS, array_intersect_key($options, static::DEFAULT_OPTIONS));
    }

    public function supports(Request $request): bool
    {
        return $this->extract($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->extract($request);

        if ($token === null || $token === '') {
            throw new BadCredentialsException('Invalid access token.');
        }

        $user = $this->handler->getUserFrom($token);

        if ($user instanceof UserInterface) {
            return Passport::selfValidating($user->getUserIdentifier())->setUser($user);
        }

        if ($user === '') {
            throw new BadCredentialsException('Invalid access token.');
        }

        return Passport::selfValidating($user);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => $exception->getSafeMessage()], 401, [
            'WWW-Authenticate' => $this->challenge('invalid_token', $exception->getSafeMessage()),
        ]);
    }

    public function start(Request $request, ?AuthenticationException $exception = null): Response
    {
        return new JsonResponse(['error' => 'Authentication required.'], 401, ['WWW-Authenticate' => $this->challenge()]);
    }

    protected function extract(Request $request): ?string
    {
        $header = $request->headers->get((string) $this->options['header']);

        if (is_string($header) && $header !== '') {
            $type = (string) $this->options['token_type'];

            if ($type === '') {
                return trim($header);
            }

            if (preg_match('/^' . preg_quote($type, '/') . '\s+(\S+)$/i', trim($header), $matches) === 1) {
                return $matches[1];
            }

            return null;
        }

        $parameter = $this->options['query_parameter'];
        $value = is_string($parameter) && $parameter !== '' ? $request->query->get($parameter) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function challenge(?string $error = null, ?string $description = null): string
    {
        $parts = [];

        if ($this->options['realm'] !== null) {
            $parts[] = sprintf('realm="%s"', addcslashes((string) $this->options['realm'], '"\\'));
        }

        if ($error !== null) {
            $parts[] = sprintf('error="%s"', $error);
            $parts[] = sprintf('error_description="%s"', addcslashes((string) $description, '"\\'));
        }

        return trim(($this->options['token_type'] !== '' ? $this->options['token_type'] : 'Bearer') . ' ' . implode(', ', $parts));
    }
}