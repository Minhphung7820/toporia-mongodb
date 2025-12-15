<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Query;

use MongoDB\Collection;
use MongoDB\Driver\Cursor;
use InvalidArgumentException;
use Closure;
use Toporia\MongoDB\Contracts\MongoDBConnectionInterface;

/**
 * Fluent builder for MongoDB aggregation pipelines.
 *
 * Provides a clean, chainable API for building complex aggregation
 * pipelines with all standard MongoDB aggregation stages.
 *
 * @example
 * ```php
 * $pipeline = new AggregationPipeline($collection);
 * $results = $pipeline
 *     ->match(['status' => 'active'])
 *     ->group('$category', ['total' => ['$sum' => '$amount']])
 *     ->sort(['total' => -1])
 *     ->limit(10)
 *     ->get();
 * ```
 */
class AggregationPipeline
{
    /**
     * The MongoDB collection instance.
     */
    protected Collection $collection;

    /**
     * The MongoDB connection instance.
     */
    protected MongoDBConnectionInterface $connection;

    /**
     * The aggregation pipeline stages.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $pipeline = [];

    /**
     * Aggregation options.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * Create a new aggregation pipeline instance.
     */
    public function __construct(Collection $collection, MongoDBConnectionInterface $connection)
    {
        $this->collection = $collection;
        $this->connection = $connection;
    }

    /**
     * Add a $match stage to filter documents.
     *
     * @param array<string, mixed>|Closure $conditions
     * @return static
     *
     * @example
     * ```php
     * $pipeline->match(['status' => 'active', 'age' => ['$gte' => 18]]);
     * ```
     */
    public function match(array|Closure $conditions): static
    {
        if ($conditions instanceof Closure) {
            $collectionName = $this->collection->getCollectionName();
            $builder = new MongoDBQueryBuilder($this->connection, $collectionName);
            $conditions($builder);
            $conditions = $builder->compileWheres();
        }

        $this->pipeline[] = ['$match' => $conditions];

        return $this;
    }

    /**
     * Add a $group stage for aggregation.
     *
     * @param string|array<string, mixed>|null $id Group by field(s) or null for all
     * @param array<string, array<string, mixed>> $accumulators Accumulator expressions
     * @return static
     *
     * @example
     * ```php
     * // Group by single field
     * $pipeline->group('$category', [
     *     'total' => ['$sum' => '$amount'],
     *     'count' => ['$sum' => 1],
     *     'avg' => ['$avg' => '$price']
     * ]);
     *
     * // Group by multiple fields
     * $pipeline->group(['year' => '$year', 'month' => '$month'], [
     *     'total' => ['$sum' => '$amount']
     * ]);
     *
     * // Group all documents (null _id)
     * $pipeline->group(null, ['grandTotal' => ['$sum' => '$amount']]);
     * ```
     */
    public function group(string|array|null $id, array $accumulators = []): static
    {
        $groupStage = ['_id' => $id];

        foreach ($accumulators as $field => $accumulator) {
            $groupStage[$field] = $accumulator;
        }

        $this->pipeline[] = ['$group' => $groupStage];

        return $this;
    }

    /**
     * Add a $project stage to reshape documents.
     *
     * @param array<string, mixed> $fields Field specifications
     * @return static
     *
     * @example
     * ```php
     * $pipeline->project([
     *     'name' => 1,
     *     'total' => ['$multiply' => ['$price', '$quantity']],
     *     '_id' => 0
     * ]);
     * ```
     */
    public function project(array $fields): static
    {
        $this->pipeline[] = ['$project' => $fields];

        return $this;
    }

    /**
     * Add a $sort stage to order documents.
     *
     * @param array<string, int> $fields Field => direction (1 for asc, -1 for desc)
     * @return static
     *
     * @example
     * ```php
     * $pipeline->sort(['createdAt' => -1, 'name' => 1]);
     * ```
     */
    public function sort(array $fields): static
    {
        $this->pipeline[] = ['$sort' => $fields];

        return $this;
    }

    /**
     * Add a $limit stage to limit results.
     *
     * @param int $value Maximum number of documents
     * @return static
     */
    public function limit(int $value): static
    {
        $this->pipeline[] = ['$limit' => $value];

        return $this;
    }

    /**
     * Add a $skip stage to skip documents.
     *
     * @param int $value Number of documents to skip
     * @return static
     */
    public function skip(int $value): static
    {
        $this->pipeline[] = ['$skip' => $value];

        return $this;
    }

    /**
     * Add a $unwind stage to deconstruct an array field.
     *
     * @param string $path Array field path (with $ prefix)
     * @param bool $preserveNullAndEmpty Keep documents with null/empty arrays
     * @param string|null $includeArrayIndex Field name to store array index
     * @return static
     *
     * @example
     * ```php
     * $pipeline->unwind('$items');
     * $pipeline->unwind('$tags', true, 'tagIndex');
     * ```
     */
    public function unwind(
        string $path,
        bool $preserveNullAndEmpty = false,
        ?string $includeArrayIndex = null
    ): static {
        $stage = ['path' => $path];

        if ($preserveNullAndEmpty) {
            $stage['preserveNullAndEmptyArrays'] = true;
        }

        if ($includeArrayIndex !== null) {
            $stage['includeArrayIndex'] = $includeArrayIndex;
        }

        // Use simple form if no options
        if (!$preserveNullAndEmpty && $includeArrayIndex === null) {
            $this->pipeline[] = ['$unwind' => $path];
        } else {
            $this->pipeline[] = ['$unwind' => $stage];
        }

        return $this;
    }

    /**
     * Add a $lookup stage to perform a left outer join.
     *
     * @param string $from Foreign collection name
     * @param string $localField Local field to match
     * @param string $foreignField Foreign field to match
     * @param string $as Output array field name
     * @return static
     *
     * @example
     * ```php
     * $pipeline->lookup('users', 'userId', '_id', 'user');
     * ```
     */
    public function lookup(
        string $from,
        string $localField,
        string $foreignField,
        string $as
    ): static {
        $this->pipeline[] = [
            '$lookup' => [
                'from' => $from,
                'localField' => $localField,
                'foreignField' => $foreignField,
                'as' => $as,
            ],
        ];

        return $this;
    }

    /**
     * Add a $lookup stage with pipeline (uncorrelated or correlated).
     *
     * @param string $from Foreign collection name
     * @param array<int, array<string, mixed>> $pipeline Sub-pipeline to run
     * @param string $as Output array field name
     * @param array<string, mixed> $let Variables to pass to pipeline
     * @return static
     *
     * @example
     * ```php
     * $pipeline->lookupPipeline('orders', [
     *     ['$match' => ['$expr' => ['$eq' => ['$userId', '$$userId']]]],
     *     ['$sort' => ['createdAt' => -1]],
     *     ['$limit' => 5]
     * ], 'recentOrders', ['userId' => '$_id']);
     * ```
     */
    public function lookupPipeline(
        string $from,
        array $pipeline,
        string $as,
        array $let = []
    ): static {
        $lookup = [
            'from' => $from,
            'pipeline' => $pipeline,
            'as' => $as,
        ];

        if (!empty($let)) {
            $lookup['let'] = $let;
        }

        $this->pipeline[] = ['$lookup' => $lookup];

        return $this;
    }

    /**
     * Add a $addFields stage to add new fields.
     *
     * @param array<string, mixed> $fields Fields to add
     * @return static
     *
     * @example
     * ```php
     * $pipeline->addFields([
     *     'fullName' => ['$concat' => ['$firstName', ' ', '$lastName']],
     *     'totalPrice' => ['$multiply' => ['$price', '$quantity']]
     * ]);
     * ```
     */
    public function addFields(array $fields): static
    {
        $this->pipeline[] = ['$addFields' => $fields];

        return $this;
    }

    /**
     * Add a $set stage (alias for $addFields).
     *
     * @param array<string, mixed> $fields Fields to set
     * @return static
     */
    public function set(array $fields): static
    {
        $this->pipeline[] = ['$set' => $fields];

        return $this;
    }

    /**
     * Add a $unset stage to remove fields.
     *
     * @param string|array<int, string> $fields Fields to remove
     * @return static
     *
     * @example
     * ```php
     * $pipeline->unset(['password', 'internalNotes']);
     * ```
     */
    public function unset(string|array $fields): static
    {
        $this->pipeline[] = ['$unset' => is_string($fields) ? [$fields] : $fields];

        return $this;
    }

    /**
     * Add a $replaceRoot stage to replace the root document.
     *
     * @param string|array<string, mixed> $newRoot New root expression
     * @return static
     *
     * @example
     * ```php
     * $pipeline->replaceRoot('$embedded');
     * $pipeline->replaceRoot(['$mergeObjects' => ['$defaults', '$doc']]);
     * ```
     */
    public function replaceRoot(string|array $newRoot): static
    {
        $this->pipeline[] = [
            '$replaceRoot' => [
                'newRoot' => $newRoot,
            ],
        ];

        return $this;
    }

    /**
     * Add a $replaceWith stage (alias for $replaceRoot).
     *
     * @param string|array<string, mixed> $replacement Replacement expression
     * @return static
     */
    public function replaceWith(string|array $replacement): static
    {
        $this->pipeline[] = ['$replaceWith' => $replacement];

        return $this;
    }

    /**
     * Add a $facet stage for multi-faceted aggregation.
     *
     * @param array<string, array<int, array<string, mixed>>> $facets Facet pipelines
     * @return static
     *
     * @example
     * ```php
     * $pipeline->facet([
     *     'byCategory' => [
     *         ['$group' => ['_id' => '$category', 'count' => ['$sum' => 1]]]
     *     ],
     *     'byStatus' => [
     *         ['$group' => ['_id' => '$status', 'count' => ['$sum' => 1]]]
     *     ],
     *     'total' => [
     *         ['$count' => 'count']
     *     ]
     * ]);
     * ```
     */
    public function facet(array $facets): static
    {
        $this->pipeline[] = ['$facet' => $facets];

        return $this;
    }

    /**
     * Add a $bucket stage for bucket categorization.
     *
     * @param string $groupBy Field to bucket by
     * @param array<int, mixed> $boundaries Bucket boundaries
     * @param array<string, mixed> $output Output field specifications
     * @param mixed $default Default bucket for out-of-range values
     * @return static
     *
     * @example
     * ```php
     * $pipeline->bucket('$price', [0, 100, 500, 1000], [
     *     'count' => ['$sum' => 1],
     *     'avgPrice' => ['$avg' => '$price']
     * ], 'Other');
     * ```
     */
    public function bucket(
        string $groupBy,
        array $boundaries,
        array $output = [],
        mixed $default = null
    ): static {
        $bucket = [
            'groupBy' => $groupBy,
            'boundaries' => $boundaries,
        ];

        if (!empty($output)) {
            $bucket['output'] = $output;
        }

        if ($default !== null) {
            $bucket['default'] = $default;
        }

        $this->pipeline[] = ['$bucket' => $bucket];

        return $this;
    }

    /**
     * Add a $bucketAuto stage for automatic bucket distribution.
     *
     * @param string $groupBy Field to bucket by
     * @param int $buckets Number of buckets
     * @param array<string, mixed> $output Output field specifications
     * @param string|null $granularity Preferred number granularity
     * @return static
     *
     * @example
     * ```php
     * $pipeline->bucketAuto('$price', 5, [
     *     'count' => ['$sum' => 1]
     * ], 'R5');
     * ```
     */
    public function bucketAuto(
        string $groupBy,
        int $buckets,
        array $output = [],
        ?string $granularity = null
    ): static {
        $bucketAuto = [
            'groupBy' => $groupBy,
            'buckets' => $buckets,
        ];

        if (!empty($output)) {
            $bucketAuto['output'] = $output;
        }

        if ($granularity !== null) {
            $bucketAuto['granularity'] = $granularity;
        }

        $this->pipeline[] = ['$bucketAuto' => $bucketAuto];

        return $this;
    }

    /**
     * Add a $graphLookup stage for recursive graph traversal.
     *
     * @param array<string, mixed> $options Graph lookup options
     * @return static
     *
     * @example
     * ```php
     * $pipeline->graphLookup([
     *     'from' => 'employees',
     *     'startWith' => '$reportsTo',
     *     'connectFromField' => 'reportsTo',
     *     'connectToField' => '_id',
     *     'as' => 'reportingHierarchy',
     *     'maxDepth' => 5,
     *     'depthField' => 'level'
     * ]);
     * ```
     */
    public function graphLookup(array $options): static
    {
        $required = ['from', 'startWith', 'connectFromField', 'connectToField', 'as'];

        foreach ($required as $field) {
            if (!isset($options[$field])) {
                throw new InvalidArgumentException("Missing required graphLookup option: {$field}");
            }
        }

        $this->pipeline[] = ['$graphLookup' => $options];

        return $this;
    }

    /**
     * Add a $sample stage to randomly select documents.
     *
     * @param int $size Number of documents to sample
     * @return static
     */
    public function sample(int $size): static
    {
        $this->pipeline[] = ['$sample' => ['size' => $size]];

        return $this;
    }

    /**
     * Add a $count stage to count documents.
     *
     * @param string $field Output field name for the count
     * @return static
     */
    public function count(string $field = 'count'): static
    {
        $this->pipeline[] = ['$count' => $field];

        return $this;
    }

    /**
     * Add a $sortByCount stage to group and count by field value.
     *
     * @param string $expression Field or expression to count
     * @return static
     *
     * @example
     * ```php
     * $pipeline->sortByCount('$category');
     * ```
     */
    public function sortByCount(string $expression): static
    {
        $this->pipeline[] = ['$sortByCount' => $expression];

        return $this;
    }

    /**
     * Add a $merge stage to output results to a collection.
     *
     * @param string $into Target collection name
     * @param array<string, mixed> $options Merge options
     * @return static
     *
     * @example
     * ```php
     * $pipeline->merge('summary', [
     *     'on' => '_id',
     *     'whenMatched' => 'replace',
     *     'whenNotMatched' => 'insert'
     * ]);
     * ```
     */
    public function merge(string $into, array $options = []): static
    {
        $merge = ['into' => $into];

        if (!empty($options)) {
            $merge = array_merge($merge, $options);
        }

        $this->pipeline[] = ['$merge' => $merge];

        return $this;
    }

    /**
     * Add a $out stage to write results to a collection.
     *
     * @param string $collection Target collection name
     * @return static
     */
    public function out(string $collection): static
    {
        $this->pipeline[] = ['$out' => $collection];

        return $this;
    }

    /**
     * Add a $redact stage for document-level access control.
     *
     * @param array<string, mixed> $expression Redact expression
     * @return static
     *
     * @example
     * ```php
     * $pipeline->redact([
     *     '$cond' => [
     *         'if' => ['$eq' => ['$level', 'public']],
     *         'then' => '$$DESCEND',
     *         'else' => '$$PRUNE'
     *     ]
     * ]);
     * ```
     */
    public function redact(array $expression): static
    {
        $this->pipeline[] = ['$redact' => $expression];

        return $this;
    }

    /**
     * Add a $geoNear stage for geospatial queries.
     *
     * @param array<string, mixed> $options GeoNear options
     * @return static
     *
     * @example
     * ```php
     * $pipeline->geoNear([
     *     'near' => ['type' => 'Point', 'coordinates' => [-73.99, 40.73]],
     *     'distanceField' => 'distance',
     *     'maxDistance' => 5000,
     *     'spherical' => true
     * ]);
     * ```
     */
    public function geoNear(array $options): static
    {
        if (!isset($options['near']) || !isset($options['distanceField'])) {
            throw new InvalidArgumentException('geoNear requires "near" and "distanceField" options');
        }

        // $geoNear must be the first stage
        array_unshift($this->pipeline, ['$geoNear' => $options]);

        return $this;
    }

    /**
     * Add a $unionWith stage to combine results from multiple collections.
     *
     * @param string $collection Collection to union with
     * @param array<int, array<string, mixed>> $pipeline Optional pipeline to apply
     * @return static
     *
     * @example
     * ```php
     * $pipeline->unionWith('archived_orders', [
     *     ['$match' => ['status' => 'completed']]
     * ]);
     * ```
     */
    public function unionWith(string $collection, array $pipeline = []): static
    {
        $union = ['coll' => $collection];

        if (!empty($pipeline)) {
            $union['pipeline'] = $pipeline;
        }

        $this->pipeline[] = ['$unionWith' => $union];

        return $this;
    }

    /**
     * Add a $densify stage to fill gaps in time series data.
     *
     * @param string $field Field to densify
     * @param array<string, mixed> $range Range specification
     * @param array<int, string> $partitionByFields Partition fields
     * @return static
     */
    public function densify(string $field, array $range, array $partitionByFields = []): static
    {
        $densify = [
            'field' => $field,
            'range' => $range,
        ];

        if (!empty($partitionByFields)) {
            $densify['partitionByFields'] = $partitionByFields;
        }

        $this->pipeline[] = ['$densify' => $densify];

        return $this;
    }

    /**
     * Add a $fill stage to populate null/missing fields.
     *
     * @param array<string, mixed> $output Fill specifications
     * @param array<int, string> $partitionByFields Partition fields
     * @param array<string, int> $sortBy Sort specification
     * @return static
     */
    public function fill(array $output, array $partitionByFields = [], array $sortBy = []): static
    {
        $fill = ['output' => $output];

        if (!empty($partitionByFields)) {
            $fill['partitionByFields'] = $partitionByFields;
        }

        if (!empty($sortBy)) {
            $fill['sortBy'] = $sortBy;
        }

        $this->pipeline[] = ['$fill' => $fill];

        return $this;
    }

    /**
     * Add a $setWindowFields stage for window functions.
     *
     * @param array<string, mixed>|null $partitionBy Partition specification
     * @param array<string, int> $sortBy Sort specification
     * @param array<string, mixed> $output Output field specifications
     * @return static
     *
     * @example
     * ```php
     * $pipeline->setWindowFields(
     *     '$state',
     *     ['orderDate' => 1],
     *     [
     *         'runningTotal' => [
     *             '$sum' => '$amount',
     *             'window' => ['documents' => ['unbounded', 'current']]
     *         ]
     *     ]
     * );
     * ```
     */
    public function setWindowFields(
        array|string|null $partitionBy,
        array $sortBy,
        array $output
    ): static {
        $stage = [
            'sortBy' => $sortBy,
            'output' => $output,
        ];

        if ($partitionBy !== null) {
            $stage['partitionBy'] = $partitionBy;
        }

        $this->pipeline[] = ['$setWindowFields' => $stage];

        return $this;
    }

    /**
     * Add a raw pipeline stage.
     *
     * @param array<string, mixed> $stage Raw stage definition
     * @return static
     */
    public function raw(array $stage): static
    {
        $this->pipeline[] = $stage;

        return $this;
    }

    /**
     * Set aggregation options.
     *
     * @param array<string, mixed> $options
     * @return static
     */
    public function withOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * Allow disk use for large aggregations.
     *
     * @return static
     */
    public function allowDiskUse(): static
    {
        $this->options['allowDiskUse'] = true;

        return $this;
    }

    /**
     * Set the batch size for cursor iteration.
     *
     * @param int $size
     * @return static
     */
    public function batchSize(int $size): static
    {
        $this->options['batchSize'] = $size;

        return $this;
    }

    /**
     * Set a comment for query profiling.
     *
     * @param string $comment
     * @return static
     */
    public function comment(string $comment): static
    {
        $this->options['comment'] = $comment;

        return $this;
    }

    /**
     * Set the maximum execution time.
     *
     * @param int $milliseconds
     * @return static
     */
    public function maxTimeMS(int $milliseconds): static
    {
        $this->options['maxTimeMS'] = $milliseconds;

        return $this;
    }

    /**
     * Specify an index hint for the aggregation.
     *
     * @param string|array<string, int> $hint Index name or specification
     * @return static
     */
    public function hint(string|array $hint): static
    {
        $this->options['hint'] = $hint;

        return $this;
    }

    /**
     * Enable explain mode.
     *
     * @param string $verbosity Verbosity level (queryPlanner, executionStats, allPlansExecution)
     * @return static
     */
    public function enableExplain(string $verbosity = 'queryPlanner'): static
    {
        $this->options['explain'] = $verbosity;

        return $this;
    }

    /**
     * Get the compiled pipeline array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPipeline(): array
    {
        return $this->pipeline;
    }

    /**
     * Get the aggregation options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Execute the aggregation and return results as array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        return iterator_to_array($this->cursor());
    }

    /**
     * Execute the aggregation and return the cursor.
     *
     * @return Cursor
     */
    public function cursor(): Cursor
    {
        return $this->collection->aggregate($this->pipeline, $this->options);
    }

    /**
     * Execute the aggregation and return the first result.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $results = $this->limit(1)->get();

        return $results[0] ?? null;
    }

    /**
     * Execute an explain on the aggregation.
     *
     * @param string $verbosity
     * @return array<string, mixed>
     */
    public function explain(string $verbosity = 'queryPlanner'): array
    {
        $options = array_merge($this->options, ['explain' => $verbosity]);

        return $this->collection->aggregate($this->pipeline, $options)->toArray()[0] ?? [];
    }

    /**
     * Clone the pipeline for branching.
     *
     * @return static
     */
    public function clone(): static
    {
        $clone = new static($this->collection, $this->connection);
        $clone->pipeline = $this->pipeline;
        $clone->options = $this->options;

        return $clone;
    }

    /**
     * Convert pipeline to debug string.
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->pipeline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get string representation for debugging.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}
