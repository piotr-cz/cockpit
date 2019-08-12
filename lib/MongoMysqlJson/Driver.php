<?php

namespace MongoMysqlJson;

use PDO;
use PDOException;

use MongoHybrid\ResultSet;

use MongoMysqlJson\ {
    DriverInterface,
    DriverException,
    CollectionInterface,
    Collection,
    QueryBuilder
};

/**
 * MySQL Driver
 * Requires 5.7.9+ (JSON support and shorthand operators)
 */
class Driver implements DriverInterface
{
    /** @var string - Min database version */
    protected const DB_MIN_SERVER_VERSION = '5.7.9';

    /** @type \PDO - Database connection */
    protected $connection;

    /** @var array - Collections cache */
    protected $collections = [];

    /** @var \MongoMysqlJson\QueryBuilder */
    protected $queryBuilder;

    /**
     * Constructor
     *
     * @param array $options {
     *   @var string [$host]
     *   @var int [$port]
     *   @var string $dbname
     *   @var string [$charset]
     *   @var string $username
     *   @var string $password
     * }
     * @param array $driverOptions
     * @throws \MysqlJson\DriverException
     */
    public function __construct(array $options, array $driverOptions = [])
    {
        // See https://www.php.net/manual/en/ref.pdo-mysql.connection.php
        $dsn = vsprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', [
            $options['host'] ?? 'localhost',
            $options['port'] ?? null,
            $options['dbname'],
            $options['charset'] ?? 'UTF8'
        ]);

        // Using + to keep keys
        $driverOptions = $driverOptions + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_COLUMN,
            // PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            // PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ];

        try {
            $this->connection = new PDO(
                $dsn,
                $options['username'],
                $options['password'],
                $driverOptions
            );
        // Access denied for user (1045), Unknown database (1049), invalid host
        } catch (PDOException $pdoException) {
            throw new DriverException(sprintf('PDO connection failed: %s', $pdoException->getMessage()), 0, $pdoException);
        }

        // TODO
        $this->assertIsSupported();

        $this->queryBuilder = new QueryBuilder([$this->connection, 'quote']);
    }

    /**
     * Assert features are supported by database
     *
     * @throws \MysqlJson\DriverException
     */
    public function assertIsSupported(): void
    {
        // Check for PDO Driver
        if (!in_array('mysql', PDO::getAvailableDrivers())) {
            throw new DriverException('pdo_mysql extension not loaded');
        }

        // Check version
        $currentMysqlVersion = $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);

        if (!version_compare($currentMysqlVersion, static::DB_MIN_SERVER_VERSION, '>=')) {
            throw new DriverException(vsprintf('Driver requires MySQL version >= %s, got %s', [
                static::DB_MIN_SERVER_VERSION,
                $currentMysqlVersion
            ]));
        }

        return;
    }

    /**
     * @inheritdoc
     */
    public function getCollection(string $name, ?string $db = null): CollectionInterface
    {
        $collectionId = $db
            ? $db . '/' . $name
            : $name;

        if (!isset($this->collections[$collectionId])) {
            $this->collections[$collectionId] = new Collection($collectionId, $this->connection, $this->queryBuilder, $this);
        }

        return $this->collections[$collectionId];
    }

    /**
     * @inheritdoc
     */
    public function dropCollection(string $collectionId): bool
    {
        return $this->getCollection($collectionId)->drop();
    }

    /**
     * Handle collection drop
     *
     * @param string $collectionId
     */
    public function handleCollectionDrop(string $collectionId): void
    {
        unset($this->collections[$collectionId]);
    }

    /**
     * @inheritdoc
     */
    public function find(string $collectionId, array $criteria = []): ResultSet
    {
        $filter = $criteria['filter'] ?? null;

        $options = [
            'sort'       => $criteria['sort'] ?? null,
            'limit'      => $criteria['limit'] ?? null,
            'skip'       => $criteria['skip'] ?? null,
            'projection' => $criteria['fields'] ?? null,
        ];

        $cursor = $this->getCollection($collectionId)->find($filter, $options);

        $docs = $cursor->toArray();

        return new ResultSet($this, $docs);
    }

    /**
     * @inheritdoc
     */
    public function findOne(string $collectionId, $filter = []): ?array
    {
        return $this->getCollection($collectionId)->findOne($filter);
    }

    /**
     * @inheritdoc
     */
    public function findOneById(string $collectionId, string $docId): ?array
    {
        return $this->findOne($collectionId, ['_id' => $docId]);
    }

    /**
     * @inheritdoc
     */
    public function save(string $collectionId, array &$doc, bool $isCreate = false): bool
    {
        if (empty($doc['_id'])) {
            return $this->insert($collectionId, $doc);
        }

        return $this->update($collectionId, ['_id' => $doc['_id']], $doc);
    }

    /**
     * @inheritdoc
     */
    public function insert(string $collectionId, array &$doc): bool
    {
        return $this->getCollection($collectionId)->insertOne($doc);
    }

    /**
     * @inheritdoc
     */
    public function update(string $collectionId, $filter = [], array $data): bool
    {
        return $this->getCollection($collectionId)->updateMany($filter, $data);
    }

    /**
     * @inheritdoc
     */
    public function remove(string $collectionId, $filter = []): bool
    {
        return $this->getCollection($collectionId)->deleteMany($filter);
    }

    /**
     * @inheritdoc
     */
    public function count(string $collectionId, $filter = []): int
    {
        return $this->getCollection($collectionId)->count($filter);
    }

    /**
     * @inheritdoc
     */
    public function removeField(string $collectionId, string $field, $filter = []): void
    {
        $docs = $this->find($collectionId, ['filter' => $filter]);

        foreach ($docs as $doc) {
            if (!isset($doc[$field])) {
                continue;
            }

            unset($doc[$field]);

            $this->update($collectionId, ['_id' => $doc['_id']], $doc, false);
        }

        return;
    }

    /**
     * @inheritdoc
     */
    public function renameField(string $collectionId, string $field, string $newField, $filter = []): void
    {
        $docs = $this->find($collectionId, ['filter' => $filter]);

        foreach ($docs as $doc) {
            if (!isset($doc[$field])) {
                continue;
            }

            $doc[$newField] = $doc[$field];

            unset($doc[$field]);

            $this->update($collectionId, ['_id' => $doc['_id']], $doc, false);
        }

        return;
    }
}
