<?php
namespace MongoMysqlJson;

use \MongoMysqlJson\CollectionInterface;

use \MongoHybrid\ResultSet;

/**
 * DB driver interface
 */
interface DriverInterface
{
    /**
     * Get collection by id
     *
     * @param string $collectionId - Collection ID
     * @param string $db - Database alternative name
     * @return \MysqlJson\CollectionInterface
     */
    public function getCollection(string $collectionId, string $db = null): CollectionInterface;

    /**
     * @deprecated [NOT USED] instead MongoHybrid\Client uses CollectionInterface::drop
     */
    public function dropCollection(string $collectionId, string $db = null): void;

    //// Wrappers

    /**
     * Wrapper around CollectionInterface::find
     *
     * @deprecated use DriverInterface::getCollection()->find()
     *
     * @param string collectionId - Collection ID
     * @param array $options {
     *  @var array [$filter] - Filter results by (criteria)
     *  @var array [$fields] - Array of fields to exclude or include from result document (projection)
     *                         Limits the fields to return for the matching document.
     *  @var int [$limit] - Limit
     *  @var int [$sort] - Sort by keys using dot notation
     *  @var int [$skip] - Offset
     * }
     */
    public function find(string $collectionId, array $options = []): ResultSet;

    /**
     * Wrapper around CollectionInterface::findOne
     *
     * @deprecated use DriverInterface::getCollection()->findOne()
     */
    public function findOne(string $collectionId, $criteria = null, array $projection = []): ?array;

    /**
     * Used by \MongoHybrid\ResultSet::hasOne
     */
    public function findOneById(string $collectionId, string $itemId): ?array;

    /**
     * May use mutliple items
     */
    public function insert(string $collectionId, array &$data): bool;

    /**
     * Insert or update, depending on $data['_id']
     *
     * @param string $collectionId
     * @param array &$data {
     *   @param string [$id]
     * }
     * @return bool
     */
    public function save(string $collectionId, array &$data): bool;

    /**
     * Not used directly, only via DriverInterface::save
     */
    public function update(string $collectionId, $criteria = null, array $data): bool;

    /**
     * Remove item
     *
     * @param string $collectionId
     * @param array $criteria {
     *   @var string $_id
     * }
     * @return bool
     */
    public function remove(string $collectionId, array $criteria): bool;

    /**
     * Used
     */
    public function count(string $collectionId, $criteria = null): int;

    /**
     * Remove field in collection items (used by CLI)
     *
     * @see #504bc559af
     *
     * @param string $collectionId
     * @param string $fieldName
     * @param array $filter - Filter collections
     *
     * @return bool
     */
    public function removeField(string $collectionId, string $fieldName, array $filter = []): bool;

    /**
     * Rename field in collection items (used by CLI)
     *
     * @see #76f9054307
     *
     * @param string $collectionId
     * @param string $fieldName
     * @param string $newfieldName
     * @param array $filter
     *
     * @return bool
     */
    public function renameField(string $collectionId, string $fieldName, string $newfieldName, array $filter = []): bool;
}