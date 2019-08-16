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
    protected const DB_MIN_SERVER_VERSION = [
        'mysql' => '5.7.9',
        // 10.9
        // 'pgsql' => '9.3.25' // '9.2', // 11 for virtual columns
        'pgsql' => '9.4' // check if table exists
    ];

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
        $this->type = $options['connection'];

        // Using + to keep keys
        // See https://www.php.net/manual/en/pdo.setattribute.php
        $driverOptions = $driverOptions + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_COLUMN,
            PDO::ATTR_EMULATE_PREPARES => true,
        ];

        switch ($options['connection']) {
            // See https://www.php.net/manual/en/ref.pdo-mysql.connection.php
            case 'mysql':
                $pdoParams = [
                    vsprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', [
                        $options['host'] ?? 'localhost',
                        $options['port'] ?? null,
                        $options['dbname'],
                        $options['charset'] ?? 'UTF8'
                    ]),
                    $options['username'],
                    $options['password'],
                    $driverOptions
                ];

                $driverOptions = array_merge($driverOptions, [
                    // Note: Setting sql_mode doesn't work in init command, at least in 5.7.26
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4;',
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
                ]);

                break;

            // See https://www.php.net/manual/en/ref.pdo-pgsql.connection.php
            case 'pgsql':
                $pdoParams = [
                    vsprintf('pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s', [
                        $options['host'] ?? 'localhost',
                        $options['port'] ?? null,
                        $options['dbname'],
                        $options['username'],
                        $options['password'],
                    ]),
                    null,
                    null,
                    $driverOptions
                ];
                break;

            default:
                throw new DriverException(sprintf('Invalid connection %s', $options['connection']));

        }

        try {
            $this->connection = new PDO(...$pdoParams);
        // Access denied for user (1045), Unknown database (1049), invalid host
        } catch (PDOException $pdoException) {
            throw new DriverException(sprintf('PDO connection failed: %s', $pdoException->getMessage()), 0, $pdoException);
        }

        // Set Mysql_mode after connection has started
        if ($options['connection'] === 'mysql') {
            // $this->connection->exec("SET sql_mode = (SELECT CONCAT(@@SESSION.sql_mode, ',ANSI_QUOTES'));");
            $this->connection->exec("SET sql_mode = 'ANSI';");
        }

        $this->assertIsSupported();

        $this->queryBuilder = new QueryBuilder([$this->connection, 'quote'], $options['connection']);
    }

    /**
     * Assert features are supported by database
     *
     * @throws \MysqlJson\DriverException
     */
    public function assertIsSupported(): void
    {
        $pdoDriverName = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Check for PDO Driver
        if (!in_array($pdoDriverName, PDO::getAvailableDrivers())) {
            throw new DriverException('Pdo extension not loaded');
        }

        // Check for driver implementation
        if (!isset(static::DB_MIN_SERVER_VERSION[$pdoDriverName])) {
            throw new DriverException('Driver %s not implemented', $pdoDriverName);
        }

        // Check version
        $currentVersion = $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);

        if (!version_compare($currentVersion, static::DB_MIN_SERVER_VERSION[$pdoDriverName], '>=')) {
            throw new DriverException(vsprintf('Driver requires MySQL version >= %s, got %s', [
                static::DB_MIN_SERVER_VERSION[$pdoDriverName],
                $currentVersion
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
    public function find(string $collectionId, array $criteria = [], bool $returnIterator = false)
    {
        $filter = $criteria['filter'] ?? null;

        $options = [
            'sort'       => $criteria['sort'] ?? null,
            'limit'      => $criteria['limit'] ?? null,
            'skip'       => $criteria['skip'] ?? null,
            'projection' => $criteria['fields'] ?? null,
        ];

        $cursor = $this->getCollection($collectionId)->find($filter, $options);

        if ($returnIterator) {
            return new ResultIterator($this, $cursor);
        }

        $docs = array_values($cursor->toArray());

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
