<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Security;

use RecursiveFilterIterator;
use RecursiveIterator;
use SplFileInfo;

class SandboxFilterIterator extends RecursiveFilterIterator
{
    public function __construct(RecursiveIterator $iterator, protected Sandbox $sandbox)
    {
        parent::__construct($iterator);
    }

    public function accept(): bool
    {
        $file = $this->current();

        return $file instanceof SplFileInfo && !$file->isLink() && !$this->sandbox->isExcluded($this->sandbox->relative($file->getPathname()));
    }

    public function getChildren(): ?RecursiveFilterIterator
    {
        $inner = $this->getInnerIterator();

        if (!$inner instanceof RecursiveIterator || ($children = $inner->getChildren()) === null) {
            return null;
        }

        return new static($children, $this->sandbox);
    }
}