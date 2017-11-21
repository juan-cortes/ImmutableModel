<?php
/**
 * Created by PhpStorm.
 * User: juan
 * Date: 20/11/17
 * Time: 8:31 PM
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class BlockChainImmutableModel
 * An experiment using block-chain like persistence to ensure the immutability
 * of any table from a model that uses this Class.
 * @author Juan José Cortés Ross juan@bohem.io
 * @package App\Models
 */
abstract class BlockChainImmutableModel extends Model
{
    protected static $resquiredColumns = ["hash", "previousHash", "id", "created_at", "updated_at"];
    public static $lockedID = 0;

    const E_INVALID_MODEL = "Invalid model extending BlockChainImmutableModel abstract, we need id, hash and timestamp columns.";
    const E_INVALID_DATA1 = "Data in this table failed the hash correlation test (previousHash).";
    const E_INVALID_DATA2 = "Data in this table passed the hash consistency check but hashes no longer match the data.";
    const E_DISABLED_UD = "This class extends BlockChainImmutableModel and therefore can't be altered.";
    const FALLBACK_KEY = "83efedfccc510d78016e6f247b93f28aa20de95a";

    public function __construct()
    {
        parent::__construct();
        $this->validateModel();
        $this->alterModelEvents();
    }

    /**
     * Disable the Update and Delete events for this Model. An immutable should be immutable.
     * Populate the hash related fields (hash and previousHash) on creation.
     */
    private final function alterModelEvents()
    {
        static::deleting(function() {
            throw new \Exception(BlockChainImmutableModel::E_DISABLED_UD);
        });
        static::updating(function() {
            throw new \Exception(BlockChainImmutableModel::E_DISABLED_UD);
        });

        static::creating(function($record) {
            $table = $this->getTable();
            $previous = DB::table($table)->orderBy('id', 'desc')->first();
            $record->previousHash = $previous?$previous->hash:null;
            $record->created_at = Carbon::now();
            $record->updated_at = Carbon::now();
            $record->hash = static::getRecordHash($record->getAttributes());
        });
    }

    protected static $requiredColumns = ["hash", "previousHash", "id", "created_at", "updated_at"];
    /**
     * Make sure the model implementing this class has all we need to work.
     * Simplest we can do is look for hash and id, since we'll use both for consistency.
     */
    private final function validateModel()
    {
        $table = $this->getTable();
        if (!Schema::hasColumns($table, static::$requiredColumns)) {
            throw new \Exception(BlockChainImmutableModel::E_INVALID_MODEL);
        }
    }

    /**
     * Iterate this table and check for inconsistencies in the data. The value of the hash is
     * salted by the previous hash except for the first record, which is salted by null. In addition
     * to the previous record salt, we also use the app key from this laravel installation, which
     * defaults to some random thing I hashed.
     *
     * We verify the integrity of a table first by relying solely on the hashes, if that part fails,
     * we don't need to recalculate the hashes in order to validate the data.
     * @return bool
     * @throws \Exception
     */
    public static function validateData()
    {
        $records = DB::table("immutable_demos")
            ->orderBy("id", 'asc')
            ->get();
        $previous = null;

        //Iterating twice, first only hashes, then recalculating if needed
        foreach ($records as $record) {
            if ($previous && $previous !== $record->previousHash) {
                throw new \Exception(BlockChainImmutableModel::E_INVALID_DATA1);
            }
            $previous = $record->hash;
        }

        //Recalculating
        foreach ($records as $record) {
            if ($record->hash && $record->hash !== static::getRecordHash((array)$record)) {
                throw new \Exception(BlockChainImmutableModel::E_INVALID_DATA2);
            }
        }

        //If we've reached this, data has not been tampered with. We are safe.
        return true;
    }

    /**
     * Simple hashing algorithm based on the app key and the serialized
     * version of the record which includes the last record's hash.
     * @param $record
     * @return string
     */
    private final static function getRecordHash($record)
    {
        unset($record['hash']);
        unset($record['id']);
        ksort($record);
        $salt = env("APP_KEY", BlockChainImmutableModel::FALLBACK_KEY);
        $data = json_encode($record);
        return hash('sha256', $data.$salt);
    }
}
