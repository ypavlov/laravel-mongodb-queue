# Laravel 5 MongoDB Queue Driver
MongoDB 3.2+ database queue implementation for Laravel

### Install

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

For more info see:
 
- [Laravel Queues](http://laravel.com/docs/queues)
- [Using the PHP Library (PHPLIB)](http://php.net/manual/en/mongodb.tutorial.library.php)

TODO:
expected setup with laravel-mongodb (we need DSN set)
dsn has format: mongodb://[username:password@]host1[:port1][,host2[:port2:],...]/db