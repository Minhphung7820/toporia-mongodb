<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Contracts;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Toporia\Framework\Database\Contracts\ModelInterface;

/**
 * MongoDB Model Interface
 *
 * Contract for MongoDB model implementations.
 * Extends the base ModelInterface with MongoDB-specific methods.
 *
 * Design Patterns:
 * - Interface Segregation: Extends base with MongoDB-specific operations
 * - Template Method: Defines structure for MongoDB models
 *
 * SOLID Principles:
 * - Liskov Substitution: Models are interchangeable where interface is used
 * - Dependency Inversion: Services depend on this abstraction
 *
 * @package toporia/mongodb
 * @author Phungtruong7820 <minhphung485@gmail.com>
 * @since 1.0.0
 */
interface MongoDBModelInterface extends ModelInterface
{
    /**
     * Get the MongoDB ObjectId for this model.
     *
     * Returns null for unsaved models.
     *
     * @return ObjectId|null The MongoDB ObjectId
     */
    public function getMongoId(): ?ObjectId;

    /**
     * Get the collection name for this model.
     *
     * @return string MongoDB collection name
     */
    public static function getCollectionName(): string;

    /**
     * Get the MongoDB collection instance.
     *
     * @return Collection MongoDB collection
     */
    public function getCollection(): Collection;

    /**
     * Get the MongoDB connection for this model.
     *
     * @return MongoDBConnectionInterface Connection instance
     */
    public static function getMongoConnection(): MongoDBConnectionInterface;

    /**
     * Get an embedded document by key.
     *
     * @param string $key Embedded document key
     * @return mixed Embedded document data
     */
    public function getEmbedded(string $key): mixed;

    /**
     * Set an embedded document.
     *
     * @param string $key Embedded document key
     * @param mixed $value Embedded document data
     * @return void
     */
    public function setEmbedded(string $key, mixed $value): void;

    /**
     * Push a value to an embedded array.
     *
     * @param string $key Embedded array key
     * @param mixed $value Value to push
     * @return bool True on success
     */
    public function pushEmbedded(string $key, mixed $value): bool;

    /**
     * Pull a value from an embedded array.
     *
     * @param string $key Embedded array key
     * @param mixed $value Value to pull (or query for matching documents)
     * @return bool True on success
     */
    public function pullEmbedded(string $key, mixed $value): bool;

    /**
     * Increment a field value atomically.
     *
     * @param string $field Field name
     * @param int|float $amount Amount to increment (negative for decrement)
     * @return bool True on success
     */
    public function increment(string $field, int|float $amount = 1): bool;

    /**
     * Decrement a field value atomically.
     *
     * @param string $field Field name
     * @param int|float $amount Amount to decrement
     * @return bool True on success
     */
    public function decrement(string $field, int|float $amount = 1): bool;

    /**
     * Unset (remove) fields from the document.
     *
     * @param string ...$fields Field names to unset
     * @return bool True on success
     */
    public function unset(string ...$fields): bool;

    /**
     * Get the raw BSON document.
     *
     * @return array<string, mixed> Raw document data
     */
    public function getRawDocument(): array;
}
