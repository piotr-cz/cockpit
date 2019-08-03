<?php
/**
 * Just use MongoHybrid\ResultSet
 */
namespace MySqlJson;

use PDO;

/**
 * Collection. Not sure why it's required by MongoHybrid\Client
 */
class Collection
{
    /** @var string */
    protected $name;

    /** @var \PDO - connection */
    protected $pdo;

    /**
     * Constructor
     */
    public function __construct(string $name, PDO $pdo)
    {
        $this->name = $name;
        $this->pdo = $pdo;

        $this->createIfNotExists();
    }

    /**
     * Create table if does not exist
     */
    protected function createIfNotExists(): void
    {
        $sql = <<<SQL

            SHOW TABLES LIKE '{$this->name}'
SQL;

        $stmt = $this->pdo->prepare($sql);
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

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return;
    }

    /**
     * Drop Table
     */
    public function drop()
    {
        $sql = <<<SQL

            DROP TABLE
                `{$this->name}`
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return;
    }

    /**
     * Rename table
     * Not used
     */
    public function renameCollection(string $newname): bool
    {
        $sql = <<<SQL

            RENAME TABLE
                `{$this->name}`
            TO
                `{$newname}`
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return true;
    }

    // TODO: Criteria
    public function count($criteria = null): int
    {
        if ($criteria) {
            var_dump($crieria);
            die('count');
        }

        $sql = <<<SQL

            SELECT
                COUNT(*)
            FROM
                `{$this->name}` AS `c`
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
