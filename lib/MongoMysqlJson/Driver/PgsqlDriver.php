<?php
declare(strict_types=1);

namespace MongoMysqlJson\Driver;

use PDO;

use MongoMysqlJson\Driver\Driver;

use MongoMysqlJson\QueryBuilder\PgsqlQueryBuilder;

/**
 * PostgreSQL Driver
 */
class PgsqlDriver extends Driver
{
    /** @inheritdoc */
    protected const DB_DRIVER_NAME = 'pgsql';

    /**
     * @inheritdoc
     * extended json added in 9.3
     * jsonb added in 9.4
     */
    protected const DB_MIN_SERVER_VERSION = '9.4';

    /** @inheritdoc */
    protected const QUERYBUILDER_CLASS = PgsqlQueryBuilder::class;

    /**
     * @inheritdoc
     */
    protected function createConnection(array $options, array $driverOptions = []): PDO
    {
        return new PDO(
            vsprintf('pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s', [
                $options['host'] ?? 'localhost',
                $options['port'] ?? 5432,
                $options['dbname'],
                $options['username'],
                $options['password'],
            ]),
            null,
            null,
            $driverOptions
        );
    }
}
