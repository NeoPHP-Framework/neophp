# Notifier

The Notifier package (`src/packages/Notifier`) sends the notifications of the application to its users: a new message, an order shipped, a comment on a post.
One notification goes through several channels, chosen from its importance:

| Channel | What the user gets | Needs |
|---|---|---|
| `database` | the list of the notifications of the site (bell icon, read / unread) | Database component |
| `mercure` | the open pages are updated at once (counter, toast) | Mercure package |
| `push` | a notification of the browser or of the phone, even when the site is closed (Web Push) | Database and HttpClient components, VAPID keys, `ext-openssl` |
| `email` | an email | Mailer component |

The push notifications are encrypted and signed without any dependency (RFC 8291 and RFC 8292). Custom channels (SMS, Slack...) implement `ChannelInterface`.

## Summary

- [Module](#module)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Recipients](#recipients)
- [Notifications](#notifications)
- [Sending](#sending)
- [Channels](#channels)
- [Database channel](#database-channel)
- [Mercure channel](#mercure-channel)
- [Push channel](#push-channel)
- [Email channel](#email-channel)
- [Custom channels](#custom-channels)
- [View functions](#view-functions)
- [Commands](#commands)
- [NotifierManagerInterface](#notifiermanagerinterface)
- [Errors](#errors)
- [Security](#security)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Package\Notifier\NotifierManager` (`final`) |
| Interface | `NeoPHP\Package\Notifier\NotifierManagerInterface` |
| Attribute | `#[Package(provider: NotifierProvider::class)]` |
| Requires | nothing; each channel uses its own module (see the table above), the Queue package for `dispatch()` |

Inject `NotifierManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Package\Notifier\NotifierManagerInterface;

public function __construct(private NotifierManagerInterface $notifier)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Package\Notifier\NotifierManager::class => false,
```

The public API of the module is its manager and its interface, the classes of `Model\` and `Contract\`, `WebPush\Subscription`, the channels and the exception. The classes marked `@internal` are used by the framework only.

## Quick start

1. The User entity implements `RecipientInterface`:

```php
use NeoPHP\Package\Notifier\Contract\RecipientInterface;

class User implements RecipientInterface
{
    // ...

    public function getNotificationIdentifier(): string
    {
        return (string) $this->id;
    }

    public function getNotificationEmail(): ?string
    {
        return $this->email;
    }
}
```

2. Send a notification:

```php
use NeoPHP\Package\Notifier\Model\Notification;

$notification = (new Notification('New comment', 'Alice answered your post.', '/posts/42#comment-7'))
    ->importance(Notification::IMPORTANCE_HIGH);

$this->notifier->send($notification, $post->getAuthor());
```

3. Show the notifications in the layout:

```twig
{% if app.user %}
    <a href="{{ path('notifications') }}">
        Notifications <span id="unread">{{ unread_notifications(app.user) }}</span>
    </a>

    <script>
        const source = new EventSource("{{ mercure(notification_topic(app.user), { subscribe: true })|escape('js') }}");

        source.addEventListener('notification', (event) => {
            const notification = JSON.parse(event.data);
            const counter = document.getElementById('unread');
            counter.textContent = Number(counter.textContent) + 1;
            console.log(notification.subject, notification.content, notification.url);
        });
    </script>
{% endif %}
```

The tables are created on first use. The `high` importance also sends a push notification to the browsers of the user who accepted them (see [Push channel](#push-channel)).

## Configuration

`neo install` generates `config/packages/notifier.yaml`:

```yaml
# Channels used for each importance of a notification (Notification::importance()), unless channels() is given
channel_policy:
    low: [ database ]
    normal: [ database, mercure ]
    high: [ database, mercure, push ]
    urgent: [ database, mercure, push, email ]

# Database channel: tables neo_notifications and neo_push_subscriptions, created on first use when auto_setup is true
database:
    connection: ~
    auto_setup: true

# Mercure channel: private topic of each recipient ({id} = RecipientInterface::getNotificationIdentifier())
mercure:
    topic: 'notifications/{id}'

# Push channel (Web Push): generate the keys with php bin/neo notifier:vapid and put them in .env.local
push:
    public_key: '%env(VAPID_PUBLIC_KEY)%'
    private_key: '%env(VAPID_PRIVATE_KEY)%'
    subject: '%env(VAPID_SUBJECT)%'
    ttl: 86400

# Email channel: sender of the notification emails (~ = the "from" of config/framework/mailer.yaml)
email:
    from: ~

# Classes implementing NeoPHP\Package\Notifier\Contract\ChannelInterface (SMS, Slack...)
custom_channels: [ ]
```

| Key | Description |
|---|---|
| `channel_policy` | channels of each importance (`low`, `normal`, `high`, `urgent`); a missing importance keeps its default channels |
| `database.connection` | connection of the Database component (`~` = the default connection) |
| `database.auto_setup` | creates the tables on first use; with `false`, run `notifier:setup` once (deployment) |
| `mercure.topic` | Mercure topic of a recipient, `{id}` is replaced by its identifier |
| `push.public_key`, `push.private_key` | VAPID keys (base64url), generated by `notifier:vapid`; the push channel is disabled while they are empty |
| `push.subject` | contact of the application for the push services: `mailto:` address or `https://` URL |
| `push.ttl` | seconds during which the push service keeps a notification for a device that is offline |
| `email.from` | sender of the emails (`~` = the sender of the Mailer component) |
| `custom_channels` | classes of the custom channels, built by the container |

`neo install` adds the variables to `.env`:

```dotenv
###> notifier ###
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:admin@example.com
###< notifier ###
```

The absolute links of the emails use `APP_URL` (`framework.app.url`).

## Recipients

A recipient implements `NeoPHP\Package\Notifier\Contract\RecipientInterface`:

| Method | Description |
|---|---|
| `getNotificationIdentifier(): string` | the key of its notifications and of its push subscriptions, the `{id}` of its Mercure topic (the id of the user) |
| `getNotificationEmail(): ?string` | the address of the email channel, `null` when there is none |

Without entity (a command, a test), `NeoPHP\Package\Notifier\Model\Recipient` holds the identifier and the address:

```php
use NeoPHP\Package\Notifier\Model\Recipient;

$this->notifier->send($notification, new Recipient(12, 'alice@example.com'));
```

Wherever a method takes `RecipientInterface|string`, the identifier is accepted too: `countUnread('12')`.

## Notifications

`NeoPHP\Package\Notifier\Model\Notification`:

```php
new Notification(
    string $subject,       // title, required
    string $content = '',  // text
    ?string $url = null,   // page opened by the notification (relative or absolute)
    ?string $type = null,  // your own category: 'comment', 'order'...
    array $data = [],      // any JSON data, kept and sent to the browser
);
```

| Method | Description |
|---|---|
| `importance(string $importance)` | `low`, `normal` (default), `high` or `urgent` (`Notification::IMPORTANCE_*`); chooses the channels with `channel_policy` |
| `channels(string ...$channels)` | channels to use instead of the policy: `->channels('database', 'email')` |
| `getSubject()`, `getContent()`, `getUrl()`, `getType()`, `getData()`, `getImportance()`, `getChannels()` | getters |
| `toArray()` | `subject`, `content`, `url`, `type`, `importance`, `data`: what Mercure and the push channel send |

An empty subject or an unknown importance throws `NotifierException`.

The `data` keys `icon`, `badge`, `image` and `tag` are used by the service worker of the push notifications (a notification with the same `tag` replaces the previous one).

## Sending

### Now

`send(Notification $notification, RecipientInterface|iterable $recipients): array` sends to one recipient or to a list, and returns a `Model\DeliveryResult` per recipient and channel:

```php
$results = $this->notifier->send($notification, $users);

foreach ($results as $result) {
    // $result->getRecipient(), $result->getChannel(), $result->getStatus(), $result->getMessage()
}
```

| Status | Meaning |
|---|---|
| `sent` (`isSent()`) | the channel sent the notification |
| `skipped` | the channel is not available for this recipient; `getMessage()` says why (no push subscription, no email address, module disabled...) |
| `failed` (`isFailed()`) | the channel threw; `getMessage()` is the error, also written to the log (`notifier` channel of the Logger when it exists) |

A channel that fails never stops the other channels nor the other recipients. An unknown channel name throws `NotifierException` before anything is sent.

In a controller, `sendNotification()` is a shortcut of `send()`:

```php
$this->sendNotification(new Notification('Welcome!', url: '/profile'), $this->getUser());
```

### In the background

`dispatch(Notification $notification, RecipientInterface|iterable $recipients, array $options = []): void` sends from a worker of the Queue package (job `Helper\Queue\SendNotificationJob`): the request does not wait for the push services and the SMTP server.

```php
$this->notifier->dispatch($notification, $followers, ['queue' => 'notifications', 'delay' => 10]);
```

The recipients are kept as identifier and email address (`Recipient`), never as entities. The options are the ones of the Queue package (`transport`, `queue`, `delay`, `priority`). Without the Queue package, `dispatch()` throws.

## Channels

`getChannelsFor(Notification $notification)` returns the channels of a notification: its own `channels()`, else the policy of its importance. `getChannelNames()` returns the registered channels.

Default policy:

| Importance | Channels |
|---|---|
| `low` | `database` |
| `normal` | `database`, `mercure` |
| `high` | `database`, `mercure`, `push` |
| `urgent` | `database`, `mercure`, `push`, `email` |

A channel whose module is disabled is `skipped`, never `failed`: a project without Mercure or without VAPID keys can keep the default policy.

## Database channel

Each notification is a row of `neo_notifications` (`id`, `recipient`, `subject`, `content`, `url`, `type`, `importance`, `data` in JSON, `created_at`, `read_at`). The table works with MySQL, PostgreSQL and SQLite, and is ignored by `make:migration` of the ORM.

```php
// the 20 last notifications, the most recent first
$notifications = $this->notifier->getNotifications($user);

// the unread ones only, page 2
$notifications = $this->notifier->getNotifications($user, limit: 20, unreadOnly: true, offset: 20);

$count = $this->notifier->countUnread($user);

$this->notifier->markAsRead($user, $id);      // one notification
$this->notifier->markAsRead($user);           // all of them
$this->notifier->deleteNotifications($user, $id);
$this->notifier->purge(90, readOnly: true);   // read notifications older than 90 days
```

`getNotifications()` returns `Model\StoredNotification`: `getId()`, `getRecipient()`, `getSubject()`, `getContent()`, `getUrl()`, `getType()`, `getImportance()`, `getData()`, `getCreatedAt()` and `getReadAt()` (`DateTimeImmutable`), `isRead()`.

A page of notifications:

```php
#[Route('/notifications', name: 'notifications')]
public function index(NotifierManagerInterface $notifier): Response
{
    $notifications = $notifier->getNotifications($this->getUser(), 50);
    $notifier->markAsRead($this->getUser());

    return $this->render('notifications/index.html.twig', ['notifications' => $notifications]);
}
```

```twig
{% for notification in notifications %}
    <a href="{{ notification.url ?? '#' }}" class="{{ notification.read ? '' : 'unread' }}">
        <strong>{{ notification.subject }}</strong>
        <p>{{ notification.content }}</p>
        <small>{{ notification.createdAt|date('d/m/Y H:i') }}</small>
    </a>
{% else %}
    <p>No notification.</p>
{% endfor %}
```

## Mercure channel

The notification is published as a **private** update of type `notification` on the topic of the recipient (`notifications/{id}` by default); its data is `Notification::toArray()`. Only the browsers authorized for this topic receive it: subscribe with `{ subscribe: true }`, which sets the `mercureAuthorization` cookie for the topic of the current user only:

```twig
const source = new EventSource("{{ mercure(notification_topic(app.user), { subscribe: true })|escape('js') }}");
source.addEventListener('notification', (event) => showToast(JSON.parse(event.data)));
```

`getTopic($recipient)` returns the same topic in PHP. See the Mercure documentation for the hub.

## Push channel

The push notifications are displayed by the browser (desktop and Android, iOS 16.4+ for a site added to the home screen) even when no page of the site is open. The site is served over HTTPS (or `localhost`).

### 1. VAPID keys

```bash
php bin/neo notifier:vapid
```

```dotenv
# .env.local
VAPID_PUBLIC_KEY=BPx...
VAPID_PRIVATE_KEY=3kV...
VAPID_SUBJECT=mailto:admin@example.com
```

Generate the keys once: the browsers subscribed with a public key, new keys invalidate every subscription.

### 2. Service worker

```bash
php bin/neo notifier:service-worker
```

writes `public/notifier-sw.js`, which displays the notifications (subject, content, `icon`, `badge`, `image` and `tag` of the data, `urgent` stays on screen until clicked) and opens their `url` on click (or focuses the open tab). Customize it freely; `--force` overwrites it.

### 3. Subscription of the browser

Ask the permission after a click of the user (the browsers refuse a request on page load), then send the subscription to the server:

```twig
<button id="enable-push">Enable notifications</button>

<script>
    document.getElementById('enable-push').addEventListener('click', async () => {
        const registration = await navigator.serviceWorker.register('/notifier-sw.js');

        if (await Notification.requestPermission() !== 'granted') {
            return;
        }

        const key = "{{ vapid_public_key()|escape('js') }}";
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: Uint8Array.from(atob(key.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0)),
        });

        await fetch("{{ path('push_subscribe') }}", {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(subscription.toJSON()),
        });
    });
</script>
```

```php
#[Route('/push/subscribe', name: 'push_subscribe', methods: ['POST'])]
public function subscribe(Request $request, NotifierManagerInterface $notifier): Response
{
    $this->denyAccessUnlessGranted('ROLE_USER');
    $notifier->subscribe($this->getUser(), $request->toArray());

    return $this->json(['ok' => true]);
}

#[Route('/push/unsubscribe', name: 'push_unsubscribe', methods: ['POST'])]
public function unsubscribe(Request $request, NotifierManagerInterface $notifier): Response
{
    $notifier->unsubscribe((string) ($request->toArray()['endpoint'] ?? ''), $this->getUser());

    return $this->json(['ok' => true]);
}
```

`subscribe()` accepts the JSON of `PushSubscription.toJSON()` (`endpoint`, `keys.p256dh`, `keys.auth`) or a `WebPush\Subscription`; it is stored in `neo_push_subscriptions`. A user has one subscription per browser; subscribing the same browser again replaces it (another user logged in on the same browser takes it over).

### Sending

The notification (`toArray()`, up to 3993 bytes in JSON) is encrypted for each subscription of the recipient (`aes128gcm`) and posted to its push service with a VAPID JWT (`ES256`, valid 12 hours). The `Urgency` header follows the importance (`urgent` is sent as `high`), `TTL` is `push.ttl`.

A subscription refused with `404` or `410` (the user revoked the permission, the browser was reset) is deleted. The result is `sent` when at least one browser received the notification, `skipped` when the recipient has no subscription, `failed` otherwise.

## Email channel

The default email has the subject of the notification, its content, and a link to its URL (made absolute with `APP_URL`), in text and HTML. It is sent to `getNotificationEmail()` (`skipped` when it is `null`) from `email.from`.

To write your own email, the notification implements `EmailNotificationInterface`:

```php
use NeoPHP\Component\Mailer\Mime\Email;
use NeoPHP\Package\Notifier\Contract\EmailNotificationInterface;
use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\Model\Notification;

class OrderShipped extends Notification implements EmailNotificationInterface
{
    public function __construct(private Order $order)
    {
        parent::__construct('Your order has shipped', 'Order ' . $order->getNumber(), '/orders/' . $order->getId(), 'order');
        $this->importance(self::IMPORTANCE_URGENT);
    }

    public function toEmail(RecipientInterface $recipient): ?Email
    {
        return (new Email())
            ->subject('Order ' . $this->order->getNumber() . ' has shipped')
            ->html('<p>Tracking number: ' . htmlspecialchars($this->order->getTracking()) . '</p>');
    }
}
```

The address of the recipient is added when the email has no recipient, and `email.from` when it has no sender. Returning `null` skips the email.

With `dispatch()`, the notification is serialized in the job: keep its properties serializable (ids rather than services).

## Custom channels

```php
use NeoPHP\Package\Notifier\Contract\ChannelInterface;
use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\Model\Notification;

class SmsChannel implements ChannelInterface
{
    public function __construct(private SmsClient $client, private UserRepository $users)
    {
    }

    public function getName(): string
    {
        return 'sms';
    }

    public function unavailableReason(Notification $notification, RecipientInterface $recipient): ?string
    {
        return $this->users->find($recipient->getNotificationIdentifier())?->getPhone() === null ? 'the recipient has no phone number' : null;
    }

    public function send(Notification $notification, RecipientInterface $recipient): void
    {
        $phone = $this->users->find($recipient->getNotificationIdentifier())->getPhone();
        $this->client->send($phone, $notification->getSubject());
    }
}
```

```yaml
# config/packages/notifier.yaml
channel_policy:
    urgent: [ database, mercure, push, email, sms ]

custom_channels:
    - App\Notifier\SmsChannel
```

`unavailableReason()` returns `null` when the channel can send, else the reason of the `skipped` result; `send()` throws on failure. A class that does not implement `ChannelInterface` throws `NotifierException`.

## View functions

| Function | Returns |
|---|---|
| `notifications(recipient, limit = 10, unreadOnly = false)` | the last `StoredNotification` of the recipient (`[]` when it is `null`) |
| `unread_notifications(recipient)` | the number of unread notifications (`0` when it is `null`) |
| `notification_topic(recipient)` | the Mercure topic of the recipient |
| `vapid_public_key()` | the VAPID public key (empty when the push channel is not configured) |

```twig
<ul class="dropdown">
    {% for notification in notifications(app.user, 5) %}
        <li class="{{ notification.read ? '' : 'unread' }}"><a href="{{ notification.url }}">{{ notification.subject }}</a></li>
    {% endfor %}
</ul>
```

## Commands

| Command | Description |
|---|---|
| `notifier:vapid` | generates a pair of VAPID keys to put in `.env.local` |
| `notifier:service-worker` | writes `public/notifier-sw.js` (`--force` overwrites it) |
| `notifier:setup` | creates the tables (when `database.auto_setup` is `false`) |
| `notifier:send <recipient> <subject> [content]` | sends a notification and prints the result of each channel; `--importance`, `--url`, `--email` (address of the email channel), `--channel` / `-c` (repeatable) |
| `notifier:purge` | deletes the notifications older than `--days` (`90`), `--read-only` keeps the unread ones |

```bash
php bin/neo notifier:send 12 "Hello" "A test notification" --importance=high
php bin/neo notifier:send 12 "Hello" --channel=push --channel=email --email=me@example.com
```

Schedule the purge with the Scheduler package:

```yaml
# config/packages/scheduler.yaml
tasks:
    - { name: 'notifier-purge', command: 'notifier:purge --days=90 --read-only', cron: '0 3 * * *' }
```

## NotifierManagerInterface

| Method | Description |
|---|---|
| `send(Notification $notification, RecipientInterface\|iterable $recipients): array` | sends now, returns the `DeliveryResult` list |
| `dispatch(Notification $notification, RecipientInterface\|iterable $recipients, array $options = []): void` | sends from a worker of the Queue package |
| `getChannelsFor(Notification $notification): array` | channels used for the notification |
| `getChannelNames(): array` | registered channels |
| `getNotifications(RecipientInterface\|string $recipient, int $limit = 20, bool $unreadOnly = false, int $offset = 0): array` | stored notifications, the most recent first |
| `countUnread(RecipientInterface\|string $recipient): int` | unread notifications |
| `markAsRead(RecipientInterface\|string $recipient, ?int $id = null): int` | marks one or all as read |
| `deleteNotifications(RecipientInterface\|string $recipient, ?int $id = null): int` | deletes one or all |
| `purge(int $days, bool $readOnly = false): int` | deletes the old notifications |
| `subscribe(RecipientInterface\|string $recipient, array\|Subscription $subscription): void` | saves the push subscription of a browser |
| `unsubscribe(string $endpoint, RecipientInterface\|string\|null $recipient = null): bool` | deletes a push subscription |
| `getVapidPublicKey(): ?string` | VAPID public key, `null` when push is not configured |
| `setup(): void` | creates the tables |
| `getTopic(RecipientInterface\|string $recipient): string` | Mercure topic of the recipient |

The manager is also available with the `notifier` container id.

## Errors

Every error throws `NeoPHP\Package\Notifier\Exception\NotifierException` (extends `FrameworkException`):

- an empty subject, an unknown importance, an unknown channel;
- the methods of the stored notifications and of the subscriptions when the Database component is disabled or has no connection;
- an invalid push subscription (endpoint not in HTTPS, keys of a wrong size), invalid VAPID keys or subject;
- `dispatch()` without the Queue package, a custom channel that does not implement `ChannelInterface`.

The errors of the channels during `send()` are not thrown: they are in the `failed` results and in the log.

## Security

- Keep `VAPID_PRIVATE_KEY` out of the repository (`.env.local` or a real environment variable): anybody holding it can send push notifications to your subscribers.
- Only save a subscription for the logged-in user (`$this->getUser()`), never for an identifier sent by the browser.
- The Mercure updates are private: a browser only receives the topic it was authorized for. Never authorize the topic of another user.
- The content of the notifications is escaped by the templates and set as text by the service worker; build the HTML of a custom email with `htmlspecialchars()`.
- The push payload is encrypted end to end: the push services (Google, Mozilla, Apple) cannot read it.

## Changelog

- v2.8.0 — `Helper/Doctor/NotifierCheck`: `neo doctor` checks the VAPID keys, `openssl` and the service worker (see the Doctor documentation).
- v2.7.0 — Notifier package: `NotifierManager` and `NotifierManagerInterface`, `Model\Notification` with importances and `channel_policy`, `RecipientInterface` and `Model\Recipient`, `database` (read / unread, `neo_notifications` table), `mercure` (private topic per recipient), `push` (Web Push encrypted with `aes128gcm` and signed with VAPID `ES256` without dependency, `neo_push_subscriptions` table, expired subscriptions deleted) and `email` (`EmailNotificationInterface`) channels, custom channels, `DeliveryResult`, `dispatch()` through the Queue package, `notifications()`, `unread_notifications()`, `notification_topic()` and `vapid_public_key()` view functions, `sendNotification()` controller helper, `notifier:vapid`, `notifier:service-worker`, `notifier:setup`, `notifier:send` and `notifier:purge` commands; `neo install` generates `config/packages/notifier.yaml` and adds the `VAPID_*` variables to `.env`.