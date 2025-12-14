<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Query;

use Closure;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use MongoDB\Driver\Cursor;
use Toporia\MongoDB\Contracts\MongoDBConnectionInterface;

/**
 * MongoDB Query Builder
 *
 * Provides a fluent interface for building MongoDB queries.
 * Implements an API similar to SQL query builders for consistency.
 *
 * Features:
 * - Fluent query construction
 * - MongoDB-specific operators ($elemMatch, $near, $geoWithin, etc.)
 * - Aggregation pipeline support
 * - Type-safe operations
 * - Query logging
 *
 * Design Patterns:
 * - Builder Pattern: Fluent interface for query construction
 * - Strategy Pattern: Grammar handles compilation
 *
 * SOLID Principles:
 * - Single Responsibility: Query building only
 * - Open/Closed: Extendable via inheritance
 *
 * @package toporia/mongodb
 * @author Phungtruong7820 <minhphung485@gmail.com>
 * @since 1.0.0
 */
class MongoDBQueryBuilder
{
    /**
     * The MongoDB connection.
     */
    protected MongoDBConnectionInterface $connection;

    /**
     * The MongoDB grammar.
     */
    protected MongoDBGrammar $grammar;

    /**
     * The collection name.
     */
    protected string $collection;

    /**
     * The columns/fields to select.
     *
     * @var array<string>
     */
    protected array $columns = [];

    /**
     * The WHERE constraints.
     *
     * @var array<array<string, mixed>>
     */
    protected array $wheres = [];

    /**
     * The ORDER BY clauses.
     *
     * @var array<array{column: string, direction: string}>
     */
    protected array $orders = [];

    /**
     * The LIMIT value.
     */
    protected ?int $limit = null;

    /**
     * The OFFSET value.
     */
    protected ?int $offset = null;

    /**
     * The GROUP BY columns (for aggregation).
     *
     * @var array<string>
     */
    protected array $groups = [];

    /**
     * Whether to return distinct results.
     */
    protected bool $distinct = false;

    /**
     * The distinct field name.
     */
    protected ?string $distinctField = null;

    /**
     * Create a new query builder instance.
     *
     * @param MongoDBConnectionInterface $connection MongoDB connection
     * @param string $collection Collection name
     */
    public function __construct(MongoDBConnectionInterface $connection, string $collection)
    {
        $this->connection = $connection;
        $this->collection = $collection;
        $this->grammar = new MongoDBGrammar();
    }

    /**
     * Get the MongoDB collection.
     *
     * @return Collection MongoDB collection
     */
    public function getCollection(): Collection
    {
        return $this->connection->collection($this->collection);
    }

    /**
     * Get the connection.
     *
     * @return MongoDBConnectionInterface Connection instance
     */
    public function getConnection(): MongoDBConnectionInterface
    {
        return $this->connection;
    }

    // =========================================================================
    // SELECT METHODS
    // =========================================================================

    /**
     * Set the columns to select.
     *
     * @param string|array<string> ...$columns Column names
     * @return static
     */
    public function select(string|array ...$columns): static
    {
        $this->columns = [];

        foreach ($columns as $column) {
            if (is_array($column)) {
                $this->columns = array_merge($this->columns, $column);
            } else {
                $this->columns[] = $column;
            }
        }

        return $this;
    }

    /**
     * Add columns to select.
     *
     * @param string|array<string> ...$columns Column names
     * @return static
     */
    public function addSelect(string|array ...$columns): static
    {
        foreach ($columns as $column) {
            if (is_array($column)) {
                $this->columns = array_merge($this->columns, $column);
            } else {
                $this->columns[] = $column;
            }
        }

        return $this;
    }

    /**
     * Select distinct values.
     *
     * @param string $field Field to get distinct values for
     * @return static
     */
    public function distinct(string $field): static
    {
        $this->distinct = true;
        $this->distinctField = $field;

        return $this;
    }

    // =========================================================================
    // WHERE METHODS
    // =========================================================================

    /**
     * Add a WHERE clause.
     *
     * @param string|Closure $column Column name or nested closure
     * @param mixed $operator Operator or value
     * @param mixed $value Value (optional)
     * @param string $boolean AND or OR
     * @return static
     */
    public function where(
        string|Closure $column,
        mixed $operator = null,
        mixed $value = null,
        string $boolean = 'AND'
    ): static {
        // Handle closure for nested where
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        // Handle two arguments (column = value)
        if ($value === null && $operator !== null && !$this->isOperator($operator)) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add an OR WHERE clause.
     *
     * @param string|Closure $column Column name or closure
     * @param mixed $operator Operator or value
     * @param mixed $value Value
     * @return static
     */
    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * Add a WHERE IN clause.
     *
     * @param string $column Column name
     * @param array<mixed> $values Values to match
     * @param string $boolean AND or OR
     * @param bool $not Whether to negate
     * @return static
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => $not ? 'notIn' : 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT IN clause.
     *
     * @param string $column Column name
     * @param array<mixed> $values Values
     * @return static
     */
    public function whereNotIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, 'AND', true);
    }

    /**
     * Add a WHERE NULL clause.
     *
     * @param string $column Column name
     * @param string $boolean AND or OR
     * @param bool $not Whether to negate
     * @return static
     */
    public function whereNull(string $column, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => $not ? 'NotNull' : 'Null',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT NULL clause.
     *
     * @param string $column Column name
     * @return static
     */
    public function whereNotNull(string $column): static
    {
        return $this->whereNull($column, 'AND', true);
    }

    /**
     * Add a WHERE BETWEEN clause.
     *
     * @param string $column Column name
     * @param mixed $min Minimum value
     * @param mixed $max Maximum value
     * @param string $boolean AND or OR
     * @param bool $not Whether to negate
     * @return static
     */
    public function whereBetween(
        string $column,
        mixed $min,
        mixed $max,
        string $boolean = 'AND',
        bool $not = false
    ): static {
        $this->wheres[] = [
            'type' => $not ? 'notBetween' : 'between',
            'column' => $column,
            'values' => [$min, $max],
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT BETWEEN clause.
     *
     * @param string $column Column name
     * @param mixed $min Minimum value
     * @param mixed $max Maximum value
     * @return static
     */
    public function whereNotBetween(string $column, mixed $min, mixed $max): static
    {
        return $this->whereBetween($column, $min, $max, 'AND', true);
    }

    /**
     * Add a nested WHERE clause.
     *
     * @param Closure $callback Callback to build nested query
     * @param string $boolean AND or OR
     * @return static
     */
    public function whereNested(Closure $callback, string $boolean = 'AND'): static
    {
        $nestedBuilder = new static($this->connection, $this->collection);
        $callback($nestedBuilder);

        if (!empty($nestedBuilder->wheres)) {
            $this->wheres[] = [
                'type' => 'nested',
                'wheres' => $nestedBuilder->wheres,
                'boolean' => $boolean,
            ];
        }

        return $this;
    }

    /**
     * Add a raw MongoDB filter.
     *
     * @param array<string, mixed> $filter MongoDB filter document
     * @param string $boolean AND or OR
     * @return static
     */
    public function whereRaw(array $filter, string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'Raw',
            'filter' => $filter,
            'boolean' => $boolean,
        ];

        return $this;
    }

    // =========================================================================
    // MONGODB-SPECIFIC WHERE METHODS
    // =========================================================================

    /**
     * Add a regex WHERE clause.
     *
     * @param string $column Column name
     * @param string $pattern Regex pattern
     * @param string $flags Regex flags (i, m, s, x)
     * @return static
     */
    public function whereRegex(string $column, string $pattern, string $flags = ''): static
    {
        $this->wheres[] = [
            'type' => 'regex',
            'column' => $column,
            'pattern' => $pattern,
            'flags' => $flags,
            'boolean' => 'AND',
        ];

        return $this;
    }

    /**
     * Add a field exists WHERE clause.
     *
     * @param string $column Column name
     * @param bool $exists Whether field should exist
     * @return static
     */
    public function whereExists(string $column, bool $exists = true): static
    {
        $this->wheres[] = [
            'type' => 'exists',
            'column' => $column,
            'exists' => $exists,
            'boolean' => 'AND',
        ];

        return $this;
    }

    /**
     * Add a type WHERE clause.
     *
     * @param string $column Column name
     * @param string|int $type BSON type
     * @return static
     */
    public function whereType(string $column, string|int $type): static
    {
        $this->wheres[] = [
            'type' => 'type',
            'column' => $column,
            'bsonType' => $type,
            'boolean' => 'AND',
        ];

        return $this;
    }

    /**
     * Add an array size WHERE clause.
     *
     * @param string $column Column name
     * @param int $size Array size
     * @return static
     */
    public function whereSize(string $column, int $size): static
    {
        $this->wheres[] = [
            'type' => 'size',
            'column' => $column,
            'size' => $size,
            'boolean' => 'AND',
        ];

        return $this;
    }

    /**
     * Add an $all WHERE clause (array contains all values).
     *
     * @param string $column Column name
     * @param array<mixed> $values Values that must all be present
     * @return static
     */
    public function whereAll(string $column, array $values): static
    {
        $this->wheres[] = [
            'type' => 'all',
            'column' => $column,
            'values' => $values,
            'boolean' => 'AND',
        ];

        return $this;
    }

    /**
     * Add an $elemMatch WHERE clause.
     *
     * @param string $column Array column name
     * @param array<string, mixed>|Closure $conditions Conditions for array elements
     * @return static
     */
    public function whereElemMatch(string $column, array|Closure $conditions): static
    {
        if ($conditions instanceof Closure) {
            $nestedBuilder = new static($this->connection, $this->collection);
            $conditions($nestedBuilder);
            $conditions = $this->grammar->compileWheres($nestedBuilder->wheres);
        }

        $this->wheres[] = [
            'type' => 'elemMatch',
            'column' => $column,
            'conditions' => $conditions,
            'boolean' => 'AND',
        ];

        return $this;
    }

    /**
     * Add a $near geospatial WHERE clause.
     *
     * @param string $column Column with geospatial index
     * @param array{float, float} $point [longitude, latitude]
     * @param int|null $maxDistance Maximum distance in meters
     * @param int|null $minDistance Minimum distance in meters
     * @return static
     */
    public function whereNear(
        string $column,
        array $point,
        ?int $maxDistance = null,
        ?int $minDistance = null
    ): static {
        $this->wheres[] = [
            'type' => 'near',
            'column' => $column,
            'point' => $point,
            'maxDistance' => $maxDistance,
            'minDistance' => $minDistance,
            'boolean' => 'AND',
        ];

        return $this;
    }

    /**
     * Add a $geoWithin WHERE clause.
     *
     * @param string $column Column with geospatial index
     * @param array<string, mixed> $geometry GeoJSON geometry
     * @return static
     */
    public function whereGeoWithin(string $column, array $geometry): static
    {
        $this->wheres[] = [
            'type' => 'geoWithin',
            'column' => $column,
            'geometry' => $geometry,
            'boolean' => 'AND',
        ];

        return $this;
    }

    // =========================================================================
    // ORDERING & LIMITING
    // =========================================================================

    /**
     * Add an ORDER BY clause.
     *
     * @param string $column Column name
     * @param string $direction Sort direction (asc/desc)
     * @return static
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtolower($direction),
        ];

        return $this;
    }

    /**
     * Order by descending.
     *
     * @param string $column Column name
     * @return static
     */
    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Order by latest (created_at desc).
     *
     * @param string $column Column name
     * @return static
     */
    public function latest(string $column = 'created_at'): static
    {
        return $this->orderByDesc($column);
    }

    /**
     * Order by oldest (created_at asc).
     *
     * @param string $column Column name
     * @return static
     */
    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * Set the LIMIT value.
     *
     * @param int $value Limit value
     * @return static
     */
    public function limit(int $value): static
    {
        $this->limit = $value;

        return $this;
    }

    /**
     * Alias for limit().
     *
     * @param int $value Limit value
     * @return static
     */
    public function take(int $value): static
    {
        return $this->limit($value);
    }

    /**
     * Set the OFFSET value.
     *
     * @param int $value Offset value
     * @return static
     */
    public function offset(int $value): static
    {
        $this->offset = $value;

        return $this;
    }

    /**
     * Alias for offset().
     *
     * @param int $value Skip value
     * @return static
     */
    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    /**
     * Set offset and limit for pagination.
     *
     * @param int $page Page number (1-indexed)
     * @param int $perPage Items per page
     * @return static
     */
    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    // =========================================================================
    // EXECUTION METHODS
    // =========================================================================

    /**
     * Execute the query and get results.
     *
     * @return array<array<string, mixed>> Query results
     */
    public function get(): array
    {
        // Handle distinct
        if ($this->distinct && $this->distinctField) {
            return $this->getDistinct();
        }

        $compiled = $this->grammar->compileFind(
            $this->collection,
            $this->wheres,
            $this->columns,
            $this->orders,
            $this->limit,
            $this->offset
        );

        $cursor = $this->getCollection()->find($compiled['filter'], $compiled['options']);

        return $cursor->toArray();
    }

    /**
     * Get distinct values.
     *
     * @return array<mixed> Distinct values
     */
    protected function getDistinct(): array
    {
        $filter = $this->grammar->compileWheres($this->wheres);

        return $this->getCollection()->distinct($this->distinctField, $filter);
    }

    /**
     * Get the first result.
     *
     * @return array<string, mixed>|null First result or null
     */
    public function first(): ?array
    {
        $results = $this->limit(1)->get();

        return $results[0] ?? null;
    }

    /**
     * Find a document by ID.
     *
     * @param string|ObjectId $id Document ID
     * @return array<string, mixed>|null Document or null
     */
    public function find(string|ObjectId $id): ?array
    {
        if (is_string($id)) {
            try {
                $id = new ObjectId($id);
            } catch (\Throwable) {
                // Keep as string
            }
        }

        return $this->where('_id', $id)->first();
    }

    /**
     * Get values of a single column.
     *
     * @param string $column Column name
     * @param string|null $key Key column for associative array
     * @return array<mixed> Column values
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $results = $this->get();
        $plucked = [];

        foreach ($results as $row) {
            $value = $row[$column] ?? null;

            if ($key !== null && isset($row[$key])) {
                $plucked[$row[$key]] = $value;
            } else {
                $plucked[] = $value;
            }
        }

        return $plucked;
    }

    /**
     * Get the count of documents.
     *
     * @return int Document count
     */
    public function count(): int
    {
        $filter = $this->grammar->compileWheres($this->wheres);

        return $this->getCollection()->countDocuments($filter);
    }

    /**
     * Check if any documents exist.
     *
     * @return bool True if documents exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Check if no documents exist.
     *
     * @return bool True if no documents exist
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Get the maximum value of a column.
     *
     * @param string $column Column name
     * @return mixed Maximum value
     */
    public function max(string $column): mixed
    {
        $result = $this->aggregate()
            ->match($this->grammar->compileWheres($this->wheres))
            ->group(null, ['max' => ['$max' => '$' . $column]])
            ->get();

        return $result[0]['max'] ?? null;
    }

    /**
     * Get the minimum value of a column.
     *
     * @param string $column Column name
     * @return mixed Minimum value
     */
    public function min(string $column): mixed
    {
        $result = $this->aggregate()
            ->match($this->grammar->compileWheres($this->wheres))
            ->group(null, ['min' => ['$min' => '$' . $column]])
            ->get();

        return $result[0]['min'] ?? null;
    }

    /**
     * Get the sum of a column.
     *
     * @param string $column Column name
     * @return int|float Sum value
     */
    public function sum(string $column): int|float
    {
        $result = $this->aggregate()
            ->match($this->grammar->compileWheres($this->wheres))
            ->group(null, ['sum' => ['$sum' => '$' . $column]])
            ->get();

        return $result[0]['sum'] ?? 0;
    }

    /**
     * Get the average of a column.
     *
     * @param string $column Column name
     * @return float|null Average value
     */
    public function avg(string $column): ?float
    {
        $result = $this->aggregate()
            ->match($this->grammar->compileWheres($this->wheres))
            ->group(null, ['avg' => ['$avg' => '$' . $column]])
            ->get();

        return $result[0]['avg'] ?? null;
    }

    // =========================================================================
    // WRITE OPERATIONS
    // =========================================================================

    /**
     * Insert a document.
     *
     * @param array<string, mixed> $values Document values
     * @return bool True on success
     */
    public function insert(array $values): bool
    {
        $result = $this->getCollection()->insertOne($values);

        return $result->isAcknowledged();
    }

    /**
     * Insert a document and get the ID.
     *
     * @param array<string, mixed> $values Document values
     * @return ObjectId|null Inserted ID
     */
    public function insertGetId(array $values): ?ObjectId
    {
        $result = $this->getCollection()->insertOne($values);

        return $result->isAcknowledged() ? $result->getInsertedId() : null;
    }

    /**
     * Insert multiple documents.
     *
     * @param array<array<string, mixed>> $documents Documents to insert
     * @return bool True on success
     */
    public function insertMany(array $documents): bool
    {
        $result = $this->getCollection()->insertMany($documents);

        return $result->isAcknowledged();
    }

    /**
     * Update documents matching the query.
     *
     * @param array<string, mixed> $values Values to update
     * @return int Number of modified documents
     */
    public function update(array $values): int
    {
        $filter = $this->grammar->compileWheres($this->wheres);
        $compiled = $this->grammar->compileUpdate($filter, $values);

        $result = $this->getCollection()->updateMany(
            $compiled['filter'],
            $compiled['update'],
            $compiled['options']
        );

        return $result->getModifiedCount();
    }

    /**
     * Update or insert a document.
     *
     * @param array<string, mixed> $values Values to set
     * @param array<string> $uniqueBy Unique fields for matching
     * @return int Number of affected documents
     */
    public function upsert(array $values, array $uniqueBy): int
    {
        $filter = [];
        foreach ($uniqueBy as $field) {
            if (isset($values[$field])) {
                $filter[$field] = $values[$field];
            }
        }

        $result = $this->getCollection()->updateOne(
            $filter,
            ['$set' => $values],
            ['upsert' => true]
        );

        return $result->getModifiedCount() + $result->getUpsertedCount();
    }

    /**
     * Increment a field value.
     *
     * @param string $column Column name
     * @param int|float $amount Amount to increment
     * @param array<string, mixed> $extra Extra values to update
     * @return int Number of modified documents
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $filter = $this->grammar->compileWheres($this->wheres);

        $update = ['$inc' => [$column => $amount]];

        if (!empty($extra)) {
            $update['$set'] = $extra;
        }

        $result = $this->getCollection()->updateMany($filter, $update);

        return $result->getModifiedCount();
    }

    /**
     * Decrement a field value.
     *
     * @param string $column Column name
     * @param int|float $amount Amount to decrement
     * @param array<string, mixed> $extra Extra values to update
     * @return int Number of modified documents
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    /**
     * Delete documents matching the query.
     *
     * @return int Number of deleted documents
     */
    public function delete(): int
    {
        $filter = $this->grammar->compileWheres($this->wheres);

        $result = $this->getCollection()->deleteMany($filter);

        return $result->getDeletedCount();
    }

    /**
     * Truncate the collection (delete all documents).
     *
     * @return bool True on success
     */
    public function truncate(): bool
    {
        $result = $this->getCollection()->deleteMany([]);

        return $result->isAcknowledged();
    }

    // =========================================================================
    // AGGREGATION
    // =========================================================================

    /**
     * Create an aggregation pipeline builder.
     *
     * @return AggregationPipeline Pipeline builder
     */
    public function aggregate(): AggregationPipeline
    {
        return new AggregationPipeline($this->getCollection());
    }

    /**
     * Execute a raw aggregation pipeline.
     *
     * @param array<array<string, mixed>> $pipeline Pipeline stages
     * @return Cursor Query cursor
     */
    public function raw(array $pipeline): Cursor
    {
        return $this->getCollection()->aggregate($pipeline);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Check if a value is a valid operator.
     *
     * @param mixed $value Value to check
     * @return bool True if operator
     */
    protected function isOperator(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower($value), [
            '=', '<', '>', '<=', '>=', '!=', '<>',
            'like', 'not like', 'in', 'not in',
        ], true);
    }

    /**
     * Get the current wheres array.
     *
     * @return array<array<string, mixed>> WHERE clauses
     */
    public function getWheres(): array
    {
        return $this->wheres;
    }

    /**
     * Get the current orders array.
     *
     * @return array<array{column: string, direction: string}> ORDER BY clauses
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    /**
     * Get the limit value.
     *
     * @return int|null Limit value
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Get the offset value.
     *
     * @return int|null Offset value
     */
    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * Clone the query builder.
     *
     * @return static Cloned instance
     */
    public function clone(): static
    {
        return clone $this;
    }
}
