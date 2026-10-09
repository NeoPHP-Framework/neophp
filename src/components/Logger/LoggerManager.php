<?php

declare(strict_types=1);

namespace NeoPHP\Component\Logger;

use DateTimeZone;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Logger\Channel\Channel;
use NeoPHP\Component\Logger\Contract\AbstractLogger;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\Logger\Contract\LogLevel;
use NeoPHP\Component\Logger\Exception\LoggerException;
use NeoPHP\Component\Logger\Formatter\LineFormatter;
use NeoPHP\Component\Logger\Provider\LoggerProvider;
use NeoPHP\Component\Logger\Writer\Archiver;
use NeoPHP\Component\Logger\Writer\FileWriter;
use Stringable;
use Throwable;

#[Component(provider: LoggerProvider::class)]
final class LoggerManager extends AbstractLogger implements LoggerManagerInterface
{
    public const DEFAULT_CHANNEL = 'app';

    protected array $channels = [];

    public function __construct(array $channels = [], protected string $defaultChannel = self::DEFAULT_CHANNEL)
    {
        foreach ($channels as $channel) {
            $this->addChannel($channel);
        }
    }

    public static function fromConfig(array $config, string $defaultPath): static
    {
        $settings = (array) ($config['settings'] ?? []);
        $rotation = (array) ($config['rotation'] ?? []);
        $when = (array) ($rotation['when'] ?? []);
        $archive = (array) ($config['archive'] ?? []);
        $path = rtrim((string) ($settings['path'] ?? $defaultPath), '/\\');
        $minimumLevel = LogLevel::normalize($settings['minimum_level'] ?? LogLevel::DEBUG);
        $formatter = new LineFormatter(
            (string) ($settings['format_message'] ?? LineFormatter::DEFAULT_FORMAT),
            (string) ($settings['date_format'] ?? LineFormatter::DEFAULT_DATE_FORMAT),
        );
        $timezone = static::createTimezone($settings['timezone'] ?? null);
        $archiver = (bool) ($archive['enabled'] ?? false) ? new Archiver((string) ($archive['extension'] ?? 'zip')) : null;
        $channels = $config['channels'] ?? [self::DEFAULT_CHANNEL => ['enabled' => true]];

        if (!is_array($channels) || $channels === []) {
            throw new LoggerException('The logger configuration must define at least one channel in "channels".');
        }

        $manager = new static([], (string) ($settings['default_channel'] ?? array_key_first($channels)));

        foreach ($channels as $name => $options) {
            $options = (array) ($options ?? []);
            $enabled = (bool) ($options['enabled'] ?? true);
            $extension = ltrim((string) ($options['extension'] ?? 'log'), '.');
            $file = rtrim((string) ($options['path'] ?? $path), '/\\') . DIRECTORY_SEPARATOR . $name . ($extension !== '' ? '.' . $extension : '');

            $writer = $enabled ? new FileWriter(
                $file,
                (bool) ($rotation['enabled'] ?? false),
                $when['filesize'] ?? null,
                isset($when['every']) ? (string) $when['every'] : null,
                isset($rotation['max_files']) ? (int) $rotation['max_files'] : null,
                $archiver,
            ) : null;

            $manager->addChannel(new Channel(
                (string) $name,
                $writer,
                $formatter,
                LogLevel::normalize($options['minimum_level'] ?? $minimumLevel),
                $enabled,
                $timezone,
            ));
        }

        return $manager;
    }

    public function addChannel(Channel $channel): static
    {
        $this->channels[$channel->getName()] = $channel;

        return $this;
    }

    public function channel(string $name): LoggerInterface
    {
        if (!isset($this->channels[$name])) {
            throw new LoggerException('The log channel "{channel}" is not defined. Available channels: {channels}.', 0, null, [
                'channel' => $name,
                'channels' => implode(', ', array_keys($this->channels)),
            ]);
        }

        return $this->channels[$name];
    }

    public function hasChannel(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    public function getChannels(): array
    {
        return $this->channels;
    }

    public function getDefaultChannel(): string
    {
        return $this->defaultChannel;
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->channel($this->defaultChannel)->log($level, $message, $context);
    }

    protected static function createTimezone(mixed $timezone): ?DateTimeZone
    {
        if ($timezone === null || $timezone === '') {
            return null;
        }

        try {
            return new DateTimeZone((string) $timezone);
        } catch (Throwable $exception) {
            throw new LoggerException('Invalid logger timezone "{timezone}".', 0, $exception, ['timezone' => (string) $timezone]);
        }
    }
}