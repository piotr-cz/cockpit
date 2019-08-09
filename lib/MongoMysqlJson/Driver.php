<?php
namespace MongoMysqlJson;

use PDO;
use PDOException;

use InvalidArgumentException;

use \MongoHybrid\ResultSet;

use \MongoMysqlJson\ {
    DriverException,
    Collection,
    CollectionInterface,
    QueryBuilder
};

// Note: Cannot use function
// use function \MongoLite\Databse\createMongoDbLikeId;

/**
 * See MongoHybry\MongoLite
 *
 * Quirks:
 * - MySQL normalizes (orders by key) JSON objects on insert
 * - Requires 5.7.9+ (JSON support and shorthand operators)
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
     *
     * Doesn't separate collection and databse
     */
    public function getCollection(string $collectionId, string $db = null): CollectionInterface
    {
        // Using one database
        $collectionFulllId = static::getCollectionFullId($collectionId, $db);

        if (!isset($this->collections[$collectionFulllId])) {
            $this->collections[$collectionFulllId] = new Collection($collectionFulllId, $this->connection, $this);
        }

        return $this->collections[$collectionFulllId];
    }

    /**
     * @inheritdoc
     *
     * [NOT USED]
     */
    public function dropCollection(string $collectionId, string $db = null): void
    {
        $this->getCollection($collectionId, $db)->drop();
    }

    /**
     * Handle collection drop
     *
     * @param string $collectionFullId
     */
    public function handleCollectionDrop(string $collectionFullId): void
    {
        // Unset from cache
        unset($this->collections[$collectionFullId]);
    }

    /**
     * Find collection items
     *
     * @param string $collectionId - ie. collections/performers5d417617d3b77
     * @param array $options {
     *   @var array|callable|null $filter - Filter results by (criteria)
     *   @var array|null $fields - Projection
     *   @var int $limit - Limit
     *   @var array $sort - Sort by keys
     *   @var int $skip
     * }
     * @return array|MongoHybryd\ResultSet
     */
    public function find(string $collectionId, array $options = []): ResultSet
    {
        $this->getCollection($collectionId);

        // Sanitize options
        $options = array_merge([
            'filter' => null,
            'fields' => null,
            'sort'   => null,
            'limit'  => null,
            'skip'   => null,
        ], $options);

        // Where
        $sqlWhere = !is_callable($options['filter'])
            ? $this->queryBuilder->buildWhere($options['filter'])
            : null;

        // Order by
        $sqlOrderBy = $this->queryBuilder->buildOrderby($options['sort']);

        // Limit and offset for filter which is not a callable
        $sqlLimit = !is_callable($options['filter'])
            ? $this->queryBuilder->buildLimit($options['limit'], $options['skip'])
            : null;


        // Build query
        $sql = <<<SQL

            SELECT
                `c`.`document`

            FROM
                `{$collectionId}` AS `c`

            {$sqlWhere}
            {$sqlOrderBy}
            {$sqlLimit}
SQL;

        $stmt = $this->connection->prepare($sql);

        $stmt->execute();

        $items = [];

        $position = 0;
        $itemsCount = 0;

        while ($result = $stmt->fetch()) {
            $item = QueryBuilder::jsonDecode($result);

            if (is_callable($options['filter'])) {
                if (!$options['filter']($item)) {
                    continue;
                }

                if ($position++ < $options['skip']) {
                    continue;
                }
            }

            $itemsCount++;
            $items[] = $item;

            // Break on limit
            if (is_callable($options['filter']) && $options['limit'] === $options['limit']) {
                break;
            }
        }

        /*
        // Callback filter
        if (is_callable($options['filter'])) {
            $index = 0;

            while ($result = $stmt->fetch()) {
                $item = QueryBuilder::jsonDecode($result);

                // Evaluate criteria, must return boolean
                if ($options['filter']($item)) {
                    // Skip
                    if (!$options['skip'] || $index >= $options['skip']) {
                        $items[] = $item;
                    }

                    $index++;
                }

                // Limit Limit
                if ($options['limit'] && count($items) >= $options['limit']) {
                    break;
                }
            }
        } else {
            foreach ($stmt->fetchAll() as $result) {
                $items[] = QueryBuilder::jsonDecode($result);
            }
        }
        */

        // See \MongoLite\Cursor::getData
        if (!empty($options['fields'])) {
            $exclude = [];
            $include = [];

            // Process lists
            foreach ($options['fields'] as $key => $value) {
                if ($value) {
                    $include[$key] = 1;
                } else {
                    $exclude[$key] = 1;
                }
            }

            // Process items
            foreach ($items as &$item) {
                $item = static::updateItemProjection($item, $exclude, $include);
            }
        }

        return new ResultSet($this, $items);
    }

    /**
     * Find item
     *
     * @param string $collection - ie cockpit/accounts
     * @param array $criteria - ie ['active' => true, 'user' => 'piotr']
     */
    public function findOne(string $collectionId, $criteria = null, array $projection = []): ?array
    {
        $results = $this->find($collectionId, [
            'filter' => $criteria,
            'fields' => $projection,
            'limit' => 1,
        ])->toArray();

        return array_shift($results);
    }

    /**
     * @inheritdoc
     */
    public function findOneById(string $collectionId, string $itemId): ?array
    {
        die('findOneById');
    }

    /**
     * Insert, may be invoked by save
     *
     * @param string $collectionId - ie. cockpit/revisions
     * @param arary $data {
     *   @var string $_oid
     *   @var array $data - Data to save
     *   @var string $meta - relation table name
     *   @var string $_creator - Creator user ID
     *   @var float $_created - Created at unix timestamp + microseconds
     * }
     * @return anything that evaluates to true
     */
    public function insert(string $collectionId, array &$data): bool
    {
        // Generate ID
        $data['_id'] = createMongoDbLikeId();

        $stmt = $this->connection->prepare(<<<SQL

            INSERT INTO
                `{$collectionId}` (`document`)
            VALUES (
                    :data
                )
SQL
        );

        $stmt->execute([
            ':data' => QueryBuilder::jsonEncode($data)
        ]);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function save(string $collectionId, array &$data): bool
    {
        // It's an insert
        if (!isset($data['_id'])) {
            return $this->insert($collectionId, $data);
        }

        return $this->update($collectionId, null, $data);
    }

    /**
     * @inheritdoc
     *
     * @todo Use criteria
     */
    public function update(string $collectionId, $criteria = null, array $data): bool
    {
        $stmt = $this->connection->prepare(<<<SQL

            UPDATE
                `{$collectionId}` AS `c`
            SET
                `c`.`document` = :data
            WHERE
                `c`.`document` -> '$._id' = :itemId
SQL
        );

        $stmt->execute([
            ':itemId' => $data['_id'],
            ':data' => QueryBuilder::jsonEncode($data),
        ]);

        return true;

        /*
        // Doesn't work with rename and remove field

        // Createa array of key, value segments for JSON_SET
        $sqlSetSubSegments = [];

        foreach ($data as $key => $value) {
            // Skip ID
            if ($key === '_id') {
                continue;
            }

            // _pid is parent_id_column_name and it's null
            if ($key === '_pid' && $value === null) {
                // continue;
            }

            $sqlSetSubSegments[] = vsprintf("'$.%s', %s", [
                $key,
                static::createSqlJsonSetValue($value)
            ]);
        }

        $sqlSet = implode(', ', $sqlSetSubSegments);

        $stmt = $this->connection->prepare(<<<SQL

            UPDATE
                `{$collectionId}` AS `c`

            SET `c`.`document` = JSON_SET(
                `c`.`document`,
                {$sqlSet}
            )

            WHERE
                `c`.`document` -> '$._id' = :itemId
SQL
        );

        $stmt->execute([':itemId' => $data['_id']]);

        return true;
        */
    }

    /**
     * @inheritdoc
     */
    public function remove(string $collectionId, array $criteria): bool
    {
        $stmt = $this->connection->prepare(<<<SQL

            DELETE
                `c`
            FROM
                `{$collectionId}` AS `c`
            WHERE
                `c`.`document` -> '$._id' = :itemId
SQL
        );

        $stmt->execute([':itemId' => $criteria['_id']]);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function count(string $collectionId, $criteria = null): int
    {
        $this->getCollection($collectionId);

        // On user defined function must use find
        if (is_callable($criteria)) {
            return count($this->find($collectionId, [
                'filter' => $criteria
            ]));
        }

        $sqlWhere = $this->queryBuilder->buildWhere($criteria);

        $sql = <<<SQL

            SELECT
                COUNT(`c`.`document`)

            FROM
                `{$collectionId}` AS `c`

            $sqlWhere
SQL
        ;

        $stmt = $this->connection->prepare($sql);

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @inheritdoc
     */
    public function removeField(string $collectionId, string $fieldName, array $filter = []): bool
    {
        $items = $this->find($collectionId, ['filter' => $filter]);

        foreach ($items as $item) {
            if (!isset($item[$fieldName])) {
                continue;
            }

            unset($item[$fieldName]);

            $this->update($collectionId, ['_id' => $item['_id']], $item, false);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function renameField(string $collectionId, string $fieldName, string $newfieldName, array $filter = []): bool
    {
        $items = $this->find($collectionId, ['filter' => $filter]);

        foreach ($items as $item) {
            if (!isset($item[$fieldName])) {
                continue;
            }

            $item[$newfieldName] = $item[$fieldName];

            unset($item[$fieldName]);

            $this->update($collectionId, ['_id' => $item['_id']], $item, false);
        }

        return true;
    }

    /**
     * Get fully qualified collection name (db / id)
     *
     * @param string $id
     * @param string|null $db
     * @return string
     */
    protected static function getCollectionFullId(string $id, string $db = null): string
    {
        return $db
            ? sprintf('%s/%s', $db, $id)
            : $id;
    }

    /**
     * Not used, tables created on the fly
     * May decide to run on install
     */
    protected function createCockpitSchema(): void
    {
        $cockpitTables = [
            'accounts',
            'assets',
            'assets_folders',
            'options',
            'revisions',
            'webhooks',
        ];

        foreach ($cockpitTables as $name) {
            $this->getCollection(sprintf('cockpit/%s', $name));
        }

        return;
    }

    /**
     * Create sql json value
     *
     * @param mixed $value
     * @return string
     */
    protected static function createSqlJsonSetValue($value): string
    {
        if (is_scalar($value) || is_null($value)) {
            return QueryBuilder::jsonEncode($value);
        }

        // Cannot work with non-arrays yet
        if (!is_array($value)) {
            throw new InvalidArgumentException('Cannot serialize value');
        }

        $jsonValues = [];
        $isSequentialArray = $value === array_values($value);

        // Sequential array
        if ($isSequentialArray) {
            $sqlFunction = 'JSON_ARRAY(%s)';

            // List of values
            foreach ($value as $subValue) {
                $jsonValues[] = static::createSqlJsonSetValue($subValue);
            }
        // Associative array
        } else {
            $sqlFunction = 'JSON_OBJECT(%s)';

            // List of pairs: key, value
            foreach ($value as $subKey => $subValue) {
                $jsonValues[] = vsprintf('%s, %s', [
                    QueryBuilder::jsonEncode($subKey),
                    static::createSqlJsonValue($subValue)
                ]);
            }
        }

        return sprintf($sqlFunction, implode(', ', $jsonValues));
    }

    /**
     * Modify item as per projection
     *
     * @param array $item
     * @param array $exclude
     * @param array $include
     *
     * @return array
     */
    protected static function updateItemProjection(array $item, array $exclude, array $include): array
    {
        $id = $item['_id'];

        // Remove keys
        if (!empty($exclude)) {
            $item = array_diff_key($item, $exclude);
        }

        // Keep keys (not sure why MongoLite::cursor uses custom function array_key_intersect)
        if (!empty($include)) {
            $item = array_intersect_key($item, $include);
        }

        // Don't remove `_id` via include unless it's explicitly excluded
        if (!isset($exclude['_id'])) {
            $item['_id'] = $id;
        }

        return $item;
    }
}

// Copied from MongoLite\Database
function createMongoDbLikeId() {

    // based on https://gist.github.com/h4cc/9b716dc05869296c1be6

    $timestamp = \microtime(true);
    $hostname  = \php_uname('n');
    $processId = \getmypid();
    $id        = \random_int(10, 1000);
    $result    = '';

    // Building binary data.
    $bin = \sprintf(
        '%s%s%s%s',
        \pack('N', $timestamp),
        \substr(md5($hostname), 0, 3),
        \pack('n', $processId),
        \substr(\pack('N', $id), 1, 3)
    );

    // Convert binary to hex.
    for ($i = 0; $i < 12; $i++) {
        $result .= \sprintf('%02x', ord($bin[$i]));
    }

    return $result;
}
