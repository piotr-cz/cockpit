<?php
namespace MongoMysqlJson;

use PDO;
use PDOException;

use ErrorException;
use InvalidArgumentException;

use \MongoHybrid\ResultSet;

use \MongoMysqlJson\ {
    DriverException,
    Collection,
    CollectionInterface
};

// Note: Cannot use function
// use function \MongoLite\Databse\createMongoDbLikeId;

/**
 * See MongoHybry\MongoLite
 *
 * Quirks:
 * - MySQL normalizes (orders by key) JSON objects on insert
 * - Required MySQL 5.7.8+ / 5.7.9 (shorthand operators)
 * );
 */
class Driver implements DriverInterface
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
     * Get connection to use in tests
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * @inheritdoc
     * Doesn't separate collection and databse
     */
    public function getCollection(string $collectionId, string $db = null): CollectionInterface
    {
        // Using one database
        $collectionFulllId = static::getCollectionFullId($collectionId, $db);

        if (!isset($this->collections[$collectionFulllId])) {
            $this->collections[$collectionFulllId] = new Collection($collectionFulllId, $this->connection);
        }

        return $this->collections[$collectionFulllId];
    }

    /**
     * @inheritdoc
     */
    public function dropCollection(string $collectionId, string $db = null): void
    {
        // TODO: remove from collections cache
        die('dropCollection');
    }

    /**
     * Find collection items
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

        // Where
        $sqlWhereSegments = [];

        if (isset($options['filter']) && is_array($options['filter'])) {
            foreach ($options['filter'] as $key => $value) {
                // Simple equals
                if (!is_array($value)) {
                    $sqlWhereSegments[] = vsprintf("`c`.`document` -> '$.%s' = %s", [
                        $key,
                        static::jsonEncode($value),
                    ]);

                    continue;
                }

                /* Multiple filters in format
                 * [    // $key
                 *
                 *     '$or' => [
                 *          // $value
                 *          ['name' => ['$regex' => 'Anna']],
                 *          ['type' => ['$regex' => 'Anna']],
                 *          ['bio' => ['$regex' => 'Anna']]
                 *      ]
                 * ]
                 */

                // Key is operator ($or)
                $sqlWhereGroupSegments = [];

                foreach ($value as $criterias) {
                    foreach ($criterias as $fieldName => $criteria) {
                        // criteria example:
                        // ['$regex' => 'foobar', '$options' => 'i']
                        // ['$in' => ['foo', 'bar']]

                        $sqlWhereGroupSubSegments = [];

                        foreach ($criteria as $mongoOperator => $value) {

                            // See https://dev.mysql.com/doc/refman/5.7/en/comparison-operators.html
                            switch ($mongoOperator) {
                                // Equals
                                case '$eq':
                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' = %s", [
                                        $fieldName,
                                        static::jsonEncode($value)
                                    ]);
                                    break;

                                // Not equals
                                case '$not' :
                                case '$ne' :
                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' <> %s", [
                                        $fieldName,
                                        static::jsonEncode($value),
                                    ]);
                                    break;

                                // Greater or equals
                                case '$gte' :
                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' >= %s", [
                                        $fieldName,
                                        static::jsonEncode($value),
                                    ]);
                                    break;

                                // Greater
                                case '$gt' :
                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' > %s", [
                                        $fieldName,
                                        static::jsonEncode($value),
                                    ]);
                                    break;

                                // Lower or equals
                                case '$lte' :
                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' <= %s", [
                                        $fieldName,
                                        static::jsonEncode($value),
                                    ]);
                                    break;

                                // Lower
                                case '$lt' :
                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' < %s", [
                                        $fieldName,
                                        static::jsonEncode($value),
                                    ]);
                                    break;

                                // In array
                                // When db value is an array, this evaluates to false
                                // Could use JSON_OVERLAPS but it's MySQL 8+
                                case '$in':
                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' IN (%s)", [
                                        $fieldName,
                                        implode(', ', array_map('static::jsonEncode', $value)),
                                    ]);
                                    /*
                                    $sqlWhereGroupSubSegments[] = vsprintf("JSON_OVERLAPS(`c`.`document` -> '$.%s', %s)", [
                                        $fieldName,
                                        $this->connection->quote(static::jsonEncode($value)),
                                    ]);
                                    */
                                    break;

                                // Not in array
                                case '$nin':
                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' NOT IN (%s)", [
                                        $fieldName,
                                        implode(', ', array_map('static::jsonEncode', $value)),
                                    ]);
                                    break;

                                // Reverse $in: lookup value in table
                                case '$has': // value is scalar
                                // Same array values
                                // Doesn't use MEMBER OF as it's MySQL 8+
                                case '$all': // value is an array
                                    if ($mongoOperator === '$has' && is_array($value)) {
                                        throw new InvalidArgumentException('Invalid argument for $has array not supported');
                                    }

                                    if ($mongoOperator === '$all' && !is_array($value)) {
                                        throw new InvalidArgumentException('Invalid argument for $all option must be array');
                                    }

                                    $sqlWhereGroupSubSegments[] = vsprintf("JSON_CONTAINS(`c`.`document`, %s, '$.%s')", [
                                        $this->connection->quote(static::jsonEncode($value)),
                                        $fieldName,
                                    ]);
                                    break;

                                /*
                                // Alternative implementation
                                case '$all':
                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' = JSON_ARRAY(%s)", [
                                        $fieldName,
                                        implode(',', array_map('static::jsonEncode', $value)),
                                    ]);
                                    break;
                                */

                                // Regexp (cockpit default is case sensitive)
                                // Note: ^ doesn't work
                                case '$preg':
                                case '$match':
                                case '$regex':
                                    $sqlWhereGroupSubSegments[] = vsprintf("LOWER(`c`.`document` -> '$.%s') REGEXP LOWER(%s)", [
                                        $fieldName,
                                        static::jsonEncode($value),
                                    ]);
                                    break;

                                // Array size
                                case '$size':
                                    $sqlWhereGroupSubSegments[] = vsprintf("JSON_LENGTH(`c`.`document`, '$.%s') = %s", [
                                        $fieldName,
                                        static::jsonEncode($value),
                                    ]);
                                    break;

                                // Mod Mote: MongoLite uses arrays' first key for value
                                case '$mod':
                                    if (!is_array($value)) {
                                        throw new InvalidArgumentException('Invalid argument for $mod option must be array');
                                    }

                                    $value = array_keys($value)[0];
                                    $sqlWhereGroupSubSegments[] = vsprintf("MOD(`c`.`document` -> '$.%s', %s) = 0", [
                                        $fieldName,
                                        static::jsonEncode($value),
                                    ]);
                                    break;

                                // User defined function
                                case '$func':
                                case '$fn':
                                case '$f':
                                    throw new InvalidArgumentException(sprintf('Function %s not supported by database driver', $mongoOperator), 1);

                                // Path exists
                                // Warning: doesn't check if key exists
                                case '$exists':
                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' %s NULL", [
                                        $fieldName,
                                        $value ? 'IS NOT' : 'IS'
                                    ]);
                                    break;

                                // Fuzzy search
                                // Note: PHP produces 4 char string while MySQL longer ones
                                // Note: no idea how to implement. SOUNDEX doesn't search in strings.
                                case '$fuzzy':
                                    throw new InvalidArgumentException(sprintf('Function %s not supported by database driver', $mongoOperator), 1);

                                // Text search
                                case '$text':
                                    if (is_array($value)) {
                                        throw new InvalidArgumentException(sprintf('Options for %s function are not suppored by database driver', $mongoOperator), 1);
                                    }

                                    $sqlWhereGroupSubSegments[] = vsprintf("`c`.`document` -> '$.%s' LIKE %s", [
                                        $fieldName,
                                        // Escape MySQL placeholders
                                        static::jsonEncode('%' . strtr($value, [
                                            '_' => '\\_',
                                            '%' => '\\%'
                                        ]) . '%'),
                                    ]);
                                    /*
                                    // Search in array or string (case sensitive)
                                    $sqlWhereGroupSubSegments[] = vsprintf("JSON_SEARCH(`c`.`document`, 'one', %s, NULL, '$.%s') IS NOT NULL", [
                                        static::jsonEncode('%' . strtr($value, [
                                            '_' => '\\_',
                                            '%' => '\\%'
                                        ]) . '%'),
                                        $fieldName,
                                    ]);
                                    */

                                    break;

                                // Skip Mongo specific stuff
                                case '$options':
                                    continue 2;

                                // Bail out on non-supported operator
                                default:
                                    throw new ErrorException(sprintf('Condition not valid ... Use %s for custom operations', $mongoOperator));
                            }
                        }

                        // Join conditions
                        switch (count($sqlWhereGroupSubSegments)) {
                            case 0:
                                break;
                            case 1:
                                $sqlWhereGroupSegments[] = $sqlWhereGroupSubSegments[0];
                                break;
                            default:
                                $sqlWhereGroupSegments[] = '(' . join(' AND ', $sqlWhereGroupSubSegments) . ')';
                                break;
                        }
                    }
                }

                // Pick up proper group operator
                switch ($key) {
                    // case '$and':
                    case '$or':
                        $sqlGroupSegmentsOperator = 'OR';
                        break;

                    case '$and':
                    default:
                        $sqlGroupSegmentsOperator = 'AND';
                        break;
                }

                $sqlWhereSegments[] = '(' . implode(' ' . $sqlGroupSegmentsOperator . ' ', $sqlWhereGroupSegments) . ')';
            }
        }

        $sqlWhere = !empty($sqlWhereSegments)
            ? 'WHERE ' . implode(' AND ', $sqlWhereSegments)
            : '';

        // Order by
        $sqlOrderBySegments = [];

        if (isset($options['sort'])) {
            foreach ($options['sort'] as $key => $value) {
                $sqlOrderBySegments[] = vsprintf("`c`.`document` -> '$.%s' %s", [
                    $key,
                    $value == 1 ? 'ASC' : 'DESC'
                ]);
            }
        }

        $sqlOrderBy = !empty($sqlOrderBySegments)
            ? 'ORDER BY ' . implode(', ', $sqlOrderBySegments)
            : '';

        // Limit and offset for filter which is not a callable
        if (!isset($options['filter']) || !is_callable($options['filter'])) {
            // Limit
            $sqlLimit = isset($options['limit'])
                ? sprintf('LIMIT %d', $options['limit'])
                : '';

            // Offset (limit must be provided)
            // https://stackoverflow.com/questions/255517/mysql-offset-infinite-rows
            if (isset($options['limit']) && isset($options['skip'])) {
                $sqlLimit = sprintf('LIMIT %d OFFSET %d', $options['limit'], $options['skip']);
            }
        } else {
            $sqlLimit = '';
        }

        // Build query (TODO: probably just document is enough)
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

        if (isset($options['filter']) && is_callable($options['filter'])) {
            $index = 0;
            $skip = $options['skip'] ?? null;
            $limit = $options['limit'] ?? null;

            while ($result = $stmt->fetch()) {
                $item = static::jsonDecode($result);

                // Evaluate criteria, must return boolean
                if ($options['filter']($item)) {
                    // Skip
                    if (!$skip || $index >= $skip) {
                        $items[] = $item;
                    }

                    $index++;
                }

                // Limit Limit
                if ($limit && count($items) >= $limit) {
                    break;
                }
            }
        } else {
            foreach ($stmt->fetchAll() as $result) {
                $items[] = static::jsonDecode($result);
            }
        }

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
                $item = static::processItemProjection($item, $exclude, $include);
            }
        }

        return new ResultSet($this, $items);
    }

    /**
     * Find item
     * @param string $collection - ie cockpit/accounts
     * @param array $criteria - ie ['active' => true, 'user' => 'piotr']
     */
    public function findOne(string $collectionId, $criteria = [], array $projection = []): ?array
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
            ':data' => static::jsonEncode($data)
        ]);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function save(string $collectionId, array &$data, bool $isCreate = false): bool
    {
        // It's an insert
        if (!isset($data['_id'])) {
            return $this->insert($collectionId, $data);
        }

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
                static::createSqlJsonValue($value)
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
    }

    /**
     * @inheritdoc
     * TODO: Use criteria
     */
    public function update(string $collectionId, array $criteria, array $data): bool
    {
        die('update');
        return true;
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
    public function count(string $collectionId, array $criteria = null): int
    {
        // ATM use ::find method instead of SELECT COUNT(*)
        $results = $this->find($collectionId, [
            'filter' => $criteria ?? []
        ]);

        return count($results);
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
     * Modify item as per projection
     * @param array $item
     * @param array $exclude
     * @param array $include
     * @return array
     */
    protected static function processItemProjection(array $item, array $exclude, array $include): array
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
