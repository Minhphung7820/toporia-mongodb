<?php

declare(strict_types=1);

namespace Toporia\MongoDB\ORM;

use Toporia\MongoDB\Query\MongoDBQueryBuilder;
use MongoDB\Collection;
use MongoDB\BSON\ObjectId;

/**
 * MongoDBModelQueryBuilder
 *
 * Query builder that returns hydrated model instances instead of raw arrays.
 * Extends MongoDBQueryBuilder with model-specific functionality.
 *
 * Features:
 * - Automatic hydration of results into model instances
 * - Eager loading of relationships
 * - Returns MongoDBCollection instead of arrays
 * - Model-aware find operations
 *
 * @template TModel of MongoDBModel
 * @package toporia/mongodb
 */
class MongoDBModelQueryBuilder extends MongoDBQueryBuilder
{
    /**
     * The model class to hydrate into.
     *
     * @var class-string<TModel>
     */
    protected string $modelClass;

    /**
     * Relations to eager load.
     *
     * @var array<string, callable|null>
     */
    protected array $eagerLoad = [];

    /**
     * Create a new model query builder.
     *
     * @param Collection $collection
     * @param class-string<TModel> $modelClass
     */
    public function __construct(Collection $collection, string $modelClass)
    {
        parent::__construct($collection);
        $this->modelClass = $modelClass;
    }

    /**
     * Set relations to eager load.
     *
     * @param string|array<string|int, string|callable> $relations
     * @return static
     *
     * @example
     * ```php
     * // Simple eager load
     * Post::with('author')->get();
     *
     * // Multiple relations
     * Post::with(['author', 'tags'])->get();
     *
     * // With constraints
     * Post::with(['comments' => function($query) {
     *     $query->where('approved', true);
     * }])->get();
     * ```
     */
    public function with(string|array $relations): static
    {
        $relations = is_string($relations) ? [$relations] : $relations;

        foreach ($relations as $key => $value) {
            if (is_numeric($key)) {
                // Simple relation name
                $this->eagerLoad[$value] = null;
            } else {
                // Relation with constraint callback
                $this->eagerLoad[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Execute the query and get results as MongoDBCollection.
     *
     * @return MongoDBCollection<TModel>
     */
    public function get(): MongoDBCollection
    {
        $results = parent::get();
        $models = $this->hydrateModels($results);

        // Eager load relations
        if (!empty($this->eagerLoad) && !empty($models)) {
            $this->eagerLoadRelations($models);
        }

        return new MongoDBCollection($models);
    }

    /**
     * Get the first result.
     *
     * @return TModel|null
     */
    public function first(): ?MongoDBModel
    {
        $results = $this->limit(1)->get();

        return $results->first();
    }

    /**
     * Get the first result or throw.
     *
     * @return TModel
     * @throws \RuntimeException
     */
    public function firstOrFail(): MongoDBModel
    {
        $result = $this->first();

        if ($result === null) {
            throw new \RuntimeException(sprintf(
                'No %s model found matching the query',
                $this->modelClass
            ));
        }

        return $result;
    }

    /**
     * Find a document by its primary key.
     *
     * @param string|ObjectId $id
     * @return TModel|null
     */
    public function find(string|ObjectId $id): ?MongoDBModel
    {
        $objectId = $id instanceof ObjectId ? $id : new ObjectId($id);

        return $this->where('_id', $objectId)->first();
    }

    /**
     * Find a document by its primary key or throw.
     *
     * @param string|ObjectId $id
     * @return TModel
     * @throws \RuntimeException
     */
    public function findOrFail(string|ObjectId $id): MongoDBModel
    {
        $model = $this->find($id);

        if ($model === null) {
            throw new \RuntimeException(sprintf(
                '%s with ID %s not found',
                $this->modelClass,
                (string) $id
            ));
        }

        return $model;
    }

    /**
     * Find multiple documents by their primary keys.
     *
     * @param array<string|ObjectId> $ids
     * @return MongoDBCollection<TModel>
     */
    public function findMany(array $ids): MongoDBCollection
    {
        if (empty($ids)) {
            return new MongoDBCollection([]);
        }

        $objectIds = array_map(function ($id) {
            return $id instanceof ObjectId ? $id : new ObjectId($id);
        }, $ids);

        return $this->whereIn('_id', $objectIds)->get();
    }

    /**
     * Get a paginated result set.
     *
     * @param int $perPage
     * @param int $page
     * @return array{data: MongoDBCollection<TModel>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        // Clone for count query
        $countBuilder = clone $this;
        $total = $countBuilder->count();

        $lastPage = (int) ceil($total / $perPage);
        $page = max(1, min($page, $lastPage ?: 1));

        $data = $this->skip(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Cursor-based pagination.
     *
     * @param int $perPage
     * @param string|ObjectId|null $cursor
     * @param string $direction 'next' or 'prev'
     * @return array{data: MongoDBCollection<TModel>, next_cursor: string|null, prev_cursor: string|null}
     */
    public function cursorPaginate(int $perPage = 15, string|ObjectId|null $cursor = null, string $direction = 'next'): array
    {
        $builder = clone $this;

        if ($cursor !== null) {
            $cursorId = $cursor instanceof ObjectId ? $cursor : new ObjectId($cursor);

            if ($direction === 'next') {
                $builder->where('_id', '>', $cursorId);
            } else {
                $builder->where('_id', '<', $cursorId);
                $builder->orderBy('_id', 'desc');
            }
        }

        // Get one extra to determine if there's a next page
        $results = $builder->limit($perPage + 1)->get();

        $hasMore = $results->count() > $perPage;

        if ($hasMore) {
            $results = $results->take($perPage);
        }

        // Reverse if we were going backwards
        if ($direction === 'prev') {
            $results = $results->reverse();
        }

        $first = $results->first();
        $last = $results->last();

        return [
            'data' => $results,
            'next_cursor' => $hasMore && $last ? (string) $last->getMongoId() : null,
            'prev_cursor' => $first ? (string) $first->getMongoId() : null,
        ];
    }

    /**
     * Chunk the results and process them.
     *
     * @param int $size
     * @param callable(MongoDBCollection<TModel>, int): bool $callback
     * @return bool
     */
    public function chunk(int $size, callable $callback): bool
    {
        $page = 0;
        $lastId = null;

        do {
            $builder = clone $this;

            if ($lastId !== null) {
                $builder->where('_id', '>', $lastId);
            }

            $results = $builder->orderBy('_id', 'asc')->limit($size)->get();

            if ($results->isEmpty()) {
                break;
            }

            $page++;

            if ($callback($results, $page) === false) {
                return false;
            }

            $lastModel = $results->last();
            $lastId = $lastModel ? $lastModel->getMongoId() : null;

        } while ($results->count() === $size);

        return true;
    }

    /**
     * Get a lazy collection using cursor iteration.
     *
     * @return \Generator<int, TModel>
     */
    public function cursor(): \Generator
    {
        $cursor = parent::cursor();

        foreach ($cursor as $document) {
            yield $this->hydrateModel($document);
        }
    }

    /**
     * Pluck a single column from the results.
     *
     * @param string $column
     * @param string|null $key
     * @return array<mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $results = parent::get();
        $plucked = [];

        foreach ($results as $row) {
            $value = $row[$column] ?? null;

            if ($key !== null) {
                $keyValue = $row[$key] ?? null;
                if ($keyValue instanceof ObjectId) {
                    $keyValue = (string) $keyValue;
                }
                $plucked[(string) $keyValue] = $value;
            } else {
                $plucked[] = $value;
            }
        }

        return $plucked;
    }

    /**
     * Determine if any rows exist for the current query.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return parent::exists();
    }

    /**
     * Determine if no rows exist for the current query.
     *
     * @return bool
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Create a new model instance.
     *
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function create(array $attributes): MongoDBModel
    {
        $model = $this->newModelInstance($attributes);
        $model->save();

        return $model;
    }

    /**
     * Create multiple model instances.
     *
     * @param array<int, array<string, mixed>> $records
     * @return MongoDBCollection<TModel>
     */
    public function createMany(array $records): MongoDBCollection
    {
        $models = [];

        foreach ($records as $attributes) {
            $model = $this->newModelInstance($attributes);
            $model->save();
            $models[] = $model;
        }

        return new MongoDBCollection($models);
    }

    /**
     * Update all matching documents.
     *
     * @param array<string, mixed> $values
     * @return int Number of modified documents
     */
    public function update(array $values): int
    {
        return parent::update($values);
    }

    /**
     * Delete all matching documents.
     *
     * @return int Number of deleted documents
     */
    public function delete(): int
    {
        return parent::delete();
    }

    /**
     * Force delete (same as delete for MongoDB).
     *
     * @return int
     */
    public function forceDelete(): int
    {
        return $this->delete();
    }

    /**
     * Hydrate multiple documents into model instances.
     *
     * @param array<int, array<string, mixed>> $documents
     * @return array<int, TModel>
     */
    protected function hydrateModels(array $documents): array
    {
        $models = [];

        foreach ($documents as $document) {
            $models[] = $this->hydrateModel($document);
        }

        return $models;
    }

    /**
     * Hydrate a single document into a model instance.
     *
     * @param array<string, mixed> $document
     * @return TModel
     */
    protected function hydrateModel(array $document): MongoDBModel
    {
        /** @var TModel $model */
        $model = new $this->modelClass();

        // Set attributes directly, bypassing fillable checks
        foreach ($document as $key => $value) {
            $model->setAttribute($key, $value);
        }

        // Mark as existing
        $reflection = new \ReflectionClass($model);
        $property = $reflection->getProperty('exists');
        $property->setAccessible(true);
        $property->setValue($model, true);

        // Sync original
        $method = $reflection->getMethod('syncOriginal');
        $method->setAccessible(true);
        $method->invoke($model);

        return $model;
    }

    /**
     * Create a new model instance.
     *
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    protected function newModelInstance(array $attributes = []): MongoDBModel
    {
        return new $this->modelClass($attributes);
    }

    /**
     * Eager load relations for the models.
     *
     * @param array<int, TModel> $models
     * @return void
     */
    protected function eagerLoadRelations(array $models): void
    {
        foreach ($this->eagerLoad as $relation => $constraints) {
            $this->eagerLoadRelation($models, $relation, $constraints);
        }
    }

    /**
     * Eager load a single relation.
     *
     * @param array<int, TModel> $models
     * @param string $relation
     * @param callable|null $constraints
     * @return void
     */
    protected function eagerLoadRelation(array $models, string $relation, ?callable $constraints): void
    {
        if (empty($models)) {
            return;
        }

        // Get the first model to access the relation definition
        $firstModel = $models[0];

        if (!method_exists($firstModel, $relation)) {
            return;
        }

        $relationInstance = $firstModel->$relation();

        if (!$relationInstance instanceof \Toporia\MongoDB\Contracts\MongoDBRelationInterface) {
            return;
        }

        // For embedded relations, data is already in the document
        if ($relationInstance->isEmbedded()) {
            foreach ($models as $model) {
                $relationData = $model->$relation();
                $model->setRelation($relation, $relationData->getResults());
            }
            return;
        }

        // For reference relations, perform eager loading
        $relationInstance->addEagerConstraints($models);

        if ($constraints !== null) {
            $constraints($relationInstance);
        }

        $results = $relationInstance->getEager();

        // Match results back to models
        $relationInstance->match($models, $results, $relation);
    }

    /**
     * Clone the query builder.
     *
     * @return static
     */
    public function clone(): static
    {
        $clone = parent::clone();
        $clone->modelClass = $this->modelClass;
        $clone->eagerLoad = $this->eagerLoad;

        return $clone;
    }
}
