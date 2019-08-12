<?php
namespace Test\MongoHybrid;

use InvalidArgumentException;

use PHPUnit\Framework\TestCase;

/**
 * Test MongoHybrid\Client configured with MySqlJson driver
 */
class ClientTest extends TestCase
{
    /** @var \MongoHybrid\Client - Storage client */
    protected static $storage;

    /** @var \PDO - PDO Connection */
    protected static $connection;

    /** @var string - Mock collection id pattern */
    protected static $mockCollectionIdPattern = 'collections/test%s';

    /** @var array - Mock collection items (no IDs) */
    protected static $mockCollectionItemsDefs = [
        [
            'content' => 'Lorem ipsum',
            'array' => ['foo'],
            '_o' => 1,
            '_created' =>  1546297200.000,
            '_modified' => 1546297200.000,
        ],
        [
            'content' => 'Etiam tempor',
            'array' => ['foo', 'bar'],
            '_o' => 2,
            '_created' =>  1546297200.000,
            '_modified' => 1546297200.000,
        ]
    ];

    /** @var string - Mock collection id */
    protected $mockCollectionId;

    /** @var array - Mock collection items */
    protected $mockCollectionItems = [];

    /**
     * @inheritdoc
     */
    public static function setUpBeforeClass(): void
    {
        $cockpit = cockpit();

        $databaseConfig = $cockpit['config']['database'];

        // Create new storage
        static::$storage = new \MongoHybrid\Client(
            $databaseConfig['server'],
            $databaseConfig['options'],
            $databaseConfig['driverOptions']
        );
    }

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        // Create new collection for each test to avoid conflicts
        $this->mockCollectionId = sprintf(static::$mockCollectionIdPattern, uniqid());

        // Create collection via storage
        static::$storage->getCollection($this->mockCollectionId);

        // Note: using storage insert creates new IDs
        foreach (static::$mockCollectionItemsDefs as $mockCollectionItem) {
            static::$storage->insert($this->mockCollectionId, $mockCollectionItem);

            $this->mockCollectionItems[] = $mockCollectionItem;
        }
    }

    /**
     * Test drop collection
     *
     * Can test only by checking database via raw connection
     * However SQLite doesn't work fine with additional connections (file lock)
     * @covers \MongoHybrid\Client::dropCollection
     */
    public function testDropCollection(): void
    {
        static::$storage->dropCollection($this->mockCollectionId);

        // In MongoLite driver

        $this->assertTrue(
            static::$storage->count($this->mockCollectionId) === 0
        );
    }

    /**
     * Test find
     *
     * @covers \MongoHybrid\Client::find
     */
    public function testFind(): void
    {
        $items = static::$storage->find($this->mockCollectionId);

        $this->assertTrue(count($items) > 0);
    }

    /**
     * Test find with filter
     *
     * @covers \MongoHybrid\Client::find
     */
    public function testFindFilter(): void
    {
        // Simple filter by value
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                'content' => 'Etiam tempor',
            ]
        ]);

        $this->assertTrue(count($items) === 1, 'Failed to find one item via filter');
        $this->assertTrue($items[0]['content'] === 'Etiam tempor');
    }

    /**
     * Test filter operators
     *
     * @covers \MongoHybrid\Client::find
     */
    public function testFindFilterOperators(): void
    {
        // No operators
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => ['_o' => ['$eq' => 2]]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] === 2
        );


        // Non-doumented and
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => ['content' => [
                '$eq' => 'Etiam tempor',
                '$regex' => 'Etiam',
            ]]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] === 2
        );


        // Assert $and operator
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['content' => ['$eq' => 'Etiam tempor']],
                    ['_o' => ['$eq' => 2]],
                ],
            ]
        ]);

        $this->assertTrue(count($items) === 1, 'Failed to find one item via filter using $and');
        $this->assertTrue($items[0]['content'] === 'Etiam tempor');


        // Assert non-documented and
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['content' => [
                        '$eq' => 'Etiam tempor',
                        '$regex' => 'Etiam'
                    ]],
                ],
            ]
        ]);

        $this->assertTrue(count($items) === 1, 'Failed to find one item via $eq');
        $this->assertTrue($items[0]['content'] === 'Etiam tempor');


        // Assert $or operator
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$or' => [
                    ['content' => ['$eq' => 'Lorem ipsum']],
                    ['content' => ['$eq' => 'Etiam tempor']],
                ],
            ]
        ]);

        $this->assertTrue(
            count($items) === 2,
            'Failed to find one item via $eq'
        );

        // Following works only on MongoDB driver
        if (static::$storage->type === 'mongodb') {
            // Assert $not operator (regex)
            $items = static::$storage->find($this->mockCollectionId, [
                'filter' => [
                    // MongoDB Driver
                    'content' => ['$not' => new \MongoDB\BSON\Regex('Lorem ipsum')],
                    // MySQL Driver
                    // 'content' => ['$not' => 'Lorem ipsum'],
                ]
            ]);

            $this->assertTrue(
                $items[0]['content'] !== 'Lorem ipsum',
                'Filter with $not operator using expression'
            );


            // Assert $not operator (document)
            $items = static::$storage->find($this->mockCollectionId, [
                'filter' => [
                    'content' => ['$not' => ['$eq' => 'Lorem ipsum']],
                ]
            ]);

            $this->assertTrue(
                $items[0]['content'] !== 'Lorem ipsum',
                'Filter with $not operator using regex'
            );
        }
    }

    /**
     * Test filter callback (not implemented in MongoDB Driver)
     *
     * @covers \MongoHybrid\Client::find
     */
    public function testFindFilterCallback()
    {
        // Doesn't work on MongoDB
        if (static::$storage->type === 'mongodb') {
            return;
        }

        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => function (array $item): bool {
                return $item['_o'] === 2;
            }
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] === 2,
            'Filter callback: ' . var_export($items->toArray(), true)
        );


        // Test Limit
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => function (array $item): bool {
                return in_array('bar', $item['array']);
            },
            'limit' => 1,
        ]);

        $this->assertTrue(
            count($items) === 1 && $items[0]['_o'] === 2,
            'Filter callback with limit: ' . var_export($items->toArray(), true)
        );

        // Test Skip
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => function (array $item): bool {
                return in_array('foo', $item['array']);
            },
            'limit' => 1,
            'skip' => 1,
        ]);

        $this->assertTrue(
            count($items) === 1 && $items[0]['_o'] === 2,
            'Filter callback with limit and skip: ' . var_export($items->toArray(), true)
        );
    }

    /**
     * Test filter funcs
     *
     * @covers \MongoHybrid\Client::find
     */
    public function testFindFilterFuncs()
    {
        // Assert $eq func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                'content' => ['$eq' => 'Etiam tempor'],
            ]
        ]);

        $this->assertTrue(count($items) === 1, 'Failed to find one otem via $eq');
        $this->assertTrue($items[0]['content'] === 'Etiam tempor');


        // Assert $ne func
        // see https://docs.mongodb.com/manual/reference/operator/query/ne/
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                'content' => [
                    '$ne' => 'Etiam tempor'
                ],
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['content'] === 'Lorem ipsum',
            'Failed $neq for string'
        );


        // Assert $gt func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '_o' => ['$gt' => 1],
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] > 1,
            'Failed $gt'
        );


        // Assert $in func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '_o' => ['$in' => [2, 3]],
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] === 2,
            'Failed $in'
        );


        // Assert $nin func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '_o' => ['$nin' => [2, 3]],
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] === 1,
            'Failed $nin'
        );


        // Assert $has func
        try {
            $items = static::$storage->find($this->mockCollectionId, [
                'filter' => [
                    'array' => ['$has' => 'foo'],
                ]
            ]);

            $this->assertTrue(
                count($items) && in_array('foo', $items[1]['array']),
                'Failed $has'
            );
        // Ignore on not implemented
        } catch (\MongoDB\Driver\Exception\ServerException $mongoException) {
            if ($mongoException->getMessage() !== 'unknown operator: $has') {
                throw $mongoException;
            }
        }


        // Assert $all func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                'array' => ['$all' => ['foo', 'bar']],
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['array'] == ['foo', 'bar'],
            'Failed $all'
        );


        // Assert $preg/ $match/ $regex func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                'content' => ['$regex' => 'Lorem.*'],
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['content'] == 'Lorem ipsum',
            'Failed $regex'
        );


        // Assert $size func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                'array' => ['$size' => 2],
            ]
        ]);

        $this->assertTrue(
            count($items) && count($items[0]['array']) == 2,
            'Failed $size'
        );


        // Assert $mod func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '_o' => ['$mod' => [2, 0]],
            ]
        ]);

        $this->assertTrue(
            count($items) && fmod($items[0]['_o'], 2) == 0,
            'Failed $mod'
        );


        /*
        // Assert $func/ $fn/ $f func
        // Doesn't seem to work in MongoLite (callable is mangled in var_export)
        // Not implemented in MysqlJson
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '_o' => ['$func' => function (array $item): bool { return $item['_o'] === 2; }],
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] === 2,
            'Failed $func func'
        );
        */


        // Assert $exists func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '_o' => ['$exists' => true],
            ]
        ]);

        $this->assertTrue(
            count($items) && isset($items[0]['_o']),
            'Failed $exists'
        );


        // Assert $fuzzy func
        // Not implemented in MysqlJson
        try {
            $items = static::$storage->find($this->mockCollectionId, [
                'filter' => [
                    'content' => ['$fuzzy' => 'temp'],
                ]
            ]);

            $this->assertTrue(
                count($items) && strpos($items[0]['content'], 'Etiam tempo') !== false,
                'Failed $fuzzy func'
            );
        } catch (InvalidArgumentException $exception) {
            if ($exception->getCode() !== 1) {
                throw $exception;
            }
        } catch (\MongoDB\Driver\Exception\ServerException $mongoException) {
            if ($mongoException->getMessage() !== 'unknown operator: $fuzzy') {
                throw $mongoException;
            }
        }

        // Assert $text func
        try {
            $items = static::$storage->find($this->mockCollectionId, [
                'filter' => [
                    'content' => ['$text' => 'Etiam tempo'],
                ]
            ]);

            $this->assertTrue(
                count($items) && strpos($items[0]['content'], 'Etiam tempo') !== false,
                'Failed $text'
            );
        } catch (\MongoDB\Driver\Exception\ServerException $mongoException) {
            if ($mongoException->getMessage() !== 'unknown operator: $text') {
                throw $mongoException;
            }
        }
    }

    /**
     * Test find with fields (projection)
     * Returned items have added/ removed properties
     *
     * @covers \MongoHybrid\Client::find
     */
    public function testFindFields(): void
    {
        // Remove when _id: false, only id is retuned ?
        $items = static::$storage->find($this->mockCollectionId, [
            'fields' => [
                'content' => false,
            ]
        ]);

        $this->assertTrue(
            !in_array('content', array_keys($items[0])),
            'Fields without content'
        );

        // Keep only
        $items = static::$storage->find($this->mockCollectionId, [
            'fields' => [
                'content' => true,
            ]
        ]);

        $itemKeys = array_keys($items[0]);
        $testKeys = ['_id', 'content'];

        // Note: id must be available unless it's explicitely blacklisted
        $this->assertTrue(
            array_diff($itemKeys, $testKeys) === array_diff($testKeys, $itemKeys),
            'Fields with only content and _id'
        );
    }

    /**
     * Test find with sort
     *
     * @covers \MongoHybrid\Client::find
     */
    public function testFindSort(): void
    {
        $items = static::$storage->find($this->mockCollectionId, [
            'sort' => ['content' => -1],
        ]);

        $this->assertTrue($items[0]['content'] > $items[1]['content']);
    }

    /**
     * Test find with limit
     *
     * @covers \MongoHybrid\Client::find
     */
    public function testFindLimit(): void
    {
        $items = static::$storage->find($this->mockCollectionId, [
            'limit' => 1,
        ]);

        $this->assertTrue(count($items) === 1);
    }

    /**
     * Test find with skip
     *
     * @covers \MongoHybrid\Client::find
     */
    public function testFindSkip(): void
    {
        $items = static::$storage->find($this->mockCollectionId, [
            'limit' => 99,
            'skip' => 1,
        ]);

        $this->assertTrue($items[0]['_o'] !== 1);
    }

    /**
     * Test find one item
     *
     * @covers \MongoHybrid\Client::findOne
     */
    public function testFindOne(): void
    {
        $item = static::$storage->findOne($this->mockCollectionId);

        $this->assertTrue($item['_o'] === 1);
    }

    /**
     * @covers \MongoHybrid\Client::findOneById
     */
    public function TODOtestFinOneById(): void
    {
        $itemId = $this->mockCollectionItems[0]['_id'];

        // TODO: use findOneById
        $item = static::$storage->findOne($this->mockCollectionId, ['_id' => $itemId]);

        $this->assertTrue(
            $item['_id'] === $itemId
        );
    }

    /**
     * Test save
     *
     * @covers \MongoHybrid\Client::save
     * @covers \MongoHybrid\Client::insert
     * @covers \MongoHybrid\Client::update
     * @covers \MongoHybrid\Client::count
     */
    public function testSave(): void
    {
        $item = [
            '_o' => 3,
            '_created' =>  1546297200.000,
            '_modified' => 1546297200.000,
        ];

        // Insert
        static::$storage->save($this->mockCollectionId, $item);

        $this->assertTrue(
            static::$storage->count($this->mockCollectionId, ['_o' => $item['_o']]) === 1,
            'Insert via Save'
        );

        // Update
        $item = [
            '_id' => $this->mockCollectionItems[1]['_id'],
            '_o' => 4,
            '_created' =>  1546297200.000,
            '_modified' => 1546297200.000,
        ];

        static::$storage->save($this->mockCollectionId, $item);

        $this->assertTrue(
            static::$storage->count($this->mockCollectionId, ['_o' => $item['_o']]) === 1,
            'Update via Save'
        );
    }

    /**
     * Test remove
     *
     * @covers \MongoHybrid\Client::remove
     */
    public function testRemove(): void
    {
        $item = $this->mockCollectionItems[0];
        $filter = ['id' => $item['_id']];

        static::$storage->remove($this->mockCollectionId, $filter);

        $this->assertTrue(
            static::$storage->count($this->mockCollectionId, $filter) === 0
        );
    }

    /**
     * Test count
     *
     * @covers \MongoHybrid\Client::count
     */
    public function testCount()
    {
        // Assert count with no filter
        $count = static::$storage->count($this->mockCollectionId);

        $this->assertTrue(
             $count === count($this->mockCollectionItems)
        );

        // Test count with array filter
        $count = static::$storage->count($this->mockCollectionId, [
            'content' => 'Lorem ipsum'
        ]);

        $this->assertTrue($count === 1);

        // Test count with callable filter
        $count = static::$storage->count($this->mockCollectionId, function (array $doc): bool {
            return $doc['content'] === 'Lorem ipsum';
        });

        $this->assertTrue($count === 1);
    }

    /**
     * Test remove field
     *
     * @covers \MongoHybridClient::removeField
     */
    public function testRemoveField()
    {
        static::$storage->removeField($this->mockCollectionId, 'content');

        $items = static::$storage->find($this->mockCollectionId);

        $this->assertTrue(
            !in_array('content', array_keys($items[0]))
        );
    }

    /**
     * Test rename field
     *
     * @covers \MongoHybridClient::renameField
     */
    public function testRenameField()
    {
        static::$storage->renameField($this->mockCollectionId, 'content', 'bio');

        $items = static::$storage->find($this->mockCollectionId);

        $this->assertTrue(
            !in_array('content', array_keys($items[0]))
        );

        $this->assertTrue(
            in_array('bio', array_keys($items[0]))
        );
    }

    /**
     * @inheritdoc
     */
    public function tearDown(): void
    {
        static::$storage->dropCollection($this->mockCollectionId);
    }
}