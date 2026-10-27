<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Channel;

use NeoPHP\Component\Mailer\MailerManagerInterface;
use NeoPHP\Component\Mailer\Mime\Email;
use NeoPHP\Package\Notifier\Contract\ChannelInterface;
use NeoPHP\Package\Notifier\Contract\EmailNotificationInterface;
use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\Exception\NotifierException;
use NeoPHP\Package\Notifier\Model\Notification;
use Throwable;

/**
 * @internal
 */
class EmailChannel implements ChannelInterface
{
    public const NAME = 'email';

    public function __construct(protected ?MailerManagerInterface $mailer, protected ?string $from = null, protected string $baseUrl = '')
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function unavailableReason(Notification $notification, RecipientInterface $recipient): ?string
    {
        if ($this->mailer === null) {
            return 'the Mailer component is disabled';
        }

        if ($notification instanceof EmailNotificationInterface) {
            return null;
        }

        $email = $recipient->getNotificationEmail();

        return $email === null || $email === '' ? 'the recipient has no email address' : null;
    }

    public function send(Notification $notification, RecipientInterface $recipient): void
    {
        if ($this->mailer === null) {
            return;
        }

        $email = $notification instanceof EmailNotificationInterface ? $notification->toEmail($recipient) : $this->createEmail($notification);

        if ($email === null) {
            return;
        }

        if ($email->getTo() === [] && $email->getCc() === [] && $email->getBcc() === []) {
            $address = $recipient->getNotificationEmail();

            if ($address === null || $address === '') {
                throw new NotifierException('The email of the notification has no recipient and the recipient has no email address.');
            }

            $email->to($address);
        }

        if ($email->getFrom() === [] && $this->from !== null && $this->from !== '') {
            $email->from($this->from);
        }

        try {
            $this->mailer->send($email);
        } catch (Throwable $exception) {
            throw new NotifierException('The notification email cannot be sent: {error}', 0, $exception, ['error' => $exception->getMessage()]);
        }
    }

    protected function createEmail(Notification $notification): Email
    {
        $url = $this->absolute($notification->getUrl());
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<p><strong>' . $escape($notification->getSubject()) . '</strong></p>';

        if ($notification->getContent() !== '') {
            $html .= '<p>' . nl2br($escape($notification->getContent())) . '</p>';
        }

        if ($url !== null) {
            $html .= '<p><a href="' . $escape($url) . '">' . $escape($url) . '</a></p>';
        }

        return (new Email())
            ->subject($notification->getSubject())
            ->text(trim($notification->getContent() . ($url !== null ? "\n\n" . $url : '')) ?: $notification->getSubject())
            ->html($html);
    }

    protected function absolute(?string $url): ?string
    {
        if ($url === null || $url === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1 || $this->baseUrl === '') {
            return $url === '' ? null : $url;
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }
}