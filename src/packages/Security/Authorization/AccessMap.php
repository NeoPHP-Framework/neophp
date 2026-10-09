<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Authorization;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\Security\Firewall\FirewallMap;

class AccessMap
{
    public function __construct(protected array $rules = [])
    {
    }

    public function getRules(): array
    {
        return $this->rules;
    }

    public function match(Request $request): ?array
    {
        foreach ($this->rules as $rule) {
            if (is_array($rule) && FirewallMap::matches($request, $rule)) {
                return array_values(array_map('strval', (array) ($rule['roles'] ?? [])));
            }
        }

        return null;
    }
}