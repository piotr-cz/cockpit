<?php
declare(strict_types=1);

namespace MongoMysqlJson\QueryBuilder;

use ErrorException;
use InvalidArgumentException;

use MongoMysqlJson\QueryBuilder;

/**
 * PostgreSQL Query builder
 * @see {@link https://www.postgresql.org/docs/9.4/functions-json.html}
 */
class PgsqlQueryBuilder extends QueryBuilder
{
    /**
     * @inheritdoc
     * @param bool $asObject - Get JSON object at as text
     */
    protected function createPathSelector(string $fieldName, bool $asText = true): string
    {
        return vsprintf('"document" %s \'{%s}\'', [
            $asText ? '#>>' : '#>',
            str_replace('.', ',', $fieldName)
        ]);
    }

    /**
     * @inheritdoc
     *
     * Use undocumented aliases if need to avoid using question marks https://github.com/yiisoft/yii2/issues/15873
     * ?  | jsonb_exists
     * ?| | jsonb_exists_any
     * ?& | jsonb_exists_all
     */
    protected function buildWhereSegment(string $func, string $fieldName, $value): ?string
    {
        $pathTextSelector = $this->createPathSelector($fieldName);
        $pathObjectSelector = $this->createPathSelector($fieldName, false);

        switch ($func) {
            case '$eq':
                return vsprintf('%s = %s', [
                    $pathTextSelector,
                    $this->qv($value),
                ]);

            case '$ne' :
                return vsprintf('%s <> %s', [
                    $pathTextSelector,
                    $this->qv($value),
                ]);

            case '$gte' :
                return vsprintf('%s >= %s', [
                    $pathTextSelector,
                    $this->qv($value),
                ]);

            case '$gt' :
                return vsprintf('%s > %s', [
                    $pathTextSelector,
                    $this->qv($value),
                ]);

            case '$lte' :
                return vsprintf('%s <= %s', [
                    $pathTextSelector,
                    $this->qv($value),
                ]);

            case '$lt' :
                return vsprintf('%s < %s', [
                    $pathTextSelector,
                    $this->qv($value),
                ]);

            case '$in':
                return vsprintf('%s IN (%s)', [
                    $pathTextSelector,
                    $this->qvs($value),
                ]);

            case '$nin':
                return vsprintf('%s NOT IN (%s)', [
                    $pathTextSelector,
                    $this->qvs($value),
                ]);

            // Warning: When using PDO, make sure it handles question mark properly
            case '$has':
                return vsprintf('%s ? %s', [
                // return vsprintf('jsonb_exists(%s, %s)', [
                    $pathObjectSelector,
                    $this->qv($value)
                ]);

            case '$all':
                return vsprintf('%s ?& array[%s]', [
                // return vsprintf('jsonb_exists_all(%s, array[%s])', [
                    $pathObjectSelector,
                    $this->qvs($value),
                ]);

            // Note: cockpit default is case sensitive
            // See https://www.postgresql.org/docs/9.3/functions-matching.html#FUNCTIONS-POSIX-REGEXP
            case '$preg':
            case '$match':
            case '$regex':
                return vsprintf('%s ~* %s', [
                    $pathTextSelector,
                    $this->qv(trim($value, '/')),
                ]);

            case '$size':
                return vsprintf('jsonb_array_length(%s) = %s', [
                    $pathObjectSelector,
                    $this->qv($value)
                ]);

            // See https://www.postgresql.org/docs/7.4/functions-math.html
            case '$mod':
                if (!is_array($value)) {
                    throw new InvalidArgumentException('Invalid argument for $mod option must be array');
                }

                return vsprintf('(%s)::int %% %s = %s', [
                    $pathTextSelector,
                    // Divisor
                    $this->qv($value[0]),
                    // Remainder
                    $this->qv($value[1] ?? 0),
                ]);

            case '$func':
            case '$fn':
            case '$f':
                throw new InvalidArgumentException(sprintf('Function %s not supported by database driver', $func), 1);

            // Warning: doesn't check if key exists
            case '$exists':
                return vsprintf('%s %s NULL', [
                    $pathTextSelector,
                    $value ? 'IS NOT' : 'IS',
                ]);

            // Note: no idea how to implement.
            case '$fuzzy':
                throw new InvalidArgumentException(sprintf('Function %s not supported by database driver', $func), 1);

            case '$text':
                if (is_array($value)) {
                    throw new InvalidArgumentException(sprintf('Options for %s function are not suppored by database driver', $func), 1);
                }

                return vsprintf('(%s)::text LIKE %s', [
                    $pathTextSelector,
                    // Escape placeholders
                    $this->qv('%' . strtr($value, [
                        '_' => '\\_',
                        '%' => '\\%',
                    ]) . '%'),
                ]);

            // Skip Mongo specific stuff
            case '$options':
                break;

            default:
                throw new ErrorException(sprintf('Condition not valid ... Use %s for custom operations', $func));
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function buildTableExists(string $tableName): string
    {
        return sprintf("SELECT to_regclass('%s')", $tableName);
    }

    /**
     * @inheritdoc
     */
    public function buildCreateTable(string $tableName): string
    {
        return <<<SQL

            CREATE TABLE IF NOT EXISTS "{$tableName}" (
                "id"       serial NOT NULL,
                "document" jsonb  NOT NULL,
                -- Generated columns requires PostgreSQL 12+
                -- "_id_virtual" VARCHAR(24) GENERATED ALWAYS AS ("document" #> '_id') STORED,
                PRIMARY KEY ("id")
            );

            -- Add index to _id
            CREATE UNIQUE INDEX "idx_{$tableName}_id" ON "${tableName}" ((("document" ->> '_id')::text));
SQL;
    }

    /**
     * @inheritdoc
     *
     * Note: PostgreSQL JSON functions generally requires quoted values
     */
    public function qv($value, bool $jsonEncode = false): string
    {
        // When using #>
        if ($jsonEncode) {
            $value = static::jsonEncode($value);
        }

        // When using #>>
        // Should quote everything as it's sql ISO/ ANSI standard
        return parent::qv($value);
    }

    /**
     * @inheritdoc
     */
    public function qvs(array $values, bool $jsonEncode = false): string
    {
        $jsonEncodeArgs = array_fill(0, count($values), $jsonEncode);

        return implode(', ', array_map([$this, 'qv'], $values, $jsonEncodeArgs));
    }

    /**
     * @inheritdoc
     */
    public function qi(string $identifier): string
    {
        return '"' . str_replace('"', '\"', $identifier) . '"';
    }
}
