<?php
/**
 * Docblocks from MongoLite\Cursor
 */
namespace MongoMysqlJson;

/**
 * @see https://docs.mongodb.com/manual/reference/method/js-cursor/
 */
interface CursorInterface // extends \Iterator
{
    /**
     * Constructor
     * @param CollectionInterface $collection
     * @param array|callable|null $criteria
     * @param array $projection
     */
    public function __construct(CollectionInterface $collection, $criteria = null, array $projection = null);

    /**
     * Set limit
     */
    public function limit(?int $limit): CursorInterface;

    /**
     * Set sort fields
     */
    public function sort(?array $sorts): CursorInterface;

    /**
     * Set skip
     */
    public function skip(?int $skip): CursorInterface;

    /**
     * Loop through result set
     */
    public function each(callable $callable): CursorInterface;

    /**
     * Get documents matching criteria
     */
    public function toArray(): array;

    /**
     * Documents count
     */
    public function count(): int;

    //// Iterator implementations
    // rewind, current, key, next, valid
}