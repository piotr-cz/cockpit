<?php
namespace MongoMysqlJson;

use PDO;

use Generator;
use IteratorAggregate;

use MongoMysqlJson\ {
    CursorInterface,
    QueryBuilder
};

/**
 * Cursor implementation
 * @see {@link MongoDB\Driver\Cursor https://www.php.net/manual/en/class.mongodb-driver-cursor.php}
 * @see MongoDB\Operation\Find
 *
 * @note this is different than https://www.php.net/manual/en/class.mongocursor.php
 */
class Cursor implements IteratorAggregate, CursorInterface
{
    /** @var \PDO */
    protected $connection;

    /** @var \MongoMysqlJson\QueryBuilder */
    protected $queryBuilder;

    /** @var string */
    protected $collectionName;

    /** @var array|callable|null */
    protected $filter;

    /** @var array */
    protected $options = [
        'sort'       => null,
        'limit'      => null,
        'skip'       => null,
        'projection' => null,
    ];

    /**
     * Constructor
     *
     * @param \PDO $connection
     * @param string $collectionName
     * @param array|callable $filter
     * @param array $options {
     *   @var array [$sort]
     *   @var int [$limit]
     *   @var int [$skip]
     *   @var array [$projection]
     * }
     */
    public function __construct(PDO $connection, QueryBuilder $queryBuilder, string $collectionName, $filter = [], array $options = [])
    {
        $this->connection = $connection;
        $this->queryBuilder = $queryBuilder;

        $this->collectionName = $collectionName;
        $this->filter = $filter;
        $this->options = array_merge($this->options, $options);
    }

    /**
     * @inheritdoc
     */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator());
    }

    /**
     * Get Traversable
     * IteratorAggregate implementation
     *
     * @see {@link https://www.php.net/manual/en/class.generator.php}
     * @return \Traversable
     */
    public function getIterator(): Generator
    {
        $sqlWhere = !is_callable($this->filter) ? $this->queryBuilder->buildWhere($this->filter) : null;
        $sqlOrderBy = $this->queryBuilder->buildOrderBy($this->options['sort']);
        $sqlLimit = !is_callable($this->filter) ? $this->queryBuilder->buildLimit($this->options['limit'], $this->options['skip']) : null;

        // Build query
        $sql = <<<SQL

            SELECT
                `document`

            FROM
                `{$this->collectionName}`

            {$sqlWhere}
            {$sqlOrderBy}
            {$sqlLimit}
SQL;

        $stmt = $this->connection->prepare($sql);
        $stmt->execute();

        $projection = static::compileProjection($this->options['projection']);

        $docsCount = 0;
        $callablePosition = 0;

        // Fetch documents
        while ($result = $stmt->fetch(PDO::FETCH_COLUMN)) {
            $doc = QueryBuilder::jsonDecode($result);

            if (is_callable($this->filter)) {
                // Evaluate
                if (!($this->filter)($doc)) {
                    continue;
                }

                if ($callablePosition++ < $this->options['skip']) {
                    continue;
                }
            }

            // Apply projection
            $doc = static::applyDocumentProjection($doc, $projection);

            $docsCount++;
            yield $doc;

            // Callable: Stop on limit
            if (is_callable($this->filter) && $docsCount >= $this->options['limit']) {
                break;
            }
        }

        return;
    }

    /**
     * Create projection schema
     */
    protected static function compileProjection(array $projection = null): ?array
    {
        if (empty($projection)) {
            return null;
        }

        $include = array_filter($projection);
        $exclude = array_diff($projection, $include);

        return [
            'include' => $include,
            'exclude' => $exclude,
        ];
    }

    /**
     * Apply projection to document
     *
     * @param array $document
     * @param array $projection {
     *   @var array $exclude
     *   @var array $include
     * }
     * @return array
     */
    protected static function applyDocumentProjection(array $document, array $projection = null): array
    {
        if (empty($projection)) {
            return $document;
        }

        $id = $document['_id'];
        $include = $projection['include'];
        $exclude = $projection['exclude'];

        // Remove keys
        if (!empty($exclude)) {
            $document = array_diff_key($document, $exclude);
        }

        // Keep keys (not sure why MongoLite::cursor uses custom function array_key_intersect)
        if (!empty($include)) {
            $document = array_intersect_key($document, $include);
        }

        // Don't remove `_id` via include unless it's explicitly excluded
        if (!isset($exclude['_id'])) {
            $document['_id'] = $id;
        }

        return $document;
    }
}
