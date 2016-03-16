# Laravel 5 MongoDB Queue Driver
MongoDB 3.2+ database queue implementation for Laravel

### Install

Require the latest version of this package with Composer

    composer require chefsplate/laravel-mongodb-queue:"0.1.x"

Add the Service Provider to the providers array in config/app.php

    Chefsplate\Queue\MongoDBServiceProvider::class,

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

By default, `php` is used as the binary path to PHP. You can change this by adding the `binary` option to the queue config. You can also add extra arguments (for HHVM for example)

    'connections' => array(
        ...
        'mongodb' => array(
            'driver' => 'mongodb',
            'table' => 'jobs',
            'queue' => 'default',
            'expire' => 60,
            'binary' => 'php',
            'binary_args' => '',
        ),
        ...
    }

For more info see http://laravel.com/docs/queues

