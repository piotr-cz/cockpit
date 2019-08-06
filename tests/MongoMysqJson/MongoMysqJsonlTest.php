<?php
namespace Test\Database;

use ReflectionClass;

use PHPUnit\Framework\TestCase;

/**
 * runTestsInSeparateProcesses
 * Test MongoHybrid\Collection configured with MySqlJson
 */
class MongoMysqJsonTest extends TestCase
{
    /** @var \MongoHybrid\Client */
    protected static $storage;

    /** @var \Lime\Module */
    protected static $collectionsModule;

    /** @var \PDO */
    protected static $connection;

    protected $mockCollectionName = 'test';

    protected $mockCollectionId = 'collections/test01';

    protected $mockCollectionDefinition = [
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
    ];

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

    /** @var array */
    protected $mockCollection;

    /**
     * @inheritdoc
     */
    public static function setUpBeforeClass(): void
    {
        $cockpit = cockpit();

        static::$storage = $cockpit->storage;
        static::$collectionsModule = $cockpit->module('collections');

        // Get driver
        $storageReflection = new ReflectionClass(static::$storage);
        $driverProperty = $storageReflection->getProperty('driver');
        $driverProperty->setAccessible(true);
        $driver = $driverProperty->getValue(static::$storage);

        // Get PDO Connection
        $driverReflection = new ReflectionClass($driver);
        $connectionProperty = $driverReflection->getProperty('connection');
        $connectionProperty->setAccessible(true);
        static::$connection = $connectionProperty->getValue($driver);

        /*
        $databaseConfig = $cockpit['config']['database'];

        $storageDriver = new \MongoMysqlJson\Driver(
            $databaseConfig['options'],
            $databaseConfig['driverOptions']
        );
        */

        /*
        $connection = new \PDO(
            vsprintf('%s:host=%s;dbname=%s;charset=UTF8', [
                $databaseConfig['options']['connection'],
                $databaseConfig['options']['host'],
                $databaseConfig['options']['db']
            ]),
            $databaseConfig['options']['username'],
            $databaseConfig['options']['password'],
            $databaseConfig['driverOptions']
        );
        */

        /*
        $storageClient = new \MongoHybrid\Client(
            $databaseConfig['server'],
            $databaseConfig['options'],
            $databaseConfig['driverOptions']
        );

        var_dump($storageClient);
        */
    }

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        // Create collection via storage
        static::$storage->getCollection($this->mockCollectionId);

        return;

        // Create collection file via cockpit API
        $this->mockCollection = static::$collectionsModule->createCollection(
            $this->mockCollectionName,
            $this->mockCollectionDefinition
        );

        // Cockpit error
        if ($this->mockCollection === false) {
            exit('ERR');
        }

        // Create test table
        $stmt = static::$connection->query(<<<SQL

            CREATE TABLE `collections/{$this->mockCollection['_id']}` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `document` JSON NOT NULL,
                PRIMARY KEY (`id`)
            )
SQL
        );

        // Empty table
        $stmt = static::$connection->query(<<<SQL

            TRUNCATE
                `collections/{$this->mockCollection['_id']}`
SQL
        );

        // Add mock data
        $stmt = static::$connection->prepare(<<<SQL

            INSERT INTO
                `collections/{$this->mockCollection['_id']}` (`document`)
            VALUES (:data)
SQL
        );

        foreach ($this->mockCollectionData as $mockCollection) {
            $stmt->execute([':data' => json_encode($mockCollection)]);
        }
    }

    /**
     * @inheritdoc
     */
    protected function assertPreConditions(): void
    {
    }

    public function testFoobar()
    {
        $this->assertTrue(true, 'Foobar fail');
    }

    public function XtestCollectionFind(): void
    {
        $items = static::$storage->find('collections/' . $this->mockCollection['_id']);

        $this->assertTrue(count($items) > 0);
    }

    /**
     * @depends testCollectionFind
     */
    public function XtestCollectionFindSkip(): void
    {
        $items = static::$storage->find('collections/' . $this->mockCollection['_id'], [
            'limit' => 99,
            'skip' => 1,
        ]);

        $this->assertTrue($items[0]['_o'] !== 1);
    }

    /**
     * Test collection find one
     */
    public function XtestCollectionFindOne(): void
    {
        // $item = $this->app->module('collections')->findOne('performers', []); var_dump($item); die();
        $item = static::$storage->findOne('collections/' . $this->mockCollectionName);

        $this->assertTrue($item['_o'] === 1);
    }

    /**
     * @inheritdoc
     */
    public function tearDown(): void
    {
        static::$storage->dropCollection($this->mockCollectionId);

        return;

        static::$collectionsModule->removeCollection($this->mockCollection['name']);

        /*
        // Drop test table (handled by removeCollection)
        static::$connection->query(<<<SQL

            DROP TABLE `collections/{$this->mockCollection['_id']}`
SQL
        );
        */

        $this->mockCollection = null;
    }
}