# Mailer

The Mailer component builds MIME emails (UTF-8, HTML and text, attachments, embedded images) and sends them through SMTP, or writes them to files or to the log in development.
No external library is used: the SMTP client is native, and `ext-openssl` is needed for TLS.

## Summary

- [Configuration](#configuration)
- [Transports](#transports)
- [Sending an email](#sending-an-email)
- [Email API](#email-api)
- [Envelope and sent message](#envelope-and-sent-message)
- [Email classes](#email-classes)
- [Events](#events)
- [Console commands](#console-commands)
- [Services](#services)
- [Exceptions](#exceptions)
- [Changelog](#changelog)

## Configuration

`config/framework/mailer.yaml`

```yaml
dsn: '%env(MAILER_DSN)%'

from: '%env(APP_NAME)% <noreply@example.com>'

envelope:
  sender: ~
  recipients: '%env(csv:MAILER_RECIPIENTS)%'

headers: {}
```

| Option | Default | Description |
|---|---|---|
| `dsn` | `null://null` | transport (see [Transports](#transports)) |
| `from` | `~` | sender used when an email has no `from()` |
| `envelope.sender` | `~` | forces the envelope sender (`MAIL FROM`, bounces) |
| `envelope.recipients` | `[]` | redirects every email to these addresses |
| `headers` | `{}` | headers added to every email, e.g. `X-App: shop` |

`.env`

```dotenv
MAILER_DSN="null://null"
MAILER_RECIPIENTS=
```

In development, `MAILER_RECIPIENTS="me@example.com"` in `.env.local` sends every email to you, whatever the To / Cc / Bcc (the headers are kept, only the envelope changes).

## Transports

| DSN | Transport |
|---|---|
| `smtp://user:password@smtp.example.com:587` | `SmtpTransport`, STARTTLS when the server offers it |
| `smtps://user:password@smtp.example.com` | `SmtpTransport` over implicit TLS (port 465 by default; `smtp://…:465` works too) |
| `file://default` | `FileTransport`: writes each email as a `.eml` file in `var/mails/` (`file:///absolute/path` for another directory) |
| `log://default` | `LogTransport`: writes one line per email in the logger (`log://debug` for another level) |
| `null://null` | `NullTransport`: sends nothing (tests) |

Special characters in the user or the password must be URL-encoded (`@` → `%40`, `/` → `%2F`, `:` → `%3A`, `?` → `%3F`).

### SMTP options

These options go in the query string, for example `smtp://…:587?verify_peer=0&timeout=30`:

| Option | Default | Description |
|---|---|---|
| `auto_tls` | `1` | use STARTTLS when the server offers it |
| `require_tls` | `0` | fail when the connection cannot be encrypted |
| `verify_peer` | `1` | check the server certificate (`0` for a self-signed certificate) |
| `auth_mode` | auto | `plain`, `login` or `cram-md5` (by default, the modes offered by the server are tried in the order CRAM-MD5, LOGIN, PLAIN) |
| `allow_insecure_auth` | `0` | allow PLAIN / LOGIN over an unencrypted connection (always allowed on localhost) |
| `timeout` | `10` | connection and read timeout, in seconds |
| `local_domain` | host name | domain sent with EHLO |
| `ping_threshold` | `60` | seconds of inactivity after which the connection is checked with `NOOP` before reuse |

The connection stays open between two emails sent by the same process, and is closed at the end of the request or command.

### Transport classes

Every transport implements `NeoPHP\Component\Mailer\Contract\TransportInterface` (`send(Email $email, ?Envelope $envelope = null): SentMessage`, and `__toString()` for the DSN). `AbstractTransport` renders the MIME message and calls `doSend(SentMessage $message): void`; it provides `getRenderer()` / `setRenderer(MimeRenderer)`.

| Class | Constructor / methods |
|---|---|
| `SmtpTransport` | `__construct(string $host = 'localhost', int $port = 25, bool $tls = false, string $username = '', string $password = '', array $options = [])`, `start()`, `stop()`, `isConnected()`, `isEncrypted()`, `getHost()`, `getPort()`, `isTls()`, `getOptions()`, `getCapabilities()` |
| `FileTransport` | `__construct(string $directory)`, `getDirectory()` |
| `LogTransport` | `__construct(LoggerInterface $logger, string $level = 'info')` |
| `NullTransport` | no argument |
| `TransportFactory` | `__construct(string $rootPath = '', ?LoggerInterface $logger = null)`, `create(Dsn\|string $dsn): TransportInterface` |
| `Dsn` | `Dsn::fromString(string)`, `Dsn::mask(string)` (hides the password), `getScheme()`, `getHost()`, `getPort(?int $default)`, `getUser()`, `getPassword()`, `getPath()`, `getOptions()`, `getOption($name, $default)` |

A custom transport:

```php
<?php

declare(strict_types=1);

namespace App\Mailer;

use NeoPHP\Component\Mailer\Contract\AbstractTransport;
use NeoPHP\Component\Mailer\Message\SentMessage;

class MemoryTransport extends AbstractTransport
{
    public array $messages = [];

    protected function doSend(SentMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function __toString(): string
    {
        return 'memory://';
    }
}
```

## Sending an email

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Mailer\Mime\Address;
use NeoPHP\Component\Mailer\Mime\Email;

class OrderController extends AbstractController
{
    public function confirm(int $id): Response
    {
        $order = $this->getRepository(Order::class)->find($id);

        $email = (new Email())
            ->from(new Address('shop@example.com', 'My Shop'))
            ->to($order->getCustomerEmail())
            ->bcc('orders@example.com')
            ->replyTo('support@example.com')
            ->subject('Your order #' . $order->getId())
            ->html('<h1>Thank you!</h1><img src="cid:logo.png">')
            ->text('Thank you!')
            ->embedFromPath($this->get('kernel.root_path') . '/assets/img/logo.png')
            ->attachFromPath('/path/to/invoice.pdf', 'invoice-' . $order->getId() . '.pdf')
            ->priority(Email::PRIORITY_HIGH)
            ->addHeader('X-Order', (string) $order->getId());

        $this->sendEmail($email);

        return $this->redirectToRoute('order_done');
    }
}
```

`sendEmail(Email $email): ?SentMessage` comes from the `MailerController` trait of `AbstractController`. In any service, inject `MailerInterface`:

```php
public function __construct(protected MailerInterface $mailer)
{
}

public function notify(string $to): void
{
    $this->mailer->send((new Email())->to($to)->subject('Hello')->text('Hello!'));
}
```

`MailerInterface`:

| Method | Description |
|---|---|
| `send(Email $email, ?Envelope $envelope = null): ?SentMessage` | sends a copy of the email; `null` when a listener rejected it |
| `getTransport(): TransportInterface` | the transport |
| `getConfig(): array` | the mailer configuration |

Before sending, the default `from` and `headers` of the configuration are applied, the email is validated, `MessageEvent` is dispatched, then the envelope is redirected to `envelope.recipients` when set.

## Email API

`NeoPHP\Component\Mailer\Mime\Email`, every setter returns `static`:

| Method | Description |
|---|---|
| `from()`, `to()`, `cc()`, `bcc()`, `replyTo()` | replace the addresses; `addFrom()`, `addTo()`, `addCc()`, `addBcc()`, `addReplyTo()` add to them. Variadic, accepts `'a@b.com'`, `'Name <a@b.com>'` or `Address` objects |
| `sender(Address\|string)`, `returnPath(Address\|string)` | Sender header, and the address that receives bounces |
| `subject(string)`, `text(?string)`, `html(?string)` | content. Without `text()`, a text version is generated from the HTML |
| `attach(string $body, string $filename, ?string $contentType = null)`, `attachFromPath(string $path, ?string $filename = null, ?string $contentType = null)` | attachments. The content type is guessed from the extension when omitted |
| `embed(string $body, string $name, ?string $contentType = null)`, `embedFromPath(string $path, ?string $name = null, ?string $contentType = null)` | inline images, referenced in the HTML with `src="cid:name"` |
| `priority(int)` | `Email::PRIORITY_HIGHEST` (1), `PRIORITY_HIGH`, `PRIORITY_NORMAL` (3, default), `PRIORITY_LOW`, `PRIORITY_LOWEST` (5) |
| `date(DateTimeInterface)` | Date header |
| `addHeader(string $name, string $value)`, `removeHeader(string $name)` | custom headers (the reserved headers such as `From` or `Subject` are refused) |
| `validate(): void` | throws a `MailerException` without sender, recipient or content |

Getters: `getFrom()`, `getSender()`, `getReplyTo()`, `getTo()`, `getCc()`, `getBcc()`, `getReturnPath()`, `getSubject()`, `getText()`, `getHtml()`, `getPriority()`, `getDate()`, `getHeaders()`, `getAttachments()`, `getEmbedded()`, `getRecipients()` (To + Cc + Bcc).

Line breaks in addresses, the subject and headers are rejected, which prevents header injection. Non-ASCII names, subjects and file names are encoded (RFC 2047 / 2231). Bcc addresses are only used in the envelope and never appear in the message.

### Address

`new Address(string $address, string $name = '')` validates the address. `Address::create(Address|string)` parses `'Name <a@b.com>'`, `Address::createArray(array)` parses a list. Methods: `getAddress()`, `getName()`, `toString()`.

### Attachment

`new Attachment(string $body, string $filename, string $contentType = 'application/octet-stream', bool $inline = false)`, or `Attachment::fromPath($path, $filename, $contentType, $inline)`. Methods: `getBody()`, `getFilename()`, `getContentType()`, `isInline()`, `getSize()`, `Attachment::guessContentType(string $path)`.

### MimeRenderer

`MimeRenderer::render(Email $email, string $messageId): string` returns the raw message; `generateMessageId()`, `htmlToText()`, `encodeAddress()`, `encodeAddresses()`, `encodeText()` and `encodeWords()` are also public.

## Envelope and sent message

`NeoPHP\Component\Mailer\Message\Envelope` holds the SMTP sender and recipients: `new Envelope(Address|string $sender, array $recipients)`, `Envelope::create(Email $email)`, `getSender()`, `setSender()`, `getRecipients()`, `setRecipients()`. By default, the sender is the return path, the Sender or the first From, and the recipients are To + Cc + Bcc.

```php
$envelope = new Envelope('bounces@example.com', [new Address('qa@example.com')]);
$this->mailer->send($email, $envelope);
```

`NeoPHP\Component\Mailer\Message\SentMessage`:

| Method | Description |
|---|---|
| `getEmail()` | the sent `Email` |
| `getEnvelope()` | the used `Envelope` |
| `getMessageId()` | `Message-ID` header |
| `toString()` | raw MIME message |
| `getTransport()` | transport DSN |
| `getTransportId()` | SMTP queue id, or the `.eml` file |
| `getDebug()` | SMTP dialogue, without passwords |

## Email classes

```bash
php bin/neo make:email Welcome
php bin/neo make:email Order/Shipped --force
```

`make:email` creates `src/Email/WelcomeEmail.php` (`src/Email/Order/ShippedEmail.php`); the `Email` suffix is added. `--force` overwrites an existing file.

```php
<?php

declare(strict_types=1);

namespace App\Email;

use NeoPHP\Component\Mailer\Mime\Email;

class WelcomeEmail extends Email
{
    public function __construct(string $to, string $name = '')
    {
        $this->to($to)
            ->subject('Welcome')
            ->text('Hello ' . $name . '!')
            ->html('<p>Hello <strong>' . htmlspecialchars($name, ENT_QUOTES) . '</strong>!</p>');
    }
}
```

```php
$this->sendEmail(new WelcomeEmail('alice@example.com', 'Alice'));
```

## Events

The events are in `NeoPHP\Component\Mailer\Event\` (see the Event documentation).

| Event | When | Methods |
|---|---|---|
| `MessageEvent` | before sending | `getEmail()`, `setEmail()`, `getEnvelope()`, `setEnvelope()`, `isEnvelopeChanged()`, `getTransport()`, `reject()`, `isRejected()` |
| `SentMessageEvent` | after sending | `getMessage(): SentMessage` |
| `FailedMessageEvent` | when the transport fails | `getEmail()`, `getEnvelope()`, `getError()`; the exception is then thrown |

```php
<?php

declare(strict_types=1);

namespace App\Listener;

use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Mailer\Event\MessageEvent;

class MailerListener
{
    #[AsListener]
    public function addAuditCopy(MessageEvent $event): void
    {
        $event->getEmail()->addBcc('audit@example.com');
    }
}
```

## Console commands

| Command | Description |
|---|---|
| `mailer:test <to>` | sends a test email; options `--from`, `-s\|--subject`, `-b\|--body`, `-d\|--dsn` (another DSN than `MAILER_DSN`) |
| `make:email <name>` | generates an email class in `src/Email/` |

```bash
php bin/neo mailer:test me@example.com -v
php bin/neo mailer:test me@example.com --dsn="smtp://user:pass@smtp.example.com:587" -v
```

`-v` shows the dialogue with the SMTP server (the passwords are hidden). Without the argument, the recipient is asked.

## Services

| Service | Class |
|---|---|
| `MailerInterface` (aliases `MailerManager`, `mailer`) | `MailerManager`, `__construct(TransportInterface $transport, ?EventDispatcherInterface $events = null, array $config = [])` |
| `TransportInterface` (alias `mailer.transport`) | transport created from `dsn` |
| `TransportFactory` | DSN parser |

## Exceptions

| Exception | Thrown when |
|---|---|
| `MailerException` (extends `FrameworkException`) | invalid address, header, attachment, DSN or email, email class that cannot be generated |
| `TransportException` (extends `MailerException`) | connection, TLS, authentication or SMTP error; `getDebug()` returns the SMTP dialogue |

## Changelog

- v1.17.0 — `mailer:test` and `make:email` ask for their missing arguments.
- v1.16.0 — Mailer component: fluent `Email`, native SMTP transport (STARTTLS / implicit TLS, AUTH PLAIN / LOGIN / CRAM-MD5, connection reused), `file`, `log` and `null` transports configured with `MAILER_DSN`, default sender and headers, envelope redirection with `MAILER_RECIPIENTS`, `MessageEvent` / `SentMessageEvent` / `FailedMessageEvent`, `sendEmail()` in controllers, `mailer:test` and `make:email` commands, `config/framework/mailer.yaml`.