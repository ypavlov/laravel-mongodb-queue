<?php
namespace ChefsPlate\Queue;

use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue;


class MongoDBQueue extends DatabaseQueue
{
    /** @var string */
    protected $binary;

    /** @var string */
    protected $binaryArgs;

    /** @var string */
    protected $connectionName;

    /**
     * @param  \Illuminate\Database\Connection $database
     * @param  string $table
     * @param  string $default
     * @param  int $expire
     * @param  string $binary
     * @param  string|array $binaryArgs
     */
    public function __construct(Connection $database, $table, $default = 'default', $expire = 60, $binary = 'php', $binaryArgs = '', $connectionName = '')
    {
        parent::__construct($database, $table, $default, $expire);
        $this->binary         = $binary;
        $this->binaryArgs     = $binaryArgs;
        $this->connectionName = $connectionName;
    }
}