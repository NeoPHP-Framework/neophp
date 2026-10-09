<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Authenticator;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Authentication\Passport;
use NeoPHP\Package\Security\Contract\AbstractAuthenticator;
use NeoPHP\Package\Security\Contract\EntryPointInterface;
use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Exception\AuthenticationException;
use NeoPHP\Package\Security\Exception\BadCredentialsException;
use NeoPHP\Package\Security\Firewall\HttpUtils;
use NeoPHP\Package\Security\SecurityManagerInterface;

class FormLoginAuthenticator extends AbstractAuthenticator implements EntryPointInterface
{
    public const DEFAULT_OPTIONS = [
        'login_path' => '/login',
        'check_path' => null,
        'username_parameter' => '_username',
        'password_parameter' => '_password',
        'remember_me_parameter' => '_remember_me',
        'enable_csrf' => false,
        'csrf_parameter' => '_csrf_token',
        'csrf_token_id' => 'authenticate',
        'default_target_path' => '/',
        'always_use_default_target_path' => false,
        'target_path_parameter' => '_target_path',
        'use_referer' => false,
        'failure_path' => null,
        'post_only' => true,
    ];

    protected array $options;

    public function __construct(protected HttpUtils $http, protected string $firewall, array $options = [])
    {
        $this->options = array_replace(static::DEFAULT_OPTIONS, array_intersect_key($options, static::DEFAULT_OPTIONS));
        $this->options['check_path'] ??= $this->options['login_path'];
    }

    public function getOption(string $name): mixed
    {
        return $this->options[$name] ?? null;
    }

    public function supports(Request $request): bool
    {
        if ($this->options['post_only'] && $request->getMethod() !== 'POST') {
            return false;
        }

        return $this->http->checkRequestPath($request, (string) $this->options['check_path']);
    }

    public function authenticate(Request $request): Passport
    {
        $username = $this->parameter($request, (string) $this->options['username_parameter']);
        $password = $this->parameter($request, (string) $this->options['password_parameter']);

        if (!is_string($username) || trim($username) === '' || strlen($username) > 4096) {
            throw new BadCredentialsException('The username must be a non-empty string.');
        }

        $username = trim($username);
        $this->http->getSession()->set(SecurityManagerInterface::LAST_USERNAME, $username);

        if (!is_string($password) || $password === '') {
            throw new BadCredentialsException('The password must be a non-empty string.');
        }

        $passport = new Passport($username, $password);

        if ($this->options['enable_csrf']) {
            $token = $this->parameter($request, (string) $this->options['csrf_parameter']);
            $passport->csrf((string) $this->options['csrf_token_id'], is_string($token) ? $token : null);
        }

        $remember = $this->parameter($request, (string) $this->options['remember_me_parameter']);

        if (in_array(is_string($remember) ? strtolower($remember) : $remember, [true, 1, '1', 'on', 'yes', 'true'], true)) {
            $passport->rememberMe();
        }

        return $passport;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewall): ?Response
    {
        $session = $this->http->getSession();
        $session->remove(SecurityManagerInterface::LAST_ERROR);
        $session->remove(SecurityManagerInterface::LAST_USERNAME);

        return new RedirectResponse($this->targetPath($request, $firewall));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($request->wantsJson() || $request->isJson()) {
            return new JsonResponse(['error' => $exception->getSafeMessage()], $exception->getStatusCode(), $exception->getHeaders());
        }

        $this->http->getSession()->set(SecurityManagerInterface::LAST_ERROR, $exception->getSafeMessage());

        return $this->http->createRedirectResponse((string) ($this->options['failure_path'] ?? $this->options['login_path']));
    }

    public function start(Request $request, ?AuthenticationException $exception = null): Response
    {
        if ($request->wantsJson() || $request->isJson() || $request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => 'Authentication required.'], 401);
        }

        if ($request->getMethod() === 'GET') {
            $this->http->getSession()->set(self::targetPathKey($this->firewall), $this->http->getRequestTarget($request));
        }

        return $this->http->createRedirectResponse((string) $this->options['login_path']);
    }

    public static function targetPathKey(string $firewall): string
    {
        return '_security.' . $firewall . '.target_path';
    }

    protected function targetPath(Request $request, string $firewall): string
    {
        $session = $this->http->getSession();
        $saved = $session->remove(self::targetPathKey($firewall));

        if ($this->options['always_use_default_target_path']) {
            return $this->http->generateUrl((string) $this->options['default_target_path']);
        }

        $target = $this->parameter($request, (string) $this->options['target_path_parameter']);

        if ($this->http->isSafeTargetPath($target)) {
            return $target;
        }

        if ($this->http->isSafeTargetPath($saved)) {
            return $saved;
        }

        if ($this->options['use_referer']) {
            $referer = $request->headers->get('Referer');
            $prefix = $request->getSchemeAndHttpHost();

            if (is_string($referer) && str_starts_with($referer, $prefix . '/')) {
                $path = substr($referer, strlen($prefix));

                $login = (string) parse_url($this->http->generateUrl((string) $this->options['login_path']), PHP_URL_PATH);

                if ($this->http->isSafeTargetPath($path) && rtrim((string) parse_url($path, PHP_URL_PATH), '/') !== rtrim($login, '/')) {
                    return $path;
                }
            }
        }

        return $this->http->generateUrl((string) $this->options['default_target_path']);
    }

    protected function parameter(Request $request, string $name): mixed
    {
        return $request->request->get($name) ?? $request->query->get($name);
    }
}