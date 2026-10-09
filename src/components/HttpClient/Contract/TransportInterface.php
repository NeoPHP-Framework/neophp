<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Contract;

use NeoPHP\Component\HttpClient\Request\Request;
use NeoPHP\Component\HttpClient\Response\Response;

interface TransportInterface
{
    public function send(Request $request): Response;

    public function sendMany(array $requests): array;

    public function getName(): string;
}