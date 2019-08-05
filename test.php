<?php
/**
 * Test MongoMysqlJson driver
 */

use LimeExtra\App;
use PHPMailer\PHPMailer\Exception;

// Using config/config.php
// define('COCKPIT_CONFIG_PATH', __DIIR__ . '/config/config-test.php');

require_once __DIR__ . '/bootstrap.php';

/**
 * Assert function. Like phps' assert but executed in production mode
 * Note: stops app execution and teardown is not executed
 */
function assertResult(bool $result, string $description = null): bool {
    if (!$result) {
        throw new AssertionError($description);
    }

    return true;
}

class DatabaseTest
{
    /** @var string */
    protected $mockCollectionName = 'test01';

    /** @var array */
    protected $mockCollectionData = [
        [
            'content' => 'Lorem ipsum',
            'array' => ['foo'],
            '_o' => 1,
            '_id' => '5d41792c3961382d610002e2',
            '_created' =>  1546297200.000,
            '_modified' => 1546297200.000,
        ],
        [
            'content' => 'Etiam tempor',
            'array' => ['foo', 'bar'],
            '_o' => 2,
            '_id' => '5d41792c3961382d610002e3',
            '_created' =>  1546297200.000,
            '_modified' => 1546297200.000,
        ]
    ];

    /** @var \LimeExtra\App */
    protected $app;

    /** @var \MongoHybrid\Client */
    protected $storage;

    /** @var \PDO */
    protected $connection;

    /**
     * Constructor
     */
    public function __construct(App $app)
    {
        $this->app = $app;

        /** @type \MongoHybrid\Client */
        $this->storage = $app->storage;

        /** @type \Lime\Module */
        $this->collectionsModule = $this->app->module('collections');

        // Get driver
        $storageReflection = new ReflectionClass($this->storage);
        $driverProperty = $storageReflection->getProperty('driver');
        $driverProperty->setAccessible(true);
        $driver = $driverProperty->getValue($this->storage);

        // Get PDO Connection
        $driverReflection = new ReflectionClass($driver);
        $connectionProperty = $driverReflection->getProperty('connection');
        $connectionProperty->setAccessible(true);
        $this->connection = $connectionProperty->getValue($driver);

        $this->setUp();
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->tearDown();
    }

    /**
     * Set up
     */
    protected function setUp(): void
    {
        // Create collection file via cockpit API
        $this->app->module('collections')->createCollection($this->mockCollectionName, [
            'fields' => [
                [
                    'name' => 'content',
                    'type' => 'text',
                ],
                [
                    'name' => 'array',
                    'type' => 'select',
                    'options' => [
                        'options' => 'Foo, Bar',
                        'default' => 'Foo',
                    ],
                ]
            ]
        ]);

        // Create test table
        $stmt = $this->connection->query(<<<SQL

            CREATE TABLE IF NOT EXISTS `collections/{$this->mockCollectionName}` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `document` JSON NOT NULL,
                PRIMARY KEY (`id`)
            )
SQL
        );

        // Empty table
        $stmt = $this->connection->query(<<<SQL

            TRUNCATE
                `collections/{$this->mockCollectionName}`
SQL
        );

        // Add mock data
        $stmt = $this->connection->prepare(<<<SQL

            INSERT INTO
                `collections/{$this->mockCollectionName}` (`document`)
            VALUES (:data)
SQL
        );

        foreach ($this->mockCollectionData as $mockCollection) {
            $stmt->execute([':data' => json_encode($mockCollection)]);
        }
    }

    /**
     * Tear down
     */
    public function tearDown(): void
    {
        // Remove collection file via cockpit API
        $this->app->module('collections')->removeCollection('test01');

        // Drop test table
        $this->connection->query(<<<SQL

            DROP TABLE `collections/{$this->mockCollectionName}`
SQL
        );
    }

    /**
     * Test collection find
     */
    public function testCollectionFind(): self
    {
        /** @type \MongoHybrid\ResultSet|ArrayObject */
        $items = $this->storage->find('collections/' . $this->mockCollectionName);
        // $items = $this->app->module('collections')->find('performers', []);

        assertResult(count($items) > 0);

        $this->testCollectionFindFilter();
        $this->testCollectionFindFields();
        $this->testCollectionFindLimit();
        $this->testCollectionFindSort();
        $this->testCollectionFindSkip();

        return $this;
    }

    /**
     * Test filter
     */
    public function testCollectionFindFilter()
    {
        // Simple filter by value
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                'content' => 'Etiam tempor',
            ]
        ]);

        assertResult(count($items) === 1, 'Failed to find one item via filter');
        assertResult($items[0]['content'] === 'Etiam tempor');


        // Assert $and operator
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['content' => ['$eq' => 'Etiam tempor']],
                    ['_o' => ['$eq' => 2]],
                ],
            ]
        ]);


        // Assert non-documented and
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['content' => ['$eq' => 'Etiam tempor', '$regex' => 'Etiam']],
                ],
            ]
        ]);

        assertResult(count($items) === 1, 'Failed to find one item via $eq');
        assertResult($items[0]['content'] === 'Etiam tempor');


        // Assert $or operator
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$or' => [
                    ['content' => ['$eq' => 'Lorem ipsum']],
                    ['content' => ['$eq' => 'Etiam tempor']],
                ],
            ]
        ]);

        assertResult(
            count($items) === 2,
            'Failed to find one item via $eq'
        );


        // Assert $eq func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['content' => ['$eq' => 'Etiam tempor']],
                ],
            ]
        ]);

        assertResult(count($items) === 1, 'Failed to find one otem via $eq');
        assertResult($items[0]['content'] === 'Etiam tempor');


        // Assert $not/ $ne func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['content' => ['$not' => 'Etiam tempor']],
                ],
            ]
        ]);

        assertResult(
            count($items) && $items[0]['content'] === 'Lorem ipsum',
            'Failed $neq for string'
        );


        // Assert $gt func
        $items = $this->storage->find('collections/'. $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['_o' => ['$gt' => 1]]
                ]
            ]
        ]);

        assertResult(
            count($items) && $items[0]['_o'] > 1,
            'Failed $gt'
        );


        // Assert $in func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['_o' => ['$in' => [2, 3]]]
                ]
            ]
        ]);

        assertResult(
            count($items) && $items[0]['_o'] === 2,
            'Failed $in'
        );


        // Assert $nin func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['_o' => ['$nin' => [2, 3]]]
                ]
            ]
        ]);

        assertResult(
            count($items) && $items[0]['_o'] === 1,
            'Failed $nin'
        );


        // Assert $has func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['array' => ['$has' => 'foo']]
                ]
            ]
        ]);

        assertResult(
            count($items) && in_array('foo', $items[1]['array']),
            'Failed $has'
        );


        // Assert $all func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['array' => ['$all' => ['foo', 'bar']]]
                ]
            ]
        ]);

        assertResult(
            count($items) && $items[0]['array'] == ['foo', 'bar'],
            'Failed $all'
        );


        // Assert $preg/ $match/ $regex func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['content' => ['$regex' => 'Lorem.*']]
                ]
            ]
        ]);

        assertResult(
            count($items) && $items[0]['content'] == 'Lorem ipsum',
            'Failed $regex'
        );


        // Assert $size func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['array' => ['$size' => 2]]
                ]
            ]
        ]);

        assertResult(
            count($items) && count($items[0]['array']) == 2,
            'Failed $size'
        );


        // Assert $mod func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['_o' => ['$mod' => [2 => null]]]
                    // ['_o' => ['$mod' => 2]]
                ]
            ]
        ]);

        assertResult(
            count($items) && fmod($items[0]['_o'], 2) == 0,
            'Failed $mod'
        );


        // Assert $func/ $fn/ $f func
        // Not implemented


        // Assert $exists func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['_o' => ['$exists' => true]]
                ]
            ]
        ]);

        assertResult(
            count($items) && isset($items[0]['_o']),
            'Failed $exists'
        );


        // Assert $fuzzy func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['content' => ['$fuzzy' => 'Etiam tmpor']]
                ]
            ]
        ]);


        // Assert $text func
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'filter' => [
                '$and' => [
                    ['content' => ['$text' => 'Etiam tempo']]
                ]
            ]
        ]);

        assertResult(
            count($items) && strpos($items[0]['content'], 'Etiam tempo') !== false,
            'Failed $text'
        );
    }

    /**
     * Test fields (projection)
     * Returned items have added/ removed properties
     */
    public function testCollectionFindFields()
    {
        // TODO
    }

    /**
     * Test limit
     */
    public function testCollectionFindLimit(): void
    {
        $items = $this->storage->find('collections/'. $this->mockCollectionName, [
            'limit' => 1,
        ]);

        assertResult(count($items) === 1);
    }

    /**
     * Test sort
     */
    public function testCollectionFindSort(): void
    {
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'sort' => ['content' => 1],
        ]);

        assertResult($items[0]['content'] < $items[1]['content']);
    }

    /**
     *  Test Skip
     */
    public function testCollectionFindSkip(): void
    {
        $items = $this->storage->find('collections/' . $this->mockCollectionName, [
            'limit' => 99,
            'skip' => 1,
        ]);

        assertResult($items[0]['_o'] !== 1);
    }

    /**
     * Test collection find one
     */
    public function testCollectionFindOne(): self
    {
        // $item = $this->app->module('collections')->findOne('performers', []); var_dump($item); die();
        $item = $this->storage->findOne('collections/' . $this->mockCollectionName);

        assertResult($item['_o'] === 1, 'Find one');

        return $this;
    }
}

$test = new DatabaseTest(cockpit());

try {
    $test
        ->testCollectionFind()
        ->testCollectionFindOne()
    ;

    exit('All tests passed');
} catch (Throwable $throwable) {
    $test->tearDown();

    throw $throwable;
}
