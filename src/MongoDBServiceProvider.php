<?php

namespace ChefsPlate\Queue;

use ChefsPlate\Queue\Connectors\MongoDBConnector;
use Illuminate\Support\ServiceProvider;

class MongoDBServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Add the connector to the queue drivers.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerMongoDBConnector($this->app['queue']);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
    }

    /**
     * Register the Async queue connector.
     *
     * @param \Illuminate\Queue\QueueManager $manager
     *
     * @return void
     */
    protected function registerMongoDBConnector($manager)
    {
        $manager->addConnector('mongodb', function () {
            return new MongoDBConnector($this->app['db']);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array();
    }
}