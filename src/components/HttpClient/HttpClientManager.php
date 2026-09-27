<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient;

use NeoPHP\Component\Event\Contract\EventDispatcherInterface;
use NeoPHP\Component\HttpClient\Contract\AbstractHttpClient;
use NeoPHP\Component\HttpClient\Contract\TransportInterface;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\Logger\Contract\LoggerManagerInterface;

class HttpClientManager extends AbstractHttpClient
{
    public function __construct(TransportInterface $transport, ?EventDispatcherInterface $events = null, ?LoggerInterface $logger = null, array $config = [])
    {
        $this->transport = $transport;
        $this->events = $events;
        $this->logger = $logger instanceof LoggerManagerInterface && $logger->hasChannel(self::LOG_CHANNEL) ? $logger->channel(self::LOG_CHANNEL) : $logger;
        $this->config = array_replace(self::DEFAULT_CONFIG, $config);
        $this->options = $this->mergeOptions(self::DEFAULT_OPTIONS, (array) ($this->config['default_options'] ?? []));
    }
}