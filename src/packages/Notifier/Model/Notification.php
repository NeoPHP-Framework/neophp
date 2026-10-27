<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Model;

use NeoPHP\Package\Notifier\Exception\NotifierException;

class Notification
{
    public const IMPORTANCE_LOW = 'low';

    public const IMPORTANCE_NORMAL = 'normal';

    public const IMPORTANCE_HIGH = 'high';

    public const IMPORTANCE_URGENT = 'urgent';

    public const IMPORTANCES = [self::IMPORTANCE_LOW, self::IMPORTANCE_NORMAL, self::IMPORTANCE_HIGH, self::IMPORTANCE_URGENT];

    protected string $importance = self::IMPORTANCE_NORMAL;

    /**
     * @var list<string>
     */
    protected array $channels = [];

    /**
     * @param string $subject Short text: title of the push notification, subject of the email
     * @param string $content Body of the notification
     * @param string|null $url Page opened when the notification is clicked (absolute in the emails and push notifications)
     * @param string|null $type Free category of the application (post.created, comment.reply...), stored with the notification
     * @param array<string, mixed> $data Extra data, JSON-serializable, given to the front-end (Mercure, push) and stored
     */
    public function __construct(
        protected string $subject,
        protected string $content = '',
        protected ?string $url = null,
        protected ?string $type = null,
        protected array $data = [],
    ) {
        if (trim($subject) === '') {
            throw new NotifierException('A notification needs a subject.');
        }
    }

    /**
     * @throws NotifierException When the importance is unknown
     */
    public function importance(string $importance): static
    {
        if (!in_array($importance, self::IMPORTANCES, true)) {
            throw new NotifierException('The importance "{importance}" is unknown: use {importances}.', 0, null, ['importance' => $importance, 'importances' => implode(', ', self::IMPORTANCES)]);
        }

        $this->importance = $importance;

        return $this;
    }

    /**
     * Sends the notification on these channels only, whatever its importance.
     */
    public function channels(string ...$channels): static
    {
        $this->channels = array_values(array_unique($channels));

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getImportance(): string
    {
        return $this->importance;
    }

    /**
     * @return list<string> The channels given with channels(), empty when the channel policy decides
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * Returns the payload sent to the browser (Mercure, push) and stored by the database channel.
     *
     * @return array{subject: string, content: string, url: string|null, type: string|null, importance: string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'content' => $this->content,
            'url' => $this->url,
            'type' => $this->type,
            'importance' => $this->importance,
            'data' => $this->data,
        ];
    }
}