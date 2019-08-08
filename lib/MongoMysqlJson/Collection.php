<?php
/**
 * Just use MongoHybrid\ResultSet
 */
namespace MongoMysqlJson;

use PDO;

use \MongoMysqlJson\CollectionInterface;

/**
 * Collection. Not sure why it's required by MongoHybrid\Client
 */
class Collection implements CollectionInterface
{
    /**
     * Order by values
     */
    protected const ORDER_BY_ASC = 1;
    protected const ORDER_BY_DESC = -1;

    /** @var string - Collection ID */
    protected $id;

    /** @var \PDO - connection */
    protected $connection;

    public $isDropped = false;

    /**
     * Constructor
     */
    public function __construct(string $id, PDO $connection)
    {
        $this->id = $id;
        $this->connection = $connection;

        $this->createIfNotExists();
    }

    /**
     * @inheritdoc
     *
     * [NOT USED]
     * Note: MongoLite\Collection passes only criteria and projection and expects Cursor
     * TODO: return generator which behaves like \MongoLite\Cusor
     */
    public function find($criteria = null, array $projection = null): array
    {
        return [];
    }

    /**
     * @inheritdoc
     *
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
                `{$this->id}` AS `c`
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
        */
    }

    /**
     * @inheritdoc
     *
     * [NOT USED]
     */
    public function renameCollection(string $newname): bool
    {
        $stmt = $this->connection->prepare(<<<SQL

            RENAME TABLE
                `{$this->id}`
            TO
                `{$newname}`
SQL
        );

        $stmt->execute();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function drop(): bool
    {
        $stmt = $this->connection->prepare(<<<SQL

            DROP TABLE IF EXISTS
                `{$this->id}`
SQL
        );

        $stmt->execute();

        $this->isDropped = true;

        return true;
    }

    /**
     * Create table if does not exist
     */
    protected function createIfNotExists(): void
    {
        $stmt = $this->connection->prepare(<<<SQL

            SHOW TABLES LIKE '{$this->id}'
SQL
        );

        $stmt->execute();

        if ($stmt->fetchColumn()) {
            return;
        }

        $this->create();

        return;
    }

    /**
     * Create collection
     */
    public function create()
    {
        $stmt = $this->connection->prepare(<<<SQL

            CREATE TABLE IF NOT EXISTS `{$this->id}` (
                `id`       INT  NOT NULL AUTO_INCREMENT,
                `document` JSON NOT NULL,
                `_id_virtual`       VARCHAR(24) AS (`document` ->> '$._id')                      NOT NULL UNIQUE COMMENT 'Id',
                `_created_virtual`  TIMESTAMP   AS (FROM_UNIXTIME(`document` ->> '$._created'))  NOT NULL        COMMENT 'Created at',
                `_modified_virtual` TIMESTAMP   AS (FROM_UNIXTIME(`document` ->> '$._modified'))     NULL        COMMENT 'Modified at',
                PRIMARY KEY (`id`)
            )
SQL
        );

        /*
        // keyval (cockpit.memory.sqlite) has different signature
        if ($this->id === 'cockpit.memory') {
            $stmt = $this->connection->prepare(<<<SQL

                CREATE TABLE IF NOT EXISTS `{$this->id}` (
                    `key`    VARCHAR NOT NULL,
                    `keyval` TEXT        NULL,
                    UNIQUE KEY (`key`)
                )
SQL
            );
        }
        */

        $stmt->execute();
    }
}
