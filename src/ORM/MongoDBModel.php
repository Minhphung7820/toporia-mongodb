<?php

declare(strict_types=1);

namespace Toporia\MongoDB\ORM;

use Toporia\MongoDB\Contracts\MongoDBModelInterface;
use Toporia\MongoDB\Contracts\MongoDBConnectionInterface;
use Toporia\MongoDB\Query\MongoDBQueryBuilder;
use Toporia\MongoDB\Query\AggregationPipeline;
use Toporia\MongoDB\ORM\Concerns\HasMongoDBAttributes;
use Toporia\MongoDB\ORM\Concerns\HasEmbeddedDocuments;
use Toporia\MongoDB\ORM\Concerns\HasMongoDBRelationships;
use MongoDB\Collection;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use JsonSerializable;
use DateTime;

/**
 * MongoDBModel
 *
 * Base model class for MongoDB documents. Provides Active Record pattern
 * for MongoDB with support for embedded documents and references.
 *
 * Features:
 * - CRUD operations (save, delete, find)
 * - Embedded document relationships
 * - Reference relationships to other collections
 * - Attribute casting (ObjectId, DateTime, etc.)
 * - Timestamps (created_at, updated_at)
 * - Mass assignment protection
 * - Query builder integration
 *
 * @package toporia/mongodb
 */
abstract class MongoDBModel implements MongoDBModelInterface, JsonSerializable
{
    use HasMongoDBAttributes;
    use HasEmbeddedDocuments;
    use HasMongoDBRelationships;

    /**
     * MongoDB collection name.
     *
     * @var string
     */
    protected static string $collection = '';

    /**
     * Primary key field name.
     *
     * @var string
     */
    protected static string $primaryKey = '_id';

    /**
     * Whether the model uses timestamps.
     *
     * @var bool
     */
    protected static bool $timestamps = true;

    /**
     * Created at field name.
     *
     * @var string
     */
    protected static string $createdAt = 'created_at';

    /**
     * Updated at field name.
     *
     * @var string
     */
    protected static string $updatedAt = 'updated_at';

    /**
     * Mass assignable attributes (whitelist).
     *
     * @var array<int, string>
     */
    protected static array $fillable = [];

    /**
     * Protected attributes (blacklist).
     *
     * @var array<int, string>
     */
    protected static array $guarded = [];

    /**
     * Attribute casts.
     *
     * @var array<string, string>
     */
    protected static array $casts = [];

    /**
     * Hidden attributes (not included in toArray/toJson).
     *
     * @var array<int, string>
     */
    protected static array $hidden = [];

    /**
     * Visible attributes (only these included in toArray/toJson).
     *
     * @var array<int, string>
     */
    protected static array $visible = [];

    /**
     * Appended computed attributes.
     *
     * @var array<int, string>
     */
    protected static array $appends = [];

    /**
     * Connection name.
     *
     * @var string|null
     */
    protected static ?string $connection = null;

    /**
     * Model attributes.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * Original attributes (for dirty checking).
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * Whether the model exists in the database.
     *
     * @var bool
     */
    protected bool $exists = false;

    /**
     * Loaded relations.
     *
     * @var array<string, mixed>
     */
    protected array $relations = [];

    /**
     * The MongoDB connection instance.
     *
     * @var MongoDBConnectionInterface|null
     */
    protected static ?MongoDBConnectionInterface $resolver = null;

    /**
     * Booted model classes.
     *
     * @var array<string, bool>
     */
    protected static array $booted = [];

    /**
     * Create a new model instance.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        static::bootIfNotBooted();

        $this->fill($attributes);
        $this->syncOriginal();
    }

    /**
     * Boot the model if not already booted.
     *
     * @return void
     */
    protected static function bootIfNotBooted(): void
    {
        $class = static::class;

        if (!isset(static::$booted[$class])) {
            static::$booted[$class] = true;
            static::boot();
        }
    }

    /**
     * Bootstrap the model.
     *
     * Override this method to add custom boot logic.
     *
     * @return void
     */
    protected static function boot(): void
    {
        // Override in child classes for custom boot logic
    }

    /**
     * Set the connection resolver.
     *
     * @param MongoDBConnectionInterface $connection
     * @return void
     */
    public static function setConnectionResolver(MongoDBConnectionInterface $connection): void
    {
        static::$resolver = $connection;
    }

    /**
     * Get the connection resolver.
     *
     * @return MongoDBConnectionInterface
     * @throws \RuntimeException
     */
    public static function getConnectionResolver(): MongoDBConnectionInterface
    {
        if (static::$resolver === null) {
            throw new \RuntimeException(
                'MongoDB connection not configured. Call MongoDBModel::setConnectionResolver() first.'
            );
        }

        return static::$resolver;
    }

    /**
     * Get the MongoDB collection for this model.
     *
     * @return Collection
     */
    public static function getMongoCollection(): Collection
    {
        return static::getConnectionResolver()->collection(static::getTableName());
    }

    /**
     * Get the collection/table name.
     *
     * @return string
     */
    public static function getTableName(): string
    {
        if (!empty(static::$collection)) {
            return static::$collection;
        }

        // Auto-infer from class name
        $className = basename(str_replace('\\', '/', static::class));
        $className = preg_replace('/Model$/', '', $className);

        return static::pluralize(static::toSnakeCase($className));
    }

    /**
     * Get the primary key name.
     *
     * @return string
     */
    public static function getPrimaryKey(): string
    {
        return static::$primaryKey;
    }

    /**
     * Create a new query builder instance.
     *
     * @return MongoDBModelQueryBuilder
     */
    public static function query(): MongoDBModelQueryBuilder
    {
        static::bootIfNotBooted();

        return new MongoDBModelQueryBuilder(
            static::getMongoCollection(),
            static::class
        );
    }

    /**
     * Find a document by its primary key.
     *
     * @param string|ObjectId $id
     * @return static|null
     */
    public static function find(string|ObjectId $id): ?static
    {
        return static::query()->find($id);
    }

    /**
     * Find a document by its primary key or throw.
     *
     * @param string|ObjectId $id
     * @return static
     * @throws \RuntimeException
     */
    public static function findOrFail(string|ObjectId $id): static
    {
        $model = static::find($id);

        if ($model === null) {
            throw new \RuntimeException(sprintf(
                '%s with ID %s not found',
                static::class,
                (string) $id
            ));
        }

        return $model;
    }

    /**
     * Get all documents.
     *
     * @return MongoDBCollection<static>
     */
    public static function all(): MongoDBCollection
    {
        return static::query()->get();
    }

    /**
     * Create a new document.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * Insert multiple documents.
     *
     * @param array<int, array<string, mixed>> $documents
     * @return bool
     */
    public static function insert(array $documents): bool
    {
        if (empty($documents)) {
            return true;
        }

        $prepared = [];
        foreach ($documents as $doc) {
            $model = new static($doc);
            $prepared[] = $model->prepareForInsert();
        }

        $result = static::getMongoCollection()->insertMany($prepared);

        return $result->getInsertedCount() === count($documents);
    }

    /**
     * Save the model to the database.
     *
     * @return bool
     */
    public function save(): bool
    {
        if ($this->exists) {
            return $this->performUpdate();
        }

        return $this->performInsert();
    }

    /**
     * Perform insert operation.
     *
     * @return bool
     */
    protected function performInsert(): bool
    {
        // Fire creating event
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        // Set timestamps
        if (static::$timestamps) {
            $now = new UTCDateTime();
            $this->setAttribute(static::$createdAt, $now);
            $this->setAttribute(static::$updatedAt, $now);
        }

        // Generate ObjectId if not set
        if (!$this->getAttribute('_id')) {
            $this->setAttribute('_id', new ObjectId());
        }

        // Prepare document for MongoDB
        $document = $this->prepareForInsert();

        // Insert into database
        $result = static::getMongoCollection()->insertOne($document);

        if ($result->getInsertedCount() > 0) {
            $this->exists = true;
            $this->syncOriginal();

            // Fire created event
            $this->fireModelEvent('created');

            return true;
        }

        return false;
    }

    /**
     * Perform update operation.
     *
     * @return bool
     */
    protected function performUpdate(): bool
    {
        // Check if dirty
        if (!$this->isDirty()) {
            return true;
        }

        // Fire updating event
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // Update timestamp
        if (static::$timestamps) {
            $this->setAttribute(static::$updatedAt, new UTCDateTime());
        }

        // Get dirty attributes
        $dirty = $this->getDirtyForUpdate();

        if (empty($dirty)) {
            return true;
        }

        // Update in database
        $result = static::getMongoCollection()->updateOne(
            ['_id' => $this->getMongoId()],
            ['$set' => $dirty]
        );

        if ($result->getModifiedCount() > 0 || $result->getMatchedCount() > 0) {
            $this->syncOriginal();

            // Fire updated event
            $this->fireModelEvent('updated');

            return true;
        }

        return false;
    }

    /**
     * Delete the model from the database.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        // Fire deleting event
        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $result = static::getMongoCollection()->deleteOne([
            '_id' => $this->getMongoId()
        ]);

        if ($result->getDeletedCount() > 0) {
            $this->exists = false;

            // Fire deleted event
            $this->fireModelEvent('deleted');

            return true;
        }

        return false;
    }

    /**
     * Refresh the model from the database.
     *
     * @return self
     */
    public function refresh(): self
    {
        if (!$this->exists) {
            return $this;
        }

        $fresh = static::find($this->getMongoId());

        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->syncOriginal();
            $this->relations = [];
        }

        return $this;
    }

    /**
     * Increment a field value atomically.
     *
     * @param string $field
     * @param int|float $amount
     * @param array<string, mixed> $extra Extra updates
     * @return bool
     */
    public function increment(string $field, int|float $amount = 1, array $extra = []): bool
    {
        if (!$this->exists) {
            return false;
        }

        $update = ['$inc' => [$field => $amount]];

        if (!empty($extra)) {
            $update['$set'] = $extra;
        }

        if (static::$timestamps) {
            $update['$set'][static::$updatedAt] = new UTCDateTime();
        }

        $result = static::getMongoCollection()->updateOne(
            ['_id' => $this->getMongoId()],
            $update
        );

        if ($result->getModifiedCount() > 0) {
            $this->attributes[$field] = ($this->attributes[$field] ?? 0) + $amount;
            foreach ($extra as $key => $value) {
                $this->attributes[$key] = $value;
            }
            $this->syncOriginal();

            return true;
        }

        return false;
    }

    /**
     * Decrement a field value atomically.
     *
     * @param string $field
     * @param int|float $amount
     * @param array<string, mixed> $extra Extra updates
     * @return bool
     */
    public function decrement(string $field, int|float $amount = 1, array $extra = []): bool
    {
        return $this->increment($field, -$amount, $extra);
    }

    /**
     * Push a value to an array field atomically.
     *
     * @param string $field
     * @param mixed $value
     * @return bool
     */
    public function push(string $field, mixed $value): bool
    {
        if (!$this->exists) {
            return false;
        }

        $update = ['$push' => [$field => $value]];

        if (static::$timestamps) {
            $update['$set'] = [static::$updatedAt => new UTCDateTime()];
        }

        $result = static::getMongoCollection()->updateOne(
            ['_id' => $this->getMongoId()],
            $update
        );

        if ($result->getModifiedCount() > 0) {
            $current = $this->attributes[$field] ?? [];
            if (!is_array($current)) {
                $current = [];
            }
            $current[] = $value;
            $this->attributes[$field] = $current;
            $this->syncOriginal();

            return true;
        }

        return false;
    }

    /**
     * Pull a value from an array field atomically.
     *
     * @param string $field
     * @param mixed $value
     * @return bool
     */
    public function pull(string $field, mixed $value): bool
    {
        if (!$this->exists) {
            return false;
        }

        $update = ['$pull' => [$field => $value]];

        if (static::$timestamps) {
            $update['$set'] = [static::$updatedAt => new UTCDateTime()];
        }

        $result = static::getMongoCollection()->updateOne(
            ['_id' => $this->getMongoId()],
            $update
        );

        if ($result->getModifiedCount() > 0) {
            $current = $this->attributes[$field] ?? [];
            if (is_array($current)) {
                $this->attributes[$field] = array_values(array_filter(
                    $current,
                    fn($v) => $v != $value
                ));
            }
            $this->syncOriginal();

            return true;
        }

        return false;
    }

    /**
     * Execute a raw aggregation pipeline.
     *
     * @param array<int, array<string, mixed>> $pipeline
     * @return array<int, array<string, mixed>>
     */
    public static function raw(array $pipeline): array
    {
        return iterator_to_array(
            static::getMongoCollection()->aggregate($pipeline)
        );
    }

    /**
     * Start an aggregation pipeline builder.
     *
     * @return AggregationPipeline
     */
    public static function aggregate(): AggregationPipeline
    {
        return new AggregationPipeline(static::getMongoCollection());
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * Fill from embedded document (bypasses fillable checks).
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function fillFromEmbedded(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        $this->exists = true;
        $this->syncOriginal();

        return $this;
    }

    /**
     * Check if an attribute is fillable.
     *
     * @param string $key
     * @return bool
     */
    protected function isFillable(string $key): bool
    {
        // If fillable is defined, check whitelist
        if (!empty(static::$fillable)) {
            return in_array($key, static::$fillable, true);
        }

        // If guarded contains *, nothing is fillable
        if (in_array('*', static::$guarded, true)) {
            return false;
        }

        // If guarded is empty, everything is fillable
        if (empty(static::$guarded)) {
            return true;
        }

        // Otherwise, check blacklist
        return !in_array($key, static::$guarded, true);
    }

    /**
     * Get an attribute value.
     *
     * @param string $key
     * @return mixed
     */
    public function getAttribute(string $key): mixed
    {
        // Check for accessor
        $method = 'get' . $this->studlyCase($key) . 'Attribute';
        if (method_exists($this, $method)) {
            return $this->$method();
        }

        $value = $this->attributes[$key] ?? null;

        return $this->castMongoAttribute($key, $value);
    }

    /**
     * Set an attribute value.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setAttribute(string $key, mixed $value): void
    {
        // Check for mutator
        $method = 'set' . $this->studlyCase($key) . 'Attribute';
        if (method_exists($this, $method)) {
            $this->$method($value);
            return;
        }

        $this->attributes[$key] = $value;
    }

    /**
     * Get raw attribute without casting.
     *
     * @param string $key
     * @return mixed
     */
    public function getRawAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Get the model's primary key value.
     *
     * @return mixed
     */
    public function getKey(): mixed
    {
        return $this->getAttribute(static::$primaryKey);
    }

    /**
     * Check if the model exists in the database.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Check if the model is dirty (has changes).
     *
     * @param string|null $key
     * @return bool
     */
    public function isDirty(?string $key = null): bool
    {
        if ($key !== null) {
            return $this->originalIsEquivalent($key);
        }

        return !empty($this->getDirty());
    }

    /**
     * Get the dirty attributes.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } elseif (!$this->originalIsEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Get dirty attributes for update.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate(): array
    {
        $dirty = $this->getDirty();

        // Prepare values for MongoDB
        return $this->prepareForMongoDB($dirty);
    }

    /**
     * Check if original value is equivalent to current.
     *
     * @param string $key
     * @return bool
     */
    protected function originalIsEquivalent(string $key): bool
    {
        if (!array_key_exists($key, $this->original)) {
            return false;
        }

        $original = $this->original[$key];
        $current = $this->attributes[$key] ?? null;

        // Handle ObjectId comparison
        if ($original instanceof ObjectId || $current instanceof ObjectId) {
            return (string) $original === (string) $current;
        }

        // Handle UTCDateTime comparison
        if ($original instanceof UTCDateTime || $current instanceof UTCDateTime) {
            $originalTs = $original instanceof UTCDateTime ? $original->toDateTime()->getTimestamp() : $original;
            $currentTs = $current instanceof UTCDateTime ? $current->toDateTime()->getTimestamp() : $current;
            return $originalTs === $currentTs;
        }

        return $original === $current;
    }

    /**
     * Sync the original attributes with current.
     *
     * @return void
     */
    protected function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    /**
     * Get the original attributes.
     *
     * @param string|null $key
     * @return mixed
     */
    public function getOriginal(?string $key = null): mixed
    {
        if ($key !== null) {
            return $this->original[$key] ?? null;
        }

        return $this->original;
    }

    /**
     * Prepare document for insert.
     *
     * @return array<string, mixed>
     */
    protected function prepareForInsert(): array
    {
        return $this->prepareForMongoDB($this->attributes);
    }

    /**
     * Set a relation.
     *
     * @param string $name
     * @param mixed $value
     * @return static
     */
    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;

        return $this;
    }

    /**
     * Get a relation.
     *
     * @param string $name
     * @return mixed
     */
    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * Check if a relation is loaded.
     *
     * @param string $name
     * @return bool
     */
    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /**
     * Convert the model to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [];

        // Get attributes
        foreach ($this->attributes as $key => $value) {
            // Skip hidden attributes
            if (!empty(static::$visible) && !in_array($key, static::$visible)) {
                continue;
            }
            if (in_array($key, static::$hidden)) {
                continue;
            }

            // Convert BSON types
            $array[$key] = $this->convertForArray($value);
        }

        // Add appended attributes
        foreach (static::$appends as $append) {
            $array[$append] = $this->getAttribute($append);
        }

        // Add loaded relations
        foreach ($this->relations as $name => $relation) {
            if ($relation instanceof MongoDBCollection) {
                $array[$name] = $relation->toArray();
            } elseif ($relation instanceof MongoDBModel) {
                $array[$name] = $relation->toArray();
            } elseif (is_array($relation)) {
                $array[$name] = $relation;
            } else {
                $array[$name] = $relation;
            }
        }

        return $array;
    }

    /**
     * Convert value for array output.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function convertForArray(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string) $value;
        }

        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return array_map(fn($v) => $this->convertForArray($v), $value);
        }

        return $value;
    }

    /**
     * Convert the model to JSON.
     *
     * @param int $options
     * @return string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Serialize for json_encode().
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Fire a model event.
     *
     * @param string $event
     * @return mixed
     */
    protected function fireModelEvent(string $event): mixed
    {
        // Check for model method
        $method = $event;
        if (method_exists($this, $method)) {
            return $this->$method();
        }

        return true;
    }

    /**
     * Convert string to StudlyCase.
     *
     * @param string $value
     * @return string
     */
    protected function studlyCase(string $value): string
    {
        $value = str_replace(['_', '-'], ' ', $value);
        $value = ucwords($value);

        return str_replace(' ', '', $value);
    }

    /**
     * Convert string to snake_case.
     *
     * @param string $value
     * @return string
     */
    protected static function toSnakeCase(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        return strtolower($value);
    }

    /**
     * Pluralize a word.
     *
     * @param string $word
     * @return string
     */
    protected static function pluralize(string $word): string
    {
        $irregulars = [
            'person' => 'people',
            'man' => 'men',
            'woman' => 'women',
            'child' => 'children',
        ];

        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        if (preg_match('/(s|x|z|ch|sh)$/', $word)) {
            return $word . 'es';
        } elseif (preg_match('/[^aeiou]y$/', $word)) {
            return substr($word, 0, -1) . 'ies';
        }

        return $word . 's';
    }

    /**
     * Magic getter.
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        // Check relations first
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        // Check if it's a relationship method
        if (method_exists($this, $key)) {
            $relation = $this->$key();
            if ($relation instanceof \Toporia\MongoDB\Contracts\MongoDBRelationInterface) {
                $results = $relation->getResults();
                $this->relations[$key] = $results;
                return $results;
            }
        }

        return $this->getAttribute($key);
    }

    /**
     * Magic setter.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Magic isset.
     *
     * @param string $key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) || isset($this->relations[$key]);
    }

    /**
     * Magic unset.
     *
     * @param string $key
     * @return void
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    /**
     * Convert to string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Handle dynamic static method calls.
     *
     * @param string $method
     * @param array<mixed> $parameters
     * @return mixed
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::query()->$method(...$parameters);
    }

    /**
     * Handle dynamic method calls.
     *
     * @param string $method
     * @param array<mixed> $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return static::query()->$method(...$parameters);
    }
}
