<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\ApiToken;

use NeoPHP\Package\Security\Contract\AccessTokenHandlerInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\BadCredentialsException;

class ApiTokenHandler implements AccessTokenHandlerInterface
{
    protected ?array $current = null;

    public function __construct(protected ApiTokenManager $tokens)
    {
    }

    public function getUserFrom(string $accessToken): string|UserInterface
    {
        $this->current = $this->tokens->find($accessToken);

        if ($this->current === null) {
            throw new BadCredentialsException('Invalid access token.');
        }

        return (string) $this->current['identifier'];
    }

    public function getCurrent(): ?array
    {
        return $this->current;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) ($this->current['scopes'] ?? []), true);
    }
}