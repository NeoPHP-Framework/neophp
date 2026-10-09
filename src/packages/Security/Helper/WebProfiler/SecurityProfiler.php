<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\WebProfiler;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Authorization\RoleHierarchy;
use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Firewall\Firewall;
use NeoPHP\Package\Security\Firewall\FirewallMap;
use NeoPHP\Package\Security\SecurityManager;
use NeoPHP\Package\Security\SecurityManagerInterface;
use NeoPHP\Package\Security\User\UserClass;
use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\KeyValueBlock;
use NeoPHP\Package\WebProfiler\Block\MetricBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Block\TabsBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Model\Metric;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use Throwable;

/**
 * @internal
 */
class SecurityProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface
{
    public const PRIORITY = 70;

    public const MASK = '******';

    public const SECRET_PATTERN = '/pass(word)?|secret|(^|_)token$|api_?key|private/i';

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        if (!$this->container->bound(SecurityManagerInterface::class) || !$this->container->resolved(SecurityManagerInterface::class)) {
            return [];
        }

        $security = $this->container->get(SecurityManagerInterface::class);

        if (!$security instanceof SecurityManagerInterface) {
            return [];
        }

        try {
            $token = $security->getToken();
        } catch (Throwable) {
            $token = null;
        }

        $firewall = $this->firewall($security, $request);
        $data = [
            'enabled' => $security->isEnabled(),
            'authenticated' => $token !== null && $token->isAuthenticated(),
            'token' => $this->token($token),
            'firewall' => $firewall,
            'impersonator' => null,
            'strategy' => null,
            'voters' => [],
            'decisions' => [],
            'dropped' => 0,
            'events' => [],
            'access_control' => ['rules' => [], 'matched' => null, 'roles' => null, 'granted' => null],
            'tracing' => false,
        ];

        if (!$security instanceof SecurityManager) {
            return $data;
        }

        $manager = $security->getAccessDecisionManager();
        $data['strategy'] = $manager->getStrategy();
        $data['voters'] = array_map(static fn (object $voter): string => $voter::class, $manager->getVoters());
        $data['access_control']['rules'] = $this->rules($security->getAccessMap()->getRules());
        $data['access_control']['matched'] = $this->matchRule($request, $security->getAccessMap()->getRules());
        $trace = $manager->getTrace();

        if ($trace !== null) {
            $data['tracing'] = true;
            $data['decisions'] = $trace->getDecisions();
            $data['dropped'] = $trace->getDropped();
            $data['events'] = $trace->getEvents();
            $data['access_control']['roles'] = $trace->getAccessControl()['roles'] ?? null;
            $data['access_control']['granted'] = $trace->getAccessControl()['granted'] ?? null;
            $trace->reset();
        }

        return $data;
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        if (!isset($data['enabled'])) {
            return null;
        }

        $token = (array) ($data['token'] ?? []);
        $authenticated = (bool) ($data['authenticated'] ?? false);
        $denied = $this->denied($data);
        $status = match (true) {
            $denied > 0 => Status::WARNING,
            $authenticated => Status::SUCCESS,
            default => Status::DEFAULT,
        };

        return new ToolbarItem('Security', $authenticated ? (string) ($token['user'] ?? 'n/a') : 'n/a', 'user', $status, [
            'User' => $authenticated ? (string) ($token['user'] ?? '') : 'anonymous',
            'Roles' => implode(', ', (array) ($token['roles'] ?? [])) ?: 'none',
            'Firewall' => (string) ($data['firewall']['name'] ?? 'none'),
            'Authenticated' => $authenticated ? 'yes' : 'no',
            'Token' => (string) ($token['class'] ?? 'none'),
            'Decisions' => sprintf('%d (%d denied)', count((array) ($data['decisions'] ?? [])), $denied),
            'Logout' => (string) ($data['firewall']['logout_path'] ?? '') ?: 'n/a',
        ]);
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        if (!isset($data['enabled'])) {
            return null;
        }

        $token = (array) ($data['token'] ?? []);
        $firewall = (array) ($data['firewall'] ?? []);
        $decisions = (array) ($data['decisions'] ?? []);
        $events = (array) ($data['events'] ?? []);
        $access = (array) ($data['access_control'] ?? []);
        $authenticated = (bool) ($data['authenticated'] ?? false);
        $denied = $this->denied($data);
        $blocks = [
            new MetricBlock([
                new Metric('Authenticated', $authenticated ? 'yes' : 'no', null, $authenticated ? Status::SUCCESS : Status::DEFAULT),
                new Metric('Roles', count((array) ($token['reachable_roles'] ?? []))),
                new Metric('Decisions', count($decisions)),
                new Metric('Denied', $denied, null, $denied > 0 ? Status::WARNING : Status::DEFAULT),
            ]),
        ];

        if (!(bool) $data['enabled']) {
            $blocks[] = new AlertBlock('Security is disabled: no firewall nor access_control configured.', Status::INFO);
        }

        if (!(bool) ($data['tracing'] ?? false)) {
            $blocks[] = new AlertBlock('Access decisions are not traced (the web profiler was disabled when the access decision manager was built).', Status::INFO);
        }

        if ((int) ($data['dropped'] ?? 0) > 0) {
            $blocks[] = new AlertBlock(sprintf('%d decisions were not recorded (limit reached).', (int) $data['dropped']), Status::WARNING);
        }

        $blocks[] = new KeyValueBlock([
            'User' => $authenticated ? (string) ($token['user'] ?? '') : 'anonymous',
            'User class' => (string) ($token['user_class'] ?? '') ?: 'n/a',
            'Token class' => (string) ($token['class'] ?? '') ?: 'none',
            'Authenticator' => (string) ($token['authenticator'] ?? '') ?: 'n/a',
            'Remembered' => ($token['remembered'] ?? false) ? 'yes' : 'no',
            'Firewall' => (string) ($firewall['name'] ?? '') ?: 'none',
            'Impersonator' => (string) ($data['impersonator'] ?? '') ?: 'n/a',
            'Logout path' => (string) ($firewall['logout_path'] ?? '') ?: 'n/a',
        ], 'User & token');

        $direct = (array) ($token['roles'] ?? []);
        $reachable = (array) ($token['reachable_roles'] ?? []);
        $rules = (array) ($access['rules'] ?? []);
        $matched = $access['matched'] ?? null;

        $blocks[] = new TabsBlock([
            sprintf('Roles (%d)', count($reachable)) => [new TableBlock(['Role', 'Origin'], array_map(static fn (string $role): array => [$role, in_array($role, $direct, true) ? 'direct' : 'inherited (role hierarchy)'], $reachable), null, 'No role.')],
            'Firewall' => [new KeyValueBlock((array) ($firewall['config'] ?? []), null, 'No firewall matched this request.')],
            sprintf('Access decisions (%d)', count($decisions)) => [new TableBlock(['#', 'Attribute', 'Subject', 'Result', 'Strategy', 'Voters'], array_map(fn (int $index, array $decision): array => [
                $index + 1,
                implode(', ', (array) ($decision['attributes'] ?? [])),
                (string) ($decision['subject'] ?? '') ?: 'null',
                ($decision['granted'] ?? false) ? 'GRANTED' : 'DENIED',
                (string) ($decision['strategy'] ?? ''),
                $this->votes((array) ($decision['votes'] ?? [])),
            ], array_keys($decisions), $decisions), null, 'No access decision during this request.')],
            sprintf('Access control (%d)', count($rules)) => [
                new KeyValueBlock([
                    'Matched rule' => $matched === null ? 'none' : '#' . ((int) $matched + 1),
                    'Required roles' => ($access['roles'] ?? null) === null ? 'n/a' : (implode(', ', (array) $access['roles']) ?: 'none'),
                    'Result' => match ($access['granted'] ?? null) {
                        true => 'GRANTED',
                        false => 'DENIED',
                        default => 'not checked (public or no rule)',
                    },
                ]),
                new TableBlock(['#', 'Path', 'Roles', 'Other', 'Matched'], array_map(static fn (int $index, array $rule): array => [
                    $index + 1,
                    $rule['path'],
                    $rule['roles'],
                    $rule['other'],
                    $index === $matched ? 'yes' : '',
                ], array_keys($rules), $rules), null, 'No access_control rule.'),
            ],
            sprintf('Events (%d)', count($events)) => [new TableBlock(['Type', 'Firewall', 'Authenticator', 'User', 'Message'], array_map(static fn (array $event): array => [
                (string) ($event['type'] ?? ''),
                (string) ($event['firewall'] ?? ''),
                (string) ($event['authenticator'] ?? ''),
                (string) ($event['user'] ?? ''),
                (string) ($event['message'] ?? ''),
            ], $events), null, 'No authentication event during this request.')],
            sprintf('Voters (%d)', count((array) ($data['voters'] ?? []))) => [
                new KeyValueBlock(['Strategy' => (string) ($data['strategy'] ?? '') ?: 'n/a']),
                new TableBlock(['#', 'Voter'], array_map(static fn (int $index, string $voter): array => [$index + 1, $voter], array_keys((array) ($data['voters'] ?? [])), (array) ($data['voters'] ?? [])), null, 'No voter registered.'),
            ],
        ]);

        return new Panel('Security', 'user', $blocks, $denied > 0 ? $denied : null);
    }

    protected function token(?TokenInterface $token): array
    {
        if ($token === null) {
            return [];
        }

        $user = $token->getUser();
        $roles = array_values(array_map('strval', $token->getRoleNames()));

        return [
            'class' => $token::class,
            'user' => $token->getUserIdentifier(),
            'user_class' => $user !== null ? UserClass::of($user) : null,
            'authenticator' => $token->getAuthenticator(),
            'remembered' => $token->isRemembered(),
            'roles' => $roles,
            'reachable_roles' => $this->reachable($roles),
            'attributes' => $this->mask($token->getAttributes()),
        ];
    }

    protected function reachable(array $roles): array
    {
        if (!$this->container->has(RoleHierarchy::class)) {
            return $roles;
        }

        $hierarchy = $this->container->get(RoleHierarchy::class);

        return $hierarchy instanceof RoleHierarchy ? $hierarchy->getReachableRoleNames($roles) : $roles;
    }

    protected function firewall(SecurityManagerInterface $security, Request $request): array
    {
        try {
            $firewall = $security->getFirewall();
        } catch (Throwable) {
            $firewall = null;
        }

        $name = $firewall?->getName() ?? $request->attributes->get('_firewall');

        if (!$firewall instanceof Firewall) {
            return is_string($name) ? ['name' => $name, 'logout_path' => null, 'config' => []] : [];
        }

        $config = $firewall->getConfig();
        $logout = $firewall->getLogout();
        $entry = $firewall->getEntryPoint();
        $summary = [
            'Name' => $firewall->getName(),
            'Pattern' => (string) ($config['pattern'] ?? '') ?: 'any',
            'Security' => $firewall->isSecurityEnabled() ? 'enabled' : 'disabled',
            'Stateless' => $firewall->isStateless() ? 'yes' : 'no',
            'Context' => $firewall->getContext(),
            'Provider' => (string) ($config['provider'] ?? '') ?: ($firewall->hasProvider() ? 'default' : 'none'),
            'Authenticators' => implode(', ', array_keys($firewall->getAuthenticators())) ?: 'none',
            'Entry point' => $entry !== null ? $entry::class : 'none',
            'User checker' => $firewall->getUserChecker() !== null ? $firewall->getUserChecker()::class : 'none',
            'Remember me' => $firewall->getRememberMe() !== null ? 'yes' : 'no',
            'Login throttling' => $firewall->getThrottler() !== null ? 'yes' : 'no',
        ];

        if (is_array($config['form_login'] ?? null)) {
            $summary['Login path'] = (string) ($config['form_login']['login_path'] ?? '');
            $summary['Check path'] = (string) ($config['form_login']['check_path'] ?? '');
        }

        if ($logout !== null) {
            $summary['Logout path'] = (string) ($logout['path'] ?? '');
            $summary['Logout target'] = (string) ($logout['target'] ?? '');
        }

        foreach ($this->mask($config) as $key => $value) {
            $summary['config.' . $key] = is_scalar($value) || $value === null ? $this->scalar($value) : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return ['name' => $firewall->getName(), 'logout_path' => $logout !== null ? (string) ($logout['path'] ?? '') : null, 'config' => $summary];
    }

    protected function rules(array $rules): array
    {
        $result = [];

        foreach ($rules as $rule) {
            $rule = (array) $rule;
            $other = array_diff_key($rule, ['path' => true, 'pattern' => true, 'roles' => true]);
            $result[] = [
                'path' => (string) ($rule['path'] ?? $rule['pattern'] ?? '') ?: 'any',
                'roles' => implode(', ', array_map('strval', (array) ($rule['roles'] ?? []))),
                'other' => $other === [] ? '' : (string) json_encode($this->mask($other), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ];
        }

        return $result;
    }

    protected function matchRule(Request $request, array $rules): ?int
    {
        foreach (array_values($rules) as $index => $rule) {
            if (is_array($rule) && FirewallMap::matches($request, $rule)) {
                return $index;
            }
        }

        return null;
    }

    protected function mask(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            $result[$key] = match (true) {
                is_string($key) && preg_match(self::SECRET_PATTERN, $key) === 1 && !is_array($value) && $value !== null && $value !== false => self::MASK,
                is_array($value) => $this->mask($value),
                is_object($value) => get_debug_type($value),
                default => $value,
            };
        }

        return $result;
    }

    protected function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    protected function votes(array $votes): string
    {
        if ($votes === []) {
            return 'no voter';
        }

        return implode(', ', array_map(static function (array $vote): string {
            $voter = (string) ($vote['voter'] ?? '');
            $short = substr((string) strrchr('\\' . $voter, '\\'), 1);

            return sprintf('%s: %s', $short, (string) ($vote['vote'] ?? ''));
        }, $votes));
    }

    protected function denied(array $data): int
    {
        return count(array_filter((array) ($data['decisions'] ?? []), static fn (mixed $decision): bool => is_array($decision) && !($decision['granted'] ?? false)));
    }
}