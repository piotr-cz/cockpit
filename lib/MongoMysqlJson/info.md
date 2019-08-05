
- storage init by DriverFactory: `\MongoHybrid\Client`
- sets driver:
  - MongoHybrid\Mongo
  - MongoHybrid\MongoLite
    - MongoLite\Client::selectCollection
      - MongoLite\Database
        PDO Wrapper
        connection access via connection property
        registerCriteriaFunction
        getCollectionNames
        dropCollection
        selectCollection

        not used: vacuum, drop, listCollections
      - MongoLite\Cursor - results iterator


# Creating tables on the fly
When to create tables?
- installation script doesn't check if db is installed,
- adding new collection in admin doesn't trigger storage stuff
 
MongoLite creates databases on fly on every operation via
  MongoHybrid::getCollection ->
    MongoLite\Client::selectCollection -> 
      MongoLite\Database::selectCollection ->
        MongoLite\Database::createCollection
        
        
# Usage
Note: non-existent methods are proxied from MongoHybrid\Client to driver via __call

## bootstrap.php
$this->app->storage
  ->getKey(string, string, array): array
  ->setKey(string, string, $data): void
  ->findOne(): ?array
  
## modules\Cockpit\Controllers\Accounts.php
$this->app->storage
  ->findOne
  ->save
  ->remove
  ->find()->toArray()
  ->count
  
  ->insert

## modules\Collections\bootstrap.php
$this->app->storage
  ->dropCollection($name): bool
  ->getCollection($name): array
  ->find($name, array $options): array
  ->save($name, array $entry): bool
  ->remove($name, array $criteria): bool
  ->count($name, array $criteria): int

## modules\Collections\Controller\Admin.php
$this->app->storage->type

## modules\Forms\bootstrap.php
  ->getform(string $name) ???


# Funky filters like $eq used in:
- MongoHybryd\Client::removeKey: $in
- modules\Cockpit\Controller\
    Accounts: $or, $regex
    Assets: $in
    RestApi: $or, $regex
- modules\Collections\Controller\AdminL $exists, $regex $options, $or


defined in MongoLite\Database

# Utils
- ContainerArray ?
- DataCollection not used
- FileStorage - cloud file access
- LiteDB - not used
~~~

# MongoLite
_SQLite_

files:
- `cockpit.sqlite` Tables with columns id:integer, document:text
  - accounts
  - assets
  - asset_folders
  - options
  - revisions
  - webhooks
- `collections.sqlite` Table: <collection id>, columns: id:integer, document:text
- `forms.sqlite`

settings
```php
'database' => [
    'server' => 'mongolite://' . COCKPIT_STORAGE_FOLDER . '/data',
    'options' => [],
];
```

# MongoHybrid
Factory for
 - mongodb:// -> MongoHybrid\MongoLegacy|MongoHybrid\Mongo wrapper around \MongoDB\Client
 - mongolite:// -> MongoHybrid\MongoLite


_mongoDB_

settings
```php
'database' => [
    'server' => 'mongodb://localhost:27017'
    'options' => ['db' => 'cockpitdb'],
    'driverOptions' => [],
];
```

~~~

# Redis
_file_

file:
- `cockpit.memory.sqlite`

settings
```php
'memory' => [
    'server' => 'redislite://' . COCKPIT_STORAGE_FOLDER . '/data/cockpit.memory.sqlite',
    'options' => [],
];
