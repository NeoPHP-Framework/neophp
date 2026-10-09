<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Connection;

use NeoPHP\Component\Database\Contract\AbstractConnection;
use NeoPHP\Component\Database\Contract\DriverInterface;
use PDO;

class Connection extends AbstractConnection
{
    public function __construct(string $name, DriverInterface $driver, array $params = [], ?PDO $pdo = null)
    {
        $this->name = $name;
        $this->driver = $driver;
        $this->params = $params;
        $this->pdo = $pdo;
    }
}