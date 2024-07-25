<?php

declare(strict_types=1);

/*
 * This file is part of rekalogika/rekapager package.
 *
 * (c) Priyadi Iman Nurcahyo <https://rekalogika.dev>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace Rekalogika\Rekapager\Doctrine\ORM;

use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\From;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Rekalogika\Contracts\Rekapager\Exception\LogicException;
use Rekalogika\Rekapager\Adapter\Common\IndexResolver;
use Rekalogika\Rekapager\Adapter\Common\KeysetExpressionCalculator;
use Rekalogika\Rekapager\Doctrine\ORM\Exception\UnsupportedQueryBuilderException;
use Rekalogika\Rekapager\Doctrine\ORM\Internal\KeysetQueryBuilderVisitor;
use Rekalogika\Rekapager\Doctrine\ORM\Internal\QueryBuilderKeysetItem;
use Rekalogika\Rekapager\Doctrine\ORM\Internal\QueryCounter;
use Rekalogika\Rekapager\Doctrine\ORM\Internal\QueryParameter;
use Rekalogika\Rekapager\Keyset\Contracts\BoundaryType;
use Rekalogika\Rekapager\Keyset\KeysetPaginationAdapterInterface;
use Rekalogika\Rekapager\Offset\OffsetPaginationAdapterInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

/**
 * @template TKey of array-key
 * @template T
 * @implements KeysetPaginationAdapterInterface<TKey,T>
 * @implements OffsetPaginationAdapterInterface<TKey,T>
 */
final class QueryBuilderAdapter implements KeysetPaginationAdapterInterface, OffsetPaginationAdapterInterface
{
    /**
     * @var Paginator<T>
     */
    private readonly Paginator $paginator;

    /**
     * @param array<string,ParameterType|ArrayParameterType|string|int> $typeMapping
     */
    public function __construct(
        private readonly QueryBuilder $queryBuilder,
        private readonly array $typeMapping = [],
        private readonly bool|null $useOutputWalkers = null,
        private readonly string|null $indexBy = null,
    ) {
        if ($queryBuilder->getFirstResult() !== 0 || $queryBuilder->getMaxResults() !== null) {
            throw new UnsupportedQueryBuilderException();
        }

        $this->paginator = new Paginator($queryBuilder, true);
    }

    /**
     * @return int<0,max>|null
     */
    #[\Override]
    public function countItems(): ?int
    {
        $result = $this->paginator->count();

        if ($result < 0) {
            return null;
        }

        return $result;
    }

    /**
     * @param null|array<string,mixed> $boundaryValues
     */
    private function getQueryBuilder(
        int $offset,
        int $limit,
        null|array $boundaryValues,
        BoundaryType $boundaryType,
    ): QueryBuilder {
        // wrap boundary values using QueryParameter

        $newBoundaryValues = [];

        /** @var mixed $value */
        foreach ($boundaryValues ?? [] as $property => $value) {
            $type = $this->getType($property, $value);
            $newBoundaryValues[$property] = new QueryParameter($value, $type);
        }

        $boundaryValues = $newBoundaryValues;

        // clone the query builder and set the limit and offset

        $queryBuilder = (clone $this->queryBuilder)
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        // if upper bound, reverse the sort order

        if ($boundaryType === BoundaryType::Upper) {
            $orderings = $this->getReversedSortOrder();

            $first = true;
            foreach ($orderings as $field => $direction) {
                if ($first) {
                    $queryBuilder->orderBy($field, $direction);
                    $first = false;
                } else {
                    $queryBuilder->addOrderBy($field, $direction);
                }
            }
        } else {
            $orderings = $this->getSortOrder();
        }

        // convert orderings to criteria orderings

        $orderings = $this->convertQueryBuilderOrderingToCriteriaOrdering($orderings);

        // calculate keyset expression

        $keysetExpression = KeysetExpressionCalculator::calculate($orderings, $boundaryValues);

        if ($keysetExpression !== null) {
            $visitor = new KeysetQueryBuilderVisitor();
            $queryBuilder->andWhere($visitor->dispatch($keysetExpression));

            foreach ($visitor->getParameters() as $template => $parameter) {
                $queryBuilder->setParameter(
                    $template,
                    $parameter->getValue(),
                    $parameter->getType()
                );
            }
        }

        // adds the boundary values to the select statement

        $i = 1;
        foreach ($this->getBoundaryFieldNames() as $field) {
            $queryBuilder->addSelect(sprintf('%s AS rekapager_boundary_%s', $field, $i));
            $i++;
        }

        return $queryBuilder;
    }

    /** @psalm-suppress InvalidReturnType */
    #[\Override]
    public function getKeysetItems(
        int $offset,
        int $limit,
        null|array $boundaryValues,
        BoundaryType $boundaryType,
    ): array {
        $queryBuilder = $this->getQueryBuilder($offset, $limit, $boundaryValues, $boundaryType);

        /** @var array<int,array<int,mixed>> */
        $result = $queryBuilder->getQuery()->getResult();

        if ($boundaryType === BoundaryType::Upper) {
            $result = array_reverse($result);
        }

        $boundaryFieldNames = $this->getBoundaryFieldNames();
        $results = [];

        foreach ($result as $key => $row) {
            /** @var array<string,mixed> */
            $boundaryValues = [];
            foreach (array_reverse($boundaryFieldNames) as $field) {
                /** @var mixed */
                $value = array_pop($row);
                /** @psalm-suppress MixedAssignment */
                $boundaryValues[$field] = $value;
            }

            if (\count($row) === 1) {
                /** @var mixed */
                $row = array_pop($row);
            }

            if ($this->indexBy !== null) {
                $key = IndexResolver::resolveIndex($row, $this->indexBy);
            }

            $results[] = new QueryBuilderKeysetItem($key, $row, $boundaryValues);
        }

        /**
         * @psalm-suppress InvalidReturnStatement
         * @phpstan-ignore-next-line
         */
        return $results;
    }

    #[\Override]
    public function countKeysetItems(
        int $offset,
        int $limit,
        null|array $boundaryValues,
        BoundaryType $boundaryType,
    ): int {
        $queryBuilder = $this->getQueryBuilder($offset, $limit, $boundaryValues, $boundaryType);
        $paginator = new QueryCounter($queryBuilder->getQuery(), $this->useOutputWalkers);

        $result = $paginator->count();

        if ($result < 0) {
            throw new \RuntimeException('Counting keyset items failed');
        }

        return $result;
    }

    /**
     * @var array<string,'ASC'|'DESC'>
     */
    private null|array $sortOrderCache = null;

    /**
     * @return array<string,'ASC'|'DESC'>
     */
    private function getSortOrder(): array
    {
        if ($this->sortOrderCache !== null) {
            return $this->sortOrderCache;
        }

        /** @var array<string,'ASC'|'DESC'> */
        $result = [];

        /** @var array<int,OrderBy> */
        $orderBys = $this->queryBuilder->getDQLPart('orderBy');

        foreach ($orderBys as $orderBy) {
            if (!$orderBy instanceof OrderBy) {
                continue;
            }

            foreach ($orderBy->getParts() as $part) {
                $exploded = explode(' ', $part);
                $field = $exploded[0];
                $direction = $exploded[1] ?? 'ASC';
                $direction = strtoupper($direction);

                if (!\in_array($direction, ['ASC', 'DESC'], true)) {
                    throw new LogicException('Invalid direction');
                }

                if (isset($result[$field])) {
                    throw new LogicException(sprintf('The field "%s" appears multiple times in the ORDER BY clause.', $field));
                }

                $result[$field] = $direction;
            }
        }

        if (\count($result) === 0) {
            throw new LogicException('The QueryBuilder does not have any ORDER BY clause.');
        }

        return $this->sortOrderCache = $result;
    }

    /**
     * @return array<int,string>
     */
    private function getBoundaryFieldNames(): array
    {
        return array_keys($this->getSortOrder());
    }

    /**
     * @return array<string,'ASC'|'DESC'>
     */
    private function getReversedSortOrder(): array
    {
        $result = [];

        foreach ($this->getSortOrder() as $field => $direction) {
            $result[$field] = $direction === 'ASC' ? 'DESC' : 'ASC';
        }

        return $result;
    }

    private function getType(
        string $fieldName,
        mixed $value
    ): ParameterType|ArrayParameterType|string|int|null {
        $type = $this->typeMapping[$fieldName] ?? null;

        // if type is defined in mapping, return it
        if ($type !== null) {
            return $type;
        }

        // if type is null and value is not object, just return as null
        if (!\is_object($value)) {
            return null;
        }

        // if it is an object, we start looking for the type in the class
        // metadata
        $type = $this->detectTypeFromMetadata($fieldName);

        if ($type !== null) {
            return $type;
        }

        // if not found, use heuristics to detect the type
        return $this->detectTypeByHeuristics($value);
    }

    private function detectTypeByHeuristics(object $value): string|null
    {
        if ($value instanceof \DateTime) {
            return Types::DATETIME_MUTABLE;
        } elseif ($value instanceof \DateTimeImmutable) {
            return Types::DATETIME_IMMUTABLE;
        } elseif ($value instanceof Uuid) {
            return UuidType::NAME;
        } elseif ($value instanceof Ulid) {
            return UlidType::NAME;
        }

        return null;
    }

    private function detectTypeFromMetadata(
        string $fieldName
    ): string|null {
        [$alias, $property] = explode('.', $fieldName);
        $class = $this->getClassFromAlias($alias);

        if ($class === null) {
            return null;
        }

        $manager = $this->queryBuilder->getEntityManager();
        $metadata = $manager->getClassMetadata($class);

        return $metadata->getTypeOfField($property);
    }

    /**
     * @return class-string|null
     */
    private function getClassFromAlias(string $alias): ?string
    {
        $dqlParts = $this->queryBuilder->getDQLParts();
        $from = $dqlParts['from'] ?? [];

        if (!\is_array($from)) {
            throw new LogicException('FROM clause is not an array');
        }

        foreach ($from as $fromItem) {
            if (!$fromItem instanceof From) {
                throw new LogicException('FROM clause is not an instance of From');
            }

            if ($fromItem->getAlias() === $alias) {
                return $fromItem->getFrom();
            }
        }

        return null;
    }

    #[\Override]
    public function getOffsetItems(int $offset, int $limit): array
    {
        /** @var \Traversable<TKey,T> */
        $iterator = $this->paginator
            ->getQuery()
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getResult();

        return iterator_to_array($iterator);
    }

    #[\Override]
    public function countOffsetItems(int $offset = 0, ?int $limit = null): int
    {
        if ($limit === null) {
            throw new \LogicException('Limit must be set when counting offset items');
        }

        $queryBuilder = $this->getQueryBuilder($offset, $limit, null, BoundaryType::Lower);
        $paginator = new QueryCounter($queryBuilder->getQuery(), $this->useOutputWalkers);

        $result = $paginator->count();

        if ($result < 0) {
            throw new \RuntimeException('Counting keyset items failed');
        }

        return $result;
    }

    /**
     * @param array<string, 'ASC'|'DESC'> $queryBuilderOrdering
     * @return array<string,Order>
     */
    private function convertQueryBuilderOrderingToCriteriaOrdering(
        array $queryBuilderOrdering
    ): array {
        return array_map(
            static fn (string $direction): Order => $direction === 'ASC' ? Order::Ascending : Order::Descending,
            $queryBuilderOrdering
        );
    }
}
