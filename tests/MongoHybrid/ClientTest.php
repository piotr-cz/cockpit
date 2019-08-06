<?php
namespace Test\MongoHybrid;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test MongoHybrid\Client configured with MySqlJson driver
 */
class ClientTest extends TestCase
{
    /** @var \MongoHybrid\Client - Storage client */
    protected static $storage;

    /** @var \PDO */
    protected static $connection;

    /** @var string */
    protected static $mockCollectionIdPattern = 'collections/test%s';

    /** @var array */
    protected static $mockCollectionItems = [
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

    /** @var string */
    protected $mockCollectionId;

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

        // Create inpection connection
        static::$connection = new PDO(
            vsprintf('%s:host=%s;dbname=%s;charset=UTF8', [
                $databaseConfig['options']['connection'],
                $databaseConfig['options']['host'],
                $databaseConfig['options']['db']
            ]),
            $databaseConfig['options']['username'],
            $databaseConfig['options']['password'],
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

        // Add mock data
        $stmt = static::$connection->prepare(<<<SQL

            INSERT INTO
                `{$this->mockCollectionId}` (`document`)
            VALUES (
                :item
            )
SQL
        );

        foreach (static::$mockCollectionItems as $mockCollectionItem) {
            $stmt->execute([':item' => json_encode($mockCollectionItem)]);
        }

        /*
        // Note: using storage insert creates new IDs
        foreach (static::$mockCollectionItems as $mockCollectionItem) {
            static::$storage->insert($this->mockCollectionId, $mockCollectionItem);
        }
        */
    }

    /**
     * @inheritdoc
     */
    protected function assertPreConditions(): void
    {
    }

    /**
     * Can test only by check database
     * @covers \MongoHybrid\Client::dropCollection
     */
    public function testDropCollection(): void
    {
        static::$storage->dropCollection($this->mockCollectionId);

        $stmt = static::$connection->query(<<<SQL

            SHOW TABLES LIKE '{$this->mockCollectionId}'
SQL
        );

        $this->assertTrue($stmt->fetchColumn() === false);
    }

    /**
     * Test find
     * @covers \MongoHybrid\Client::find
     */
    public function testFind(): void
    {
        $items = static::$storage->find($this->mockCollectionId);

        $this->assertTrue(count($items) > 0);
    }

    /**
     * Test find with filter
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
     * @covers \MongoHybrid\Client::find
     */
    public function testFindFilterOperators(): void
    {
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
    }

    /**
     * Test filter callback
     * @covers \MongoHybrid\Client::find
     */
    public function testFindFilterCallback()
    {
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => function (array $item): bool {
                return $item['_o'] === 2;
            }
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] === 2
        );


        // Test Limit
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => function (array $item): bool {
                return in_array('bar', $item['array']);
            },
            'limit' => 1,
        ]);

        $this->assertTrue(
            count($items) === 1 && $items[0]['_o'] === 2
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
            count($items) === 1 && $items[0]['_o'] === 2
        );
    }

    /**
     * Test filter funcs
     * @covers \MongoHybrid\Client::find
     */
    public function testFindFilterFuncs()
    {
        // Assert $eq func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['content' => ['$eq' => 'Etiam tempor']],
                ],
            ]
        ]);

        $this->assertTrue(count($items) === 1, 'Failed to find one otem via $eq');
        $this->assertTrue($items[0]['content'] === 'Etiam tempor');


        // Assert $not/ $ne func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['content' => ['$not' => 'Etiam tempor']],
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
                '$and' => [
                    ['_o' => ['$gt' => 1]]
                ]
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] > 1,
            'Failed $gt'
        );


        // Assert $in func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['_o' => ['$in' => [2, 3]]]
                ]
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] === 2,
            'Failed $in'
        );


        // Assert $nin func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['_o' => ['$nin' => [2, 3]]]
                ]
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['_o'] === 1,
            'Failed $nin'
        );


        // Assert $has func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['array' => ['$has' => 'foo']]
                ]
            ]
        ]);

        $this->assertTrue(
            count($items) && in_array('foo', $items[1]['array']),
            'Failed $has'
        );


        // Assert $all func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['array' => ['$all' => ['foo', 'bar']]]
                ]
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['array'] == ['foo', 'bar'],
            'Failed $all'
        );


        // Assert $preg/ $match/ $regex func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['content' => ['$regex' => 'Lorem.*']]
                ]
            ]
        ]);

        $this->assertTrue(
            count($items) && $items[0]['content'] == 'Lorem ipsum',
            'Failed $regex'
        );


        // Assert $size func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['array' => ['$size' => 2]]
                ]
            ]
        ]);

        $this->assertTrue(
            count($items) && count($items[0]['array']) == 2,
            'Failed $size'
        );


        // Assert $mod func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['_o' => ['$mod' => [2 => null]]]
                    // ['_o' => ['$mod' => 2]]
                ]
            ]
        ]);

        $this->assertTrue(
            count($items) && fmod($items[0]['_o'], 2) == 0,
            'Failed $mod'
        );


        // Assert $func/ $fn/ $f func
        // Not implemented


        // Assert $exists func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['_o' => ['$exists' => true]]
                ]
            ]
        ]);

        $this->assertTrue(
            count($items) && isset($items[0]['_o']),
            'Failed $exists'
        );


        // Assert $fuzzy func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['content' => ['$fuzzy' => 'Etiam tmpor']]
                ]
            ]
        ]);


        // Assert $text func
        $items = static::$storage->find($this->mockCollectionId, [
            'filter' => [
                '$and' => [
                    ['content' => ['$text' => 'Etiam tempo']]
                ]
            ]
        ]);

        $this->assertTrue(
            count($items) && strpos($items[0]['content'], 'Etiam tempo') !== false,
            'Failed $text'
        );
    }

    /**
     * Test find with fields (projection)
     * Returned items have added/ removed properties
     * @covers \MongoHybrid\Client::find
     */
    public function testFindFields(): void
    {
        // Remove
        $items = static::$storage->find($this->mockCollectionId, [
            'fields' => [
                'content' => false,
            ]
        ]);

        $this->assertTrue(
            !in_array('content', array_keys($items[0]))
        );

        // Keep only
        $items = static::$storage->find($this->mockCollectionId, [
            'fields' => [
                'content' => true,
            ]
        ]);

        $this->assertTrue(
            array_keys($items[0]) == ['content']
        );
    }

    /**
     * Test find with limit
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
     * Test find with sort
     * @covers \MongoHybrid\Client::find
     */
    public function testFindSort(): void
    {
        $items = static::$storage->find($this->mockCollectionId, [
            'sort' => ['content' => 1],
        ]);

        $this->assertTrue($items[0]['content'] < $items[1]['content']);
    }

    /**
     * Test find with skip
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
        $itemId = '5d41792c3961382d610002e2';

        // TODO: use findOneById
        $item = static::$storage->findOne($this->mockCollectionId, ['_id' => $itemId]);

        $this->assertTrue(
            $item['_id'] === $itemId
        );
    }

    /**
     * Test save
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
            '_id' => '5d41792c3961382d610002e3',
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
     * @covers \MongoHybrid\Client::remove
     */
    public function testRemove(): void
    {
        $item = static::$mockCollectionItems[0];

        static::$storage->remove($this->mockCollectionId, $item);

        $this->assertTrue(
            static::$storage->count($this->mockCollectionId, ['_id' => $item['_id']]) === 0
        );
    }

    /**
     * Test count
     * @covers \MongoHybrid\Client::count
     */
    public function testCount()
    {
        $this->assertTrue(
            static::$storage->count($this->mockCollectionId) === count(static::$mockCollectionItems)
        );
    }

    /**
     * @covers \MongoHybridClient::removeField
     */
    public function TODOtestRemoveField()
    {
        static::$storage->removeField($this->mockCollectionId, 'content');

        $items = static::$storage->find($this->mockCollectionId);

        $this->assertTrue(
            !in_array('content', array_keys($items[0]))
        );
    }

    /**
     * @covers \MongoHybridClient::renameField
     */
    public function TODOtestRenameField()
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