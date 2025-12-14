<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Relations;

use Toporia\MongoDB\ORM\MongoDBModel;
use Toporia\MongoDB\ORM\MongoDBCollection;
use MongoDB\BSON\ObjectId;

/**
 * BelongsToReference
 *
 * Represents the inverse of a reference relationship in MongoDB.
 * The current model stores the ObjectId reference to the related model.
 *
 * @example
 * ```php
 * class Comment extends MongoDBModel
 * {
 *     public function post(): BelongsToReference
 *     {
 *         return $this->belongsToReference(Post::class, 'post_id');
 *     }
 *
 *     public function author(): BelongsToReference
 *     {
 *         return $this->belongsToReference(User::class, 'author_id');
 *     }
 * }
 *
 * // Access
 * $comment->post; // Returns Post model
 * $comment->author; // Returns User model
 *
 * // Associate
 * $comment->post()->associate($post);
 * $comment->save();
 *
 * // Eager load
 * Comment::with(['post', 'author'])->get();
 * ```
 *
 * @package toporia/mongodb
 */
class BelongsToReference extends MongoDBRelation
{
    /**
     * The foreign key on this model.
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
     * Default value when relation is null.
     *
     * @var MongoDBModel|array<string, mixed>|null
     */
    protected MongoDBModel|array|null $withDefault = null;

    /**
     * Create a new belongs to reference relationship.
     *
     * @param MongoDBModel $parent
     * @param class-string<MongoDBModel> $related
     * @param string $foreignKey Foreign key in this model
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
            return $this->getDefaultFor($this->parent);
        }

        // Convert to ObjectId if needed
        $objectId = $this->toObjectId($foreignValue);

        // Query the related collection
        $result = $this->related::query()
            ->where($this->ownerKey, $objectId)
            ->first();

        return $result ?? $this->getDefaultFor($this->parent);
    }

    /**
     * Associate a model with the child.
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
     * Return a default model if the relationship is empty.
     *
     * @param MongoDBModel|array<string, mixed>|callable|null $default
     * @return static
     */
    public function withDefault(MongoDBModel|array|callable|null $default = []): static
    {
        $this->withDefault = is_callable($default) ? $default($this->parent) : $default;

        return $this;
    }

    /**
     * Get the default value for the model.
     *
     * @param MongoDBModel $parent
     * @return MongoDBModel|null
     */
    protected function getDefaultFor(MongoDBModel $parent): ?MongoDBModel
    {
        if ($this->withDefault === null) {
            return null;
        }

        if ($this->withDefault instanceof MongoDBModel) {
            return $this->withDefault;
        }

        if (is_array($this->withDefault)) {
            $model = new $this->related();
            foreach ($this->withDefault as $key => $value) {
                $model->setAttribute($key, $value);
            }
            return $model;
        }

        return null;
    }

    /**
     * Get the foreign key.
     *
     * @return string
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the owner key.
     *
     * @return string
     */
    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    /**
     * Get the value of the child's foreign key.
     *
     * @return mixed
     */
    public function getParentKey(): mixed
    {
        return $this->parent->getAttribute($this->foreignKey);
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
                $objectId = $this->toObjectId($id);
                $ids[(string) $objectId] = $objectId;
            }
        }

        if (empty($ids)) {
            return new MongoDBCollection([]);
        }

        // Query for all related models at once
        $query = $this->related::query()->whereIn($this->ownerKey, array_values($ids));

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
                $match = $dictionary[$key] ?? $this->getDefaultFor($model);
                $model->setRelation($relation, $match);
            } else {
                $model->setRelation($relation, $this->getDefaultFor($model));
            }
        }
    }

    /**
     * Check if the relationship is null.
     *
     * @return bool
     */
    public function is(MongoDBModel|null $model): bool
    {
        if ($model === null) {
            return $this->parent->getAttribute($this->foreignKey) === null;
        }

        $foreignValue = $this->parent->getAttribute($this->foreignKey);
        $modelKey = $model->getAttribute($this->ownerKey);

        return (string) $this->toObjectId($foreignValue) === (string) $this->toObjectId($modelKey);
    }

    /**
     * Check if the relationship is not the given model.
     *
     * @param MongoDBModel|null $model
     * @return bool
     */
    public function isNot(?MongoDBModel $model): bool
    {
        return !$this->is($model);
    }
}
