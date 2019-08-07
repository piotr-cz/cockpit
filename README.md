# Cockpit Next - MySQL driver version

## Requirements

- MySQL 5.7.9+
- PHP 7.1+
- PHP extensions: *pdo*, *pdo_mysql* and *json*


## Differences

### Collection filters

#### Not implemented

- `$func`/ `$fn`/ `$f`
- `$fuzzy`

#### Works differently

- callable as a filter
  PDO MySQL Driver [unlike SQLite](https://www.php.net/manual/en/pdo.sqlitecreatefunction.php) doesn't have support for User Defined Functions so callable is evaluated for every result

- `$in`, `$nin`
  When databse value is an array, evaluates to false

- `$regexp`
  implemented via [REGEXP](https://dev.mysql.com/doc/refman/5.7/en/regexp.html) + case insensitive
  Wrapping in `//` or adding flag via `/foobar/i` doesn't work

- `$text`
  implemeted via [LIKE](https://dev.mysql.com/doc/refman/5.7/en/string-comparison-functions.html#operator_like)
  Options are not supported (_$minScore_, _$distance_, _$search_)

### No Cursor implementation

This doesn't work:

```php
$cursor = $app->storage->getCollection('foobar')->find(['_o' => ['$lte' => 0]]);

$results = $cursor
    ->limit(10)
    ->sort(['_o' => 1])
    ->skip(1)
    ->toArray();
```

This works:

```php
$results = $app->storage->find([
    'filter' => ['_o' => ['$lte' => 0]],
    'limit'  => 10,
    'sort'   => ['_o' => 1],
    'skip'   => 1,
]);
```

## Setup

Configure connection in `config/config.php`:

```php
return [
    'database' => [
        'server' => 'mongomysqljson',
        // Connection options
        'options' => [
            'host'     => 'localhost', // Optional, defaults to 'localhost'
            'port'     => '3306' // Optional
            'dbname'   => 'DATABASE_NAME',
            'charset'  => 'UTF8', // Optional, defaults to 'UTF8'
            'username' => 'USER',
            'password' => 'PASSWORD',
        ],
        // PDO Attributes
        // see https://www.php.net/manual/en/pdo.setattribute.php
        // see https://www.php.net/manual/en/ref.pdo-mysql.php
        'driverOptions' => [],
    ],
];
```

## Testing
Install phpunit globally

```sh
composer global require --dev phpunit/phpunit ^8
```

```
phpunit
```

___

# Cockpit Next

[![Backers on Open Collective](https://opencollective.com/cockpit/backers/badge.svg)](#backers) [![Sponsors on Open Collective](https://opencollective.com/cockpit/sponsors/badge.svg)](#sponsors)

* Homepage: [http://getcockpit.com](https://getcockpit.com)
* Twitter: [@getcockpit](http://twitter.com/getcockpit)
* Support Forum: [https://discourse.getcockpit.com](https://discourse.getcockpit.com)


### Requirements

* PHP >= 7.0
* PDO + SQLite (or MongoDB)
* GD extension
* mod_rewrite, mod_versions enabled (on apache)

make also sure that <code>$_SERVER['DOCUMENT_ROOT']</code> exists and is set correctly.


### Installation

1. Download Cockpit and put the cockpit folder in the root of your web project
2. Make sure that the __/cockpit/storage__ folder and all its subfolders are writable
3. Go to __/cockpit/install__ via Browser
4. You're ready to use Cockpit :-)


### Build (Only if you modify JS components)

You need [nodejs](https://nodejs.org/) installed on your system.

First run `npm install` to install development dependencies

1. Run `npm run build` - For one-time build of styles and components
2. Run `npm run watch` - For continuous build every time styles or components change


### Dockerized Development

You need docker installed on your system: https://www.docker.com.

1. Run `npm run docker-init` to build the initial image.
2. Run `npm run docker` to start an Apache environment suited for Cockpit on port 8080 (this folder mapped to /var/www/html).


### Copyright and license

Copyright since 2015 [Agentejo](https://agentejo.com) under the MIT license.

See [LICENSE](LICENSE) for more information.


### 💐 SPONSORED BY

[![ginetta](https://user-images.githubusercontent.com/321047/29219315-f1594924-7eb7-11e7-9d58-4dcf3f0ad6d6.png)](https://www.ginetta.net)<br>
We create websites and apps that click with users.


[![BrowserStack](https://user-images.githubusercontent.com/355427/27389060-9f716c82-569d-11e7-923c-bd5fe7f1c55a.png)](https://www.browserstack.com)<br>
Live, Web-Based Browser Testing


# OpenCollective

## Backers

Thank you to all our backers! 🙏 [[Become a backer](https://opencollective.com/cockpit#backer)]

<a href="https://opencollective.com/cockpit#backers" target="_blank"><img src="https://opencollective.com/cockpit/backers.svg?width=890"></a>


## Sponsors

Support this project by becoming a sponsor. Your logo will show up here with a link to your website. [[Become a sponsor](https://opencollective.com/cockpit#sponsor)]

<a href="https://opencollective.com/cockpit/sponsor/0/website" target="_blank"><img src="https://opencollective.com/cockpit/sponsor/0/avatar.svg"></a>
<a href="https://opencollective.com/cockpit/sponsor/1/website" target="_blank"><img src="https://opencollective.com/cockpit/sponsor/1/avatar.svg"></a>
<a href="https://opencollective.com/cockpit/sponsor/2/website" target="_blank"><img src="https://opencollective.com/cockpit/sponsor/2/avatar.svg"></a>
<a href="https://opencollective.com/cockpit/sponsor/3/website" target="_blank"><img src="https://opencollective.com/cockpit/sponsor/3/avatar.svg"></a>
<a href="https://opencollective.com/cockpit/sponsor/4/website" target="_blank"><img src="https://opencollective.com/cockpit/sponsor/4/avatar.svg"></a>
<a href="https://opencollective.com/cockpit/sponsor/5/website" target="_blank"><img src="https://opencollective.com/cockpit/sponsor/5/avatar.svg"></a>
<a href="https://opencollective.com/cockpit/sponsor/6/website" target="_blank"><img src="https://opencollective.com/cockpit/sponsor/6/avatar.svg"></a>
<a href="https://opencollective.com/cockpit/sponsor/7/website" target="_blank"><img src="https://opencollective.com/cockpit/sponsor/7/avatar.svg"></a>
<a href="https://opencollective.com/cockpit/sponsor/8/website" target="_blank"><img src="https://opencollective.com/cockpit/sponsor/8/avatar.svg"></a>
<a href="https://opencollective.com/cockpit/sponsor/9/website" target="_blank"><img src="https://opencollective.com/cockpit/sponsor/9/avatar.svg"></a>
