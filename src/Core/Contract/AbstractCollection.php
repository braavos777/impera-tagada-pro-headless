<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Contract;

use ArrayIterator;
use Countable;
use ImperaTagadaProHeadless\Core\Support\ArrayUtil;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * Simple Collection class to work with arrays in an object-oriented way.
 *
 */
abstract class AbstractCollection implements IteratorAggregate, Countable
{

    protected array $items;

    public function __construct(array $items = [])
    {
        $this->addMultiple($items);
    }

    abstract protected function getClassType(): string;

    abstract public function toArray(): array;

    abstract public static function fromArray(array $items): static;


    public static function create(array $items = []): static
    {
        return new static($items);
    }
    /**
     *
     * @param mixed $item
     * @return static
     */
    public function add(mixed $item): static
    {

        $this->checkItem($item);

        $this->items[] = $item;

        return $this;
    }

    /**
     *
     * @param mixed $items
     * @return static
     */
    public function addMultiple(array $items): static
    {
        array_map([$this, 'add'], $items);

        return $this;
    }

    /**
     * @param mixed $item
     * @param string $class
     * @return void
     */
    protected function checkItem(mixed $item): void
    {
        $collectionClass = $this->getClassType();

        if (!$item instanceof $collectionClass) {
            throw new InvalidArgumentException('All items must be instances of ' . esc_html($collectionClass));
        }
    }


    /** Return all items as array */
    public function all(): array
    {
        return $this->items;
    }

    /**
     *  Example:
     *  $collection = new Collection([1, 2, 2, 3, 4]);
     *
     *  $mapped = $collection->map(fn($item) => $item * 10);
     *  // [10, 20, 20, 30, 40]
     *
     * */
    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     *  Example:
     *  $collection = new Collection([1, 2, 2, 3, 4]);
     *
     *  $filtered = $collection->filter(fn($item) => $item > 2);
     *  // [3, 4]
     * */
    public function filter(callable $callback): static
    {
        return new static(array_filter($this->items, $callback));
    }

    /**
     *  Remove duplicate
     *  Example:
     *  $collection = new Collection([1, 2, 2, 3, 4]);
     *
     *  $unique = $collection->unique();
     *  // [1, 2, 3, 4]
     *
     * */
    public function unique(bool $keepKeys = false): static
    {
        return new static(ArrayUtil::unique($this->items, $keepKeys));
    }


    /** Sort items */
    public function sort(): static
    {
        $sorted = $this->items;
        sort($sorted);
        return new static($sorted);
    }

    /** Return first item */
    public function first()
    {
        return $this->items[array_key_first($this->items)] ?? null;
    }

    /** Return last item */
    public function last()
    {
        return $this->items[array_key_last($this->items)] ?? null;
    }


    /** Countable interface */
    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /** IteratorAggregate interface */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
