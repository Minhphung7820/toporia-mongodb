<?php

declare(strict_types=1);

namespace Toporia\MongoDB\ORM;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use MongoDB\BSON\ObjectId;
use Traversable;

/**
 * MongoDBCollection
 *
 * A typed collection for MongoDB models with fluent methods for
 * filtering, transforming, and aggregating model data.
 *
 * Features:
 * - Type-safe collection of MongoDBModel instances
 * - Fluent chainable methods (map, filter, reduce, etc.)
 * - Find by ObjectId
 * - Pluck and key-by operations
 * - JSON serialization
 *
 * @template TModel of MongoDBModel
 * @implements IteratorAggregate<int, TModel>
 * @package toporia/mongodb
 */
class MongoDBCollection implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * The items contained in the collection.
     *
     * @var array<int, TModel>
     */
    protected array $items = [];

    /**
     * Create a new collection instance.
     *
     * @param array<int, TModel> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    /**
     * Create a new collection instance.
     *
     * @param array<int, TModel> $items
     * @return static
     */
    public static function make(array $items = []): static
    {
        return new static($items);
    }

    /**
     * Get all items in the collection.
     *
     * @return array<int, TModel>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get the first item in the collection.
     *
     * @return TModel|null
     */
    public function first(): ?MongoDBModel
    {
        return $this->items[0] ?? null;
    }

    /**
     * Get the last item in the collection.
     *
     * @return TModel|null
     */
    public function last(): ?MongoDBModel
    {
        if (empty($this->items)) {
            return null;
        }

        return $this->items[count($this->items) - 1];
    }

    /**
     * Get an item by index.
     *
     * @param int $index
     * @return TModel|null
     */
    public function get(int $index): ?MongoDBModel
    {
        return $this->items[$index] ?? null;
    }

    /**
     * Find a model by its ObjectId.
     *
     * @param string|ObjectId $id
     * @return TModel|null
     */
    public function find(string|ObjectId $id): ?MongoDBModel
    {
        $idString = (string) $id;

        foreach ($this->items as $item) {
            if ((string) $item->getMongoId() === $idString) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Find models matching a condition.
     *
     * @param string $key Attribute key
     * @param mixed $operator Operator or value
     * @param mixed|null $value Value (if operator provided)
     * @return static
     */
    public function where(string $key, mixed $operator, mixed $value = null): static
    {
        // If only two arguments, operator is the value
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->filter(function ($item) use ($key, $operator, $value) {
            $itemValue = $item->getAttribute($key);

            return match ($operator) {
                '=', '==' => $itemValue == $value,
                '===' => $itemValue === $value,
                '!=', '<>' => $itemValue != $value,
                '!==' => $itemValue !== $value,
                '>' => $itemValue > $value,
                '>=' => $itemValue >= $value,
                '<' => $itemValue < $value,
                '<=' => $itemValue <= $value,
                'in' => in_array($itemValue, (array) $value),
                'not_in' => !in_array($itemValue, (array) $value),
                default => $itemValue == $value,
            };
        });
    }

    /**
     * Filter items where attribute is null.
     *
     * @param string $key
     * @return static
     */
    public function whereNull(string $key): static
    {
        return $this->filter(fn($item) => $item->getAttribute($key) === null);
    }

    /**
     * Filter items where attribute is not null.
     *
     * @param string $key
     * @return static
     */
    public function whereNotNull(string $key): static
    {
        return $this->filter(fn($item) => $item->getAttribute($key) !== null);
    }

    /**
     * Filter items where attribute is in array.
     *
     * @param string $key
     * @param array<mixed> $values
     * @return static
     */
    public function whereIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => in_array($item->getAttribute($key), $values));
    }

    /**
     * Filter items where attribute is not in array.
     *
     * @param string $key
     * @param array<mixed> $values
     * @return static
     */
    public function whereNotIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => !in_array($item->getAttribute($key), $values));
    }

    /**
     * Filter items using a callback.
     *
     * @param callable(TModel, int): bool $callback
     * @return static
     */
    public function filter(callable $callback): static
    {
        $filtered = [];

        foreach ($this->items as $index => $item) {
            if ($callback($item, $index)) {
                $filtered[] = $item;
            }
        }

        return new static($filtered);
    }

    /**
     * Reject items using a callback.
     *
     * @param callable(TModel, int): bool $callback
     * @return static
     */
    public function reject(callable $callback): static
    {
        return $this->filter(fn($item, $index) => !$callback($item, $index));
    }

    /**
     * Map over items and return new collection.
     *
     * @template TResult
     * @param callable(TModel, int): TResult $callback
     * @return array<int, TResult>
     */
    public function map(callable $callback): array
    {
        $mapped = [];

        foreach ($this->items as $index => $item) {
            $mapped[] = $callback($item, $index);
        }

        return $mapped;
    }

    /**
     * Map over items and return a new MongoDBCollection.
     *
     * @param callable(TModel, int): TModel $callback
     * @return static
     */
    public function mapInto(callable $callback): static
    {
        return new static($this->map($callback));
    }

    /**
     * Execute a callback for each item.
     *
     * @param callable(TModel, int): void $callback
     * @return static
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $index => $item) {
            if ($callback($item, $index) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Reduce collection to a single value.
     *
     * @template TResult
     * @param callable(TResult, TModel, int): TResult $callback
     * @param TResult $initial
     * @return TResult
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $result = $initial;

        foreach ($this->items as $index => $item) {
            $result = $callback($result, $item, $index);
        }

        return $result;
    }

    /**
     * Pluck a single attribute from all items.
     *
     * @param string $key Attribute to pluck
     * @param string|null $keyBy Key the results by this attribute
     * @return array<mixed>
     */
    public function pluck(string $key, ?string $keyBy = null): array
    {
        $result = [];

        foreach ($this->items as $item) {
            $value = $item->getAttribute($key);

            if ($keyBy !== null) {
                $keyValue = $item->getAttribute($keyBy);
                if ($keyValue instanceof ObjectId) {
                    $keyValue = (string) $keyValue;
                }
                $result[$keyValue] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Key the collection by an attribute.
     *
     * @param string $key Attribute to key by
     * @return array<string, TModel>
     */
    public function keyBy(string $key): array
    {
        $result = [];

        foreach ($this->items as $item) {
            $keyValue = $item->getAttribute($key);
            if ($keyValue instanceof ObjectId) {
                $keyValue = (string) $keyValue;
            }
            $result[(string) $keyValue] = $item;
        }

        return $result;
    }

    /**
     * Group items by an attribute.
     *
     * @param string $key Attribute to group by
     * @return array<string, static>
     */
    public function groupBy(string $key): array
    {
        $grouped = [];

        foreach ($this->items as $item) {
            $groupKey = $item->getAttribute($key);
            if ($groupKey instanceof ObjectId) {
                $groupKey = (string) $groupKey;
            }
            $groupKey = (string) $groupKey;

            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [];
            }

            $grouped[$groupKey][] = $item;
        }

        $result = [];
        foreach ($grouped as $groupKey => $items) {
            $result[$groupKey] = new static($items);
        }

        return $result;
    }

    /**
     * Sort the collection by an attribute.
     *
     * @param string $key Attribute to sort by
     * @param string $direction 'asc' or 'desc'
     * @return static
     */
    public function sortBy(string $key, string $direction = 'asc'): static
    {
        $items = $this->items;

        usort($items, function ($a, $b) use ($key, $direction) {
            $aValue = $a->getAttribute($key);
            $bValue = $b->getAttribute($key);

            $comparison = $aValue <=> $bValue;

            return $direction === 'desc' ? -$comparison : $comparison;
        });

        return new static($items);
    }

    /**
     * Sort the collection by a callback.
     *
     * @param callable(TModel): mixed $callback
     * @param string $direction 'asc' or 'desc'
     * @return static
     */
    public function sortByCallback(callable $callback, string $direction = 'asc'): static
    {
        $items = $this->items;

        usort($items, function ($a, $b) use ($callback, $direction) {
            $aValue = $callback($a);
            $bValue = $callback($b);

            $comparison = $aValue <=> $bValue;

            return $direction === 'desc' ? -$comparison : $comparison;
        });

        return new static($items);
    }

    /**
     * Reverse the order of items.
     *
     * @return static
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items));
    }

    /**
     * Take the first n items.
     *
     * @param int $limit
     * @return static
     */
    public function take(int $limit): static
    {
        return new static(array_slice($this->items, 0, $limit));
    }

    /**
     * Skip the first n items.
     *
     * @param int $count
     * @return static
     */
    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count));
    }

    /**
     * Get a slice of items.
     *
     * @param int $offset
     * @param int|null $length
     * @return static
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length));
    }

    /**
     * Chunk the collection into smaller collections.
     *
     * @param int $size
     * @return array<int, static>
     */
    public function chunk(int $size): array
    {
        $chunks = [];

        foreach (array_chunk($this->items, $size) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return $chunks;
    }

    /**
     * Merge another collection into this one.
     *
     * @param MongoDBCollection|array<int, TModel> $items
     * @return static
     */
    public function merge(MongoDBCollection|array $items): static
    {
        $mergeItems = $items instanceof MongoDBCollection ? $items->all() : $items;

        return new static(array_merge($this->items, $mergeItems));
    }

    /**
     * Get unique items by attribute.
     *
     * @param string|null $key Attribute to check uniqueness (null for full object comparison)
     * @return static
     */
    public function unique(?string $key = null): static
    {
        if ($key === null) {
            // Use _id for uniqueness
            $key = '_id';
        }

        $seen = [];
        $unique = [];

        foreach ($this->items as $item) {
            $value = $item->getAttribute($key);
            if ($value instanceof ObjectId) {
                $value = (string) $value;
            }

            $valueKey = serialize($value);

            if (!isset($seen[$valueKey])) {
                $seen[$valueKey] = true;
                $unique[] = $item;
            }
        }

        return new static($unique);
    }

    /**
     * Check if any item passes the test.
     *
     * @param callable(TModel): bool $callback
     * @return bool
     */
    public function some(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all items pass the test.
     *
     * @param callable(TModel): bool $callback
     * @return bool
     */
    public function every(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if (!$callback($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the collection contains an item.
     *
     * @param string|ObjectId|callable $keyOrCallback
     * @param mixed $value
     * @return bool
     */
    public function contains(string|ObjectId|callable $keyOrCallback, mixed $value = null): bool
    {
        if (is_callable($keyOrCallback)) {
            return $this->some($keyOrCallback);
        }

        // Check by ObjectId
        if ($keyOrCallback instanceof ObjectId || (is_string($keyOrCallback) && ObjectId::isValid($keyOrCallback))) {
            return $this->find($keyOrCallback) !== null;
        }

        // Check by attribute
        foreach ($this->items as $item) {
            if ($item->getAttribute($keyOrCallback) == $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sum values of an attribute.
     *
     * @param string $key
     * @return float|int
     */
    public function sum(string $key): float|int
    {
        return array_sum($this->pluck($key));
    }

    /**
     * Get average of an attribute.
     *
     * @param string $key
     * @return float|int|null
     */
    public function avg(string $key): float|int|null
    {
        $values = $this->pluck($key);

        if (empty($values)) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    /**
     * Get minimum value of an attribute.
     *
     * @param string $key
     * @return mixed
     */
    public function min(string $key): mixed
    {
        $values = $this->pluck($key);

        return !empty($values) ? min($values) : null;
    }

    /**
     * Get maximum value of an attribute.
     *
     * @param string $key
     * @return mixed
     */
    public function max(string $key): mixed
    {
        $values = $this->pluck($key);

        return !empty($values) ? max($values) : null;
    }

    /**
     * Add an item to the collection.
     *
     * @param TModel $item
     * @return static
     */
    public function push(MongoDBModel $item): static
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * Add an item to the beginning.
     *
     * @param TModel $item
     * @return static
     */
    public function prepend(MongoDBModel $item): static
    {
        array_unshift($this->items, $item);

        return $this;
    }

    /**
     * Remove and return the last item.
     *
     * @return TModel|null
     */
    public function pop(): ?MongoDBModel
    {
        return array_pop($this->items);
    }

    /**
     * Remove and return the first item.
     *
     * @return TModel|null
     */
    public function shift(): ?MongoDBModel
    {
        return array_shift($this->items);
    }

    /**
     * Check if the collection is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Check if the collection is not empty.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Get the count of items.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Get model IDs as array of strings.
     *
     * @return array<int, string>
     */
    public function modelKeys(): array
    {
        return array_map(fn($item) => (string) $item->getMongoId(), $this->items);
    }

    /**
     * Get model IDs as ObjectIds.
     *
     * @return array<int, ObjectId>
     */
    public function modelObjectIds(): array
    {
        return array_filter(
            array_map(fn($item) => $item->getMongoId(), $this->items),
            fn($id) => $id !== null
        );
    }

    /**
     * Convert the collection to an array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn($item) => $item->toArray(), $this->items);
    }

    /**
     * Convert the collection to JSON.
     *
     * @param int $options
     * @return string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Get the iterator for foreach.
     *
     * @return Traversable<int, TModel>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Serialize for json_encode().
     *
     * @return array<int, array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert collection to string (JSON).
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}
