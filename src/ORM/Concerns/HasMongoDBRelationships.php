<?php

declare(strict_types=1);

namespace Toporia\MongoDB\ORM\Concerns;

use Toporia\MongoDB\Relations\EmbedsOne;
use Toporia\MongoDB\Relations\EmbedsMany;
use Toporia\MongoDB\Relations\ReferencesOne;
use Toporia\MongoDB\Relations\ReferencesMany;
use Toporia\MongoDB\Relations\BelongsToReference;

/**
 * Trait HasMongoDBRelationships
 *
 * Provides MongoDB-specific relationship methods for models.
 * Supports both embedded document relationships and document reference relationships.
 *
 * Relationship Types:
 * - EmbedsOne: Single embedded document stored within the parent
 * - EmbedsMany: Array of embedded documents stored within the parent
 * - ReferencesOne: Reference to a single document in another collection
 * - ReferencesMany: References to multiple documents in another collection
 * - BelongsToReference: Inverse of ReferencesOne/ReferencesMany
 *
 * @package toporia/mongodb
 */
trait HasMongoDBRelationships
{
    /**
     * Define an embedded one-to-one relationship.
     *
     * The related model is stored as a sub-document within this model.
     * No separate query is needed to access it.
     *
     * @param string $related Related model class
     * @param string|null $localKey Local attribute key (defaults to relation method name)
     * @return EmbedsOne
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
     * // Usage
     * $order->shippingAddress; // Returns Address model
     * $order->shippingAddress()->create(['city' => 'NYC']);
     * ```
     */
    public function embedsOne(string $related, ?string $localKey = null): EmbedsOne
    {
        $localKey = $localKey ?? $this->guessRelationKey();

        return new EmbedsOne($this, $related, $localKey);
    }

    /**
     * Define an embedded one-to-many relationship.
     *
     * The related models are stored as an array of sub-documents.
     * No separate query is needed to access them.
     *
     * @param string $related Related model class
     * @param string|null $localKey Local attribute key (defaults to relation method name)
     * @return EmbedsMany
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
     * // Usage
     * $order->items; // Returns MongoDBCollection of OrderItem models
     * $order->items()->create(['product_id' => 'xyz', 'qty' => 2]);
     * $order->items()->find($itemId);
     * ```
     */
    public function embedsMany(string $related, ?string $localKey = null): EmbedsMany
    {
        $localKey = $localKey ?? $this->guessRelationKey();

        return new EmbedsMany($this, $related, $localKey);
    }

    /**
     * Define a reference one-to-one relationship.
     *
     * Stores an ObjectId reference to a document in another collection.
     * Requires a query to retrieve the related document.
     *
     * @param string $related Related model class
     * @param string|null $foreignKey Foreign key in this model (stores the ObjectId)
     * @param string|null $ownerKey Primary key in the related model (defaults to '_id')
     * @return ReferencesOne
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
     * // Usage
     * $post->author; // Returns User model
     * $post->author_id = $user->getMongoId();
     * Post::with('author')->get(); // Eager load
     * ```
     */
    public function referencesOne(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ReferencesOne
    {
        $foreignKey = $foreignKey ?? $this->guessReferenceKey($related);
        $ownerKey = $ownerKey ?? '_id';

        return new ReferencesOne($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Define a reference one-to-many relationship.
     *
     * Stores an array of ObjectId references to documents in another collection.
     * Requires a query to retrieve the related documents.
     *
     * @param string $related Related model class
     * @param string|null $foreignKey Foreign key in this model (stores array of ObjectIds)
     * @param string|null $ownerKey Primary key in the related model (defaults to '_id')
     * @return ReferencesMany
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
     * // Usage
     * $post->tags; // Returns MongoDBCollection of Tag models
     * $post->tags()->attach($tagId);
     * $post->tags()->sync([$tagId1, $tagId2]);
     * Post::with('tags')->get(); // Eager load
     * ```
     */
    public function referencesMany(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ReferencesMany
    {
        $foreignKey = $foreignKey ?? $this->guessReferenceManyKey($related);
        $ownerKey = $ownerKey ?? '_id';

        return new ReferencesMany($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Define an inverse reference relationship (belongs to).
     *
     * The related model stores the reference to this model.
     * This is the inverse of referencesOne/referencesMany.
     *
     * @param string $related Related model class
     * @param string|null $foreignKey Foreign key in the related model
     * @param string|null $ownerKey Primary key in this model (defaults to '_id')
     * @return BelongsToReference
     *
     * @example
     * ```php
     * class Comment extends MongoDBModel
     * {
     *     public function post(): BelongsToReference
     *     {
     *         return $this->belongsToReference(Post::class, 'post_id');
     *     }
     * }
     *
     * // Usage
     * $comment->post; // Returns Post model
     * Comment::with('post')->get(); // Eager load
     * ```
     */
    public function belongsToReference(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsToReference
    {
        $foreignKey = $foreignKey ?? $this->guessReferenceKey($related);
        $ownerKey = $ownerKey ?? '_id';

        return new BelongsToReference($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Guess the local key name for embedded relationships.
     *
     * Uses the calling method name converted to snake_case.
     *
     * @return string
     */
    protected function guessRelationKey(): string
    {
        // Get the calling method name
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['function'] ?? '';

        return $this->toSnakeCaseRelation($caller);
    }

    /**
     * Guess the foreign key name for reference relationships.
     *
     * Uses the related model's short name + '_id'.
     *
     * @param string $related Related model class
     * @return string
     */
    protected function guessReferenceKey(string $related): string
    {
        // Get short class name
        $shortName = class_exists($related) ? (new \ReflectionClass($related))->getShortName() : basename(str_replace('\\', '/', $related));

        // Remove 'Model' suffix if present
        $shortName = preg_replace('/Model$/', '', $shortName);

        return $this->toSnakeCaseRelation($shortName) . '_id';
    }

    /**
     * Guess the foreign key name for references many relationships.
     *
     * Uses the related model's short name + '_ids'.
     *
     * @param string $related Related model class
     * @return string
     */
    protected function guessReferenceManyKey(string $related): string
    {
        // Get short class name
        $shortName = class_exists($related) ? (new \ReflectionClass($related))->getShortName() : basename(str_replace('\\', '/', $related));

        // Remove 'Model' suffix if present
        $shortName = preg_replace('/Model$/', '', $shortName);

        return $this->toSnakeCaseRelation($shortName) . '_ids';
    }

    /**
     * Convert string to snake_case.
     *
     * @param string $value
     * @return string
     */
    protected function toSnakeCaseRelation(string $value): string
    {
        // Insert underscore before uppercase letters
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        return strtolower($value);
    }

    /**
     * Get the relation resolver for a relation.
     *
     * @param string $relation Relation name
     * @return \Toporia\MongoDB\Contracts\MongoDBRelationInterface|null
     */
    public function getRelationshipFromMethod(string $relation): mixed
    {
        if (!method_exists($this, $relation)) {
            return null;
        }

        return $this->$relation();
    }

    /**
     * Load relationships into the model.
     *
     * @param string|array<string> $relations Relations to load
     * @return static
     */
    public function load(string|array $relations): static
    {
        $relations = is_string($relations) ? [$relations] : $relations;

        foreach ($relations as $relation) {
            $this->loadRelation($relation);
        }

        return $this;
    }

    /**
     * Load a single relationship into the model.
     *
     * @param string $relation Relation name
     * @return void
     */
    protected function loadRelation(string $relation): void
    {
        $relationInstance = $this->getRelationshipFromMethod($relation);

        if ($relationInstance !== null) {
            $results = $relationInstance->getResults();
            $this->setRelation($relation, $results);
        }
    }
}
