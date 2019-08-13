<?php
namespace MongoMysqlJson;

use PDO;

use Traversable;
use IteratorAggregate;

use IteratorIterator;
use CallbackFilterIterator;
use LimitIterator;

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
    public function getIterator(): Traversable
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

        $stmt->setFetchMode(PDO::FETCH_COLUMN, 0);
        $stmt->execute();

        $it = new MapIterator($stmt, [QueryBuilder::class, 'jsonDecode']);

        if (is_callable($this->filter)) {
            $it = new CallbackFilterIterator($it, $this->filter);
            // Note: Rewinding LimitIterator empties it
            $it = new LimitIterator($it, $this->options['skip'] ?? 0, $this->options['limit'] ?? -1);
        }

        $projection = static::compileProjection($this->options['projection']);

        return new MapIterator($it, [static::class, 'applyDocumentProjection'], $projection);
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
    public static function applyDocumentProjection(?array $document, array $projection = null): ?array
    {
        if (empty($document) || empty($projection)) {
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

/**
 * Apply callback to every element
 */
class MapIterator extends IteratorIterator
{
    /** @var callable */
    protected $callback;

    /** @var array - Callable arguments */
    protected $args = [];

    /**
     * Constructor
     *
     * @param \Traversable $iterator
     * @param callable $callback
     * @param mixed ...$args
     */
    public function __construct(Traversable $iterator, callable $callback, ...$args)
    {
        parent::__construct($iterator);

        $this->callback = $callback;
        $this->args = $args;
    }

    /**
     * @inheritdoc
     */
    public function current()
    {
        return ($this->callback)(parent::current(), ...$this->args);
    }
}
