<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode;

use Closure;
use DateTimeImmutable;
use JsonException;
use NeoPHP\Component\Cache\CacheManager;
use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Package\QrCode\Code\CodeStore;
use NeoPHP\Package\QrCode\Exception\QrCodeException;
use NeoPHP\Package\QrCode\Model\ContentParser;
use NeoPHP\Package\QrCode\Model\QrCode;
use NeoPHP\Package\QrCode\Model\ReadResult;
use NeoPHP\Package\QrCode\Provider\QrCodeProvider;
use NeoPHP\Package\QrCode\Signer\PayloadSigner;

#[Package(provider: QrCodeProvider::class, requires: [CacheManager::class])]
class QrCodeManager implements QrCodeManagerInterface
{
    public const CONFIG_FILE = 'config/packages/qr_code.yaml';

    public const READER_SCRIPT = __DIR__ . '/Resources/qr-reader.js';

    public const DEFAULT_CONFIG = [
        'path' => '/_qr',
        'cache_pool' => null,
        'image_ttl' => 86400,
        'defaults' => QrCode::DEFAULT_OPTIONS,
        'code' => [
            'length' => 8,
            'ttl' => 2592000,
        ],
        'signed' => [
            'ttl' => null,
            'leeway' => 5,
        ],
        'reader' => [
            'route' => false,
        ],
    ];

    private const KEY_IMAGE = 'qr_code.image.';

    protected array $config;

    protected PayloadSigner $signer;

    protected CodeStore $codes;

    /**
     * @param Closure(): string|null $basePath Base path of the application (sub-directory), added to the image URLs
     */
    public function __construct(array $config = [], protected ?CacheInterface $cache = null, string $secret = '', protected ?Closure $basePath = null)
    {
        $this->config = array_replace(self::DEFAULT_CONFIG, array_filter($config, static fn (mixed $value): bool => $value !== null));

        foreach (['defaults', 'code', 'signed', 'reader'] as $key) {
            $this->config[$key] = array_replace(self::DEFAULT_CONFIG[$key], (array) ($config[$key] ?? []));
        }

        $length = (int) $this->config['code']['length'];

        if ($length < 6 || $length > 16) {
            throw new QrCodeException('code.length must be between 6 and 16 characters in {config}, {length} given.', 0, null, ['config' => self::CONFIG_FILE, 'length' => $length]);
        }

        $this->signer = new PayloadSigner($secret);
        $this->codes = new CodeStore($cache, $this->signer, $length);
    }

    public function create(string $content, array $options = []): QrCode
    {
        $code = $options['code'] ?? false;
        unset($options['code']);
        $qr = new QrCode($content, array_replace((array) $this->config['defaults'], $options));

        if ($code === false) {
            return $qr;
        }

        return $this->withCode($qr, $this->code($content, is_int($code) ? $code : null), null);
    }

    public function signed(mixed $data, ?int $rotate = null, ?int $ttl = null, bool $once = false, array $options = []): QrCode
    {
        unset($options['code']);
        $now = time();

        if ($rotate !== null && $rotate < 5) {
            throw new QrCodeException('The rotation of a signed QR code must be at least 5 seconds, {rotate} given.', 0, null, ['rotate' => $rotate]);
        }

        $ttl ??= $this->config['signed']['ttl'] !== null ? (int) $this->config['signed']['ttl'] : null;
        $expiresAt = match (true) {
            $rotate !== null => (intdiv($now, $rotate) + 1) * $rotate,
            $ttl !== null => $now + max(1, $ttl),
            default => null,
        };
        $content = $this->signer->sign($data, $expiresAt, $once);
        $codeTtl = $expiresAt !== null ? $expiresAt - $now + (int) $this->config['signed']['leeway'] : null;

        return $this->withCode(
            new QrCode($content, array_replace((array) $this->config['defaults'], $options)),
            $this->code($content, $codeTtl),
            $expiresAt !== null ? (new DateTimeImmutable())->setTimestamp($expiresAt) : null,
        );
    }

    public function wifi(string $ssid, ?string $password = null, string $encryption = 'WPA', bool $hidden = false, array $options = []): QrCode
    {
        $encryption = strtoupper($encryption) === 'NOPASS' || $password === null || $password === '' ? 'nopass' : strtoupper($encryption);
        $content = 'WIFI:T:' . $encryption . ';S:' . ContentParser::escapeWifi($ssid) . ';';

        if ($encryption !== 'nopass') {
            $content .= 'P:' . ContentParser::escapeWifi((string) $password) . ';';
        }

        return $this->create($content . ($hidden ? 'H:true;' : '') . ';', $options);
    }

    public function vcard(array $contact, array $options = []): QrCode
    {
        $escape = static fn (string $value): string => str_replace(["\r\n", "\n", ',', ';'], ['\\n', '\\n', '\\,', '\\;'], $value);
        $fields = ['name' => 'FN', 'phone' => 'TEL', 'email' => 'EMAIL', 'organization' => 'ORG', 'title' => 'TITLE', 'url' => 'URL', 'address' => 'ADR', 'note' => 'NOTE'];
        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];

        if (isset($contact['name'])) {
            $lines[] = 'N:' . $escape((string) $contact['name']) . ';;;;';
        }

        foreach ($fields as $key => $field) {
            if (isset($contact[$key]) && $contact[$key] !== '') {
                $lines[] = $field . ($key === 'address' ? ':;;' : ':') . $escape((string) $contact[$key]);
            }
        }

        $lines[] = 'END:VCARD';

        return $this->create(implode("\r\n", $lines), $options);
    }

    public function email(string $to, ?string $subject = null, ?string $body = null, array $options = []): QrCode
    {
        $query = http_build_query(array_filter(['subject' => $subject, 'body' => $body], static fn (?string $value): bool => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986);

        return $this->create('mailto:' . $to . ($query !== '' ? '?' . $query : ''), $options);
    }

    public function phone(string $number, array $options = []): QrCode
    {
        return $this->create('tel:' . preg_replace('/[^\d+]/', '', $number), $options);
    }

    public function sms(string $number, ?string $message = null, array $options = []): QrCode
    {
        return $this->create('SMSTO:' . preg_replace('/[^\d+]/', '', $number) . ($message !== null && $message !== '' ? ':' . $message : ''), $options);
    }

    public function geo(float $latitude, float $longitude, array $options = []): QrCode
    {
        return $this->create(sprintf('geo:%s,%s', self::coordinate($latitude), self::coordinate($longitude)), $options);
    }

    public function json(mixed $data, array $options = []): QrCode
    {
        try {
            return $this->create(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $options);
        } catch (JsonException $exception) {
            throw new QrCodeException('The data of the QR code cannot be encoded in JSON: {error}', 0, $exception, ['error' => $exception->getMessage()]);
        }
    }

    public function code(string $content, ?int $ttl = null): string
    {
        return $this->codes->register($content, $ttl ?? ($this->config['code']['ttl'] !== null ? (int) $this->config['code']['ttl'] : null));
    }

    public function read(string $input, ?string $source = null): ReadResult
    {
        $input = trim($input);
        $typed = $source === ReadResult::SOURCE_CODE;

        if ($input === '') {
            return ReadResult::failure(ReadResult::ERROR_EMPTY, $typed ? ReadResult::SOURCE_CODE : ReadResult::SOURCE_QR);
        }

        $code = $source === ReadResult::SOURCE_QR || $this->signer->isSigned($input) ? null : $this->codes->normalize($input);
        $source = $typed ? ReadResult::SOURCE_CODE : ReadResult::SOURCE_QR;
        $content = $input;

        if ($code !== null) {
            $resolved = $this->codes->resolve($code);

            if ($resolved !== null) {
                $source = ReadResult::SOURCE_CODE;
                $content = $resolved;
            } elseif ($typed || preg_match('/[\s\-]/', $input) === 1 || $input === strtoupper($input)) {
                return ReadResult::failure(ReadResult::ERROR_UNKNOWN_CODE, ReadResult::SOURCE_CODE, '', CodeStore::format($code));
            }
        } elseif ($typed && !$this->signer->isSigned($input)) {
            return ReadResult::failure(ReadResult::ERROR_UNKNOWN_CODE, ReadResult::SOURCE_CODE);
        }

        $formatted = $code !== null && $source === ReadResult::SOURCE_CODE ? CodeStore::format($code) : null;

        if (!$this->signer->isSigned($content)) {
            [$type, $data] = ContentParser::parse($content);

            return ReadResult::success($source, $type, $content, $data, $formatted);
        }

        $payload = $this->signer->verify($content);

        if ($payload === null) {
            return ReadResult::failure(ReadResult::ERROR_INVALID_SIGNATURE, $source, $content, $formatted, true);
        }

        $expiresAt = $payload['expires_at'] !== null ? (new DateTimeImmutable())->setTimestamp($payload['expires_at']) : null;
        $formatted ??= $this->codeOf($content);

        if ($payload['expires_at'] !== null && time() > $payload['expires_at'] + (int) $this->config['signed']['leeway']) {
            return ReadResult::failure(ReadResult::ERROR_EXPIRED, $source, $content, $formatted, true, $expiresAt);
        }

        if ($payload['once'] && !$this->codes->consume($content, $payload['expires_at'] !== null ? $payload['expires_at'] - time() + (int) $this->config['signed']['leeway'] + 60 : 31536000)) {
            return ReadResult::failure(ReadResult::ERROR_ALREADY_USED, $source, $content, $formatted, true, $expiresAt);
        }

        return ReadResult::success($source, 'signed', $content, $payload['data'], $formatted, true, $expiresAt);
    }

    public function imageUrl(string $content, array $options = [], string $format = 'svg'): string
    {
        $format = QrCode::format($format);
        unset($options['code']);
        $qr = new QrCode($content, array_replace((array) $this->config['defaults'], $options));
        $spec = ['content' => $content, 'options' => $qr->getOptions()];
        $key = substr(hash('sha256', (string) json_encode($spec)), 0, 32);

        if ($this->cache === null) {
            throw new QrCodeException('The image URLs of the QR codes are kept in a cache pool: enable the Cache component and check cache_pool in {config}.', 0, null, ['config' => self::CONFIG_FILE]);
        }

        $this->cache->set(self::KEY_IMAGE . $key, $spec, (int) $this->config['image_ttl'] > 0 ? (int) $this->config['image_ttl'] : null);

        return rtrim($this->basePath !== null ? (string) ($this->basePath)() : '', '/') . $this->getPath() . '/' . $key . '.' . $format;
    }

    public function fromImageKey(string $key): ?QrCode
    {
        if (preg_match('/^[a-f0-9]{32}$/', $key) !== 1 || $this->cache === null) {
            return null;
        }

        $spec = $this->cache->get(self::KEY_IMAGE . $key);

        return is_array($spec) && isset($spec['content']) ? new QrCode((string) $spec['content'], (array) ($spec['options'] ?? [])) : null;
    }

    public function getPath(): string
    {
        return '/' . trim((string) $this->config['path'], '/');
    }

    public function getReaderScript(): string
    {
        return self::READER_SCRIPT;
    }

    public function getReaderScriptUrl(): string
    {
        return rtrim($this->basePath !== null ? (string) ($this->basePath)() : '', '/') . $this->getPath() . '/reader.js';
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Short code of a scanned content when one was registered for it.
     */
    protected function codeOf(string $content): ?string
    {
        $code = $this->codes->codeFor($content);

        return $this->codes->resolve($code) === $content ? CodeStore::format($code) : null;
    }

    protected function withCode(QrCode $qr, ?string $code, ?DateTimeImmutable $expiresAt): QrCode
    {
        return new QrCode($qr->getContent(), $qr->getOptions(), $code, $expiresAt);
    }

    protected static function coordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.');
    }
}