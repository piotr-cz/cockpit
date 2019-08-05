<?php
/**
 * Just use MongoHybrid\ResultSet
 */
namespace MongoMysqlJson;

use PDO;

/**
 * Collection. Not sure why it's required by MongoHybrid\Client
 */
class Collection
{
    /**
     * Order by values
     */
    protected const ORDER_BY_ASC = 1;
    protected const ORDER_BY_DESC = -1;

    /** @var string */
    protected $name;

    /** @var \PDO - connection */
    protected $connection;

    /**
     * Constructor
     */
    public function __construct(string $name, PDO $connection)
    {
        $this->name = $name;
        $this->connection = $connection;

        $this->createIfNotExists();
    }

    /**
     * @inheritdoc
     * [NOT USED]
     * Note: MongoLite\Collection passes only criteria and projection and expects Cursor
     * TODO: return generator which behaves like \MongoLite\Cusor
     */
    public function find($criteria = null, array $projection = null): array
    {
        $sqlWhere = '';
        $sqlLimit = '';
        $sqlOrderBy = '';

        // Create SQL modifications based on criteria
        if (is_array($criteria)) {
            // Build WHERE
            if (!empty($criteria['filter'])) {
                $sqlWhereSegments = [];

                // Workaround document_key UDF
                foreach ($criteria['filter'] as $key => $value) {
                    $sqlWhereSegments[] = vsprintf("`c`.`document` -> '$.%s' = %s", [
                        $key,
                        static::jsonEncode($value)
                    ]);
                }

                // TODO: Handle other operators than AND
                $sqlWhere = sprintf('WHERE %s', implode(' AND ', $sqlWhereSegments));
            }

            // Build ORDER BY. may use nested keys
            if (!empty($criteria['sort'])) {
                $sqlOrderBySegments = [];

                foreach ($criteria['sort'] as $key => $value) {
                    $sqlOrderBySegments[] = vsprintf("`c`.`document` -> '$.%s' %s", [
                        $key,
                        $value == static::ORDER_BY_ASC ? 'ASC' : 'DESC'
                    ]);
                }

                $sqlOrderBy = sprintf('ORDER BY %s', implode(', ', $sqlOrderBySegments));
            }

            // Build LIMIT
            if (!empty($criteria['limit'])) {
                $sqlLimit = sprintf('LIMIT %d', $criteria['limit']);
            }
        }

        $sql = <<<SQL

            SELECT
                `c`.`document`

            FROM
                `{$this->name}` AS `c`

            {$sqlWhere}
            {$sqlOrderBy}
            {$sqlLimit}
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        $items = [];

        // Fetch items one by one while evaluating criteria
        // Workaround document_criteria UDF
        // see https://www.php.net/manual/en/pdo.sqlitecreatefunction.php
        // see https://dev.mysql.com/doc/refman/5.5/en/create-function-udf.html
        if (is_callable($criteria)) {
            while ($result = $stmt->fetch()) {
                $item = static::jsonDecode($result);

                // Evaluate criteria, must return boolean
                if ($criteria($item)) {
                    $items[] = $item;
                }

                // Limit reached
                if (!empty($criteria['limit']) && count($items) >= $criteria['limit']) {
                    break;
                }
            }
        // Fetch all items at once
        } else {
            // JSON decode
            foreach ($stmt->fetchAll() as $result) {
                $items[] = static::jsonDecode($result);
            }
        }

        // See \MongoLite\Cursor::getData
        if ($projection) {
            $exclude = [];
            $include = [];

            // Process lists
            foreach ($projection as $key => $value) {
                if ($value) {
                    $include[$key] = 1;
                } else {
                    $exclude[$key] = 1;
                }
            }

            // Don't remove `_id` via includes unliess it's explicitly excluded
            if (!isset($exclude['_id'])) {
                unset($include['_id']);
            }

            // Process items
            foreach ($items as &$item) {
                $item = static::processItemProjection($item, $exclude, $include);
            }
        }

        return $items;
    }

    /**
     * @inheritdoc
     * [NOT USED]
     */
    public function findOne($criteria = null, array $projection = null): ?array
    {
        $items = $this->find(array_merge($criteria, [
            'limit' => 1
        ]));

        return array_shift($items);
    }

    /**
     * @inheritdoc
     */
    public function drop()
    {
        $sql = <<<SQL

            DROP TABLE
                `{$this->name}`
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        return;
    }

    /**
     * @inheritdoc
     * [NOT USED]
     */
    public function renameCollection(string $newname): bool
    {
        $sql = <<<SQL

            RENAME TABLE
                `{$this->name}`
            TO
                `{$newname}`
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function count(array $criteria = null): int
    {
        // Using find which is slower
        $items = $this->find($criteria);

        return count($items);

        /*
        // TODO: Criteria
        $sql = <<<SQL

            SELECT
                COUNT(*)
            FROM
                `{$this->name}` AS `c`
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
        */
    }

    /**
     * Create table if does not exist
     */
    protected function createIfNotExists(): void
    {
        $sql = <<<SQL

            SHOW TABLES LIKE '{$this->name}'
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        $exists = (bool) $stmt->fetchColumn();

        if ($exists) {
            return;
        }

        $sql = <<<SQL

            CREATE TABLE IF NOT EXISTS `{$this->name}` (
                `id`       INT  NOT NULL AUTO_INCREMENT,
                `document` JSON NOT NULL,
                PRIMARY KEY (`id`)
            )
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        return;
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
        // Remove keys
        if (!empty($exclude)) {
            $item = array_diff_key($item, $exclude);
        }

        // Keep keys (not sure why MongoLite::cursor uses custom function array_key_intersect)
        if (!empty($include)) {
            $item = array_intersect_key($item, $include);
        }

        return $item;
    }

    /**
     * Encode value helper
     */
    protected static function jsonEncode($value): string
    {
        // Slashes are nomalized by MySQL anyway
        return \json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode value helper
     */
    protected static function jsonDecode(string $string)
    {
        return \json_decode($string, true);
    }
}
