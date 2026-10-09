<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Exception\AuthenticationException;

interface EntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $exception = null): Response;
}