# Laravel 5 MongoDB Queue Driver
Thread-safe MongoDB database queue implementation for Laravel. 

This driver is compatible with jensseger's 
[laravel-mongodb](https://github.com/jenssegers/laravel-mongodb) library, 
however we use the mongodb client instead so we can leverage 
findAndModify updates with write concerns and $isolated operations.

## Requirements
- PHP 5.4+
- mongodb-1.1.x PHP driver (or higher) 
- Mongo 3.2+ (Mongo 2.2+ may be supported; use at your own risk. Future 
updates may include bulk updates which have been added in Mongo 3.2)
- You may also leverage laravel-mongodb in your project

For more details on driver compatibility, please see 
[the MongoDB ecosystem documentation](https://docs.mongodb.org/ecosystem/drivers/php/#php-mongodb-driver) 

## Install

Require the latest version of this package with Composer

    composer require chefsplate/laravel-mongodb-queue:"0.1.x"

Add the Service Provider to the providers array in config/app.php

    ChefsPlate\Queue\MongoDBServiceProvider::class,

You need to create the migration table for queues and run it.

    $ php artisan queue:table
    $ php artisan migrate

You should now be able to use the **mongodb** driver in config/queue.php. (Use the same config as for the database, but use mongodb as driver.)

    'default' => 'mongodb',

    'connections' => array(
        ...
        'mongodb' => array(
            'driver' => 'mongodb',
            'table' => 'jobs',
            'queue' => 'default',
            'expire' => 60,
        ),
        ...
    }

Please ensure your config/database.php configuration for mongodb 
includes a DSN. This is required by our driver:

    'mongodb' => array(
        'driver'   => 'mongodb',
        'dsn'      => 'mongodb://127.0.0.1:27017',
        'database' => 'database_name'
    ),

The format for the DSN is:
`mongodb://[username:password@]host1[:port1][,host2[:port2:],...]/db`

For more info see:
 
- [Using the PHP Library (PHPLIB)](http://php.net/manual/en/mongodb.tutorial.library.php)
- [Laravel Queues](http://laravel.com/docs/queues)
