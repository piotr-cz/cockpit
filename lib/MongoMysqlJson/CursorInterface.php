<?php
/**
 * Docblocks from MongoLite\Cursor
 */
namespace MongoMysqlJson;

use Iterator;

interface CursorInterface extends Iterator
{
    public function __construct(CollectionInterface $collection, string $criteria, iterable $projection = null);

    /**
     * Documents count
     */
    public function count();

    /**
     * Set limit
     */
    public function limit(?int $limit): CursorInterface;

    public function sort(?array $sorts): CursorInterface;

    public function skip(?int $skip): CursorInterface;

    public function each(callable $callable): CursorInterface;

    public function toArray(): array;

    //// Iterator implementations
    // rewind, current, key, next, valid
}