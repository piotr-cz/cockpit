<?php
namespace MongoMysqlJson;

use \MysqlJson\CollectionInterface;

use \MongoHybrid\ResultSet;

/**
 * DB driver interface
 */
interface DriverInterface
{
    /**
     * Get collection by name
     * @param string $name - Collection name
     * @param string $db - Alternative database name
     * @return \MysqlJson\CollectionInterface
     */
    public function getCollection(string $name, string $db = null): CollectionInterface;

    /**
     * @deprecated [NOT USED] instead MongoHybrid\Client uses CollectionInterface::drop
     */
    public function dropCollection(string $name, string $db = null): void;

    //// Wrappers

    /**
     * Wrapper around CollectionInterface::find
     * @deprecated use DriverInterface::getCollection()->find()
     *
     * @param string collectionName
     * @param array $options {
     *  @var array [$filter] - Filter results by (criteria)
     *  @var array [$fields] - Array of fields to exclude or include from result document (projection)
     *                         Limits the fields to return for the matching document.
     *  @var int [$limit] - Limit
     *  @var int [$sort] - Sort by keys using dot notation
     *  @var int [$skip] - Offset
     * }
     */
    public function find(string $collectionName, array $options = []): ResultSet;

    /**
     * Wrapper around CollectionInterface::findOne
     * @deprecated use DriverInterface::getCollection()->findOne()
     */
    public function findOne(string $collectionName, array $criteria): ?array;

    /**
     * Used by \MongoHybrid\ResultSet::hasOne
     */
    public function findOneById(string $collectionName, string $id): ?array;

    /**
     * May use mutliple items
     */
    public function insert(string $collectionName, array &$data): bool;

    /**
     * Insert or update, depending on $data['_id']
     */
    public function save(string $collectionName, array &$data, bool $isCreate = false): bool;

    /**
     * Not used directly, only via DriverInterface::save
     */
    public function update(string $collectionName, array $criteria, array $data): bool;

    /**
     * Used
     */
    public function remove(string $collectionName, array $criteria): bool;

    /**
     * Used
     */
    public function count(string $collectionName, array $criteria = null): int;

    /**
     * Remove field in collection items (used by CLI)
     * @see #504bc559af
     *
     * @param string $collectionName
     * @param string $fieldName
     * @param array $filter - Filter collections
     *
     * @return bool
     */
    public function removeField(string $collectionName, string $fieldName, array $filter = []): bool;

    /**
     * Rename field in collection items (used by CLI)
     * @see #76f9054307
     *
     * @param string $collectionName
     * @param string $fieldName
     * @param string $newfieldName
     * @param array $filter
     *
     * @return bool
     */
    public function renameField(string $collectionName, string $fieldName, string $newfieldName, array $filter = []): bool;
}