<?php

interface CollectionInterface
{
    /** @var DatabaseInterface */
    public $database;

    /** @var string - Collection name*/
    public $name;
  
    public function drop();

    public function insert(&$document);

    public function save(&$document);

    /**
     * Update documents
     */
    public function update($criteria, $data): int;

    public function remove($criteria);

    /**
     * Count documents in collections
     *
     * @param  mixed $criteria
     * @return integer
     */
    public function count($criteria = null): int

    /**
     * Find documents
     *
     * @param  array|null $criteria - Such as filter and sort
     * @param  ?
     * @return object Cursor
     */
    public function find(array $criteria = null, $projection = null);

    /**
     * Find one document
     *
     * @param  mixed $criteria
     * @param  ? $projection
     * @return array
     */
    public function findOne($criteria = null, $projection = null): ?array

    /**
     * Rename collection
     * @param  string $newname
     * @return boolean
     */
    public function renameCollection(string $newname): bool
}