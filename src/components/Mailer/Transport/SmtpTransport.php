<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Transport;

use NeoPHP\Component\Mailer\Contract\AbstractTransport;
use NeoPHP\Component\Mailer\Exception\TransportException;
use NeoPHP\Component\Mailer\Message\SentMessage;
use Throwable;

class SmtpTransport extends AbstractTransport
{
    public const DEFAULT_OPTIONS = [
        'auto_tls' => true,
        'require_tls' => false,
        'verify_peer' => true,
        'local_domain' => null,
        'timeout' => 10,
        'auth_mode' => null,
        'ping_threshold' => 60,
        'allow_insecure_auth' => false,
    ];

    public const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    public const AUTH_MODES = ['CRAM-MD5', 'LOGIN', 'PLAIN'];

    protected mixed $stream = null;

    protected array $capabilities = [];

    protected string $transcript = '';

    protected float $lastActivity = 0.0;

    protected bool $encrypted = false;

    protected array $options;

    public function __construct(
        protected string $host = 'localhost',
        protected int $port = 25,
        protected bool $tls = false,
        protected string $username = '',
        protected string $password = '',
        array $options = [],
    ) {
        $this->options = array_replace(self::DEFAULT_OPTIONS, $options);
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function isTls(): bool
    {
        return $this->tls;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function start(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->bool('verify_peer'),
            'verify_peer_name' => $this->bool('verify_peer'),
            'allow_self_signed' => !$this->bool('verify_peer'),
            'peer_name' => $this->host,
        ]]);
        $address = ($this->tls ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $timeout = (float) $this->options['timeout'];
        $stream = @stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            throw new TransportException('Unable to connect to the SMTP server "{address}": {error} ({code}).', 0, null, ['address' => $address, 'error' => $errstr !== '' ? $errstr : 'connection failed', 'code' => $errno], $this->transcript);
        }

        stream_set_timeout($stream, (int) ceil($timeout));
        $this->stream = $stream;
        $this->encrypted = $this->tls;
        $this->capabilities = [];
        $this->transcript .= '* Connected to ' . $address . "\n";

        try {
            $this->handshake();
        } catch (Throwable $exception) {
            $this->close();

            throw $exception;
        }
    }

    public function stop(): void
    {
        if (!is_resource($this->stream)) {
            $this->stream = null;

            return;
        }

        try {
            $this->write("QUIT\r\n", 'QUIT');
            $this->read();
        } catch (Throwable) {
        }

        $this->close();
    }

    public function isConnected(): bool
    {
        return is_resource($this->stream) && !feof($this->stream);
    }

    public function __toString(): string
    {
        return ($this->tls ? 'smtps' : 'smtp') . '://' . $this->host . ':' . $this->port;
    }

    protected function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
        $this->capabilities = [];
        $this->encrypted = false;
    }

    protected function handshake(): void
    {
        $this->expect($this->read(), [220], 'greeting');
        $this->hello();

        if (!$this->tls && $this->bool('auto_tls') && isset($this->capabilities['STARTTLS'])) {
            $this->command('STARTTLS', [220]);
            $this->enableCrypto();
            $this->hello();
        }

        if ($this->bool('require_tls') && !$this->encrypted) {
            throw new TransportException('The SMTP server "{host}" does not support STARTTLS and "require_tls" is enabled.', 0, null, ['host' => $this->host], $this->transcript);
        }

        if ($this->username !== '') {
            $this->authenticate();
        }
    }

    protected function doSend(SentMessage $message): void
    {
        $this->transcript = '';

        try {
            $this->ping();
            $this->start();
            $envelope = $message->getEnvelope();
            $this->command('MAIL FROM:<' . $envelope->getSender()->getAddress() . '>', [250]);

            foreach ($envelope->getRecipients() as $recipient) {
                $this->command('RCPT TO:<' . $recipient->getAddress() . '>', [250, 251, 252]);
            }

            $this->command('DATA', [354]);
            $data = $message->toString();
            $data = (str_starts_with($data, '.') ? '.' : '') . str_replace("\r\n.", "\r\n..", $data);
            $this->write($data . "\r\n.\r\n", '[message data, ' . strlen($data) . ' bytes]');
            $response = $this->expect($this->read(), [250], 'end of data');
            $message->setTransportId(preg_match('/queued as\s+(\S+)/i', $response, $matches) === 1 ? $matches[1] : trim(substr($response, 4)));
            $this->lastActivity = microtime(true);
        } catch (TransportException $exception) {
            $this->reset();

            throw new TransportException($exception->getMessage(), 0, $exception, $exception->getContext(), $this->transcript);
        } finally {
            $message->appendDebug($this->transcript);
        }
    }

    protected function ping(): void
    {
        if (!$this->isConnected() || microtime(true) - $this->lastActivity < (float) $this->options['ping_threshold']) {
            return;
        }

        try {
            $this->command('NOOP', [250]);
        } catch (TransportException) {
            $this->stop();
        }
    }

    protected function reset(): void
    {
        if (!$this->isConnected()) {
            $this->close();

            return;
        }

        try {
            $this->command('RSET', [250]);
        } catch (Throwable) {
            $this->stop();
        }
    }

    protected function hello(): void
    {
        $domain = $this->localDomain();

        try {
            $response = $this->command('EHLO ' . $domain, [250]);
        } catch (TransportException) {
            $this->command('HELO ' . $domain, [250]);
            $this->capabilities = [];

            return;
        }

        $this->capabilities = [];

        foreach (array_slice(explode("\n", trim($response)), 1) as $line) {
            $parts = explode(' ', trim(substr($line, 4)), 2);
            $this->capabilities[strtoupper($parts[0])] = $parts[1] ?? '';
        }
    }

    protected function enableCrypto(): void
    {
        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        if (@stream_socket_enable_crypto($this->stream, true, $method) !== true) {
            $error = error_get_last();

            throw new TransportException('Unable to enable TLS with the SMTP server "{host}": {error}', 0, null, ['host' => $this->host, 'error' => $error['message'] ?? 'handshake failed'], $this->transcript);
        }

        $this->encrypted = true;
        $this->transcript .= "* TLS enabled\n";
    }

    protected function authenticate(): void
    {
        $advertised = isset($this->capabilities['AUTH']) ? array_map('strtoupper', preg_split('/\s+/', trim($this->capabilities['AUTH'])) ?: []) : [];
        $modes = $this->options['auth_mode'] !== null && $this->options['auth_mode'] !== ''
            ? [strtoupper((string) $this->options['auth_mode'])]
            : array_values(array_intersect(self::AUTH_MODES, $advertised));

        if ($modes === []) {
            throw new TransportException('The SMTP server "{host}" does not support any of the authentication modes {modes} (advertised: {advertised}).', 0, null, ['host' => $this->host, 'modes' => implode(', ', self::AUTH_MODES), 'advertised' => $advertised === [] ? 'none' : implode(', ', $advertised)], $this->transcript);
        }

        if (!$this->encrypted && !$this->bool('allow_insecure_auth') && !in_array(strtolower($this->host), self::LOCAL_HOSTS, true)) {
            $modes = array_values(array_diff($modes, ['PLAIN', 'LOGIN']));

            if ($modes === []) {
                throw new TransportException('Refusing to send the credentials in clear text to the SMTP server "{host}": it does not support TLS. Use smtps://, a server with STARTTLS, or the "allow_insecure_auth=1" option.', 0, null, ['host' => $this->host], $this->transcript);
            }
        }

        $errors = [];

        foreach ($modes as $mode) {
            try {
                match ($mode) {
                    'PLAIN' => $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235], 'AUTH PLAIN ****'),
                    'LOGIN' => $this->authLogin(),
                    'CRAM-MD5' => $this->authCramMd5(),
                    default => throw new TransportException('Unknown authentication mode "{mode}".', 0, null, ['mode' => $mode]),
                };

                $this->transcript .= '* Authenticated with ' . $mode . "\n";

                return;
            } catch (TransportException $exception) {
                $errors[] = $mode . ': ' . $exception->getMessage();

                if (!$this->isConnected()) {
                    break;
                }
            }
        }

        throw new TransportException('Failed to authenticate on the SMTP server "{host}" with the user "{user}": {errors}', 0, null, ['host' => $this->host, 'user' => $this->username, 'errors' => implode(' | ', $errors)], $this->transcript);
    }

    protected function authLogin(): void
    {
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->username), [334], '****');
        $this->command(base64_encode($this->password), [235], '****');
    }

    protected function authCramMd5(): void
    {
        $challenge = base64_decode(trim(substr($this->command('AUTH CRAM-MD5', [334]), 4)), true);
        $this->command(base64_encode($this->username . ' ' . hash_hmac('md5', (string) $challenge, $this->password)), [235], '****');
    }

    protected function command(string $command, array $codes, ?string $log = null): string
    {
        $this->write($command . "\r\n", $log ?? $command);

        return $this->expect($this->read(), $codes, $log ?? $command);
    }

    protected function expect(string $response, array $codes, string $command): string
    {
        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $codes, true)) {
            throw new TransportException('Expected response code {expected} to "{command}" but got {code}: "{response}".', $code, null, [
                'expected' => implode('/', $codes),
                'command' => $command,
                'code' => $code === 0 ? 'nothing' : (string) $code,
                'response' => trim(str_replace("\n", ' ', $response)),
            ], $this->transcript);
        }

        return $response;
    }

    protected function write(string $data, string $log): void
    {
        if (!is_resource($this->stream)) {
            throw new TransportException('The connection to the SMTP server "{host}" is closed.', 0, null, ['host' => $this->host], $this->transcript);
        }

        $this->transcript .= '> ' . $log . "\n";
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $bytes = @fwrite($this->stream, substr($data, $written));

            if ($bytes === false || $bytes === 0) {
                throw new TransportException('Unable to write to the SMTP server "{host}".', 0, null, ['host' => $this->host], $this->transcript);
            }

            $written += $bytes;
        }
    }

    protected function read(): string
    {
        $response = '';

        while (is_resource($this->stream)) {
            $line = fgets($this->stream, 1024);

            if ($line === false) {
                $meta = stream_get_meta_data($this->stream);

                throw new TransportException(!empty($meta['timed_out']) ? 'Timeout while reading from the SMTP server "{host}".' : 'The SMTP server "{host}" closed the connection.', 0, null, ['host' => $this->host], $this->transcript);
            }

            $line = rtrim($line, "\r\n");
            $this->transcript .= '< ' . $line . "\n";
            $response .= $line . "\n";

            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $this->lastActivity = microtime(true);

        return $response;
    }

    protected function localDomain(): string
    {
        $domain = $this->options['local_domain'];

        if (is_string($domain) && $domain !== '') {
            return $domain;
        }

        $name = gethostname();

        return is_string($name) && $name !== '' && str_contains($name, '.') ? $name : '[127.0.0.1]';
    }

    protected function bool(string $option): bool
    {
        $value = $this->options[$option];

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}