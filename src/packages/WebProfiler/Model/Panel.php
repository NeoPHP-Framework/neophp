<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Model;

use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Contract\BlockInterface;
use NeoPHP\Package\WebProfiler\Exception\InvalidElementException;

class Panel
{
    protected string $badgeStatus;

    protected array $blocks = [];

    public function __construct(
        protected string $title,
        protected string $icon = 'info',
        array $blocks = [],
        protected string|int|null $badge = null,
        string $badgeStatus = Status::DEFAULT,
        protected bool $disabled = false,
    ) {
        $this->badgeStatus = Status::normalize($badgeStatus);

        foreach ($blocks as $block) {
            $this->add($block);
        }
    }

    public function add(BlockInterface $block): static
    {
        $this->blocks[] = $block;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getBlocks(): array
    {
        return $this->blocks;
    }

    public function getBadge(): string|int|null
    {
        return $this->badge;
    }

    public function setBadge(string|int|null $badge, string $status = Status::DEFAULT): static
    {
        $this->badge = $badge;
        $this->badgeStatus = Status::normalize($status);

        return $this;
    }

    public function getBadgeStatus(): string
    {
        return $this->badgeStatus;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public static function error(string $name, string $message): static
    {
        return new static($name, 'exception', [
            new AlertBlock($message, Status::DANGER, sprintf('The profiler element "%s" failed.', $name)),
        ], '!', Status::DANGER);
    }

    public static function assertBlocks(array $blocks): array
    {
        foreach ($blocks as $block) {
            if (!$block instanceof BlockInterface) {
                throw new InvalidElementException('Profiler blocks must implement {interface}, "{type}" given.', 0, null, [
                    'interface' => BlockInterface::class,
                    'type' => get_debug_type($block),
                ]);
            }
        }

        return array_values($blocks);
    }
}