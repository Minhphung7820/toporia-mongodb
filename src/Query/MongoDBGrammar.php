<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Query;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;

/**
 * MongoDB Grammar
 *
 * Compiles query builder structures into MongoDB query syntax.
 * Unlike SQL grammars, this produces MongoDB query arrays/documents.
 *
 * MongoDB Query Structure:
 * - find() with filter, projection, sort, limit, skip
 * - insertOne/insertMany for INSERT
 * - updateOne/updateMany with $set, $unset for UPDATE
 * - deleteOne/deleteMany for DELETE
 * - aggregate() for aggregation pipelines
 *
 * Design Pattern: Adapter Pattern
 * - Adapts SQL-like QueryBuilder API to MongoDB query syntax
 *
 * SOLID Principles:
 * - Single Responsibility: Only compile queries to MongoDB syntax
 * - Open/Closed: Extensible for new MongoDB features
 *
 * Performance Optimizations:
 * - Query compilation caching
 * - Efficient array building
 * - Minimal memory allocation
 *
 * @package toporia/mongodb
 * @author Phungtruong7820 <minhphung485@gmail.com>
 * @since 1.0.0
 */
class MongoDBGrammar
{
    /**
     * Compilation cache to avoid recompiling identical queries.
     *
     * @var array<string, array>
     */
    private array $compilationCache = [];

    /**
     * MongoDB-specific features support map.
     *
     * @var array<string, bool>
     */
    protected array $features = [
        'window_functions' => false,
        'returning_clause' => true,
        'upsert' => true,
        'json_operators' => true,
        'cte' => false,
        'aggregation_pipeline' => true,
        'text_search' => true,
        'geospatial' => true,
        'change_streams' => true,
        'transactions' => true,
    ];

    /**
     * Compile a find query.
     *
     * @param string $collection Collection name
     * @param array<string, mixed> $wheres WHERE clauses
     * @param array<string> $columns Columns to select
     * @param array<array{column: string, direction: string}> $orders ORDER BY clauses
     * @param int|null $limit LIMIT value
     * @param int|null $offset OFFSET value
     * @return array<string, mixed> MongoDB find options
     */
    public function compileFind(
        string $collection,
        array $wheres,
        array $columns = [],
        array $orders = [],
        ?int $limit = null,
        ?int $offset = null
    ): array {
        $options = [];

        // Projection
        $projection = $this->compileProjection($columns);
        if (!empty($projection)) {
            $options['projection'] = $projection;
        }

        // Sort
        $sort = $this->compileSort($orders);
        if (!empty($sort)) {
            $options['sort'] = $sort;
        }

        // Limit
        if ($limit !== null) {
            $options['limit'] = $limit;
        }

        // Skip (offset)
        if ($offset !== null) {
            $options['skip'] = $offset;
        }

        return [
            'collection' => $collection,
            'filter' => $this->compileWheres($wheres),
            'options' => $options,
        ];
    }

    /**
     * Compile WHERE clauses to MongoDB filter.
     *
     * @param array<array<string, mixed>> $wheres WHERE clause array
     * @return array<string, mixed> MongoDB filter
     */
    public function compileWheres(array $wheres): array
    {
        if (empty($wheres)) {
            return [];
        }

        $filter = [];
        $andConditions = [];
        $orConditions = [];

        foreach ($wheres as $where) {
            $condition = $this->compileWhere($where);
            $boolean = strtoupper($where['boolean'] ?? 'AND');

            if ($boolean === 'OR') {
                $orConditions[] = $condition;
            } else {
                $andConditions[] = $condition;
            }
        }

        // Build filter
        if (!empty($andConditions)) {
            if (count($andConditions) === 1) {
                $filter = $andConditions[0];
            } else {
                $filter['$and'] = $andConditions;
            }
        }

        if (!empty($orConditions)) {
            if (empty($filter)) {
                if (count($orConditions) === 1) {
                    $filter = $orConditions[0];
                } else {
                    $filter['$or'] = $orConditions;
                }
            } else {
                // Combine AND and OR conditions
                $filter = [
                    '$and' => [
                        $filter,
                        ['$or' => $orConditions],
                    ],
                ];
            }
        }

        return $filter;
    }

    /**
     * Compile a single WHERE clause to MongoDB condition.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    public function compileWhere(array $where): array
    {
        $type = $where['type'] ?? 'basic';

        return match ($type) {
            'basic', 'Basic' => $this->compileBasicWhere($where),
            'in' => $this->compileWhereIn($where),
            'notIn' => $this->compileWhereNotIn($where),
            'Null' => $this->compileWhereNull($where),
            'NotNull' => $this->compileWhereNotNull($where),
            'between' => $this->compileWhereBetween($where),
            'notBetween' => $this->compileWhereNotBetween($where),
            'nested', 'Nested' => $this->compileNestedWhere($where),
            'Raw' => $this->compileRawWhere($where),
            'regex' => $this->compileWhereRegex($where),
            'exists' => $this->compileWhereFieldExists($where),
            'type' => $this->compileWhereType($where),
            'size' => $this->compileWhereSize($where),
            'all' => $this->compileWhereAll($where),
            'elemMatch' => $this->compileWhereElemMatch($where),
            'near' => $this->compileWhereNear($where),
            'geoWithin' => $this->compileWhereGeoWithin($where),
            default => throw new \InvalidArgumentException("Unknown WHERE type: {$type}"),
        };
    }

    /**
     * Compile basic WHERE (column operator value).
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileBasicWhere(array $where): array
    {
        $column = $where['column'];
        $operator = $where['operator'] ?? '=';
        $value = $where['value'] ?? null;

        // Convert _id string to ObjectId
        if ($column === '_id' && is_string($value) && strlen($value) === 24) {
            try {
                $value = new ObjectId($value);
            } catch (\Throwable) {
                // Keep as string if not valid ObjectId
            }
        }

        // Convert dates
        if ($value instanceof \DateTimeInterface) {
            $value = new UTCDateTime($value);
        }

        // Map SQL operators to MongoDB operators
        return match ($operator) {
            '=' => [$column => $value],
            '!=' , '<>' => [$column => ['$ne' => $value]],
            '>' => [$column => ['$gt' => $value]],
            '>=' => [$column => ['$gte' => $value]],
            '<' => [$column => ['$lt' => $value]],
            '<=' => [$column => ['$lte' => $value]],
            'like', 'LIKE' => $this->compileLikeWhere($column, $value),
            'not like', 'NOT LIKE' => $this->compileNotLikeWhere($column, $value),
            default => throw new \InvalidArgumentException("Unsupported operator: {$operator}"),
        };
    }

    /**
     * Compile LIKE WHERE to MongoDB regex.
     *
     * @param string $column Column name
     * @param mixed $value LIKE pattern
     * @return array<string, mixed> MongoDB regex condition
     */
    protected function compileLikeWhere(string $column, mixed $value): array
    {
        $pattern = $this->convertLikeToRegex((string) $value);
        return [$column => new Regex($pattern, 'i')];
    }

    /**
     * Compile NOT LIKE WHERE.
     *
     * @param string $column Column name
     * @param mixed $value LIKE pattern
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileNotLikeWhere(string $column, mixed $value): array
    {
        $pattern = $this->convertLikeToRegex((string) $value);
        return [$column => ['$not' => new Regex($pattern, 'i')]];
    }

    /**
     * Convert SQL LIKE pattern to MongoDB regex.
     *
     * @param string $pattern SQL LIKE pattern
     * @return string MongoDB regex pattern
     */
    protected function convertLikeToRegex(string $pattern): string
    {
        // Escape regex special characters except % and _
        $pattern = preg_quote($pattern, '/');

        // Convert SQL wildcards to regex
        $pattern = str_replace('%', '.*', $pattern);
        $pattern = str_replace('_', '.', $pattern);

        return '^' . $pattern . '$';
    }

    /**
     * Compile WHERE IN clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereIn(array $where): array
    {
        $column = $where['column'];
        $values = $where['values'] ?? [];

        // Convert ObjectId strings
        if ($column === '_id') {
            $values = array_map(function ($value) {
                if (is_string($value) && strlen($value) === 24) {
                    try {
                        return new ObjectId($value);
                    } catch (\Throwable) {
                        return $value;
                    }
                }
                return $value;
            }, $values);
        }

        return [$column => ['$in' => array_values($values)]];
    }

    /**
     * Compile WHERE NOT IN clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereNotIn(array $where): array
    {
        $column = $where['column'];
        $values = $where['values'] ?? [];

        return [$column => ['$nin' => array_values($values)]];
    }

    /**
     * Compile WHERE NULL clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereNull(array $where): array
    {
        return [$where['column'] => null];
    }

    /**
     * Compile WHERE NOT NULL clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereNotNull(array $where): array
    {
        return [$where['column'] => ['$ne' => null]];
    }

    /**
     * Compile WHERE BETWEEN clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereBetween(array $where): array
    {
        $column = $where['column'];
        $values = $where['values'] ?? [];

        return [
            $column => [
                '$gte' => $values[0] ?? null,
                '$lte' => $values[1] ?? null,
            ],
        ];
    }

    /**
     * Compile WHERE NOT BETWEEN clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereNotBetween(array $where): array
    {
        $column = $where['column'];
        $values = $where['values'] ?? [];

        return [
            '$or' => [
                [$column => ['$lt' => $values[0] ?? null]],
                [$column => ['$gt' => $values[1] ?? null]],
            ],
        ];
    }

    /**
     * Compile nested WHERE clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileNestedWhere(array $where): array
    {
        $nestedWheres = $where['wheres'] ?? [];
        return $this->compileWheres($nestedWheres);
    }

    /**
     * Compile raw WHERE clause (already MongoDB filter).
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileRawWhere(array $where): array
    {
        return $where['filter'] ?? [];
    }

    /**
     * Compile regex WHERE clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereRegex(array $where): array
    {
        $column = $where['column'];
        $pattern = $where['pattern'] ?? '';
        $flags = $where['flags'] ?? '';

        return [$column => new Regex($pattern, $flags)];
    }

    /**
     * Compile field exists WHERE clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereFieldExists(array $where): array
    {
        $column = $where['column'];
        $exists = $where['exists'] ?? true;

        return [$column => ['$exists' => $exists]];
    }

    /**
     * Compile type WHERE clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereType(array $where): array
    {
        $column = $where['column'];
        $type = $where['bsonType'] ?? '';

        return [$column => ['$type' => $type]];
    }

    /**
     * Compile size WHERE clause (for arrays).
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereSize(array $where): array
    {
        $column = $where['column'];
        $size = $where['size'] ?? 0;

        return [$column => ['$size' => $size]];
    }

    /**
     * Compile $all WHERE clause (array contains all values).
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereAll(array $where): array
    {
        $column = $where['column'];
        $values = $where['values'] ?? [];

        return [$column => ['$all' => array_values($values)]];
    }

    /**
     * Compile $elemMatch WHERE clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereElemMatch(array $where): array
    {
        $column = $where['column'];
        $conditions = $where['conditions'] ?? [];

        return [$column => ['$elemMatch' => $conditions]];
    }

    /**
     * Compile $near geospatial WHERE clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereNear(array $where): array
    {
        $column = $where['column'];
        $point = $where['point'] ?? [];
        $maxDistance = $where['maxDistance'] ?? null;
        $minDistance = $where['minDistance'] ?? null;

        $nearQuery = [
            '$geometry' => [
                'type' => 'Point',
                'coordinates' => $point,
            ],
        ];

        if ($maxDistance !== null) {
            $nearQuery['$maxDistance'] = $maxDistance;
        }

        if ($minDistance !== null) {
            $nearQuery['$minDistance'] = $minDistance;
        }

        return [$column => ['$near' => $nearQuery]];
    }

    /**
     * Compile $geoWithin WHERE clause.
     *
     * @param array<string, mixed> $where WHERE clause data
     * @return array<string, mixed> MongoDB condition
     */
    protected function compileWhereGeoWithin(array $where): array
    {
        $column = $where['column'];
        $geometry = $where['geometry'] ?? [];

        return [$column => ['$geoWithin' => ['$geometry' => $geometry]]];
    }

    /**
     * Compile SELECT columns to MongoDB projection.
     *
     * @param array<string> $columns Columns to select
     * @return array<string, int> MongoDB projection
     */
    public function compileProjection(array $columns): array
    {
        if (empty($columns) || $columns === ['*']) {
            return [];
        }

        $projection = [];

        foreach ($columns as $column) {
            // Handle exclusions (starts with -)
            if (str_starts_with($column, '-')) {
                $projection[substr($column, 1)] = 0;
            } else {
                $projection[$column] = 1;
            }
        }

        return $projection;
    }

    /**
     * Compile ORDER BY to MongoDB sort.
     *
     * @param array<array{column: string, direction: string}> $orders ORDER BY clauses
     * @return array<string, int> MongoDB sort
     */
    public function compileSort(array $orders): array
    {
        if (empty($orders)) {
            return [];
        }

        $sort = [];

        foreach ($orders as $order) {
            $column = $order['column'];
            $direction = strtoupper($order['direction'] ?? 'ASC');
            $sort[$column] = $direction === 'DESC' ? -1 : 1;
        }

        return $sort;
    }

    /**
     * Compile INSERT operation.
     *
     * @param array<string, mixed>|array<array<string, mixed>> $documents Documents to insert
     * @return array<string, mixed> Compiled insert data
     */
    public function compileInsert(array $documents): array
    {
        // Single document
        if (!isset($documents[0])) {
            return [
                'operation' => 'insertOne',
                'document' => $this->prepareDocument($documents),
            ];
        }

        // Multiple documents
        return [
            'operation' => 'insertMany',
            'documents' => array_map([$this, 'prepareDocument'], $documents),
        ];
    }

    /**
     * Compile UPDATE operation.
     *
     * @param array<string, mixed> $filter Filter criteria
     * @param array<string, mixed> $values Values to update
     * @param bool $multiple Update multiple documents
     * @param bool $upsert Insert if not exists
     * @return array<string, mixed> Compiled update data
     */
    public function compileUpdate(
        array $filter,
        array $values,
        bool $multiple = true,
        bool $upsert = false
    ): array {
        // Separate atomic operators from regular fields
        $setValues = [];
        $atomicOps = [];

        foreach ($values as $key => $value) {
            if (str_starts_with($key, '$')) {
                $atomicOps[$key] = $value;
            } else {
                $setValues[$key] = $value;
            }
        }

        // Build update document
        $update = $atomicOps;
        if (!empty($setValues)) {
            $update['$set'] = $this->prepareDocument($setValues);
        }

        return [
            'operation' => $multiple ? 'updateMany' : 'updateOne',
            'filter' => $filter,
            'update' => $update,
            'options' => [
                'upsert' => $upsert,
            ],
        ];
    }

    /**
     * Compile DELETE operation.
     *
     * @param array<string, mixed> $filter Filter criteria
     * @param bool $multiple Delete multiple documents
     * @return array<string, mixed> Compiled delete data
     */
    public function compileDelete(array $filter, bool $multiple = true): array
    {
        return [
            'operation' => $multiple ? 'deleteMany' : 'deleteOne',
            'filter' => $filter,
        ];
    }

    /**
     * Compile aggregate pipeline.
     *
     * @param array<array<string, mixed>> $pipeline Pipeline stages
     * @return array<string, mixed> Compiled aggregation
     */
    public function compileAggregate(array $pipeline): array
    {
        return [
            'operation' => 'aggregate',
            'pipeline' => $pipeline,
        ];
    }

    /**
     * Prepare a document for insertion/update.
     *
     * Converts PHP types to MongoDB BSON types.
     *
     * @param array<string, mixed> $document Document data
     * @return array<string, mixed> Prepared document
     */
    protected function prepareDocument(array $document): array
    {
        $prepared = [];

        foreach ($document as $key => $value) {
            $prepared[$key] = $this->prepareValue($value);
        }

        return $prepared;
    }

    /**
     * Prepare a value for MongoDB.
     *
     * @param mixed $value Value to prepare
     * @return mixed Prepared value
     */
    protected function prepareValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return new UTCDateTime($value);
        }

        if (is_array($value)) {
            return array_map([$this, 'prepareValue'], $value);
        }

        return $value;
    }

    /**
     * Check if a feature is supported.
     *
     * @param string $feature Feature name
     * @return bool True if supported
     */
    public function supportsFeature(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    /**
     * Clear compilation cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->compilationCache = [];
    }
}
