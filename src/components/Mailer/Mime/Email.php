<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Mime;

use DateTimeImmutable;
use DateTimeInterface;
use NeoPHP\Component\Mailer\Exception\MailerException;

class Email
{
    public const PRIORITY_HIGHEST = 1;

    public const PRIORITY_HIGH = 2;

    public const PRIORITY_NORMAL = 3;

    public const PRIORITY_LOW = 4;

    public const PRIORITY_LOWEST = 5;

    public const PRIORITIES = [
        self::PRIORITY_HIGHEST => 'Highest',
        self::PRIORITY_HIGH => 'High',
        self::PRIORITY_NORMAL => 'Normal',
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_LOWEST => 'Lowest',
    ];

    public const MAX_WORD_LENGTH = 900;

    public const RESERVED_HEADERS = [
        'from', 'sender', 'reply-to', 'to', 'cc', 'bcc', 'return-path', 'subject', 'date', 'message-id',
        'mime-version', 'content-type', 'content-transfer-encoding', 'content-disposition', 'content-id', 'x-priority',
    ];

    protected array $from = [];

    protected ?Address $sender = null;

    protected array $replyTo = [];

    protected array $to = [];

    protected array $cc = [];

    protected array $bcc = [];

    protected ?Address $returnPath = null;

    protected string $subject = '';

    protected ?string $text = null;

    protected ?string $html = null;

    protected int $priority = self::PRIORITY_NORMAL;

    protected ?DateTimeInterface $date = null;

    protected array $headers = [];

    protected array $attachments = [];

    public function from(Address|string ...$addresses): static
    {
        $this->from = Address::createArray($addresses);

        return $this;
    }

    public function addFrom(Address|string ...$addresses): static
    {
        array_push($this->from, ...Address::createArray($addresses));

        return $this;
    }

    public function sender(Address|string $address): static
    {
        $this->sender = Address::create($address);

        return $this;
    }

    public function replyTo(Address|string ...$addresses): static
    {
        $this->replyTo = Address::createArray($addresses);

        return $this;
    }

    public function addReplyTo(Address|string ...$addresses): static
    {
        array_push($this->replyTo, ...Address::createArray($addresses));

        return $this;
    }

    public function to(Address|string ...$addresses): static
    {
        $this->to = Address::createArray($addresses);

        return $this;
    }

    public function addTo(Address|string ...$addresses): static
    {
        array_push($this->to, ...Address::createArray($addresses));

        return $this;
    }

    public function cc(Address|string ...$addresses): static
    {
        $this->cc = Address::createArray($addresses);

        return $this;
    }

    public function addCc(Address|string ...$addresses): static
    {
        array_push($this->cc, ...Address::createArray($addresses));

        return $this;
    }

    public function bcc(Address|string ...$addresses): static
    {
        $this->bcc = Address::createArray($addresses);

        return $this;
    }

    public function addBcc(Address|string ...$addresses): static
    {
        array_push($this->bcc, ...Address::createArray($addresses));

        return $this;
    }

    public function returnPath(Address|string $address): static
    {
        $this->returnPath = Address::create($address);

        return $this;
    }

    public function subject(string $subject): static
    {
        if (preg_match('/[\r\n\0]/', $subject) === 1) {
            throw new MailerException('The subject cannot contain a line break.');
        }

        $this->subject = $subject;

        return $this;
    }

    public function text(?string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function html(?string $html): static
    {
        $this->html = $html;

        return $this;
    }

    public function priority(int $priority): static
    {
        $this->priority = max(self::PRIORITY_HIGHEST, min(self::PRIORITY_LOWEST, $priority));

        return $this;
    }

    public function date(DateTimeInterface $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function addHeader(string $name, string $value): static
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $name) !== 1) {
            throw new MailerException('The header name "{name}" is not valid.', 0, null, ['name' => $name]);
        }

        if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
            throw new MailerException('The header "{name}" is managed by the email: use the dedicated method.', 0, null, ['name' => $name]);
        }

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new MailerException('The value of the header "{name}" contains a line break.', 0, null, ['name' => $name]);
        }

        if (max(array_map('strlen', explode(' ', $value))) > self::MAX_WORD_LENGTH) {
            throw new MailerException('The value of the header "{name}" contains a word longer than {max} characters: it cannot be folded.', 0, null, ['name' => $name, 'max' => self::MAX_WORD_LENGTH]);
        }

        $this->headers[] = [$name, $value];

        return $this;
    }

    public function removeHeader(string $name): static
    {
        $this->headers = array_values(array_filter($this->headers, static fn (array $header): bool => strcasecmp($header[0], $name) !== 0));

        return $this;
    }

    public function attach(string $body, string $filename, ?string $contentType = null): static
    {
        $this->attachments[] = new Attachment($body, $filename, $contentType ?? Attachment::guessContentType($filename));

        return $this;
    }

    public function attachFromPath(string $path, ?string $filename = null, ?string $contentType = null): static
    {
        $this->attachments[] = Attachment::fromPath($path, $filename, $contentType);

        return $this;
    }

    public function embed(string $body, string $name, ?string $contentType = null): static
    {
        $this->attachments[] = new Attachment($body, $name, $contentType ?? Attachment::guessContentType($name), true);

        return $this;
    }

    public function embedFromPath(string $path, ?string $name = null, ?string $contentType = null): static
    {
        $this->attachments[] = Attachment::fromPath($path, $name ?? basename($path), $contentType, true);

        return $this;
    }

    public function getFrom(): array
    {
        return $this->from;
    }

    public function getSender(): ?Address
    {
        return $this->sender;
    }

    public function getReplyTo(): array
    {
        return $this->replyTo;
    }

    public function getTo(): array
    {
        return $this->to;
    }

    public function getCc(): array
    {
        return $this->cc;
    }

    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function getReturnPath(): ?Address
    {
        return $this->returnPath;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getDate(): DateTimeInterface
    {
        return $this->date ?? new DateTimeImmutable();
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getAttachments(): array
    {
        return array_values(array_filter($this->attachments, static fn (Attachment $attachment): bool => !$attachment->isInline()));
    }

    public function getEmbedded(): array
    {
        return array_values(array_filter($this->attachments, static fn (Attachment $attachment): bool => $attachment->isInline()));
    }

    public function getRecipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    public function validate(): void
    {
        if ($this->from === []) {
            throw new MailerException('The email has no sender: call from() or configure "from" in config/framework/mailer.yaml.');
        }

        if ($this->getRecipients() === []) {
            throw new MailerException('The email has no recipient: call to(), cc() or bcc().');
        }

        if ($this->text === null && $this->html === null && $this->attachments === []) {
            throw new MailerException('The email has no body: call text(), html() or attach().');
        }

        if (count($this->from) > 1 && $this->sender === null) {
            throw new MailerException('An email with several "From" addresses must define a sender().');
        }
    }
}