<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Contracts;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use Toporia\MongoDB\Query\MongoDBQueryBuilder;

/**
 * MongoDB Connection Interface
 *
 * Contract for MongoDB connection implementations.
 * Provides a consistent API for interacting with MongoDB databases.
 *
 * Design Patterns:
 * - Interface Segregation: Focused contract for MongoDB operations
 * - Dependency Inversion: High-level code depends on this abstraction
 *
 * SOLID Principles:
 * - Single Responsibility: Connection management only
 * - Open/Closed: Extendable via implementations
 * - Liskov Substitution: All implementations are interchangeable
 *
 * @package toporia/mongodb
 * @author Phungtruong7820 <minhphung485@gmail.com>
 * @since 1.0.0
 */
interface MongoDBConnectionInterface
{
    /**
     * Get the underlying MongoDB Client instance.
     *
     * The client manages the connection pool and provides access
     * to databases and collections.
     *
     * @return Client MongoDB client instance
     */
    public function getClient(): Client;

    /**
     * Get the current database instance.
     *
     * Returns the database specified in the connection configuration.
     *
     * @return Database MongoDB database instance
     */
    public function getDatabase(): Database;

    /**
     * Get a collection from the current database.
     *
     * @param string $name Collection name
     * @return Collection MongoDB collection instance
     */
    public function collection(string $name): Collection;

    /**
     * Get the connection configuration.
     *
     * @return array<string, mixed> Connection configuration array
     */
    public function getConfig(): array;

    /**
     * Get the database name.
     *
     * @return string Database name
     */
    public function getDatabaseName(): string;

    /**
     * Get the driver name.
     *
     * @return string Always returns 'mongodb'
     */
    public function getDriverName(): string;

    /**
     * Create a new query builder for a collection.
     *
     * @param string $collection Collection name
     * @return MongoDBQueryBuilder Query builder instance
     */
    public function table(string $collection): MongoDBQueryBuilder;

    /**
     * Begin a database transaction.
     *
     * MongoDB transactions require replica set or sharded cluster (MongoDB 4.0+).
     * Single-node deployments do not support transactions.
     *
     * @return bool True if transaction started successfully
     * @throws \RuntimeException If transactions are not supported
     */
    public function beginTransaction(): bool;

    /**
     * Commit the current transaction.
     *
     * @return bool True if commit successful
     * @throws \RuntimeException If no active transaction
     */
    public function commit(): bool;

    /**
     * Rollback the current transaction.
     *
     * @return bool True if rollback successful
     * @throws \RuntimeException If no active transaction
     */
    public function rollback(): bool;

    /**
     * Check if currently in a transaction.
     *
     * @return bool True if inside a transaction
     */
    public function inTransaction(): bool;

    /**
     * Execute a callback within a transaction.
     *
     * Automatically handles begin/commit/rollback.
     * Supports retry on transient errors.
     *
     * @param callable $callback Callback to execute
     * @param int $attempts Maximum retry attempts on transient errors
     * @return mixed Callback return value
     * @throws \Throwable On non-transient errors or max retries exceeded
     */
    public function transaction(callable $callback, int $attempts = 1): mixed;

    /**
     * Disconnect from the database.
     *
     * @return void
     */
    public function disconnect(): void;

    /**
     * Reconnect to the database.
     *
     * @return void
     */
    public function reconnect(): void;

    /**
     * Ping the database to check connectivity.
     *
     * @return bool True if connection is alive
     */
    public function ping(): bool;

    /**
     * Get server information.
     *
     * @return array<string, mixed> Server build info and version
     */
    public function getServerInfo(): array;

    /**
     * List all collections in the current database.
     *
     * @return array<string> Collection names
     */
    public function listCollections(): array;

    /**
     * Drop a collection from the database.
     *
     * @param string $name Collection name
     * @return bool True if dropped successfully
     */
    public function dropCollection(string $name): bool;

    /**
     * Create a new collection.
     *
     * @param string $name Collection name
     * @param array<string, mixed> $options Collection options (capped, size, etc.)
     * @return bool True if created successfully
     */
    public function createCollection(string $name, array $options = []): bool;

    /**
     * Run a MongoDB command on the database.
     *
     * @param array<string, mixed> $command Command document
     * @return array<string, mixed> Command result
     */
    public function command(array $command): array;
}
