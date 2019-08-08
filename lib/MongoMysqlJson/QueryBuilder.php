<?php
namespace MongoMysqlJson;

use ErrorException;
use InvalidArgumentException;

/**
 * See MongoLite\Database\UtilArrayQuery
 */
class QueryBuilder {
    protected const ORDER_BY_ASC = 1;
    protected const ORDER_BY_DESC = -1;

    // Logical operators
    protected const GLUE_OPERATOR_AND = ' && ';
    protected const GLUE_OPERATOR_OR  = ' || ';

    protected const GLUE_OPERATOR = [
        '$and' => self::GLUE_OPERATOR_AND,
        '$or'  => self::GLUE_OPERATOR_OR,
    ];

    /** @var callable */
    protected $connectionQuote;

    /**
     * Constructor
     */
    public function __construct(callable $connectionQuote)
    {
        $this->connectionQuote = $connectionQuote;
    }

    /**
     * Build ORDER BY subquery
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
            $sqlOrderBySegments[] = vsprintf("`c`.`document` -> '$.%s' %s", [
                $fieldName,
                $direction === static::ORDER_BY_DESC ? 'DESC' : 'ASC'
            ]);
        }

        return !empty($sqlOrderBySegments)
            ? 'ORDER BY ' . implode(', ', $sqlOrderBySegments)
            : null;
    }

    /**
     * Build LIMIT subquery
     * @param int|null $limit
     * @param int|null $offset
     * @return string|null
     */
    public function buildLimit(int $limit = null, int $offset = null)
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
     * See ::buildCondition
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
                    if (is_array($value) && array_keys($value) == ['$not']) {
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
     * See ::check
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
     * See ::evaluate
     * @throws \InvalidArgumentException
     * @throws \ErrorException
     */
    protected function buildWhereSegment(string $func, string $fieldName, $value): ?string
    {
        switch ($func) {
            // Equals
            case '$eq':
                return vsprintf("`c`.`document` -> '$.%s' = %s", [
                    $fieldName,
                    static::jsonEncode($value)
                ]);

            // Not equals
            case '$ne' :
                return vsprintf("`c`.`document` -> '$.%s' <> %s", [
                    $fieldName,
                    static::jsonEncode($value),
                ]);

            // Greater or equals
            case '$gte' :
                return vsprintf("`c`.`document` -> '$.%s' >= %s", [
                    $fieldName,
                    static::jsonEncode($value),
                ]);

            // Greater
            case '$gt' :
                return vsprintf("`c`.`document` -> '$.%s' > %s", [
                    $fieldName,
                    static::jsonEncode($value),
                ]);

            // Lower or equals
            case '$lte' :
                return vsprintf("`c`.`document` -> '$.%s' <= %s", [
                    $fieldName,
                    static::jsonEncode($value),
                ]);

            // Lower
            case '$lt' :
                return vsprintf("`c`.`document` -> '$.%s' < %s", [
                    $fieldName,
                    static::jsonEncode($value),
                ]);

            // In array
            // When db value is an array, this evaluates to false
            // Could use JSON_OVERLAPS but it's MySQL 8+
            case '$in':
                return vsprintf("`c`.`document` -> '$.%s' IN (%s)", [
                    $fieldName,
                    implode(', ', array_map('static::jsonEncode', $value)),
                ]);
                /*
                return vsprintf("JSON_OVERLAPS(`c`.`document` -> '$.%s', %s)", [
                    $fieldName,
                    ($this->connectionQuote)(static::jsonEncode($value)),
                ]);
                */
                break;

            // Not in array
            case '$nin':
                return vsprintf("`c`.`document` -> '$.%s' NOT IN (%s)", [
                    $fieldName,
                    implode(', ', array_map('static::jsonEncode', $value)),
                ]);
                break;

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

                return vsprintf("JSON_CONTAINS(`c`.`document`, %s, '$.%s')", [
                    ($this->connectionQuote)(static::jsonEncode($value)),
                    $fieldName,
                ]);

            /*
            // Alternative implementation
            case '$all':
                return = vsprintf("`c`.`document` -> '$.%s' = JSON_ARRAY(%s)", [
                    $fieldName,
                    implode(',', array_map('static::jsonEncode', $value)),
                ]);
            */

            // Regexp (cockpit default is case sensitive)
            // Note: ^ doesn't work
            case '$preg':
            case '$match':
            case '$regex':
                return vsprintf("LOWER(`c`.`document` -> '$.%s') REGEXP LOWER(%s)", [
                    $fieldName,
                    // Escape \ and trim /
                    static::jsonEncode(trim(str_replace('\\', '\\\\', $value), '/')),
                ]);

            // Array size
            case '$size':
                return vsprintf("JSON_LENGTH(`c`.`document`, '$.%s') = %s", [
                    $fieldName,
                    static::jsonEncode($value),
                ]);

            // Mod Mote: MongoLite uses arrays' first key for value
            // see https://docs.mongodb.com/manual/reference/operator/query/mod/
            case '$mod':
                if (!is_array($value)) {
                    throw new InvalidArgumentException('Invalid argument for $mod option must be array');
                }

                return vsprintf("MOD(`c`.`document` -> '$.%s', %d) = %d", [
                    $fieldName,
                    // Remainder
                    static::jsonEncode($value[0]),
                    // Divisor
                    static::jsonEncode($value[1] ?? 0)
                ]);

            // User defined function
            case '$func':
            case '$fn':
            case '$f':
                throw new InvalidArgumentException(sprintf('Function %s not supported by database driver', $func), 1);

            // Path exists
            // Warning: doesn't check if key exists
            case '$exists':
                return vsprintf("`c`.`document` -> '$.%s' %s NULL", [
                    $fieldName,
                    $value ? 'IS NOT' : 'IS'
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

                return vsprintf("`c`.`document` -> '$.%s' LIKE %s", [
                    $fieldName,
                    // Escape MySQL placeholders
                    static::jsonEncode('%' . strtr($value, [
                        '_' => '\\_',
                        '%' => '\\%'
                    ]) . '%'),
                ]);
                /*
                // Search in array or string (case sensitive)
                return = vsprintf("JSON_SEARCH(`c`.`document`, 'one', %s, NULL, '$.%s') IS NOT NULL", [
                    static::jsonEncode('%' . strtr($value, [
                        '_' => '\\_',
                        '%' => '\\%'
                    ]) . '%'),
                    $fieldName,
                ]);
                */

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
     * Encode value helper
     */
    public static function jsonEncode($value): string
    {
        // Slashes are nomalized by MySQL anyway
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode value helper
     */
    public static function jsonDecode(string $string)
    {
        return json_decode($string, true);
    }
}