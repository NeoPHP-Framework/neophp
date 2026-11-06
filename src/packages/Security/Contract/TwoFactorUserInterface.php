<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

interface TwoFactorUserInterface
{
    public function getTotpSecret(): ?string;

    public function getBackupCodeHashes(): array;
}