<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Generator;

use IteratorAggregate;
use Traversable;


/*
 *
function ordersGenerator(): Generator
{
    global $wpdb;

    $offset = 0;
    $limit  = 1000;

    while (true) {

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM big_table
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        if (!$rows) {
            break;
        }

        foreach ($rows as $row) {
            yield $row;
        }

        $offset += $limit;
    }
}
$lazy = new LazyCollection(
    ordersGenerator()
);

foreach ($lazy->chunk(500) as $chunk) {
    // processing
}
 *
 * */
final class LazyPipeline implements IteratorAggregate
{
    public function __construct(
        private iterable $items
    ) {}

    public function getIterator(): Traversable
    {
        yield from $this->items;
    }

    public function chunk(int $size): self
    {
        return new self((function () use ($size) {
            $chunk = [];

            foreach ($this as $item) {
                $chunk[] = $item;

                if (count($chunk) >= $size) {
                    yield $chunk;
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                yield $chunk;
            }
        })());
    }
}


