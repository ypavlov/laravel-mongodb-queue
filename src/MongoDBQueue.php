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
     * @param  string $table
     * @param  string $default
     * @param  int $expire
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
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        if (!is_null($this->expire)) {
            $this->releaseJobsThatHaveBeenReservedTooLong($queue);
        }

        if ($job = $this->getNextAvailableJobAndMarkAsReserved($queue)) {
            $this->database->commit();

            return new DatabaseJob(
                $this->container, $this, $job, $queue
            );
        }

        $this->database->commit();
    }

    /**
     * Get the next available job for the queue.
     *
     * @param  string|null $queue
     * @return \StdClass|null
     */
    protected function getNextAvailableJob($queue)
    {
        // Original query
//        $job = $this->database->table($this->table)
//            ->lockForUpdate()
//            ->where('queue', $this->getQueue($queue))
//            ->where('reserved', 0)
//            ->where('available_at', '<=', $this->getTime())
//            ->orderBy('_id', 'asc')
//            ->first();

        $job = $this->client->selectCollection($this->databaseName, $this->table)->findOne(
        // filter
            ['reserved' => 0, 'available_at' => ['$lte' => $this->getTime()], 'queue' => $this->getQueue($queue)],
            // update
            ['sort' => ['_id' => -1]]);

        if ($job) {
            $job     = (object)$job;
            $job->id = $job->_id;
        }

        return $job ?: null;
    }

    /**
     * Release the jobs that have been reserved for too long.
     *
     * @param  string $queue
     * @return void
     */
    protected function releaseJobsThatHaveBeenReservedTooLong($queue)
    {
        $expired = Carbon::now()->subSeconds($this->expire)->getTimestamp();

        // original query:
//        $reserved = $this->database->collection($this->table)
//            ->where('queue', $this->getQueue($queue))
//            ->where('reserved', 1)
//            ->where('reserved_at', '<=', $expired)->get();
//
//        foreach ($reserved as $job) {
//            $attempts = $job['attempts'] + 1;
//            $this->releaseJob($job['_id'], $attempts);
//        }

        $reserved = $this->client->selectCollection($this->databaseName, $this->table)->find(
                [ 'queue' => $this->getQueue($queue), 'reserved' => 1, 'reserved_at' => ['$lte' => $expired]]
        );

        // TODO: can use bulk writes here
        foreach ($reserved as $job) {
            $job      = (array)$job;
            $attempts = $job['attempts'] + 1;
            $this->releaseJob($job['_id'], $attempts);
        }
    }

    /**
     * Release the given job ID from reservation.
     *
     * @param  string $id
     *
     * @return void
     */
    protected function releaseJob($id, $attempts)
    {
        // original query:
//        $this->database->table($this->table)->where('_id', $id)->update([
//            'reserved'    => 0,
//            'reserved_at' => null,
//            'attempts'    => $attempts,
//        ]);

        try {
            $result = $this->client->selectCollection($this->databaseName, $this->table)->updateOne(
            // filter
                ['_id' => $id],
                // update
                ['$set' => ['reserved' => 0, 'reserved_at' => null, 'attempts' => $attempts]],
                // option
                ['$isolated' => 1]
            );
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
        $job = $this->client->selectCollection($this->databaseName, $this->table)->findOneAndUpdate(
        // query
            ['reserved' => 0, 'available_at' => ['$lte' => $this->getTime()], 'queue' => $this->getQueue($queue)],
            // update
            ['$set' => ['reserved' => 1, 'reserved_at' => $this->getTime()]],
            // options
            ['sort' => ['_id' => -1], 'new' => true, '$isolated' => 1]
        );

        if ($job) {
            $job     = (object)$job;
            $job->id = $job->_id;
        }

        return $job ?: null;

    }

    /**
     * Mark the given job ID as reserved.
     *
     * @param  string $id
     * @return void
     */
    protected function markJobAsReserved($id)
    {
        // original query:
//        $this->database->collection($this->table)->where('_id', $id)->update([
//            'reserved' => 1, 'reserved_at' => $this->getTime(),
//        ]);

        try {
            $result = $this->client->selectCollection($this->databaseName, $this->table)->updateOne(
            // filter
                ['_id' => $id],
                // update
                ['$set' => ['reserved' => 1, 'reserved_at' => $this->getTime()]],
                // options
                ['$isolated' => 1]);
        } catch (Exception $e) {
            printf("Other error: %s\n", $e->getMessage());
            exit;
        }
    }

    /**
     * Delete a reserved job from the queue.
     *
     * @param  string $queue
     * @param  string $id
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
}