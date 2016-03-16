<?php
namespace ChefsPlate\Queue;

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue;
use MongoDB;
use MongoDB\Driver;
use MongoDB\Driver\WriteResult;

class MongoDBQueue extends DatabaseQueue
{
    /** @var Driver\Manager */
    private $manager;

    /** @var string $namespace A fully qualified namespace (databaseName.collectionName) */
    private $namespace;

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

        $this->manager   = new MongoDB\Driver\Manager($dsn, $options, $driverOptions);
        $this->namespace = $database->getConfig('database') . "." . $table;
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

        $cursor = $this->manager->executeQuery(
            $this->namespace,
            new MongoDB\Driver\Query(
                [
                    'reserved'     => 0,
                    'available_at' => ['$lte' => $this->getTime()],
                    'queue'        => $this->getQueue($queue)
                ],
                [
//                    'projection' => ['_id' => 0],
                    'sort' => ['_id' => -1],
                ]
            )
        );

        // TODO: use projections to return 1 object
        $jobs_array = $cursor->toArray();
        $job        = array_shift($jobs_array);

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

        $reserved = $this->manager->executeQuery(
            $this->namespace,
            new MongoDB\Driver\Query(
                [
                    'queue'       => $this->getQueue($queue),
                    'reserved'    => 1,
                    'reserved_at' => ['$lte' => $expired]
                ]
            )
        )->toArray();

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
            $bulk = new MongoDB\Driver\BulkWrite(['ordered' => true]);
            $bulk->update(['_id' => $id], ['$set' => ['reserved' => 0, 'reserved_at' => null, 'attempts' => $attempts], '$isolated' => 1]);

            /** @var WriteResult $result */
            $result = $this->manager->executeBulkWrite($this->namespace, $bulk);
            $this->displayResults($result);

        } catch (MongoDB\Driver\Exception\BulkWriteException $e) {
            $this->displayResults($e->getWriteResult());
        } catch (MongoDB\Driver\Exception\Exception $e) {
            printf("Other error: %s\n", $e->getMessage());
            exit;
        }
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
            $bulk = new MongoDB\Driver\BulkWrite(['ordered' => true]);
            $bulk->update(['_id' => $id], ['$set' => ['reserved' => 1, 'reserved_at' => $this->getTime()], '$isolated' => 1]);

            /** @var WriteResult $result */
            $result = $this->manager->executeBulkWrite($this->namespace, $bulk);
            $this->displayResults($result);

        } catch (MongoDB\Driver\Exception\BulkWriteException $e) {
            $this->displayResults($e->getWriteResult());
        } catch (MongoDB\Driver\Exception\Exception $e) {
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
            $bulk = new MongoDB\Driver\BulkWrite(['ordered' => true]);
            $bulk->delete(['_id' => $id], ['$isolated' => 1]);

            /** @var WriteResult $result */
            $result = $this->manager->executeBulkWrite($this->namespace, $bulk);
            $this->displayResults($result);

        } catch (MongoDB\Driver\Exception\BulkWriteException $e) {
            $this->displayResults($e->getWriteResult());
        } catch (MongoDB\Driver\Exception\Exception $e) {
            printf("Other error: %s\n", $e->getMessage());
            exit;
        }
    }

    /**
     * @param WriteResult $result
     */
    private function displayResults($result)
    {
        printf("Inserted %d document(s)\n", $result->getInsertedCount());
        printf("Matched  %d document(s)\n", $result->getMatchedCount());
        printf("Updated  %d document(s)\n", $result->getModifiedCount());
        printf("Upserted %d document(s)\n", $result->getUpsertedCount());
        printf("Deleted  %d document(s)\n", $result->getDeletedCount());

        foreach ($result->getUpsertedIds() as $index => $id) {
            printf('upsertedId[%d]: ', $index);
            var_dump($id);
        }

        /* If the WriteConcern could not be fulfilled */
        if ($writeConcernError = $result->getWriteConcernError()) {
            printf("%s (%d): %s\n", $writeConcernError->getMessage(), $writeConcernError->getCode(), var_export($writeConcernError->getInfo(), true));
        }

        /* If a write could not happen at all */
        foreach ($result->getWriteErrors() as $writeError) {
            printf("Operation#%d: %s (%d)\n", $writeError->getIndex(), $writeError->getMessage(), $writeError->getCode());
        }
    }

}