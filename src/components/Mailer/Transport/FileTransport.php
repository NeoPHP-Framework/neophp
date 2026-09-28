<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Transport;

use NeoPHP\Component\Mailer\Contract\AbstractTransport;
use NeoPHP\Component\Mailer\Exception\TransportException;
use NeoPHP\Component\Mailer\Message\SentMessage;

class FileTransport extends AbstractTransport
{
    public function __construct(protected string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function __toString(): string
    {
        return 'file://' . $this->directory;
    }

    protected function doSend(SentMessage $message): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new TransportException('Unable to create the directory "{directory}".', 0, null, ['directory' => $this->directory]);
        }

        $file = $this->directory . DIRECTORY_SEPARATOR . date('Ymd-His') . '-' . substr(hash('sha1', $message->getMessageId()), 0, 8) . '.eml';

        if (file_put_contents($file, $message->toString()) === false) {
            throw new TransportException('Unable to write the email in "{file}".', 0, null, ['file' => $file]);
        }

        $message->setTransportId($file);
        $message->appendDebug('* Written to ' . $file . "\n");
    }
}