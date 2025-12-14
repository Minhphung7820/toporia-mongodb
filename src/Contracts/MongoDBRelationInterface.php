<?php

declare(strict_types=1);

namespace Toporia\MongoDB\Contracts;

use Toporia\MongoDB\ORM\MongoDBCollection;
use Toporia\MongoDB\ORM\MongoDBModel;

/**
 * MongoDB Relation Interface
 *
 * Contract for MongoDB relationship implementations.
 * MongoDB supports both embedded documents and reference-based relationships.
 *
 * Relationship Types:
 * - EmbedsOne: Single embedded document (sub-document)
 * - EmbedsMany: Array of embedded documents
 * - ReferencesOne: Single document reference (stores ObjectId)
 * - ReferencesMany: Array of document references (stores ObjectIds)
 * - BelongsToReference: Inverse of ReferencesOne/ReferencesMany
 *
 * Design Patterns:
 * - Strategy Pattern: Different relationship types implement same interface
 * - Template Method: Base implementation with customization points
 *
 * @package toporia/mongodb
 * @author Phungtruong7820 <minhphung485@gmail.com>
 * @since 1.0.0
 */
interface MongoDBRelationInterface
{
    /**
     * Get the results of the relationship.
     *
     * @return MongoDBModel|MongoDBCollection|null Relationship results
     */
    public function getResults(): MongoDBModel|MongoDBCollection|null;

    /**
     * Get the related model class name.
     *
     * @return string Fully qualified class name
     */
    public function getRelatedClass(): string;

    /**
     * Get the parent model instance.
     *
     * @return MongoDBModel Parent model
     */
    public function getParent(): MongoDBModel;

    /**
     * Get the local key used for the relationship.
     *
     * @return string Local key name
     */
    public function getLocalKey(): string;

    /**
     * Get the foreign key used for the relationship.
     *
     * @return string|null Foreign key name (null for embedded relationships)
     */
    public function getForeignKey(): ?string;

    /**
     * Check if this is an embedded relationship.
     *
     * Embedded relationships store documents within the parent document.
     * No separate collection query is needed.
     *
     * @return bool True if embedded relationship
     */
    public function isEmbedded(): bool;

    /**
     * Add eager loading constraints for batch loading.
     *
     * Called during eager loading to add WHERE IN constraints
     * for loading relationships of multiple parent models at once.
     *
     * @param array<MongoDBModel> $models Parent models to load relationships for
     * @return void
     */
    public function addEagerConstraints(array $models): void;

    /**
     * Match eagerly loaded results to their parent models.
     *
     * Called after eager loading query to attach results to parents.
     *
     * @param array<MongoDBModel> $models Parent models
     * @param MongoDBCollection|array $results Eagerly loaded results
     * @param string $relationName Relationship name for setting on models
     * @return array<MongoDBModel> Models with relationships attached
     */
    public function match(array $models, MongoDBCollection|array $results, string $relationName): array;

    /**
     * Create a new instance for eager loading.
     *
     * @return static Fresh relation instance without constraints
     */
    public function newEagerInstance(): static;

    /**
     * Get the default value when relationship is empty.
     *
     * @return MongoDBModel|MongoDBCollection|null Default value
     */
    public function getDefaultValue(): MongoDBModel|MongoDBCollection|null;
}
