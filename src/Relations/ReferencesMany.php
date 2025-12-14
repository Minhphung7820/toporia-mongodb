<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Relations;

use Toporia\MongoDB\ORM\MongoDBModel;
use Toporia\MongoDB\ORM\MongoDBCollection;
use MongoDB\BSON\ObjectId;

/**
 * ReferencesMany
 *
 * Represents a reference one-to-many relationship in MongoDB.
 * Stores an array of ObjectId references to documents in another collection.
 *
 * @example
 * ```php
 * class Post extends MongoDBModel
 * {
 *     public function tags(): ReferencesMany
 *     {
 *         return $this->referencesMany(Tag::class, 'tag_ids');
 *     }
 * }
 *
 * // Access
 * $post->tags; // Returns MongoDBCollection of Tag models
 *
 * // Attach
 * $post->tags()->attach($tagId);
 * $post->tags()->attach([$tagId1, $tagId2]);
 *
 * // Detach
 * $post->tags()->detach($tagId);
 *
 * // Sync
 * $post->tags()->sync([$tagId1, $tagId2, $tagId3]);
 *
 * // Eager load
 * Post::with('tags')->get();
 * ```
 *
 * @package toporia/mongodb
 */
class ReferencesMany extends MongoDBRelation
{
    /**
     * The foreign key on the parent model (stores array of ObjectIds).
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
     * Create a new references many relationship.
     *
     * @param MongoDBModel $parent
     * @param class-string<MongoDBModel> $related
     * @param string $foreignKey Foreign key in parent (stores array of ObjectIds)
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
     * @return MongoDBCollection
     */
    public function getResults(): MongoDBCollection
    {
        $ids = $this->getRelatedIds();

        if (empty($ids)) {
            return new MongoDBCollection([]);
        }

        // Query the related collection
        $query = $this->related::query()->whereIn($this->ownerKey, $ids);

        // Apply constraints
        foreach ($this->constraints as $constraint) {
            $query->where($constraint['column'], $constraint['operator'], $constraint['value']);
        }

        return $query->get();
    }

    /**
     * Get the related IDs from the parent.
     *
     * @return array<int, ObjectId>
     */
    protected function getRelatedIds(): array
    {
        $value = $this->parent->getAttribute($this->foreignKey);

        if ($value === null || !is_array($value)) {
            return [];
        }

        return array_map(fn($id) => $this->toObjectId($id), $value);
    }

    /**
     * Attach IDs to the relationship.
     *
     * @param ObjectId|string|array<ObjectId|string> $ids
     * @return static
     */
    public function attach(ObjectId|string|array $ids): static
    {
        $ids = is_array($ids) ? $ids : [$ids];

        $current = $this->parent->getAttribute($this->foreignKey) ?? [];
        $currentStrings = array_map(fn($id) => (string) $id, $current);

        foreach ($ids as $id) {
            $objectId = $this->toObjectId($id);
            $idString = (string) $objectId;

            // Only add if not already present
            if (!in_array($idString, $currentStrings, true)) {
                $current[] = $objectId;
                $currentStrings[] = $idString;
            }
        }

        $this->parent->setAttribute($this->foreignKey, $current);

        return $this;
    }

    /**
     * Detach IDs from the relationship.
     *
     * @param ObjectId|string|array<ObjectId|string>|null $ids Null to detach all
     * @return static
     */
    public function detach(ObjectId|string|array|null $ids = null): static
    {
        if ($ids === null) {
            // Detach all
            $this->parent->setAttribute($this->foreignKey, []);
            return $this;
        }

        $ids = is_array($ids) ? $ids : [$ids];
        $idsToRemove = array_map(fn($id) => (string) $this->toObjectId($id), $ids);

        $current = $this->parent->getAttribute($this->foreignKey) ?? [];

        $filtered = array_values(array_filter($current, function ($id) use ($idsToRemove) {
            return !in_array((string) $id, $idsToRemove, true);
        }));

        $this->parent->setAttribute($this->foreignKey, $filtered);

        return $this;
    }

    /**
     * Sync the relationship with the given IDs.
     *
     * @param array<ObjectId|string> $ids
     * @param bool $detaching Whether to detach IDs not in the list
     * @return array{attached: array<string>, detached: array<string>, updated: array<string>}
     */
    public function sync(array $ids, bool $detaching = true): array
    {
        $changes = [
            'attached' => [],
            'detached' => [],
            'updated' => [],
        ];

        $current = $this->parent->getAttribute($this->foreignKey) ?? [];
        $currentStrings = array_map(fn($id) => (string) $id, $current);

        $newIds = array_map(fn($id) => $this->toObjectId($id), $ids);
        $newStrings = array_map(fn($id) => (string) $id, $newIds);

        // Find IDs to detach
        if ($detaching) {
            foreach ($currentStrings as $currentId) {
                if (!in_array($currentId, $newStrings, true)) {
                    $changes['detached'][] = $currentId;
                }
            }
        }

        // Find IDs to attach
        foreach ($newStrings as $index => $newId) {
            if (!in_array($newId, $currentStrings, true)) {
                $changes['attached'][] = $newId;
            }
        }

        // Apply changes
        $this->parent->setAttribute($this->foreignKey, $newIds);

        return $changes;
    }

    /**
     * Sync without detaching.
     *
     * @param array<ObjectId|string> $ids
     * @return array{attached: array<string>, detached: array<string>, updated: array<string>}
     */
    public function syncWithoutDetaching(array $ids): array
    {
        return $this->sync($ids, false);
    }

    /**
     * Toggle the presence of IDs in the relationship.
     *
     * @param array<ObjectId|string> $ids
     * @return array{attached: array<string>, detached: array<string>}
     */
    public function toggle(array $ids): array
    {
        $changes = [
            'attached' => [],
            'detached' => [],
        ];

        $current = $this->parent->getAttribute($this->foreignKey) ?? [];
        $currentStrings = array_map(fn($id) => (string) $id, $current);

        $updated = [];

        foreach ($ids as $id) {
            $objectId = $this->toObjectId($id);
            $idString = (string) $objectId;

            if (in_array($idString, $currentStrings, true)) {
                // Remove it
                $changes['detached'][] = $idString;
            } else {
                // Add it
                $updated[] = $objectId;
                $changes['attached'][] = $idString;
            }
        }

        // Keep IDs that weren't toggled
        foreach ($current as $id) {
            $idString = (string) $id;
            if (!in_array($idString, $changes['detached'], true)) {
                $updated[] = $id;
            }
        }

        $this->parent->setAttribute($this->foreignKey, $updated);

        return $changes;
    }

    /**
     * Create and attach a new related model.
     *
     * @param array<string, mixed> $attributes
     * @return MongoDBModel
     */
    public function create(array $attributes): MongoDBModel
    {
        $model = $this->related::create($attributes);

        $this->attach($model->getAttribute($this->ownerKey));
        $this->parent->save();

        return $model;
    }

    /**
     * Create multiple and attach them.
     *
     * @param array<int, array<string, mixed>> $records
     * @return MongoDBCollection
     */
    public function createMany(array $records): MongoDBCollection
    {
        $models = [];
        $ids = [];

        foreach ($records as $attributes) {
            $model = $this->related::create($attributes);
            $models[] = $model;
            $ids[] = $model->getAttribute($this->ownerKey);
        }

        $this->attach($ids);
        $this->parent->save();

        return new MongoDBCollection($models);
    }

    /**
     * Get the first related model.
     *
     * @return MongoDBModel|null
     */
    public function first(): ?MongoDBModel
    {
        return $this->getResults()->first();
    }

    /**
     * Find a related model by ID.
     *
     * @param string|ObjectId $id
     * @return MongoDBModel|null
     */
    public function find(string|ObjectId $id): ?MongoDBModel
    {
        $ids = $this->getRelatedIds();
        $searchId = (string) $this->toObjectId($id);

        // Check if the ID is in our references
        $found = false;
        foreach ($ids as $relatedId) {
            if ((string) $relatedId === $searchId) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return null;
        }

        return $this->related::find($id);
    }

    /**
     * Get the count of related models.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->getRelatedIds());
    }

    /**
     * Check if the relationship contains an ID.
     *
     * @param string|ObjectId $id
     * @return bool
     */
    public function contains(string|ObjectId $id): bool
    {
        $searchId = (string) $this->toObjectId($id);
        $ids = array_map(fn($id) => (string) $id, $this->getRelatedIds());

        return in_array($searchId, $ids, true);
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

        // Collect all IDs from all models
        $allIds = [];
        foreach ($this->eagerModels as $model) {
            $ids = $model->getAttribute($this->foreignKey) ?? [];
            foreach ($ids as $id) {
                $objectId = $this->toObjectId($id);
                $allIds[(string) $objectId] = $objectId;
            }
        }

        if (empty($allIds)) {
            return new MongoDBCollection([]);
        }

        // Query for all related models at once
        $query = $this->related::query()->whereIn($this->ownerKey, array_values($allIds));

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
            $ids = $model->getAttribute($this->foreignKey) ?? [];
            $matched = [];

            foreach ($ids as $id) {
                $key = (string) $this->toObjectId($id);
                if (isset($dictionary[$key])) {
                    $matched[] = $dictionary[$key];
                }
            }

            $model->setRelation($relation, new MongoDBCollection($matched));
        }
    }
}
