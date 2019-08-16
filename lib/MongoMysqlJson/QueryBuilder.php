<?php
declare(strict_types=1);

namespace MongoMysqlJson;

use ErrorException;
use InvalidArgumentException;

/**
 * See \MongoLite\Database\UtilArrayQuery
 */
class QueryBuilder {
    // Ordering directions
    protected const ORDER_BY_ASC = 1;
    protected const ORDER_BY_DESC = -1;

    // Logical operators
    protected const GLUE_OPERATOR_AND = ' AND ';
    protected const GLUE_OPERATOR_OR  = ' OR ';

    protected const GLUE_OPERATOR = [
        '$and' => self::GLUE_OPERATOR_AND,
        '$or'  => self::GLUE_OPERATOR_OR,
    ];

    /** @var callable */
    protected $connectionQuote;

    /** @var string - Dialect */
    protected $driverName;

    /**
     * Constructor
     */
    public function __construct(callable $connectionQuote, string $driverName = 'mysql')
    {
        $this->connectionQuote = $connectionQuote;
        $this->driverName = $driverName;
    }

    /**
     * Build ORDER BY subquery
     *
     * @param array|null $sorts
     * @return string|null
     */
    public function buildOrderby(array $sorts = null): ?string
    {
        if (!$sorts) {
            return null;
        }

        $sqlOrderBySegments = [];

        foreach ($sorts as $fieldName => $direction) {
            $sqlOrderBySegments[] = $this->buildOrderBySegment($fieldName, $direction);
        }

        return !empty($sqlOrderBySegments)
            ? 'ORDER BY ' . implode(', ', $sqlOrderBySegments)
            : null;
    }

    /**
     * Build single ORDER by segment
     *
     * @param string $fieldName
     * @param int $direction
     * @return string
     */
    protected function buildOrderBySegment(string $fieldName, int $direction): string
    {
        return vsprintf('"document" #>> %s %s', [
            $this->getPath($fieldName),
            $direction === static::ORDER_BY_DESC ? 'DESC' : 'ASC'
        ]);
    }

    /**
     * Build LIMIT subquery
     *
     * @param int|null $limit
     * @param int|null $offset
     * @return string|null
     */
    public function buildLimit(int $limit = null, int $offset = null): ?string
    {
        if (!$limit) {
            return null;
        }

        // Offset (limit must be provided)
        // https://stackoverflow.com/questions/255517/mysql-offset-infinite-rows
        if ($offset) {
            return sprintf('LIMIT %d OFFSET %d', $limit, $offset);
        }

        return sprintf('LIMIT %d', $limit);
    }

    /**
     * Build WHERE subquery
     *
     * @param array|null $criteria
     * @return string|null
     */
    public function buildWhere(array $criteria = null): ?string
    {
        if (!$criteria) {
            return null;
        }

        $segments = $this->buildWhereSegments($criteria);

        return !empty($segments)
            ? sprintf('WHERE %s', $segments)
            : null;
    }

    /**
     * Build WHERE segments
     *
     * See \MongoLite\Database\UtilArrayQuery::buildCondition
     *
     * @param array $criteria
     * @param string $concat
     * @return string|null
     */
    public function buildWhereSegments(array $criteria, string $concat = self::GLUE_OPERATOR_AND): ?string
    {
        $whereSegments = [];

        // Key may be field name or operator
        foreach ($criteria as $key => $value) {
            switch ($key) {
                // Operators: value is array of conditions
                case '$and':
                case '$or':
                    $whereSubSegments = [];

                    foreach ($value as $subCriteria) {
                        $whereSubSegments[] = $this->buildWhereSegments($subCriteria, static::GLUE_OPERATOR[$key]);
                    }

                    $whereSegments[] = '(' . implode(static::GLUE_OPERATOR[$key], $whereSubSegments) . ')';
                    break;

                // No operator:
                default:
                    // $not operator in values' key, condition in it's value
                    if (is_array($value) && array_keys($value) === ['$not']) {
                        $whereSegments[] = 'NOT ' . is_array($value['$not'])
                            ? $this->buildWhereSegments([$key => $value['$not']])
                            : $this->buildWhereSegmentsGroup($key, ['$regex' => $value['$not']]);
                        break;
                    }

                    // Value
                    $whereSegments[] = $this->buildWhereSegmentsGroup(
                        $key,
                        is_array($value) ? $value : ['$eq' => $value]
                    );
                    break;
            }
        }

        if (empty($whereSegments)) {
            return null;
        }

        return implode($concat, $whereSegments);
    }

    /**
     * Build where segments group
     *
     * See \MongoLite\Database\UtilArrayQuery::check
     */
    protected function buildWhereSegmentsGroup(string $fieldName, array $conditions): string
    {
        $subSegments = [];

        foreach ($conditions as $func => $value) {
            $subSegments[] = $this->buildWhereSegment($func, $fieldName, $value);
        }

        // Remove nulls
        $subSegments = array_filter($subSegments);

        return implode(static::GLUE_OPERATOR_AND, $subSegments);
    }

    /**
     * Build single where segment
     *
     * See \MongoLite\Database\UtilArrayQuery::evaluate
     *
     * @throws \InvalidArgumentException
     * @throws \ErrorException
     *
     * Use undocumented aliases if need to avoid question markes https://github.com/yiisoft/yii2/issues/15873
     * ?  | jsonb_exists
     * ?| | jsonb_exists_any
     * ?& | jsonb_exists_all
     */
    protected function buildWhereSegment(string $func, string $fieldName, $value): ?string
    {
        $path = $this->getPath($fieldName);

        switch ($func) {
            // Equals
            case '$eq':
                return vsprintf('"document" #>> %s = %s', [
                    $path,
                    $this->qv($value),
                ]);

            // Not equals
            case '$ne' :
                return vsprintf('"document" #>> %s <> %s', [
                    $path,
                    $this->qv($value),
                ]);

            // Greater or equals
            case '$gte' :
                return vsprintf('"document" #>> %s >= %s', [
                    $path,
                    $this->qv($value),
                ]);

            // Greater
            case '$gt' :
                return vsprintf('"document" #>> %s > %s', [
                    $path,
                    $this->qv($value),
                ]);

            // Lower or equals
            case '$lte' :
                return vsprintf('"document" #>> %s <= %s', [
                    $path,
                    $this->qv($value),
                ]);

            // Lower
            case '$lt' :
                return vsprintf('"document" #>> %s < %s', [
                    $path,
                    $this->qv($value),
                ]);

            // In array
            // When db value is an array, this evaluates to false
            // Could use JSON_OVERLAPS but it's MySQL 8+
            case '$in':
                return vsprintf('"document" #>> %s IN (%s)', [
                    $path,
                    $this->qvs($value),
                ]);

            // Not in array
            case '$nin':
                return vsprintf('"document" #>> %s NOT IN (%s)', [
                    $path,
                    $this->qvs($value),
                ]);

            /*
            // Reverse $in: lookup value in table
            case '$has': // value is scalar
            // Same array values
            // Doesn't use MEMBER OF as it's MySQL 8+
            case '$all': // value is an array
                if ($func === '$has' && is_array($value)) {
                    throw new InvalidArgumentException('Invalid argument for $has array not supported');
                }

                if ($func === '$all' && !is_array($value)) {
                    throw new InvalidArgumentException('Invalid argument for $all option must be array');
                }

                // MySQL
                return vsprintf('JSON_CONTAINS("document" -> %s, %s)', [
                    $path,
                    $this->qv(static::jsonEncode($value)),
                ]);
            */

            /*
            // Alternative implementation via strict comparision
            case '$all':
                return vsprintf('"document" -> %s = JSON_ARRAY(%s)', [
                    $path,
                    $this->qvs($value),
                ]);
            */

            // Warning: When using PDO, make sur it handles question mark properly
            case '$has':
                // return vsprintf('jsonb_exists("document" #> %s, %s)', [
                return vsprintf('"document" #> %s ? %s', [
                    $path,
                    $this->qv($value),
                ]);

            case '$all':
                // return vsprintf('jsonb_exists_all("document" #> %s, array[%s])', [
                return vsprintf('"document" #> %s ?& array[%s]', [
                    $path,
                    $this->qvs($value),
                ]);

            // Regexp (cockpit default is case sensitive)
            // Note: ^ doesn't work
            case '$preg':
            case '$match':
            case '$regex':
                return vsprintf('"document" #>> %s ~* %s', [
                    $path,
                    $this->qv('.*' . trim($value, '/') . '.*'),
                ]);

            // Array size
            case '$size':
                return vsprintf('jsonb_array_length("document" #> %s) = %s', [
                    $path,
                    $this->qv($value)
                ]);

            // Mod Mote: MongoLite uses arrays' first key for value
            // see https://docs.mongodb.com/manual/reference/operator/query/mod/
            case '$mod':
                if (!is_array($value)) {
                    throw new InvalidArgumentException('Invalid argument for $mod option must be array');
                }

                return vsprintf('CAST("document" #>> %s AS INTEGER) %% %s = %s', [
                    $path,
                    // Divisor
                    $this->qv($value[0]),
                    // Remainder
                    $this->qv($value[1] ?? 0),
                ]);

            // User defined function
            case '$func':
            case '$fn':
            case '$f':
                throw new InvalidArgumentException(sprintf('Function %s not supported by database driver', $func), 1);

            // Path exists
            // Warning: doesn't check if key exists
            case '$exists':
                return vsprintf('"document" #>> %s %s NULL', [
                    $path,
                    $value ? 'IS NOT' : 'IS',
                ]);

            // Fuzzy search
            // Note: PHP produces 4 char string while MySQL longer ones
            // Note: no idea how to implement. SOUNDEX doesn't search in strings.
            case '$fuzzy':
                throw new InvalidArgumentException(sprintf('Function %s not supported by database driver', $func), 1);

            // Text search
            case '$text':
                if (is_array($value)) {
                    throw new InvalidArgumentException(sprintf('Options for %s function are not suppored by database driver', $func), 1);
                }

                return vsprintf('"document" #>> %s LIKE %s', [
                    $path,
                    // Escape placeholders
                    $this->qv('%' . strtr($value, [
                        '_' => '\\_',
                        '%' => '\\%',
                    ]) . '%'),
                ]);

            // Skip Mongo specific stuff
            case '$options':
                break;

            // Bail out on non-supported operator
            default:
                throw new ErrorException(sprintf('Condition not valid ... Use %s for custom operations', $func));
        }

        return null;
    }

    /**
     * Build query checking if table exists
     */
    public function buildDoesTableExist(string $tableName): ?string
    {
        if ($this->driverName === 'mysql') {
            return sprintf("SHOW TABLES LIKE '%s'", $tableName);
        }

        if ($this->driverName === 'pgsql') {
            return sprintf("SELECT to_regclass('%s')", $tableName);
        }

        return null;
    }

    /**
     * Build query to create table
     */
    public function buildCreateTable(string $tableName): ?string
    {
        if ($this->driverName === 'mysql') {
            return <<<SQL

                CREATE TABLE IF NOT EXISTS "{$tableName}" (
                    "id"       INT  NOT NULL AUTO_INCREMENT,
                    "document" JSON NOT NULL,
                    "_id_virtual"       VARCHAR(24) AS ("document" ->> '$._id')                      NOT NULL UNIQUE COMMENT 'Id',
                    "_created_virtual"  TIMESTAMP   AS (FROM_UNIXTIME("document" ->> '$._created'))  NOT NULL        COMMENT 'Created at',
                    "_modified_virtual" TIMESTAMP   AS (FROM_UNIXTIME("document" ->> '$._modified'))     NULL        COMMENT 'Modified at',
                    PRIMARY KEY ("id")
                )
SQL;
        }

        if ($this->driverName === 'pgsql') {
            return <<<SQL

                CREATE TABLE IF NOT EXISTS "{$tableName}" (
                    "id"       serial NOT NULL,
                    "document" JSONB  NOT NULL,
                    -- Generated columns requires PostgreSQL 12+
                    -- "_id_virtual" VARCHAR(24) GENERATED ALWAYS AS ("document" #> '_id') STORED,
                    PRIMARY KEY ("id")
                );
SQL;
        }

        return null;
    }

    /**
     * Get quoted path
     */
    protected function getPath(string $fieldName): string
    {
        $path = $fieldName;

        // Format field name (dot separated)
        switch ($this->driverName) {
            case 'mysql':
                $path = sprintf('$.%s', $fieldName);
                break;

            case 'pgsql':
                $path = sprintf('{%s}', str_replace('.', ',', $fieldName));
                break;
        }

        return $this->qv($path);
    }

    /**
     * Quote JSON value
     * Note: All types are quotes as strings {@link https://stackoverflow.com/a/5356779/1012616}
     *
     * @param mixed $value
     * @throws \InvalidArgumentException
     */
    protected function qv($value)
    {
        // Quote everything as it's sql ANSI standard
        if ($this->driverName !== 'mysql') {
            return ($this->connectionQuote)((string) $value);
        }

        $type = gettype($value);

        // Don't quote numeric values
        if (in_array($type, ['boolean', 'integer', 'double', 'NULL'])) {
            return $value;
        }

        // Quote strings
        if ($type === 'string') {
            return ($this->connectionQuote)($value);
        }

        // Quote objects
        if ($type === 'object' && method_exists($value, '__toString')) {
            return ($this->connectionQuote)((string) $value);
        }

        throw new InvalidArgumentException(sprintf('Invalid value type %s', $type));
    }

    /**
     * Quote values
     * @param arrary $values
     * @return string
     */
    protected function qvs(array $values): string
    {
        return implode(', ', array_map([$this, 'qv'], $values));
    }

    /**
     * Quote identifier (table or column name)
     * [NOT USED]
     * @param string $identifier
     * @return string
     *
     * Better use SQL Standard (double quotes)
     */
    protected function qi(string $identifier): string
    {
        switch ($this->driverName) {
            case 'mysql':
                return '`' . str_replace('`', '``', $identifier) . '`';

            case 'pgsql':
                return '"' . str_replace('"', '\"', $identifier) . '"';

            default:
                return $identifier;
        }
    }

    /**
     * Encode value helper
     *
     * @param mixed $value
     * @return string
     */
    public static function jsonEncode($value): string
    {
        // Slashes are nomalized by MySQL anyway
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode value helper
     *
     * @param string $string
     * @return mixed
     */
    public static function jsonDecode(string $string)
    {
        return json_decode($string, true);
    }
}
