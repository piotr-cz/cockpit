<?php
namespace MongoMysqlJson;

/**
 * DB collection interface
 */
interface CollectionInterface
{
    /** @var DatabaseInterface */
    // public $database;

    /** @var string - Collection name */
    // public $name;

    /**
     * Find documents
     * Note: this is different than \MongoLite\Collection::find as it doesn't return Cursor iterator
     *
     * @param  array|callable|null $criteria - Filter results by
     *   @var int $limit=null
     *   @var int $sort=null
     *   @var int $skip=null
     * @param  array $projection
     * @return object Cursor|array
     */
    public function find($criteria = null, array $projection = null): array;

    /**
     * Find one document
     *
     * @param  array|callable|null $criteria
     * @param  ? $projection
     * @return array
     */
    public function findOne($criteria = null, array $projection = null): ?array;

    /**
     * Insert document
     */
    // public function insert(array &$document): bool;

    /**
     * Insert documents
     */
    public function insertMany(array $documents): void;

    /**
     * Insert or update depending on $document['_id']
     */
    // public function save(array &$document, bool $isCreate = false): bool;

    /**
     * Update documents
     *
     * @param callable|array $criteria
     * @param array $data
     * @param bool $isMerge
     */
    // public function update($criteria, array $data, bool $isMerge = true): int;

    /**
     * Remove document
     *
     * @param callable|array $criteria
     * @return bool
     */
    // public function remove($criteria): bool;

    /**
     * Count documents in collections
     * [called directly]
     * TODO: Move usage to Driver::count
     *
     * @param  callable|array|null $criteria
     * @return integer
     */
    public function count(array $criteria = null): int;

    /**
     * Rename collection
     * [called directly]
     *
     * @todo Move to Driver::renameCollection
     * @param  string $newname
     * @return boolean
     */
    public function renameCollection(string $newname): bool;

    /**
     * Drop collection
     * [called directly]
     * @todo Move usage to Driver::DropCollection
     */
    public function drop(): bool;
}