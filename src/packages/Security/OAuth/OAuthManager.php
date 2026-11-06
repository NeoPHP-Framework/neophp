<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\OAuth;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;
use NeoPHP\Package\Security\Exception\CustomUserMessageAuthenticationException;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\Firewall\HttpUtils;
use Throwable;

class OAuthManager
{
    public const STATE = '_security.oauth.state';

    public const PRESETS = [
        'google' => [
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scopes' => ['openid', 'email', 'profile'],
            'id_field' => 'sub',
        ],
        'github' => [
            'authorize_url' => 'https://github.com/login/oauth/authorize',
            'token_url' => 'https://github.com/login/oauth/access_token',
            'userinfo_url' => 'https://api.github.com/user',
            'scopes' => ['read:user', 'user:email'],
            'id_field' => 'id',
        ],
    ];

    protected array $providers = [];

    protected string $prefix;

    public function __construct(protected HttpUtils $http, protected HttpClientManagerInterface $client, protected array $config = [])
    {
        $this->prefix = '/' . trim((string) ($config['prefix'] ?? '/oauth'), '/');

        foreach ((array) ($config['providers'] ?? []) as $name => $options) {
            $this->providers[(string) $name] = array_replace(['id_field' => 'sub', 'scopes' => ['openid', 'email', 'profile']], self::PRESETS[(string) $name] ?? [], (array) $options);
        }
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function redirect(string $provider): RedirectResponse
    {
        $options = $this->provider($provider);
        $state = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $session = $this->http->getSession();
        $session->start();
        $session->set(self::STATE, ['provider' => $provider, 'state' => $state, 'verifier' => $verifier]);

        return new RedirectResponse((string) $options['authorize_url'] . '?' . http_build_query([
                'response_type' => 'code',
                'client_id' => (string) $options['client_id'],
                'redirect_uri' => $this->callbackUrl($provider),
                'scope' => implode(' ', array_map('strval', (array) $options['scopes'])),
                'state' => $state,
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
            ], '', '&', PHP_QUERY_RFC3986));
    }

    public function matchCallback(Request $request): ?string
    {
        foreach (array_keys($this->providers) as $provider) {
            if ($this->http->checkRequestPath($request, $this->callbackPath((string) $provider))) {
                return (string) $provider;
            }
        }

        return null;
    }

    public function fetchUser(string $provider, Request $request): OAuthUser
    {
        $options = $this->provider($provider);
        $session = $this->http->getSession();
        $saved = $session->remove(self::STATE);
        $state = $request->query->get('state');
        $code = $request->query->get('code');

        if (!is_array($saved) || ($saved['provider'] ?? null) !== $provider || !is_string($state) || !hash_equals((string) ($saved['state'] ?? ''), $state) || !is_string($code) || $code === '') {
            throw new CustomUserMessageAuthenticationException('The login with {provider} failed.', ['provider' => $provider]);
        }

        try {
            $token = $this->client->post((string) $options['token_url'], [
                'headers' => ['Accept' => 'application/json'],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->callbackUrl($provider),
                    'client_id' => (string) $options['client_id'],
                    'client_secret' => (string) $options['client_secret'],
                    'code_verifier' => (string) $saved['verifier'],
                ],
            ])->toArray();

            $data = $this->client->get((string) $options['userinfo_url'], [
                'headers' => ['Accept' => 'application/json'],
                'auth_bearer' => (string) ($token['access_token'] ?? ''),
            ])->toArray();
        } catch (Throwable $exception) {
            throw new CustomUserMessageAuthenticationException('The login with {provider} failed.', ['provider' => $provider], $exception);
        }

        $id = $data[(string) $options['id_field']] ?? null;

        if (!is_scalar($id) || (string) $id === '') {
            throw new CustomUserMessageAuthenticationException('The login with {provider} failed.', ['provider' => $provider]);
        }

        return new OAuthUser(
            $provider,
            (string) $id,
            is_string($data['email'] ?? null) ? $data['email'] : null,
            ($data['email_verified'] ?? false) === true,
            is_string($data['name'] ?? null) ? $data['name'] : null,
            $data,
        );
    }

    protected function provider(string $name): array
    {
        $options = $this->providers[$name] ?? throw new SecurityException('The OAuth provider "{provider}" is not configured under oauth.providers.', 0, null, ['provider' => $name]);

        foreach (['client_id', 'client_secret', 'authorize_url', 'token_url', 'userinfo_url'] as $key) {
            if ((string) ($options[$key] ?? '') === '') {
                throw new SecurityException('The OAuth provider "{provider}" needs the "{key}" option.', 0, null, ['provider' => $name, 'key' => $key]);
            }
        }

        return $options;
    }

    protected function callbackPath(string $provider): string
    {
        return $this->prefix . '/' . $provider . '/callback';
    }

    protected function callbackUrl(string $provider): string
    {
        $request = $this->http->getRequest() ?? throw new SecurityException('The OAuth login needs an HTTP request.');

        return $request->getSchemeAndHttpHost() . $this->http->generateUrl($this->callbackPath($provider));
    }
}