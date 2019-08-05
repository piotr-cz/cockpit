<?php
namespace MongoMysqlJson;

use PDO;
use PDOException;

use InvalidArgumentException;

use \MongoHybrid\ResultSet;

use \MongoMysqlJson\ {
    DriverException,
    Collection
};

// Note: Cannot use function
// use function \MongoLite\Databse\createMongoDbLikeId;

/**
 * See MongoHybry\MongoLite
 * @see https://scotch.io/tutorials/working-with-json-in-mysql
 * docs https://dev.mysql.com/doc/refman/5.7/en/json-function-reference.html
 * @see prepared statements https://medium.com/aubergine-solutions/working-with-mysql-json-data-type-with-prepared-statements-using-it-in-go-and-resolving-the-15ef14974c48
 *
 * Quirks:
 * - MySQL normalizes (orders by key) JSON objects on insert
 * - Required MySQL 5.7.8+ / 5.7.9 (shorthand operators)
 *
 * );
 */
class Driver
{
    /** @var string - Min database version */
    protected const DB_MIN_SERVER_VERSION = '5.7.9';

    /** @type \PDO - Database connection */
    protected $connection;

    /** @var array - Collections cache */
    protected $collections = [];

    /**
     * @param array $options {
     *   @var string $connection
     *   @var string $host
     *   @var string $db
     *   @var string $username
     *   @var string $password
     * }
     * @param array $driverOptions
     * @throws \MysqlJson\DriverException
     */
    public function __construct(array $options, array $driverOptions = [])
    {
        $dsn = vsprintf('%s:host=%s;dbname=%s;charset=UTF8', [
            $options['connection'],
            $options['host'],
            $options['db'],
        ]);

        // Using + to keep keys
        $driverOptions = $driverOptions + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_COLUMN,
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
    }

    /**
     * Assert features are supported by database
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
        // $currentMysqlVersion = $this->connection->query('SELECT VERSION()')->fetch(PDO::FETCH_COLUMN);

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
     * Doesn't separate collection and databse
     */
    public function getCollection(string $name, string $db = null): Collection
    {
        // Using one database
        $collectionName = static::getCollectionName($name, $db);

        if (!isset($this->collections[$collectionName])) {
            $this->collections[$collectionName] = new Collection($collectionName, $this->connection);
        }

        return $this->collections[$collectionName];
    }

    public function dropCollection(string $name, string $db = null)
    {
        die('dropCollection');
    }

    /**
     * Find collection items
     * @param string $collection - ie. collections/performers5d417617d3b77
     * @param array $options {
     *   @var array|callable|null $filter - Filter results by (criteria)
     *   @var array|null $fields - Projection
     *   @var int $limit - Limit
     *   @var array $sort - Sort by keys
     *   @var ? $skip
     *   @var bool $populate - Append objects
     * }
     * @return array|MongoHybryd\ResultSet
     */
    public function find(string $collection, array $options = []): ResultSet
    {
        $this->getCollection($collection);

        // Where
        $sqlWhereSegments = [];

        if (isset($options['filter'])) {
            foreach ($options['filter'] as $key => $value) {
                // $sqlWhereSegments[] = vsprintf("JSON_EXTRACT(`c`.`document`, '$.%s') = %s", [
                $sqlWhereSegments[] = vsprintf("`c`.`document` -> '$.%s' = %s", [
                    $key,
                    static::jsonEncode($value)
                ]);
            }
        }

        $sqlWhere = !empty($sqlWhereSegments)
            ? 'WHERE ' . implode(' AND ', $sqlWhereSegments)
            : '';

        // Order by
        $sqlOrderBySegments = [];

        if (isset($options['sort'])) {
            foreach ($options['sort'] as $key => $value) {
                // $sqlOrderBySegments[] = vsprintf("JSON_EXTRACT(`c`.`document`, '$.%s') %s", [
                $sqlOrderBySegments[] = vsprintf("`c`.`document` -> '$.%s' %s", [
                    $key,
                    $value == 1 ? 'ASC' : 'DESC'
                ]);
            }
        }

        $sqlOrderBy = !empty($sqlOrderBySegments)
            ? 'ORDER BY ' . implode(', ', $sqlOrderBySegments)
            : '';

        // Limit
        $sqlLimit = isset($options['limit'])
            ? sprintf('LIMIT %d', $options['limit'])
            : '';

        // Build query (TODO: probably just document is enough)
        $sql = <<<SQL

            SELECT
                `c`.`document`

            FROM
                `{$collection}` AS `c`

            {$sqlWhere}
            {$sqlOrderBy}
            {$sqlLimit}
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        $results = $stmt->fetchAll();

        // Decode each document
        $docs = array_map('static::jsonDecode', $results);

        return new ResultSet($this, $docs);
    }

    /**
     * Find item
     * @param string $collection - ie cockpit/accounts
     * @param array $criteria - ie ['active' => true, 'user' => 'piotr']
     */
    public function findOne($collection, array $criteria): ?array
    {
        $results = $this->find($collection, [
            'filter' => $criteria,
            'limit' => 1,
        ])->toArray();

        return array_shift($results);
    }

    public function findOneById($collection, $id)
    {
        die('findOneById');
    }

    /**
     * Insert, may be invoked by save
     * @param string $collection - ie. cockpit/revisions
     * @param arary $data {
     *   @var string $_oid
     *   @var array $data - Data to save
     *   @var string $meta - relation table name
     *   @var string $_creator - Creator user ID
     *   @var float $_created - Created at unix timestamp + microseconds
     * }
     * @return anything that evaluates to true
     */
    public function insert(string $collection, array &$data): bool
    {
        // Generate ID
        $data['_id'] = createMongoDbLikeId();

        $sql = <<<SQL

            INSERT INTO
                `{$collection}` (
                    `document`
                )
            VALUES (
                    :data
                )
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            ':data' => static::jsonEncode($data)
        ]);

        return true;
    }

    /**
     * Update item
     * @param string $collection
     * @param array $data
     * @return boolean
     */
    public function save(string $collection, array &$data): bool
    {
        // It's an insert
        if (!isset($data['_id'])) {
            return $this->insert($collection, $data);
        }

        $id = static::jsonEncode($data['_id']);

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

            /*
            $sqlSetSubSegments = array_merge(
                $sqlSetSubSegments,
                static::createSqlJsonSegments('$.' . $key, $value)
            );
            */

            $sqlSetSubSegments[] = vsprintf("'$.%s', %s", [
                $key,
                static::createSqlJsonValue($value)
            ]);
        }

        $sqlSet = implode(', ', $sqlSetSubSegments);

        $sql = <<<SQL

            UPDATE
                `{$collection}` AS `c`

            SET `c`.`document` = JSON_SET(
                `c`.`document`,
                {$sqlSet}
            )

            WHERE
                JSON_EXTRACT(`c`.`document`, '$._id') = {$id}
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        return true;
    }

    /**
     * Not used directly
     */
    public function update($collection, $criteria, $data)
    {
        die('update');
    }

    /**
     * Remove item
     * @param string $collection
     * @param array $criteria {
     *   @var string $_id
     * }
     */
    public function remove(string $collection, array $criteria): bool
    {
        $id = static::jsonEncode($criteria['_id']);

        $sql = <<<SQL

            DELETE
                `c`
            FROM
                `{$collection}` AS `c`
            WHERE
                JSON_EXTRACT(`c`.`document`, '$._id') = {$id}
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        /*
        // Remove from revisions, this is not handled by bootstrap
        // But it's probably a bug in Cockpit Collection module
        $sql = <<<SQL

            DELETE
                `r`
            FROM
                `cockpit/revisions` AS `r`
            WHERE
                JSON_EXTRACT(`r`.`document`, '$._oid') = {$id}
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();
        */

        return true;
    }

    public function count(string $collection, array $criteria = null): int
    {
        // ATM use ::find method instead of SELECT COUNT(*)
        $results = $this->find($collection);

        return count($results);
    }

    /**
     * Get fully qualified collection name (db / name)
     */
    protected static function getCollectionName(string $name, string $db = null): string
    {
        return $db
            ? sprintf('%s/%s', $db, $name)
            : $name;
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
     * Encode value helper
     */
    protected static function jsonEncode($value): string
    {
        // Slashes are nomalized by MySQL anyway
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode value helper
     */
    protected static function jsonDecode(string $string)
    {
        return json_decode($string, true);
    }

    /**
     * Create sql json value
     * @param mixed $value
     * @return string
     */
    public static function createSqlJsonValue($value): string
    {
        if (is_scalar($value) || is_null($value)) {
            return static::jsonEncode($value);
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
                $jsonValues[] = static::createSqlJsonValue($subValue);
            }
        // Associative array
        } else {
            $sqlFunction = 'JSON_OBJECT(%s)';

            // List of pairs: key, value
            foreach ($value as $subKey => $subValue) {
                $jsonValues[] = vsprintf('%s, %s', [
                    static::jsonEncode($subKey),
                    static::createSqlJsonValue($subValue)
                ]);
            }
        }

        return sprintf($sqlFunction, implode(', ', $jsonValues));
    }

    /**
     * Deprecated
     * Path, value
     * @param string $path
     * @param mixed $value
     * @return array
     */
    public static function createSqlJsonSegments(string $path = '$', $value): array
    {
        $segments = [];

        if (!is_array($value)) {
            // Path, value
            $segments[] = vsprintf("'%s', %s", [
                $path,
                static::jsonEncode($value),
            ]);
        } else {
            $isSequentialArray = $value === array_values($value);

            // TODO: Nesting

            // V2
            if ($isSequentialArray) {
                /*
                $subValues = array_map(function ($subValue) {
                    return static::jsonEncode($subValue);
                }, $value);

                $segments[] = vsprintf("'%s', JSON_ARRAY(%s)", [
                    $path,
                    implode(', ', $subValues)
                ]);
                */
            } else {
                $subValues = [];

                foreach ($value as $subKey => $subValue) {
                    $subValues[] = vsprintf("'%s', %s", [$subKey, static::jsonEncode($subValue)]);
                }

                $segments[] = vsprintf("'%s', JSON_OBJECT(%s)", [
                    $path,
                    implode(', ', $subValues)
                ]);
            }


            /*
            // V1
            $emptyValueFormat = $isSequentialArray
                ? '[]'
                : '{}';

            $pathSuffixFormat = $isSequentialArray
                ? "%s[%d]"
                : "%s.%s";


            // Empty an object as it may had been an empty string also purges orphaned keys
            // MySQL Problem
            // $segments[] = vsprintf("'%s', %s", [$path, $emptyValueFormat]);


            // Append sub segment
            foreach ($value as $subKey => $subValue) {
                $segments = array_merge(
                    $segments,
                    static::createSqlJsonSegments(
                        vsprintf($pathSuffixFormat, [$path, $subKey]),
                        $subValue
                    )
                );
            }
            */
        }

        return $segments;
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
