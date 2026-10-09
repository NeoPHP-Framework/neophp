<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Helper\WebProfiler;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Session\SessionManagerInterface;
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

/**
 * @internal
 */
class SessionProfiler extends AbstractProfiler implements ProfilerInterface
{
    public const PRIORITY = 290;

    public const HIDDEN = '******';

    public const SENSITIVE = '/pass(word)?|secret|token|authorization|api[_-]?key|csrf|remember|sess/i';

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        $name = $this->sessionName();
        $id = session_id();
        $opened = is_string($id) && $id !== '' && isset($_SESSION);
        $attributes = $opened ? $_SESSION : [];

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
            'cookies' => [
                'request' => $this->mask($request->cookies->all()),
                'response' => $this->responseCookies($response),
            ],
        ];
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        $session = (array) ($data['session'] ?? []);
        $cookies = (array) ($data['cookies'] ?? []);
        $requestCookies = (array) ($cookies['request'] ?? []);
        $responseCookies = (array) ($cookies['response'] ?? []);

        return new Panel('Session / Cookies', 'user', [
            new TabsBlock([
                'Session' => $this->sessionBlocks($session),
                'Cookies (' . (count($requestCookies) + count($responseCookies)) . ')' => [
                    new KeyValueBlock($requestCookies, 'Request cookies (sent by the browser)', 'The browser sent no cookie.'),
                    new TableBlock(['Name', 'Value', 'Expires', 'Path', 'Domain', 'Secure', 'HttpOnly', 'SameSite'], array_map(static fn (array $cookie): array => array_values($cookie), $responseCookies), 'Response cookies (Set-Cookie)', 'The response sets no cookie.'),
                ],
            ]),
        ]);
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
            if ($this->container->resolved(SessionManagerInterface::class)) {
                return $this->container->get(SessionManagerInterface::class)->getName();
            }
        } catch (Throwable) {
            return (string) session_name();
        }

        return (string) session_name();
    }
}