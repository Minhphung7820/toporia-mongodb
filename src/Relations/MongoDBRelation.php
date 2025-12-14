<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Relations;

use Toporia\MongoDB\Contracts\MongoDBRelationInterface;
use Toporia\MongoDB\ORM\MongoDBModel;
use Toporia\MongoDB\ORM\MongoDBCollection;
use MongoDB\BSON\ObjectId;

/**
 * MongoDBRelation
 *
 * Base class for all MongoDB relationships.
 * Provides common functionality for embedded and reference relationships.
 *
 * @package toporia/mongodb
 */
abstract class MongoDBRelation implements MongoDBRelationInterface
{
    /**
     * The parent model instance.
     *
     * @var MongoDBModel
     */
    protected MongoDBModel $parent;

    /**
     * The related model class.
     *
     * @var class-string<MongoDBModel>
     */
    protected string $related;

    /**
     * The local key on the parent model.
     *
     * @var string
     */
    protected string $localKey;

    /**
     * Constraints applied to eager loading.
     *
     * @var array<int, array{column: string, operator: string, value: mixed}>
     */
    protected array $constraints = [];

    /**
     * Create a new relation instance.
     *
     * @param MongoDBModel $parent
     * @param class-string<MongoDBModel> $related
     * @param string $localKey
     */
    public function __construct(MongoDBModel $parent, string $related, string $localKey)
    {
        $this->parent = $parent;
        $this->related = $related;
        $this->localKey = $localKey;
    }

    /**
     * Get the parent model.
     *
     * @return MongoDBModel
     */
    public function getParent(): MongoDBModel
    {
        return $this->parent;
    }

    /**
     * Get the related model class.
     *
     * @return class-string<MongoDBModel>
     */
    public function getRelated(): string
    {
        return $this->related;
    }

    /**
     * Get the local key.
     *
     * @return string
     */
    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    /**
     * Create a new instance of the related model.
     *
     * @param array<string, mixed> $attributes
     * @return MongoDBModel
     */
    public function newRelatedInstance(array $attributes = []): MongoDBModel
    {
        return new $this->related($attributes);
    }

    /**
     * Add a basic where clause to the query.
     *
     * @param string $column
     * @param mixed $operator
     * @param mixed $value
     * @return static
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->constraints[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Check if this is an embedded relationship.
     *
     * @return bool
     */
    abstract public function isEmbedded(): bool;

    /**
     * Get the results of the relationship.
     *
     * @return MongoDBModel|MongoDBCollection|null
     */
    abstract public function getResults(): MongoDBModel|MongoDBCollection|null;

    /**
     * Set the constraints for an eager load query.
     *
     * @param array<int, MongoDBModel> $models
     * @return void
     */
    public function addEagerConstraints(array $models): void
    {
        // Override in subclasses
    }

    /**
     * Get the results for eager loading.
     *
     * @return MongoDBCollection
     */
    public function getEager(): MongoDBCollection
    {
        return $this->getResults() instanceof MongoDBCollection
            ? $this->getResults()
            : new MongoDBCollection($this->getResults() ? [$this->getResults()] : []);
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param array<int, MongoDBModel> $models
     * @param MongoDBCollection $results
     * @param string $relation
     * @return void
     */
    public function match(array $models, MongoDBCollection $results, string $relation): void
    {
        // Override in subclasses
    }

    /**
     * Apply constraints to a model collection.
     *
     * @param MongoDBCollection $collection
     * @return MongoDBCollection
     */
    protected function applyConstraints(MongoDBCollection $collection): MongoDBCollection
    {
        if (empty($this->constraints)) {
            return $collection;
        }

        foreach ($this->constraints as $constraint) {
            $collection = $collection->where(
                $constraint['column'],
                $constraint['operator'],
                $constraint['value']
            );
        }

        return $collection;
    }

    /**
     * Convert a value to ObjectId if valid.
     *
     * @param mixed $value
     * @return ObjectId|mixed
     */
    protected function toObjectId(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return $value;
        }

        if (is_string($value) && ObjectId::isValid($value)) {
            return new ObjectId($value);
        }

        return $value;
    }

    /**
     * Convert ObjectId to string.
     *
     * @param mixed $value
     * @return string|mixed
     */
    protected function objectIdToString(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string) $value;
        }

        return $value;
    }
}
