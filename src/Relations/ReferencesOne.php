<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Relations;

use Toporia\MongoDB\ORM\MongoDBModel;
use Toporia\MongoDB\ORM\MongoDBCollection;
use MongoDB\BSON\ObjectId;

/**
 * ReferencesOne
 *
 * Represents a reference one-to-one relationship in MongoDB.
 * Stores an ObjectId reference to a document in another collection.
 *
 * @example
 * ```php
 * class Post extends MongoDBModel
 * {
 *     public function author(): ReferencesOne
 *     {
 *         return $this->referencesOne(User::class, 'author_id');
 *     }
 * }
 *
 * // Access
 * $post->author; // Returns User model
 *
 * // Associate
 * $post->author()->associate($user);
 * $post->save();
 *
 * // Eager load
 * Post::with('author')->get();
 * ```
 *
 * @package toporia/mongodb
 */
class ReferencesOne extends MongoDBRelation
{
    /**
     * The foreign key on the related model.
     *
     * @var string
     */
    protected string $foreignKey;

    /**
     * The owner key on the related model.
     *
     * @var string
     */
    protected string $ownerKey;

    /**
     * Models for eager loading.
     *
     * @var array<int, MongoDBModel>
     */
    protected array $eagerModels = [];

    /**
     * Create a new references one relationship.
     *
     * @param MongoDBModel $parent
     * @param class-string<MongoDBModel> $related
     * @param string $foreignKey Foreign key in parent model (stores ObjectId)
     * @param string $ownerKey Primary key in related model
     */
    public function __construct(MongoDBModel $parent, string $related, string $foreignKey, string $ownerKey = '_id')
    {
        parent::__construct($parent, $related, $foreignKey);

        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;
    }

    /**
     * Check if this is an embedded relationship.
     *
     * @return bool
     */
    public function isEmbedded(): bool
    {
        return false;
    }

    /**
     * Get the results of the relationship.
     *
     * @return MongoDBModel|null
     */
    public function getResults(): ?MongoDBModel
    {
        $foreignValue = $this->parent->getAttribute($this->foreignKey);

        if ($foreignValue === null) {
            return null;
        }

        // Convert to ObjectId if needed
        $objectId = $this->toObjectId($foreignValue);

        // Query the related collection
        return $this->related::query()
            ->where($this->ownerKey, $objectId)
            ->first();
    }

    /**
     * Associate a model with the parent.
     *
     * @param MongoDBModel|ObjectId|string|null $model
     * @return static
     */
    public function associate(MongoDBModel|ObjectId|string|null $model): static
    {
        if ($model === null) {
            $this->parent->setAttribute($this->foreignKey, null);
            return $this;
        }

        if ($model instanceof MongoDBModel) {
            $id = $model->getAttribute($this->ownerKey);
        } else {
            $id = $this->toObjectId($model);
        }

        $this->parent->setAttribute($this->foreignKey, $id);

        return $this;
    }

    /**
     * Dissociate the related model.
     *
     * @return static
     */
    public function dissociate(): static
    {
        $this->parent->setAttribute($this->foreignKey, null);

        return $this;
    }

    /**
     * Create and associate a new related model.
     *
     * @param array<string, mixed> $attributes
     * @return MongoDBModel
     */
    public function create(array $attributes): MongoDBModel
    {
        $model = $this->related::create($attributes);

        $this->associate($model);
        $this->parent->save();

        return $model;
    }

    /**
     * Update the related model.
     *
     * @param array<string, mixed> $attributes
     * @return bool
     */
    public function update(array $attributes): bool
    {
        $model = $this->getResults();

        if ($model === null) {
            return false;
        }

        foreach ($attributes as $key => $value) {
            $model->setAttribute($key, $value);
        }

        return $model->save();
    }

    /**
     * Get the foreign key name.
     *
     * @return string
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the owner key name.
     *
     * @return string
     */
    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    /**
     * Set the constraints for eager loading.
     *
     * @param array<int, MongoDBModel> $models
     * @return void
     */
    public function addEagerConstraints(array $models): void
    {
        $this->eagerModels = $models;
    }

    /**
     * Get the results for eager loading.
     *
     * @return MongoDBCollection
     */
    public function getEager(): MongoDBCollection
    {
        if (empty($this->eagerModels)) {
            return new MongoDBCollection([]);
        }

        // Collect all foreign key values
        $ids = [];
        foreach ($this->eagerModels as $model) {
            $id = $model->getAttribute($this->foreignKey);
            if ($id !== null) {
                $ids[] = $this->toObjectId($id);
            }
        }

        if (empty($ids)) {
            return new MongoDBCollection([]);
        }

        // Query for all related models at once
        $query = $this->related::query()->whereIn($this->ownerKey, array_unique($ids, SORT_STRING));

        // Apply constraints
        foreach ($this->constraints as $constraint) {
            $query->where($constraint['column'], $constraint['operator'], $constraint['value']);
        }

        return $query->get();
    }

    /**
     * Match eagerly loaded results to parents.
     *
     * @param array<int, MongoDBModel> $models
     * @param MongoDBCollection $results
     * @param string $relation
     * @return void
     */
    public function match(array $models, MongoDBCollection $results, string $relation): void
    {
        // Build dictionary of results by owner key
        $dictionary = [];
        foreach ($results as $result) {
            $key = (string) $result->getAttribute($this->ownerKey);
            $dictionary[$key] = $result;
        }

        // Match results to models
        foreach ($models as $model) {
            $foreignValue = $model->getAttribute($this->foreignKey);

            if ($foreignValue !== null) {
                $key = (string) $this->toObjectId($foreignValue);
                $model->setRelation($relation, $dictionary[$key] ?? null);
            } else {
                $model->setRelation($relation, null);
            }
        }
    }
}
