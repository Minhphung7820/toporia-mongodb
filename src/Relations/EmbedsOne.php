<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Relations;

use Toporia\MongoDB\ORM\MongoDBModel;
use Toporia\MongoDB\ORM\MongoDBCollection;
use MongoDB\BSON\ObjectId;

/**
 * EmbedsOne
 *
 * Represents an embedded one-to-one relationship in MongoDB.
 * The related document is stored as a sub-document within the parent.
 *
 * @example
 * ```php
 * class Order extends MongoDBModel
 * {
 *     public function shippingAddress(): EmbedsOne
 *     {
 *         return $this->embedsOne(Address::class, 'shipping_address');
 *     }
 * }
 *
 * // Access
 * $order->shippingAddress; // Returns Address model
 *
 * // Create
 * $order->shippingAddress()->create(['city' => 'NYC']);
 *
 * // Update
 * $order->shippingAddress()->update(['city' => 'LA']);
 * ```
 *
 * @package toporia/mongodb
 */
class EmbedsOne extends MongoDBRelation
{
    /**
     * Check if this is an embedded relationship.
     *
     * @return bool
     */
    public function isEmbedded(): bool
    {
        return true;
    }

    /**
     * Get the results of the relationship.
     *
     * @return MongoDBModel|null
     */
    public function getResults(): ?MongoDBModel
    {
        $value = $this->parent->getAttribute($this->localKey);

        if ($value === null) {
            return null;
        }

        // Already a model
        if ($value instanceof MongoDBModel) {
            return $value;
        }

        // Hydrate from array
        if (is_array($value)) {
            return $this->hydrateModel($value);
        }

        return null;
    }

    /**
     * Associate a model with the parent.
     *
     * @param MongoDBModel|array<string, mixed>|null $model
     * @return static
     */
    public function associate(MongoDBModel|array|null $model): static
    {
        if ($model === null) {
            $this->parent->setAttribute($this->localKey, null);
            return $this;
        }

        $data = $model instanceof MongoDBModel ? $model->toArray() : $model;

        // Ensure _id exists
        if (!isset($data['_id'])) {
            $data['_id'] = new ObjectId();
        }

        $this->parent->setAttribute($this->localKey, $data);

        return $this;
    }

    /**
     * Dissociate (remove) the embedded document.
     *
     * @return static
     */
    public function dissociate(): static
    {
        $this->parent->setAttribute($this->localKey, null);

        return $this;
    }

    /**
     * Create a new embedded document.
     *
     * @param array<string, mixed> $attributes
     * @return MongoDBModel
     */
    public function create(array $attributes): MongoDBModel
    {
        $model = $this->newRelatedInstance($attributes);

        // Set _id if not present
        if (!$model->getAttribute('_id')) {
            $model->setAttribute('_id', new ObjectId());
        }

        $this->parent->setAttribute($this->localKey, $model->toArray());

        // Save parent to persist embedded document
        $this->parent->save();

        return $model;
    }

    /**
     * Update the embedded document.
     *
     * @param array<string, mixed> $attributes
     * @return bool
     */
    public function update(array $attributes): bool
    {
        $current = $this->parent->getAttribute($this->localKey);

        if ($current === null) {
            return false;
        }

        // Merge with existing data
        if (is_array($current)) {
            $updated = array_merge($current, $attributes);
        } else {
            $updated = $attributes;
        }

        $this->parent->setAttribute($this->localKey, $updated);

        return $this->parent->save();
    }

    /**
     * Save the embedded model.
     *
     * @param MongoDBModel $model
     * @return bool
     */
    public function save(MongoDBModel $model): bool
    {
        // Ensure _id exists
        if (!$model->getAttribute('_id')) {
            $model->setAttribute('_id', new ObjectId());
        }

        $this->parent->setAttribute($this->localKey, $model->toArray());

        return $this->parent->save();
    }

    /**
     * Delete the embedded document.
     *
     * @return bool
     */
    public function delete(): bool
    {
        $this->parent->setAttribute($this->localKey, null);

        return $this->parent->save();
    }

    /**
     * Get or create the embedded document.
     *
     * @param array<string, mixed> $attributes
     * @return MongoDBModel
     */
    public function firstOrCreate(array $attributes = []): MongoDBModel
    {
        $existing = $this->getResults();

        if ($existing !== null) {
            return $existing;
        }

        return $this->create($attributes);
    }

    /**
     * Update or create the embedded document.
     *
     * @param array<string, mixed> $attributes
     * @return MongoDBModel
     */
    public function updateOrCreate(array $attributes): MongoDBModel
    {
        $existing = $this->getResults();

        if ($existing !== null) {
            $this->update($attributes);
            return $this->getResults();
        }

        return $this->create($attributes);
    }

    /**
     * Hydrate a model from array data.
     *
     * @param array<string, mixed> $data
     * @return MongoDBModel
     */
    protected function hydrateModel(array $data): MongoDBModel
    {
        /** @var MongoDBModel $model */
        $model = new $this->related();

        foreach ($data as $key => $value) {
            $model->setAttribute($key, $value);
        }

        // Mark as existing (it exists within the parent document)
        $reflection = new \ReflectionClass($model);
        $property = $reflection->getProperty('exists');
        $property->setAccessible(true);
        $property->setValue($model, true);

        return $model;
    }

    /**
     * Match eagerly loaded results to parents.
     *
     * For embedded relations, data is already in the parent document.
     *
     * @param array<int, MongoDBModel> $models
     * @param MongoDBCollection $results
     * @param string $relation
     * @return void
     */
    public function match(array $models, MongoDBCollection $results, string $relation): void
    {
        foreach ($models as $model) {
            $value = $model->getAttribute($this->localKey);

            if ($value !== null && is_array($value)) {
                $model->setRelation($relation, $this->hydrateModel($value));
            } else {
                $model->setRelation($relation, null);
            }
        }
    }
}
