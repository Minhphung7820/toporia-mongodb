<?php

declare(strict_types=1);

namespace Toporia\MongoDB\ORM\Concerns;

use Toporia\MongoDB\ORM\MongoDBModel;
use Toporia\MongoDB\ORM\MongoDBCollection;
use MongoDB\BSON\ObjectId;

/**
 * Trait HasEmbeddedDocuments
 *
 * Provides support for embedded documents in MongoDB models.
 * Allows managing nested documents that are stored within the parent document.
 *
 * Features:
 * - Get/set embedded documents
 * - Push/pull operations on embedded arrays
 * - Type casting for embedded models
 * - Atomic updates for embedded documents
 *
 * @package toporia/mongodb
 */
trait HasEmbeddedDocuments
{
    /**
     * Define embedded document attributes and their model classes.
     *
     * Override in model to specify embedded document mappings:
     * ```php
     * protected static array $embeds = [
     *     'address' => Address::class,
     *     'items' => OrderItem::class,
     * ];
     * ```
     *
     * @var array<string, string>
     */
    protected static array $embeds = [];

    /**
     * Cache for hydrated embedded documents.
     *
     * @var array<string, mixed>
     */
    protected array $embeddedCache = [];

    /**
     * Get an embedded document or collection of documents.
     *
     * @param string $key Attribute key
     * @return MongoDBModel|MongoDBCollection|array<mixed>|null
     */
    public function getEmbedded(string $key): MongoDBModel|MongoDBCollection|array|null
    {
        // Check cache first
        if (isset($this->embeddedCache[$key])) {
            return $this->embeddedCache[$key];
        }

        $value = $this->getAttribute($key);

        if ($value === null) {
            return null;
        }

        // Check if we have a model class mapping
        $modelClass = static::$embeds[$key] ?? null;

        if ($modelClass === null) {
            return $value;
        }

        // Hydrate the embedded document(s)
        $hydrated = $this->hydrateEmbedded($value, $modelClass);
        $this->embeddedCache[$key] = $hydrated;

        return $hydrated;
    }

    /**
     * Set an embedded document or collection of documents.
     *
     * @param string $key Attribute key
     * @param MongoDBModel|MongoDBCollection|array<mixed>|null $value
     * @return void
     */
    public function setEmbedded(string $key, MongoDBModel|MongoDBCollection|array|null $value): void
    {
        // Clear cache
        unset($this->embeddedCache[$key]);

        if ($value === null) {
            $this->setAttribute($key, null);
            return;
        }

        // Convert models to arrays for storage
        if ($value instanceof MongoDBModel) {
            $this->setAttribute($key, $value->toArray());
        } elseif ($value instanceof MongoDBCollection) {
            $this->setAttribute($key, $value->toArray());
        } else {
            $this->setAttribute($key, $value);
        }
    }

    /**
     * Push a document onto an embedded array.
     *
     * @param string $key Attribute key
     * @param MongoDBModel|array<string, mixed> $document Document to push
     * @return void
     */
    public function pushEmbedded(string $key, MongoDBModel|array $document): void
    {
        // Clear cache
        unset($this->embeddedCache[$key]);

        $currentValue = $this->getAttribute($key) ?? [];

        if (!is_array($currentValue)) {
            $currentValue = [];
        }

        $documentArray = $document instanceof MongoDBModel ? $document->toArray() : $document;

        // Ensure embedded document has an _id
        if (!isset($documentArray['_id'])) {
            $documentArray['_id'] = new ObjectId();
        }

        $currentValue[] = $documentArray;
        $this->setAttribute($key, $currentValue);
    }

    /**
     * Push multiple documents onto an embedded array.
     *
     * @param string $key Attribute key
     * @param array<int, MongoDBModel|array<string, mixed>> $documents Documents to push
     * @return void
     */
    public function pushManyEmbedded(string $key, array $documents): void
    {
        foreach ($documents as $document) {
            $this->pushEmbedded($key, $document);
        }
    }

    /**
     * Pull (remove) a document from an embedded array by its _id.
     *
     * @param string $key Attribute key
     * @param string|ObjectId $documentId Document ID to remove
     * @return bool True if document was found and removed
     */
    public function pullEmbedded(string $key, string|ObjectId $documentId): bool
    {
        // Clear cache
        unset($this->embeddedCache[$key]);

        $currentValue = $this->getAttribute($key);

        if (!is_array($currentValue)) {
            return false;
        }

        $documentIdString = (string) $documentId;
        $found = false;

        $filtered = array_filter($currentValue, function ($doc) use ($documentIdString, &$found) {
            $docId = isset($doc['_id']) ? (string) $doc['_id'] : null;
            if ($docId === $documentIdString) {
                $found = true;
                return false;
            }
            return true;
        });

        if ($found) {
            $this->setAttribute($key, array_values($filtered));
        }

        return $found;
    }

    /**
     * Pull documents matching a condition from an embedded array.
     *
     * @param string $key Attribute key
     * @param array<string, mixed> $condition Conditions to match
     * @return int Number of documents removed
     */
    public function pullWhereEmbedded(string $key, array $condition): int
    {
        // Clear cache
        unset($this->embeddedCache[$key]);

        $currentValue = $this->getAttribute($key);

        if (!is_array($currentValue)) {
            return 0;
        }

        $removedCount = 0;
        $filtered = [];

        foreach ($currentValue as $doc) {
            if ($this->matchesCondition($doc, $condition)) {
                $removedCount++;
            } else {
                $filtered[] = $doc;
            }
        }

        if ($removedCount > 0) {
            $this->setAttribute($key, $filtered);
        }

        return $removedCount;
    }

    /**
     * Update an embedded document by its _id.
     *
     * @param string $key Attribute key
     * @param string|ObjectId $documentId Document ID to update
     * @param array<string, mixed> $updates Updates to apply
     * @return bool True if document was found and updated
     */
    public function updateEmbedded(string $key, string|ObjectId $documentId, array $updates): bool
    {
        // Clear cache
        unset($this->embeddedCache[$key]);

        $currentValue = $this->getAttribute($key);

        if (!is_array($currentValue)) {
            return false;
        }

        $documentIdString = (string) $documentId;
        $found = false;

        foreach ($currentValue as $index => $doc) {
            $docId = isset($doc['_id']) ? (string) $doc['_id'] : null;
            if ($docId === $documentIdString) {
                $currentValue[$index] = array_merge($doc, $updates);
                $found = true;
                break;
            }
        }

        if ($found) {
            $this->setAttribute($key, $currentValue);
        }

        return $found;
    }

    /**
     * Find an embedded document by its _id.
     *
     * @param string $key Attribute key
     * @param string|ObjectId $documentId Document ID to find
     * @return MongoDBModel|array<string, mixed>|null
     */
    public function findEmbedded(string $key, string|ObjectId $documentId): MongoDBModel|array|null
    {
        $documents = $this->getEmbedded($key);

        if ($documents === null) {
            return null;
        }

        $documentIdString = (string) $documentId;

        if ($documents instanceof MongoDBCollection) {
            foreach ($documents as $doc) {
                if ((string) $doc->getMongoId() === $documentIdString) {
                    return $doc;
                }
            }
        } elseif (is_array($documents)) {
            foreach ($documents as $doc) {
                $docId = is_array($doc) ? ($doc['_id'] ?? null) : null;
                if ($docId !== null && (string) $docId === $documentIdString) {
                    return $doc;
                }
            }
        }

        return null;
    }

    /**
     * Find embedded documents matching a condition.
     *
     * @param string $key Attribute key
     * @param array<string, mixed> $condition Conditions to match
     * @return MongoDBCollection|array<int, mixed>
     */
    public function findWhereEmbedded(string $key, array $condition): MongoDBCollection|array
    {
        $documents = $this->getEmbedded($key);

        if ($documents === null) {
            return [];
        }

        $matches = [];

        if ($documents instanceof MongoDBCollection) {
            foreach ($documents as $doc) {
                if ($this->matchesCondition($doc->toArray(), $condition)) {
                    $matches[] = $doc;
                }
            }
            return new MongoDBCollection($matches);
        }

        if (is_array($documents)) {
            foreach ($documents as $doc) {
                if ($this->matchesCondition($doc, $condition)) {
                    $matches[] = $doc;
                }
            }
        }

        return $matches;
    }

    /**
     * Count embedded documents.
     *
     * @param string $key Attribute key
     * @return int
     */
    public function countEmbedded(string $key): int
    {
        $value = $this->getAttribute($key);

        if ($value === null) {
            return 0;
        }

        if (!is_array($value)) {
            return 1;
        }

        return count($value);
    }

    /**
     * Check if embedded array is empty.
     *
     * @param string $key Attribute key
     * @return bool
     */
    public function isEmptyEmbedded(string $key): bool
    {
        return $this->countEmbedded($key) === 0;
    }

    /**
     * Clear all embedded documents from an array.
     *
     * @param string $key Attribute key
     * @return void
     */
    public function clearEmbedded(string $key): void
    {
        unset($this->embeddedCache[$key]);
        $this->setAttribute($key, []);
    }

    /**
     * Hydrate embedded document(s) into model instances.
     *
     * @param mixed $value Raw attribute value
     * @param string $modelClass Model class to hydrate into
     * @return MongoDBModel|MongoDBCollection
     */
    protected function hydrateEmbedded(mixed $value, string $modelClass): MongoDBModel|MongoDBCollection
    {
        // Single document (associative array)
        if (is_array($value) && $this->isAssociativeArray($value)) {
            return $this->hydrateEmbeddedModel($value, $modelClass);
        }

        // Multiple documents (indexed array)
        if (is_array($value)) {
            $models = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $models[] = $this->hydrateEmbeddedModel($item, $modelClass);
                }
            }
            return new MongoDBCollection($models);
        }

        // Already a model
        if ($value instanceof MongoDBModel) {
            return $value;
        }

        // Collection of models
        if ($value instanceof MongoDBCollection) {
            return $value;
        }

        // Fallback: wrap in empty collection
        return new MongoDBCollection([]);
    }

    /**
     * Hydrate a single embedded document into a model.
     *
     * @param array<string, mixed> $data Document data
     * @param string $modelClass Model class to instantiate
     * @return MongoDBModel
     */
    protected function hydrateEmbeddedModel(array $data, string $modelClass): MongoDBModel
    {
        /** @var MongoDBModel $model */
        $model = new $modelClass();
        $model->fillFromEmbedded($data);

        return $model;
    }

    /**
     * Check if document matches given conditions.
     *
     * @param array<string, mixed>|object $document Document to check
     * @param array<string, mixed> $condition Conditions to match
     * @return bool
     */
    protected function matchesCondition(array|object $document, array $condition): bool
    {
        $doc = is_object($document) ? (array) $document : $document;

        foreach ($condition as $field => $value) {
            $docValue = $doc[$field] ?? null;

            // Handle ObjectId comparison
            if ($value instanceof ObjectId && is_string($docValue)) {
                if ((string) $value !== $docValue) {
                    return false;
                }
                continue;
            }

            if ($docValue instanceof ObjectId && is_string($value)) {
                if ((string) $docValue !== $value) {
                    return false;
                }
                continue;
            }

            if ($docValue != $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if array is associative (not indexed).
     *
     * @param array<mixed> $array
     * @return bool
     */
    protected function isAssociativeArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
