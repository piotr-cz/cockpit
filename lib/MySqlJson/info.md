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
