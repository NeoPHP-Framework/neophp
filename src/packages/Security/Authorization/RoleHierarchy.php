<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Authorization;

class RoleHierarchy
{
    protected array $map = [];

    public function __construct(array $hierarchy = [])
    {
        foreach ($hierarchy as $role => $children) {
            $this->map[(string) $role] = $this->expand((string) $role, $hierarchy, []);
        }
    }

    public function getReachableRoleNames(array $roles): array
    {
        $reachable = [];

        foreach ($roles as $role) {
            $role = (string) $role;
            $reachable[$role] = $role;

            foreach ($this->map[$role] ?? [] as $child) {
                $reachable[$child] = $child;
            }
        }

        return array_values($reachable);
    }

    public function getMap(): array
    {
        return $this->map;
    }

    protected function expand(string $role, array $hierarchy, array $visited): array
    {
        if (isset($visited[$role])) {
            return [];
        }

        $visited[$role] = true;
        $roles = [];

        foreach ((array) ($hierarchy[$role] ?? []) as $child) {
            $child = (string) $child;
            $roles[$child] = $child;

            foreach ($this->expand($child, $hierarchy, $visited) as $grandChild) {
                $roles[$grandChild] = $grandChild;
            }
        }

        unset($roles[$role]);

        return array_values($roles);
    }
}