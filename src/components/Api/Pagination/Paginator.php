<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Pagination;

use Closure;
use NeoPHP\Component\Api\Exception\ApiException;
use NeoPHP\Component\Api\Pagination\Adapter\ArrayAdapter;
use NeoPHP\Component\Api\Pagination\Adapter\IterableAdapter;
use NeoPHP\Component\Api\Pagination\Adapter\QueryBuilderAdapter;
use NeoPHP\Component\Api\Pagination\Contract\AdapterInterface;
use NeoPHP\Component\Api\Pagination\Contract\PaginatorInterface;
use NeoPHP\Component\Http\Exception\BadRequestHttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\Orm\Query\QueryBuilder;

class Paginator implements PaginatorInterface
{
    public const DEFAULTS = [
        'page_parameter' => 'page',
        'limit_parameter' => 'limit',
        'default_limit' => 20,
        'max_limit' => 100,
        'absolute_links' => false,
    ];

    protected array $config;

    public function __construct(array $config = [], protected ?Closure $request = null)
    {
        $this->config = array_replace(self::DEFAULTS, $config);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function paginate(mixed $target, PageRequest|Request|null $request = null): Page
    {
        $pageRequest = $request instanceof PageRequest ? $request : $this->createPageRequest($request);
        $adapter = $this->adapter($target);
        $total = $adapter->count();
        $items = $pageRequest->getOffset() < $total ? $adapter->slice($pageRequest->getOffset(), $pageRequest->getLimit()) : [];

        return new Page($items, $total, $pageRequest);
    }

    public function createPageRequest(?Request $request = null, ?int $defaultLimit = null, ?int $maxLimit = null): PageRequest
    {
        $request ??= $this->request !== null ? ($this->request)() : null;
        $pageParameter = (string) $this->config['page_parameter'];
        $limitParameter = (string) $this->config['limit_parameter'];
        $maxLimit = max(1, $maxLimit ?? (int) $this->config['max_limit']);
        $defaultLimit = min($maxLimit, max(1, $defaultLimit ?? (int) $this->config['default_limit']));

        if (!$request instanceof Request) {
            return new PageRequest(1, $defaultLimit, '/', [], $pageParameter, $limitParameter);
        }

        $page = $this->integer($request->query->get($pageParameter), $pageParameter, 1);
        $limit = min($maxLimit, $this->integer($request->query->get($limitParameter), $limitParameter, $defaultLimit));
        $path = ($this->config['absolute_links'] ? $request->getSchemeAndHttpHost() : '') . $request->getBasePath() . $request->getPath();

        return new PageRequest($page, $limit, $path, $request->query->all(), $pageParameter, $limitParameter);
    }

    public function adapter(mixed $target): AdapterInterface
    {
        return match (true) {
            $target instanceof AdapterInterface => $target,
            is_array($target) => new ArrayAdapter($target),
            $target instanceof QueryBuilder => new QueryBuilderAdapter($target),
            is_iterable($target) => new IterableAdapter($target),
            default => throw new ApiException('Unable to paginate a value of type {type}: pass an array, an iterable, an ORM QueryBuilder or an {interface}.', 0, null, [
                'type' => get_debug_type($target),
                'interface' => AdapterInterface::class,
            ]),
        };
    }

    protected function integer(mixed $value, string $parameter, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $integer = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        if ($integer === false || $integer < 1) {
            throw new BadRequestHttpException('The query parameter "{parameter}" must be a positive integer.', ['parameter' => $parameter]);
        }

        return $integer;
    }
}