<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Helper\Profiler;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Session\Contract\SessionInterface;
use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\KeyValueBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Block\TabsBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use Throwable;

class SessionProfiler extends AbstractProfiler implements ProfilerInterface
{
    public const PRIORITY = 290;

    public const HIDDEN = '******';

    public const SENSITIVE = '/pass(word)?|secret|token|authorization|api[_-]?key|csrf|remember|sess/i';

    public const FLASH_CONFIG = 'framework.app.flash.key';

    public const DEFAULT_FLASH_KEY = '_flashes';

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        $name = $this->sessionName();
        $flashKey = $this->flashKey();
        $id = session_id();
        $opened = is_string($id) && $id !== '' && isset($_SESSION) && is_array($_SESSION);
        $attributes = $opened ? $_SESSION : [];
        $flashes = $attributes[$flashKey] ?? [];
        unset($attributes[$flashKey]);

        return [
            'session' => [
                'name' => $name,
                'opened' => $opened,
                'cookie_sent' => $name !== '' && $request->cookies->has($name),
                'id' => $opened ? substr((string) $id, 0, 6) . '…' : null,
                'attributes' => $this->mask($attributes),
                'size' => $opened ? strlen(serialize($_SESSION)) : 0,
                'cookie' => $opened ? session_get_cookie_params() : [],
                'gc_maxlifetime' => (int) ini_get('session.gc_maxlifetime'),
                'save_path' => (string) session_save_path(),
            ],
            'flashes' => [
                'key' => $flashKey,
                'pending' => is_array($flashes) ? $flashes : [],
            ],
            'cookies' => [
                'request' => $this->mask($request->cookies->all()),
                'response' => $this->responseCookies($response),
            ],
        ];
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        $session = (array) ($data['session'] ?? []);
        $flashes = (array) ($data['flashes'] ?? []);
        $cookies = (array) ($data['cookies'] ?? []);
        $pending = (array) ($flashes['pending'] ?? []);
        $flashCount = array_sum(array_map(static fn (mixed $messages): int => is_array($messages) ? count($messages) : 1, $pending));
        $requestCookies = (array) ($cookies['request'] ?? []);
        $responseCookies = (array) ($cookies['response'] ?? []);

        return new Panel('Session / Cookies / Flash', 'user', [
            new TabsBlock([
                'Session' => $this->sessionBlocks($session),
                'Flash messages (' . $flashCount . ')' => $this->flashBlocks($pending, (string) ($flashes['key'] ?? self::DEFAULT_FLASH_KEY)),
                'Cookies (' . (count($requestCookies) + count($responseCookies)) . ')' => [
                    new KeyValueBlock($requestCookies, 'Request cookies (sent by the browser)', 'The browser sent no cookie.'),
                    new TableBlock(['Name', 'Value', 'Expires', 'Path', 'Domain', 'Secure', 'HttpOnly', 'SameSite'], array_map(static fn (array $cookie): array => array_values($cookie), $responseCookies), 'Response cookies (Set-Cookie)', 'The response sets no cookie.'),
                ],
            ]),
        ], $flashCount > 0 ? $flashCount : null, $flashCount > 0 ? Status::INFO : Status::DEFAULT);
    }

    protected function sessionBlocks(array $session): array
    {
        if (!($session['opened'] ?? false)) {
            return [new AlertBlock(($session['cookie_sent'] ?? false)
                ? 'The browser sent a session cookie, but the session was not opened during this request (no read nor write).'
                : 'No session was used during this request.', Status::INFO)];
        }

        $cookie = (array) ($session['cookie'] ?? []);
        $lifetime = (int) ($cookie['lifetime'] ?? 0);

        return [
            new KeyValueBlock([
                'Name' => $session['name'] ?? '',
                'ID' => $session['id'] ?? '',
                'Attributes' => count((array) ($session['attributes'] ?? [])),
                'Size' => ($session['size'] ?? 0) . ' bytes',
                'Cookie lifetime' => $lifetime === 0 ? 'until the browser is closed' : $lifetime . ' s',
                'Garbage collection after' => ($session['gc_maxlifetime'] ?? 0) . ' s of inactivity',
                'Cookie options' => sprintf(
                    'path=%s; domain=%s; secure=%s; httponly=%s; samesite=%s',
                    $cookie['path'] ?? '/',
                    ($cookie['domain'] ?? '') !== '' ? $cookie['domain'] : '(current host)',
                    ($cookie['secure'] ?? false) ? 'yes' : 'no',
                    ($cookie['httponly'] ?? false) ? 'yes' : 'no',
                    ($cookie['samesite'] ?? '') !== '' ? $cookie['samesite'] : 'n/a',
                ),
                'Save path' => ($session['save_path'] ?? '') !== '' ? $session['save_path'] : '(PHP default)',
            ], 'Session'),
            new KeyValueBlock((array) ($session['attributes'] ?? []), 'Attributes (at the end of the request)', 'The session is empty.'),
        ];
    }

    protected function flashBlocks(array $pending, string $key): array
    {
        $rows = [];

        foreach ($pending as $type => $messages) {
            foreach ((array) $messages as $message) {
                $rows[] = [(string) $type, $message];
            }
        }

        return [
            new TableBlock(['Type', 'Message'], $rows, 'Pending flash messages', 'No pending flash message: they were displayed (read) during this request, or none was added.'),
            new AlertBlock(sprintf('Messages still stored in the session (key "%s") at the end of the request: they will be displayed by the next page that reads them. After a redirection, open the profile of the previous request to see the messages added before it.', $key), Status::INFO),
        ];
    }

    protected function responseCookies(Response $response): array
    {
        $cookies = [];

        foreach ($response->headers->getValues('Set-Cookie') as $header) {
            $parts = array_map('trim', explode(';', (string) $header));
            [$name, $value] = array_pad(explode('=', (string) array_shift($parts), 2), 2, '');
            $cookie = ['name' => $name, 'value' => preg_match(self::SENSITIVE, $name) === 1 && $value !== '' ? self::HIDDEN : urldecode($value), 'expires' => 'session', 'path' => '', 'domain' => '', 'secure' => 'no', 'httponly' => 'no', 'samesite' => ''];

            foreach ($parts as $part) {
                [$attribute, $option] = array_pad(explode('=', $part, 2), 2, '');
                $attribute = strtolower(trim($attribute));

                match ($attribute) {
                    'expires' => $cookie['expires'] = $option,
                    'max-age' => $cookie['expires'] = (int) $option <= 0 ? 'deleted' : (int) $option . ' s',
                    'path' => $cookie['path'] = $option,
                    'domain' => $cookie['domain'] = $option,
                    'secure' => $cookie['secure'] = 'yes',
                    'httponly' => $cookie['httponly'] = 'yes',
                    'samesite' => $cookie['samesite'] = $option,
                    default => null,
                };
            }

            if ($cookie['expires'] !== 'session' && $cookie['expires'] !== 'deleted' && !str_ends_with($cookie['expires'], ' s')) {
                $time = strtotime($cookie['expires']);
                $cookie['expires'] = $time !== false && $time <= time() ? 'deleted' : $cookie['expires'];
            }

            $cookies[] = $cookie;
        }

        return $cookies;
    }

    protected function mask(array $values): array
    {
        foreach ($values as $key => $value) {
            if (preg_match(self::SENSITIVE, (string) $key) === 1) {
                $values[$key] = self::HIDDEN;
            } elseif (is_array($value)) {
                $values[$key] = $this->mask($value);
            } elseif (is_object($value)) {
                $values[$key] = $this->export($value, 3);
            }
        }

        return $values;
    }

    protected function sessionName(): string
    {
        try {
            if ($this->container->resolved(SessionInterface::class)) {
                return $this->container->get(SessionInterface::class)->getName();
            }
        } catch (Throwable) {
            return (string) session_name();
        }

        return (string) session_name();
    }

    protected function flashKey(): string
    {
        try {
            $key = $this->container->has(ConfigInterface::class) ? $this->container->get(ConfigInterface::class)->get(self::FLASH_CONFIG) : null;
        } catch (Throwable) {
            $key = null;
        }

        return is_string($key) && $key !== '' ? $key : self::DEFAULT_FLASH_KEY;
    }
}