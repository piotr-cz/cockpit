<?php
declare(strict_types=1);

namespace MongoMysqlJson\Driver;

use PDO;

use MongoMysqlJson\Driver\Driver;

use MongoMysqlJson\QueryBuilder\MysqlQueryBuilder;

/**
 * MySQL Driver
 * Requires 5.7.9+ (JSON support and shorthand operators)
 */
class MysqlDriver extends Driver
{
    /** @inheritdoc */
    protected const DB_DRIVER_NAME = 'mysql';

    /** @inheritdoc */
    protected const DB_MIN_SERVER_VERSION = '5.7.9';

    /** @inheritdoc */
    protected const QUERYBUILDER_CLASS = MysqlQueryBuilder::class;

    /**
     * @inheritdoc
     */
    protected function createConnection(array $options, array $driverOptions = []): PDO
    {
        $connection = new PDO(
            vsprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', [
                $options['host'] ?? 'localhost',
                $options['port'] ?? 3306,
                $options['dbname'],
                $options['charset'] ?? 'UTF8'
            ]),
            $options['username'],
            $options['password'],
            $driverOptions + [
                // Note: Setting sql_mode doesn't work in init command, at least in 5.7.26
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4;',
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            ]
        );

        // Set Mysql_mode after connection has started to ISO/IEC 9075
        $connection->exec("SET sql_mode = 'ANSI';");

        return $connection;
    }
}
