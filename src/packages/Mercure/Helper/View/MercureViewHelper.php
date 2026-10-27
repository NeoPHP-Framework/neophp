<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Mercure\Exception\MercureException;
use NeoPHP\Package\Mercure\MercureManagerInterface;

/**
 * @internal
 */
class MercureViewHelper implements ViewFunctionInterface
{
    public function __construct(protected MercureManagerInterface $mercure)
    {
    }

    public function getName(): string
    {
        return 'mercure';
    }

    /**
     * @param string|list<string> $topics
     * @param array{subscribe?: bool|string|list<string>, payload?: array<string, mixed>, lifetime?: int|null} $options
     * @throws MercureException
     */
    public function __invoke(string|array $topics = [], array $options = []): string
    {
        $subscribe = $options['subscribe'] ?? false;

        if ($subscribe !== false && $subscribe !== null) {
            $this->mercure->authorize(
                $subscribe === true ? array_values((array) $topics) : array_values((array) $subscribe),
                (array) ($options['payload'] ?? []),
                isset($options['lifetime']) ? (int) $options['lifetime'] : null,
            );
        }

        return $this->mercure->getSubscribeUrl($topics);
    }
}