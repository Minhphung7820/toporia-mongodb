<?php

declare(strict_types=1);

namespace Toporia\MongoDB\ORM\Concerns;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Decimal128;
use DateTime;
use DateTimeInterface;
use DateTimeImmutable;

/**
 * Trait HasMongoDBAttributes
 *
 * Provides MongoDB-specific attribute handling for Model.
 * Handles BSON type conversions and MongoDB-specific casting.
 *
 * Features:
 * - ObjectId handling and conversion
 * - UTCDateTime to PHP DateTime conversion
 * - BSON type casting
 * - MongoDB-specific attribute operations
 *
 * @package toporia/mongodb
 */
trait HasMongoDBAttributes
{
    /**
     * MongoDB-specific casts in addition to standard casts.
     *
     * @var array<string, string>
     */
    protected static array $mongoCasts = [
        '_id' => 'objectid',
    ];

    /**
     * Get the MongoDB ObjectId for the model.
     *
     * @return ObjectId|null
     */
    public function getMongoId(): ?ObjectId
    {
        $id = $this->getAttribute('_id');

        if ($id instanceof ObjectId) {
            return $id;
        }

        if (is_string($id) && ObjectId::isValid($id)) {
            return new ObjectId($id);
        }

        return null;
    }

    /**
     * Get the model's primary key value as string.
     *
     * @return string|null
     */
    public function getMongoIdString(): ?string
    {
        $id = $this->getMongoId();

        return $id !== null ? (string) $id : null;
    }

    /**
     * Cast a MongoDB attribute to its native type.
     *
     * Extends standard casting with MongoDB BSON types:
     * - objectid: MongoDB\BSON\ObjectId
     * - datetime: Convert UTCDateTime to PHP DateTime
     * - utcdatetime: Keep as UTCDateTime
     * - binary: MongoDB\BSON\Binary
     * - decimal: MongoDB\BSON\Decimal128
     *
     * @param string $key Attribute key
     * @param mixed $value Value to cast
     * @return mixed Casted value
     */
    protected function castMongoAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Check MongoDB-specific casts first
        $cast = static::$mongoCasts[$key] ?? static::$casts[$key] ?? null;

        if ($cast === null) {
            // Auto-convert BSON types
            return $this->convertBsonType($value);
        }

        return match ($cast) {
            'objectid' => $this->castToObjectId($value),
            'datetime', 'date' => $this->castToDateTime($value),
            'utcdatetime' => $this->castToUtcDateTime($value),
            'timestamp' => $this->castToTimestamp($value),
            'binary' => $this->castToBinary($value),
            'decimal' => $this->castToDecimal($value),
            'int', 'integer' => is_int($value) ? $value : (int) $value,
            'float', 'double' => is_float($value) ? $value : (float) $value,
            'string' => is_string($value) ? $value : (string) $value,
            'bool', 'boolean' => is_bool($value) ? $value : (bool) $value,
            'array' => $this->castToArray($value),
            'json', 'object' => $this->castToObject($value),
            default => $value,
        };
    }

    /**
     * Cast value to ObjectId.
     *
     * @param mixed $value
     * @return ObjectId|mixed
     */
    protected function castToObjectId(mixed $value): mixed
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
     * Cast value to PHP DateTime.
     *
     * @param mixed $value
     * @return DateTime|mixed
     */
    protected function castToDateTime(mixed $value): mixed
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        if ($value instanceof DateTimeImmutable) {
            return DateTime::createFromImmutable($value);
        }

        if ($value instanceof UTCDateTime) {
            return $value->toDateTime();
        }

        if (is_string($value)) {
            return new DateTime($value);
        }

        if (is_int($value)) {
            return (new DateTime())->setTimestamp($value);
        }

        return $value;
    }

    /**
     * Cast value to UTCDateTime.
     *
     * @param mixed $value
     * @return UTCDateTime|mixed
     */
    protected function castToUtcDateTime(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return new UTCDateTime($value);
        }

        if (is_string($value)) {
            return new UTCDateTime(new DateTime($value));
        }

        if (is_int($value)) {
            return new UTCDateTime($value * 1000); // MongoDB uses milliseconds
        }

        return $value;
    }

    /**
     * Cast value to Unix timestamp.
     *
     * @param mixed $value
     * @return int|mixed
     */
    protected function castToTimestamp(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }

        if ($value instanceof UTCDateTime) {
            return (int) ($value->toDateTime()->getTimestamp());
        }

        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_string($value)) {
            return (new DateTime($value))->getTimestamp();
        }

        return $value;
    }

    /**
     * Cast value to Binary.
     *
     * @param mixed $value
     * @return Binary|mixed
     */
    protected function castToBinary(mixed $value): mixed
    {
        if ($value instanceof Binary) {
            return $value;
        }

        if (is_string($value)) {
            return new Binary($value, Binary::TYPE_GENERIC);
        }

        return $value;
    }

    /**
     * Cast value to Decimal128.
     *
     * @param mixed $value
     * @return Decimal128|mixed
     */
    protected function castToDecimal(mixed $value): mixed
    {
        if ($value instanceof Decimal128) {
            return $value;
        }

        if (is_numeric($value)) {
            return new Decimal128((string) $value);
        }

        return $value;
    }

    /**
     * Cast value to array.
     *
     * @param mixed $value
     * @return array<mixed>|mixed
     */
    protected function castToArray(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [$value];
        }

        return $value;
    }

    /**
     * Cast value to object.
     *
     * @param mixed $value
     * @return object|mixed
     */
    protected function castToObject(mixed $value): mixed
    {
        if (is_object($value)) {
            return $value;
        }

        if (is_array($value)) {
            return (object) $value;
        }

        if (is_string($value)) {
            return json_decode($value);
        }

        return $value;
    }

    /**
     * Automatically convert BSON types to PHP native types.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function convertBsonType(mixed $value): mixed
    {
        // ObjectId -> string (for comparison and display)
        if ($value instanceof ObjectId) {
            return $value;
        }

        // UTCDateTime -> PHP DateTime
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime();
        }

        // Decimal128 -> string (preserve precision)
        if ($value instanceof Decimal128) {
            return (string) $value;
        }

        // Binary -> raw data
        if ($value instanceof Binary) {
            return $value->getData();
        }

        // Regex -> pattern string
        if ($value instanceof Regex) {
            return $value->getPattern();
        }

        // Recursively convert arrays/documents
        if (is_array($value)) {
            return array_map(fn($v) => $this->convertBsonType($v), $value);
        }

        return $value;
    }

    /**
     * Prepare attributes for MongoDB storage.
     *
     * Converts PHP types to BSON types for insertion/update.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function prepareForMongoDB(array $attributes): array
    {
        $prepared = [];

        foreach ($attributes as $key => $value) {
            $prepared[$key] = $this->prepareBsonValue($key, $value);
        }

        return $prepared;
    }

    /**
     * Prepare a single value for MongoDB storage.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function prepareBsonValue(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $cast = static::$mongoCasts[$key] ?? static::$casts[$key] ?? null;

        // Apply cast-specific preparation
        if ($cast !== null) {
            return match ($cast) {
                'objectid' => $this->prepareObjectId($value),
                'datetime', 'date', 'utcdatetime' => $this->prepareDateTime($value),
                'timestamp' => $this->prepareTimestamp($value),
                'decimal' => $this->prepareDecimal($value),
                'array' => $this->prepareArray($value),
                'json', 'object' => is_string($value) ? json_decode($value, true) : (array) $value,
                default => $value,
            };
        }

        // Auto-prepare common types
        if ($value instanceof DateTimeInterface && !$value instanceof UTCDateTime) {
            return new UTCDateTime($value);
        }

        if (is_array($value)) {
            return $this->prepareArray($value);
        }

        return $value;
    }

    /**
     * Prepare ObjectId for storage.
     *
     * @param mixed $value
     * @return ObjectId|mixed
     */
    protected function prepareObjectId(mixed $value): mixed
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
     * Prepare DateTime for MongoDB storage.
     *
     * @param mixed $value
     * @return UTCDateTime|mixed
     */
    protected function prepareDateTime(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return new UTCDateTime($value);
        }

        if (is_string($value)) {
            return new UTCDateTime(new DateTime($value));
        }

        if (is_int($value)) {
            return new UTCDateTime($value * 1000);
        }

        return $value;
    }

    /**
     * Prepare timestamp for MongoDB storage.
     *
     * @param mixed $value
     * @return UTCDateTime|mixed
     */
    protected function prepareTimestamp(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            return $value;
        }

        if (is_int($value)) {
            return new UTCDateTime($value * 1000);
        }

        if ($value instanceof DateTimeInterface) {
            return new UTCDateTime($value);
        }

        return $value;
    }

    /**
     * Prepare Decimal128 for storage.
     *
     * @param mixed $value
     * @return Decimal128|mixed
     */
    protected function prepareDecimal(mixed $value): mixed
    {
        if ($value instanceof Decimal128) {
            return $value;
        }

        if (is_numeric($value)) {
            return new Decimal128((string) $value);
        }

        return $value;
    }

    /**
     * Prepare array for MongoDB storage.
     *
     * Recursively prepares nested values.
     *
     * @param mixed $value
     * @return array<mixed>
     */
    protected function prepareArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [$value];
        }

        $prepared = [];
        foreach ($value as $k => $v) {
            if ($v instanceof DateTimeInterface && !$v instanceof UTCDateTime) {
                $prepared[$k] = new UTCDateTime($v);
            } elseif (is_array($v)) {
                $prepared[$k] = $this->prepareArray($v);
            } else {
                $prepared[$k] = $v;
            }
        }

        return $prepared;
    }

    /**
     * Generate a new ObjectId.
     *
     * @return ObjectId
     */
    public static function newObjectId(): ObjectId
    {
        return new ObjectId();
    }

    /**
     * Check if a value is a valid ObjectId string.
     *
     * @param mixed $value
     * @return bool
     */
    public static function isValidObjectId(mixed $value): bool
    {
        if ($value instanceof ObjectId) {
            return true;
        }

        return is_string($value) && ObjectId::isValid($value);
    }
}
