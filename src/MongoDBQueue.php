<?php
namespace ChefsPlate\Queue;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use MongoDB;
use MongoDB\Driver;

class MongoDBQueue extends DatabaseQueue
{
    /** @var MongoDB\Client */
    private $client;

    private $databaseName;

    /**
     * @param  \Illuminate\Database\Connection $database
     * @param  string                          $table
     * @param  string                          $default
     * @param  int                             $expire
     */
    public function __construct(Connection $database, $table, $default = 'default', $expire = 60)
    {
        parent::__construct($database, $table, $default, $expire);
        // TODO: support options (timeout, replica set, customizable write concern level)
        $dsn           = $database->getConfig('dsn');
        $options       = array(
            "journal" => true,  // NOTE: must have journaling enabled for v2.6+
            "w"       => MongoDB\Driver\WriteConcern::MAJORITY      // TODO: make configurable
        );
        $driverOptions = array();

        $this->client       = new MongoDB\Client($dsn, $options, $driverOptions);
        $this->databaseName = $database->getConfig('database');
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string $queue
     *
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        // use an atomic operation to find and update the job simultaneously
        if ($job = $this->getNextAvailableJobAndMarkAsReserved($queue)) {
            return new DatabaseJob(
                $this->container, $this, $job, $queue
            );
        }
    }

    /**
     * Delete a reserved job from the queue.
     *
     * @param  string $queue
     * @param  string $id
     *
     * @return void
     */
    public function deleteReserved($queue, $id)
    {
        // original query: $this->database->table($this->table)->where('_id', $id)->delete();

        try {
            $result = $this->client->selectCollection($this->databaseName, $this->table)->deleteOne(
            // filter
                ['_id' => $id],
                // options
                ['$isolated' => 1]);
        } catch (Exception $e) {
            printf("Other error: %s\n", $e->getMessage());
            exit;
        }
    }

    /**
     *
     * @param $queue
     */
    protected function getNextAvailableJobAndMarkAsReserved($queue)
    {
        $expiration = Carbon::now()->subSeconds($this->expire)->getTimestamp();

        $job = $this->client->selectCollection($this->databaseName, $this->table)->findOneAndUpdate(
        // query
            [
                'queue' => $this->getQueue($queue),
                '$or'   => [
                    // job must be available
                    [
                        'reserved_at'  => null,
                        'available_at' => ['$lte' => $this->getTime()]
                    ],
                    // or is reserved but expired
                    [
                        'reserved_at' => ['$lte' => $expiration]
                    ]
                ]
            ],
            // update
            [
                '$set' => ['reserved_at' => $this->getTime()],
                '$inc' => ['attempts' => 1]
            ],
            // options
            ['sort' => ['_id' => 1], 'new' => true, '$isolated' => 1]
        );

        if ($job) {
            $this->database->commit();

            // set the job ID based on Mongo's _id
            $job     = (object)$job;
            $job->id = $job->_id;
        }

        return $job ?: null;
    }

    /**
     * Get the next available job for the queue.
     *
     * @param  string|null $queue
     *
     * @return \StdClass|null
     */
    protected function getNextAvailableJob($queue)
    {
        // 5.1 query
//        $job = $this->database->table($this->table)
//            ->lockForUpdate()
//            ->where('queue', $this->getQueue($queue))
//            ->where('reserved', 0)
//            ->where('available_at', '<=', $this->getTime())
//            ->orderBy('_id', 'asc')
//            ->first();

        // 5.3 query
//        $job = $this->database->table($this->table)
//            ->lockForUpdate()
//            ->where('queue', $this->getQueue($queue))
//            ->where(function ($query) {
//                $this->isAvailable($query);
//                $this->isReservedButExpired($query);
//            })
//            ->orderBy('id', 'asc')
//            ->first();

        $expiration = Carbon::now()->subSeconds($this->expire)->getTimestamp();

        $job = $this->client->selectCollection($this->databaseName, $this->table)->findOne(
        // filter
            [
                'queue' => $this->getQueue($queue),
                '$or'   => [
                    // job must be available
                    [
                        'reserved_at'  => null,
                        'available_at' => ['$lte' => $this->getTime()]
                    ],
                    // or is reserved but expired
                    [
                        'reserved_at' => ['$lte' => $expiration]
                    ]
                ]
            ],
            // update
            ['sort' => ['_id' => 1]]);

        // set the job ID based on Mongo's _id
        if ($job) {
            $job     = (object)$job;
            $job->id = $job->_id;
        }

        return $job ?: null;
    }

    /**
     * Mark the given job ID as reserved.
     *
     * @param \stdClass $job
     *
     * @return \stdClass
     */
    protected function markJobAsReserved($job)
    {
        $job->attempts    = $job->attempts + 1;
        $job->reserved_at = $this->getTime();

        try {
            $result = $this->client->selectCollection($this->databaseName, $this->table)->updateOne(
            // filter
                ['_id' => $job->id],
                // update
                [
                    '$set' => [
                        'reserved_at' => $job->reserved_at,
                        'attempts'    => $job->attempts
                    ]
                ],
                // options
                ['$isolated' => 1]);
            $this->database->commit();
        } catch (Exception $e) {
            printf("Other error: %s\n", $e->getMessage());
            exit;
        }
    }
}
