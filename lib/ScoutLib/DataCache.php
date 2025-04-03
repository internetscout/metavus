<?PHP
#
#   FILE:  DataCache.php
#
#   Part of the ScoutLib application support library
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use MatthiasMullie\Scrapbook\Adapters\MySQL;
use MatthiasMullie\Scrapbook\Psr16\SimpleCache;

/**
 * General-purpose data caching facility, providing a superset of the
 * standard PSR-16 simple cache interface.  For all methods, key prefixes
 * and keys cannot contain the following characters:  {}()/\@:
 * @see https://www.php-fig.org/psr/psr-16/
 */
class DataCache
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    # characters that are not allowed in cache keys or cache key prefixes
    const CHARS_NOT_ALLOWED_IN_KEYS = [ "{", "}", "(", ")", "/", "\\", "@", ":" ];

    /**
     * Class constructor.  Key prefixes (and keys) cannot contain the
     * following characters:  {}()/\@:
     * @param string $KeyPrefix Prefix to prepend to all keys.  (OPTIONAL)
     */
    public function __construct(string $KeyPrefix = "")
    {
        $this->KeyPrefix = $KeyPrefix;

        if (!isset(self::$Cache)) {
            # instantiate KeyValueStore cache interface with MySQL for storage
            $DBClient = Database::getPDO();
            $CacheInterface = new MySQL($DBClient);

            # instantiate PSR-16 cache interface over KeyValueStore
            self::$Cache = new SimpleCache($CacheInterface);
        }
    }

    /**
     * Fetches a value from the cache.
     * @param string $Key The unique key of this item in the cache.
     * @param mixed $Default Default value to return if the key does not exist.
     * @return mixed The value of the item from the cache, or $Default in case
     *      of cache miss.
     */
    public function get(string $Key, $Default = null)
    {
        return self::$Cache->get($this->KeyPrefix.$Key, $Default);
    }

    /**
     * Saves data to the cache, uniquely referenced by a key with an
     * optional expiration TTL time.
     * @param string $Key The key of the item to store.
     * @param mixed $Value The value of the item to store. Must be serializable.
     * @param ?int $Ttl The TTL value of this item, in seconds.  (OPTIONAL,
     *      defaults to no TTL, meaning that the goal is to store the data
     *      indefinitely.)
     * @return bool TRUE on success and FALSE on failure.
     */
    public function set(string $Key, $Value, $Ttl = null): bool
    {
        return self::$Cache->set($this->KeyPrefix.$Key, $Value, $Ttl);
    }

    /**
     * Delete an item from the cache by its unique key.
     * @param string $Key The unique cache key of the item to delete.
     * @return bool TRUE if the item was successfully removed, or FALSE
     *      if there was an error.
     */
    public function delete($Key): bool
    {
        return self::$Cache->delete($this->KeyPrefix.$Key);
    }

    /**
     * Wipe clean the entire cache.  NOTE: This clears ALL values in the
     * cache, regardless of prefix.
     * @return bool TRUE on success and FALSE on failure.
     */
    public function clear(): bool
    {
        return self::$Cache->clear();
    }

    /**
     * Obtains multiple cache items by their unique keys.
     * @param iterable $Keys A list of keys that can obtained in a single
     *      operation.
     * @param mixed $Default Default value to return for keys that were
     *      specified but do not currently have values in the cache.
     * @return iterable A list of Key => Value pairs.  Cache keys that do
     *      not exist or are stale will have $Default as value.
     */
    public function getMultiple($Keys, $Default = null): iterable
    {
        # add prefix to keys if one was set
        if ($this->KeyPrefix != "") {
            $UpdatedKeys = [];
            foreach ($Keys as $Key) {
                $UpdatedKeys[] = $this->KeyPrefix.$Key;
            }
            $Keys = $UpdatedKeys;
        }

        $Results = self::$Cache->getMultiple($Keys, $Default);

        # remove any key prefix from result keys
        if ($this->KeyPrefix != "") {
            $PrefixLen = strlen($this->KeyPrefix);
            $UpdatedResults = [];
            foreach ($Results as $Key => $Value) {
                $UpdatedKey = substr($Key, $PrefixLen);
                $UpdatedResults[$UpdatedKey] = $Value;
            }
            $Results = $UpdatedResults;
        }

        return $Results;
    }

    /**
     * Saves a set of key => value pairs in the cache, with an optional TTL.
     * @param iterable $Values A list of key => value pairs for a multiple-set
     *      operation.
     * @param ?int $Ttl The TTL value of these items in seconds.  (OPTIONAL,
     *      defaults to no TTL, meaning that the goal is to store the data
     *      indefinitely.)
     * @return bool TRUE on success and FALSE on failure.
     */
    public function setMultiple($Values, $Ttl = null): bool
    {
        # add prefix to keys if one was set
        if ($this->KeyPrefix != "") {
            $UpdatedValues = [];
            foreach ($Values as $Key => $Value) {
                $UpdatedValues[$this->KeyPrefix.$Key] = $Value;
            }
            $Values = $UpdatedValues;
        }

        return self::$Cache->setMultiple($Values, $Ttl);
    }

    /**
     * Deletes multiple cache items in a single operation.
     * @param iterable $Keys A list of string-based keys for data to be
     *      deleted from cache.
     * @return bool TRUE if the items were successfully removed. FALSE if
     *      there was an error.
     */
    public function deleteMultiple($Keys): bool
    {
        # add prefix to keys if one was set
        if ($this->KeyPrefix != "") {
            $UpdatedKeys = [];
            foreach ($Keys as $Key) {
                $UpdatedKeys[] = $this->KeyPrefix.$Key;
            }
            $Keys = $UpdatedKeys;
        }

        return self::$Cache->deleteMultiple($Keys);
    }

    /**
     * Determines whether an item is present in the cache.
     * NOTE: It is recommended that has() is only to be used for cache
     * warming purposes and not to be used within live applications
     * operations for get/set, as this method is subject to a race
     * condition where has() will return true and immediately after,
     * another script can remove it, making the state of the app out of date.
     * @param string $Key The cache item key.
     * @return bool TRUE if item is found in cache, otherwise FALSE.
     */
    public function has($Key): bool
    {
        return self::$Cache->has($this->KeyPrefix.$Key);
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $KeyPrefix;

    protected static $Cache;
}
