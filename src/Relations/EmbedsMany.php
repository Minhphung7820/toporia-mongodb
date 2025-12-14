<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Relations;

use Toporia\MongoDB\ORM\MongoDBModel;
use Toporia\MongoDB\ORM\MongoDBCollection;
use MongoDB\BSON\ObjectId;

/**
 * EmbedsMany
 *
 * Represents an embedded one-to-many relationship in MongoDB.
 * Related documents are stored as an array of sub-documents within the parent.
 *
 * @example
 * ```php
 * class Order extends MongoDBModel
 * {
 *     public function items(): EmbedsMany
 *     {
 *         return $this->embedsMany(OrderItem::class, 'items');
 *     }
 * }
 *
 * // Access
 * $order->items; // Returns MongoDBCollection of OrderItem models
 *
 * // Create
 * $order->items()->create(['product_id' => 'xyz', 'qty' => 2]);
 *
 * // Find
 * $item = $order->items()->find($itemId);
 * ```
 *
 * @package toporia/mongodb
 */
class EmbedsMany extends MongoDBRelation
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
     * @return MongoDBCollection
     */
    public function getResults(): MongoDBCollection
    {
        $value = $this->parent->getAttribute($this->localKey);

        if ($value === null || !is_array($value)) {
            return new MongoDBCollection([]);
        }

        // Check if it's already a collection
        if ($value instanceof MongoDBCollection) {
            return $this->applyConstraints($value);
        }

        // Hydrate from array
        $models = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $models[] = $this->hydrateModel($item);
            }
        }

        $collection = new MongoDBCollection($models);

        return $this->applyConstraints($collection);
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

        // Get current items
        $current = $this->parent->getAttribute($this->localKey) ?? [];

        if (!is_array($current)) {
            $current = [];
        }

        $current[] = $model->toArray();

        $this->parent->setAttribute($this->localKey, $current);

        // Save parent to persist embedded document
        $this->parent->save();

        return $model;
    }

    /**
     * Create multiple embedded documents.
     *
     * @param array<int, array<string, mixed>> $records
     * @return MongoDBCollection
     */
    public function createMany(array $records): MongoDBCollection
    {
        $models = [];

        foreach ($records as $attributes) {
            $models[] = $this->create($attributes);
        }

        return new MongoDBCollection($models);
    }

    /**
     * Save an embedded model.
     *
     * @param MongoDBModel $model
     * @return bool
     */
    public function save(MongoDBModel $model): bool
    {
        $id = $model->getAttribute('_id');

        // If model has an _id, try to update existing
        if ($id !== null) {
            $current = $this->parent->getAttribute($this->localKey) ?? [];
            $found = false;
            $idString = (string) $id;

            foreach ($current as $index => $item) {
                $itemId = isset($item['_id']) ? (string) $item['_id'] : null;
                if ($itemId === $idString) {
                    $current[$index] = $model->toArray();
                    $found = true;
                    break;
                }
            }

            if ($found) {
                $this->parent->setAttribute($this->localKey, $current);
                return $this->parent->save();
            }
        }

        // Otherwise, create new
        if (!$model->getAttribute('_id')) {
            $model->setAttribute('_id', new ObjectId());
        }

        $current = $this->parent->getAttribute($this->localKey) ?? [];
        $current[] = $model->toArray();

        $this->parent->setAttribute($this->localKey, $current);

        return $this->parent->save();
    }

    /**
     * Save multiple models.
     *
     * @param array<int, MongoDBModel> $models
     * @return bool
     */
    public function saveMany(array $models): bool
    {
        foreach ($models as $model) {
            if (!$this->save($model)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find an embedded document by its _id.
     *
     * @param string|ObjectId $id
     * @return MongoDBModel|null
     */
    public function find(string|ObjectId $id): ?MongoDBModel
    {
        return $this->getResults()->find($id);
    }

    /**
     * Find an embedded document or throw.
     *
     * @param string|ObjectId $id
     * @return MongoDBModel
     * @throws \RuntimeException
     */
    public function findOrFail(string|ObjectId $id): MongoDBModel
    {
        $model = $this->find($id);

        if ($model === null) {
            throw new \RuntimeException(sprintf(
                'Embedded %s with ID %s not found',
                $this->related,
                (string) $id
            ));
        }

        return $model;
    }

    /**
     * Update an embedded document by its _id.
     *
     * @param string|ObjectId $id
     * @param array<string, mixed> $attributes
     * @return bool
     */
    public function update(string|ObjectId $id, array $attributes): bool
    {
        $current = $this->parent->getAttribute($this->localKey) ?? [];
        $idString = (string) $id;
        $found = false;

        foreach ($current as $index => $item) {
            $itemId = isset($item['_id']) ? (string) $item['_id'] : null;
            if ($itemId === $idString) {
                $current[$index] = array_merge($item, $attributes);
                $found = true;
                break;
            }
        }

        if (!$found) {
            return false;
        }

        $this->parent->setAttribute($this->localKey, $current);

        return $this->parent->save();
    }

    /**
     * Delete an embedded document by its _id.
     *
     * @param string|ObjectId $id
     * @return bool
     */
    public function delete(string|ObjectId $id): bool
    {
        $current = $this->parent->getAttribute($this->localKey) ?? [];
        $idString = (string) $id;
        $found = false;

        $filtered = array_filter($current, function ($item) use ($idString, &$found) {
            $itemId = isset($item['_id']) ? (string) $item['_id'] : null;
            if ($itemId === $idString) {
                $found = true;
                return false;
            }
            return true;
        });

        if (!$found) {
            return false;
        }

        $this->parent->setAttribute($this->localKey, array_values($filtered));

        return $this->parent->save();
    }

    /**
     * Attach (add) models to the embedded array.
     *
     * @param MongoDBModel|array<int, MongoDBModel>|array<string, mixed> $models
     * @return static
     */
    public function attach(MongoDBModel|array $models): static
    {
        if ($models instanceof MongoDBModel) {
            $models = [$models];
        }

        // Check if it's an array of attributes
        if (!empty($models) && !($models[0] instanceof MongoDBModel)) {
            $models = [$this->newRelatedInstance($models)];
        }

        $current = $this->parent->getAttribute($this->localKey) ?? [];

        foreach ($models as $model) {
            if (!$model->getAttribute('_id')) {
                $model->setAttribute('_id', new ObjectId());
            }
            $current[] = $model->toArray();
        }

        $this->parent->setAttribute($this->localKey, $current);

        return $this;
    }

    /**
     * Detach (remove) models from the embedded array.
     *
     * @param string|ObjectId|array<string|ObjectId> $ids
     * @return static
     */
    public function detach(string|ObjectId|array $ids): static
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $idStrings = array_map(fn($id) => (string) $id, $ids);

        $current = $this->parent->getAttribute($this->localKey) ?? [];

        $filtered = array_filter($current, function ($item) use ($idStrings) {
            $itemId = isset($item['_id']) ? (string) $item['_id'] : null;
            return !in_array($itemId, $idStrings, true);
        });

        $this->parent->setAttribute($this->localKey, array_values($filtered));

        return $this;
    }

    /**
     * Sync the embedded documents (replace all).
     *
     * @param array<int, MongoDBModel|array<string, mixed>> $models
     * @return static
     */
    public function sync(array $models): static
    {
        $documents = [];

        foreach ($models as $model) {
            if ($model instanceof MongoDBModel) {
                if (!$model->getAttribute('_id')) {
                    $model->setAttribute('_id', new ObjectId());
                }
                $documents[] = $model->toArray();
            } elseif (is_array($model)) {
                if (!isset($model['_id'])) {
                    $model['_id'] = new ObjectId();
                }
                $documents[] = $model;
            }
        }

        $this->parent->setAttribute($this->localKey, $documents);

        return $this;
    }

    /**
     * Get the first embedded document.
     *
     * @return MongoDBModel|null
     */
    public function first(): ?MongoDBModel
    {
        return $this->getResults()->first();
    }

    /**
     * Get the count of embedded documents.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->getResults()->count();
    }

    /**
     * Check if there are any embedded documents.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Clear all embedded documents.
     *
     * @return static
     */
    public function clear(): static
    {
        $this->parent->setAttribute($this->localKey, []);

        return $this;
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

        // Mark as existing
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
                $hydrated = [];
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $hydrated[] = $this->hydrateModel($item);
                    }
                }
                $model->setRelation($relation, new MongoDBCollection($hydrated));
            } else {
                $model->setRelation($relation, new MongoDBCollection([]));
            }
        }
    }
}
