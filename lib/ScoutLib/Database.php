<?PHP
#
#   Database.php
#
#   Copyright 1999-2025 Axis Data
#   This code is free software that can be used or redistributed under the
#   terms of Version 2 of the GNU General Public License, as published by the
#   Free Software Foundation (http://www.fsf.org).
#
#   Author:  Edward Almasy (ealmasy@axisdata.com)
#   Part of the AxisPHP library v1.2.5
#   For more information see http://www.axisdata.com/AxisPHP/
#
# @scout:phpstan

namespace ScoutLib;
use Exception;
use handle;
use InvalidArgumentException;
use mysqli;
use mysqli_result;
use PDO;
use PDOException;
use ScoutLib\StdLib;

/**
 * SQL database abstraction object with smart query caching.
 * \nosubgrouping
 */
class Database
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** @name Setup/Initialization */ /*@(*/

    /**
     * Object constructor.  If user name, password, or database name are omitted
     * they must have been set earlier with setGlobalServerInfo() and
     * setGlobalDatabaseName().
     * @param string $UserName User name to use to log in to database server.  (OPTIONAL)
     * @param string $Password Password to use to log in to database server.  (OPTIONAL)
     * @param string $DatabaseName Name of database to use once logged in.  (OPTIONAL)
     * @param string $HostName Host name of system on which database server resides.
     *       (OPTIONAL, defaults to "localhost")
     * @throws Exception When unable to connect to database server or select
     *       specified database.
     * @see Database::setGlobalServerInfo()
     * @see Database::setGlobalDatabaseName()
     */
    public function __construct(
        ?string $UserName = null,
        ?string $Password = null,
        ?string $DatabaseName = null,
        ?string $HostName = null
    ) {
        # save DB access parameter values
        $this->DBUserName = $UserName ?? self::$GlobalDBUserName;
        $this->DBPassword = $Password ?? self::$GlobalDBPassword;
        $this->DBHostName = $HostName ?? self::$GlobalDBHostName;
        $this->DBName = $DatabaseName ?? self::$GlobalDBName;

        # set memory threshold for cache clearing
        if (!isset(self::$CacheMemoryThreshold)) {
            self::$CacheMemoryThreshold = StdLib::getPhpMemoryLimit() / 4;
        }

        # if we don't already have a connection or DB access parameters were supplied
        $HandleIndex = $this->DBHostName . ":" . $this->DBName;
        if (!array_key_exists($HandleIndex, self::$ConnectionHandles)
            || $UserName || $Password || $DatabaseName || $HostName) {
            $this->Handle = self::connectToDatabaseServer(
                $this->DBHostName,
                $this->DBUserName,
                $this->DBPassword
            );
            self::$ConnectionHandles[$HandleIndex] = $this->Handle;
            $this->selectDatabase($this->DBName);
        } else {
            # set local connection handle
            $this->Handle = self::$ConnectionHandles[$HandleIndex];
        }
    }

    /** @cond */
    /**
     * Specify variables to be saved when serialized.
     */
    public function __sleep()
    {
        return array("DBUserName", "DBPassword", "DBHostName", "DBName");
    }

    /**
     * Restore database connection when unserialized.
     * @throws Exception When unable to connect to database server or select
     *       specified database.
     */
    public function __wakeup()
    {
        # if we don't already have a database server connection
        $HandleIndex = $this->DBHostName . ":" . $this->DBName;
        if (!array_key_exists($HandleIndex, self::$ConnectionHandles)) {
            # open connection to DB server and select database
            try {
                $this->Handle = self::connectToDatabaseServer(
                    $this->DBHostName,
                    $this->DBUserName,
                    $this->DBPassword
                );
                $this->selectDatabase($this->DBName);
            } catch (Exception $Exception) {
                if (isset(self::$GlobalDBUserName)
                    && isset(self::$GlobalDBPassword)
                    && isset(self::$GlobalDBName)) {
                    $this->DBUserName = self::$GlobalDBUserName;
                    $this->DBPassword = self::$GlobalDBPassword;
                    $this->DBName = self::$GlobalDBName;
                    $this->DBHostName = self::$GlobalDBHostName;
                    $this->Handle = self::connectToDatabaseServer(
                        $this->DBHostName,
                        $this->DBUserName,
                        $this->DBPassword
                    );
                    $this->selectDatabase($this->DBName);
                } else {
                    throw $Exception;
                }
            }
            self::$ConnectionHandles[$HandleIndex] = $this->Handle;
        } else {
            # set local connection handle
            $this->Handle = self::$ConnectionHandles[$HandleIndex];
        }
    }
    /** @endcond */

    /**
     * Set default login and host info for database server.
     * @param string $UserName User name to use to log in to database server.
     * @param string $Password Password to use to log in to database server.
     * @param string $HostName Host name of system on which database server resides.
     *       (OPTIONAL, defaults to "localhost")
     */
    public static function setGlobalServerInfo(
        string $UserName,
        string $Password,
        string $HostName = "localhost"
    ): void {
        # save default DB access parameters
        self::$GlobalDBUserName = $UserName;
        self::$GlobalDBPassword = $Password;
        self::$GlobalDBHostName = $HostName;

        # clear any existing DB connection handles
        self::$ConnectionHandles = [];
    }

    /**
     * Set default database name.
     * @param string $DatabaseName Name of database to use once logged in.
     */
    public static function setGlobalDatabaseName(string $DatabaseName): void
    {
        # save new default DB name
        self::$GlobalDBName = $DatabaseName;

        # clear any existing DB connection handles
        self::$ConnectionHandles = [];
    }

    /**
     * Set default database storage engine.
     * @param string $Engine New default storage engine.
     */
    public function setDefaultStorageEngine(string $Engine): void
    {
        # choose config variable to use based on server version number
        $ConfigVar = version_compare($this->getServerVersion(), "5.5", "<")
            ? "storage_engine" : "default_storage_engine";

        # set storage engine in database
        $this->query("SET " . $ConfigVar . " = " . $Engine);
    }

    /**
     * Determine if a storage engine us available.
     * @param string $EngineName Engine to check.
     * @return bool TRUE for supported engines, FALSE otherwise.
     */
    public function isStorageEngineAvailable(string $EngineName): bool
    {
        if (is_null(self::$SupportedEngines)) {
            self::$SupportedEngines = [];

            $this->query("SHOW ENGINES");
            $Rows = $this->fetchRows();
            foreach ($Rows as $Row) {
                if ($Row["Support"] == "YES") {
                    self::$SupportedEngines[$Row["Engine"]] = true;
                }
            }
        }

        return array_key_exists($EngineName, self::$SupportedEngines);
    }

    /**
     * Get version number of the client libraries being used to connect
     * to the database server (Currently the mysql library version
     * number).
     * @return string Client library version number (e.g., 5.1.73)
     *   should be version_compare()-able, as long as mysql doesn't
     *   change their version numbering.
     */
    public function getClientVersion(): string
    {
        return mysqli_get_client_info();
    }

    /**
     * Get database connection type and hostname.
     * @return string Text description of the database connection
     *   (e.g. "Locahost via UNIX socket").
     */
    public function getHostInfo(): string
    {
        return mysqli_get_host_info($this->Handle);
    }

    /**
     * Get host name of system on which database server resides.
     * @return string Host name of database server.
     * @see setGlobalServerInfo()
     */
    public function DBHostName(): string
    {
        return $this->DBHostName;
    }

    /**
     * Get current database name.
     * @return string Database name.
     * @see setGlobalDatabaseName()
     */
    public function DBName(): string
    {
        return $this->DBName;
    }

    /**
     * Get name used to connect with database server.
     * @return string Login name.
     * @see setGlobalServerInfo()
     */
    public function DBUserName(): string
    {
        return $this->DBUserName;
    }

    /**
     * Get or set whether query result caching is enabled.  Caching is
     * <b>enabled</b> by default.  Caches are cleared whenever setting is
     * changed.  This setting applies to <b>all</b> instances of Database.
     * @param bool $NewSetting TRUE to enable caching or FALSE to disable.  (OPTIONAL)
     * @return bool Current caching setting.
     */
    public static function caching(?bool $NewSetting = null): bool
    {
        # if cache setting has changed, save new setting and clear caches
        if (($NewSetting !== null) && ($NewSetting != self::$CachingFlag)) {
            self::$CachingFlag = $NewSetting;
            self::clearCaches();
        }

        # return current setting to caller
        return self::$CachingFlag;
    }

    /**
     * Clear all data from internal class caches.
     */
    public static function clearCaches(): void
    {
        self::$QueryResultCache = [];
        self::$VUCache = [];
    }

    /**
     * Get or set whether advanced query result cachine is currently enabled.
     * Advanced caching attempts to determine whether a query has modified any
     * of the referenced tables since the data was last cached.
     * Advanced caching is <b>disabled</b> by default.
     * This setting applies to <b>all</b> instances of the Database class.
     * @param bool $NewSetting TRUE to enable advanced caching or FALSE to
     *       disable.  (OPTIONAL)
     * @return bool Current advanced caching setting.
     */
    public static function advancedCaching(?bool $NewSetting = null): bool
    {
        if ($NewSetting !== null) {
            self::$AdvancedCachingFlag = $NewSetting;
        }
        return self::$AdvancedCachingFlag;
    }

    /**
     * Get the memory threshold (in bytes) used to determine when DB caches
     * should be cleared. (Caches will be cleared when free memory drops below
     * this threshold.)
     * @return int Cache memory threshold in bytes.
     */
    public static function getThresholdForCacheClearing(): int
    {
        return self::$CacheMemoryThreshold;
    }

    /**
     * Set query errors to ignore.  The command and error message patterns should be
     *       formatted for preg_match().  For example:
     * @code
     * $SqlErrorsWeCanIgnore = array(
     *        "/ALTER TABLE [a-z]+ ADD COLUMN/i" => "/Duplicate column name/i",
     *        "/CREATE TABLE /i" => "/Table '[a-z0-9_]+' already exists/i",
     *        );
     * @endcode
     * @param array|null $ErrorsToIgnore Associative array containing errors to ignore
     *       when running queries, with patterns for SQL commands as the indexes and
     *       the patterns for the SQL error messages as the values.  Pass in NULL to
     *       clear list of errors to ignore.
     * @param bool $NormalizeWhitespace If TRUE, incoming SQL patterns have any
     *       whitespace within them replaced with "\s+" so that variations in
     *       whitespace within SQL will not cause the pattern to fail.
     *       (OPTIONAL, defaults to TRUE)
     * @see Database::ignoredError()
     */
    public function setQueryErrorsToIgnore(
        $ErrorsToIgnore,
        bool $NormalizeWhitespace = true
    ): void {
        if ($NormalizeWhitespace && ($ErrorsToIgnore !== null)) {
            $RevisedErrorsToIgnore = [];
            foreach ($ErrorsToIgnore as $SqlPattern => $ErrMsgPattern) {
                $SqlPattern = preg_replace("/\\s+/", "\\s+", $SqlPattern);
                $RevisedErrorsToIgnore[$SqlPattern] = $ErrMsgPattern;
            }
            $ErrorsToIgnore = $RevisedErrorsToIgnore;
        }
        $this->ErrorsToIgnore = $ErrorsToIgnore;
    }

    /**
     * Check whether an error was ignored by the most recent query.
     * @return string|false Error message if an error was ignored, otherwise FALSE.
     * @see Database::setQueryErrorsToIgnore()
     */
    public function ignoredError()
    {
        return $this->ErrorIgnored;
    }

    /*@)*/ /* Setup/Initialization */
    /** @name Data Manipulation */ /*@(*/

    /**
     * Query database (with caching if enabled).  It's important to keep in
     * mind that a query that returns no results is NOT the same as a query
     * that generates an error.
     * @param string $QueryString SQL query string.
     * @param string $FieldName Name of field for which to return value to
     *       caller.  (OPTIONAL)
     * @return mysqli_result|bool|string|null Query handle, FALSE on error, or (if
     *      field name supplied) retrieved value or NULL if no value available.
     */
    public function query(string $QueryString, ?string $FieldName = null)
    {
        # clear flag that indicates whether query error was ignored
        $this->ErrorIgnored = false;

        # if caching is enabled
        if (self::$CachingFlag) {
            # if SQL statement is read-only
            if ($this->isReadOnlyStatement($QueryString)) {
                # if we have statement in cache
                if (isset(self::$QueryResultCache[$QueryString]["NumRows"])) {
                    if (self::$QueryDebugOutputFlag) {
                        print("DB-C: $QueryString<br>\n");
                    }

                    # make sure query result looks okay
                    $this->QueryHandle = true;

                    # increment cache hit counter
                    self::$CachedQueryCounter++;

                    # make local copy of results
                    $this->QueryResults = self::$QueryResultCache[$QueryString];
                    $this->NumRows = self::$QueryResultCache[$QueryString]["NumRows"];

                    # set flag to indicate that results should be retrieved from cache
                    $this->GetResultsFromCache = true;
                } else {
                    # execute SQL statement
                    $this->QueryHandle = $this->runQuery($QueryString);
                    if (!$this->QueryHandle instanceof mysqli_result) {
                        if ($this->QueryHandle === false) {
                            throw new Exception("Database query \""
                                    .substr($QueryString, 0, 300)."\" failed"
                                    ." with error \"".$this->ErrNo.": "
                                    .$this->ErrMsg."\".");
                        } else {
                            return false;
                        }
                    }

                    # save number of rows in result
                    $this->NumRows = mysqli_num_rows($this->QueryHandle);

                    if (!$this->shouldCacheResult()) {
                        # set flag to indicate that query results should not
                        #       be retrieved from cache
                        $this->GetResultsFromCache = false;
                    } else {
                        # if we are low on memory
                        if (StdLib::getFreeMemory() < self::$CacheMemoryThreshold) {
                            $this->pruneQueryResultsCache();
                        }

                        # if advanced caching is enabled
                        if (self::$AdvancedCachingFlag) {
                            # save tables accessed by query
                            self::$QueryResultCache[$QueryString]["TablesAccessed"] =
                                $this->tablesAccessed($QueryString);
                        }

                        # if rows found
                        if ($this->NumRows > 0) {
                            # load query results
                            for ($Row = 0; $Row < $this->NumRows; $Row++) {
                                $this->QueryResults[$Row] =
                                    mysqli_fetch_assoc($this->QueryHandle);
                            }

                            # cache query results
                            self::$QueryResultCache[$QueryString] = $this->QueryResults;
                        } else {
                            # clear local query results
                            unset($this->QueryResults);
                        }

                        # cache number of rows
                        self::$QueryResultCache[$QueryString]["NumRows"] = $this->NumRows;

                        # set flag to indicate that query results should be
                        #       retrieved from cache
                        $this->GetResultsFromCache = true;
                    }
                }
            } else {
                # if command looks like it may delete data or alter a table
                $NormalizedQuery = strtoupper(trim($QueryString));
                if ((substr($NormalizedQuery, 0, 7) == "DELETE ")
                        || (substr($NormalizedQuery, 0, 12) == "ALTER TABLE ")) {
                    # clear value update cache
                    self::$VUCache = [];
                }

                # if advanced caching is enabled
                if (self::$AdvancedCachingFlag) {
                    # if table modified by statement is known
                    $TableModified = $this->tableModified($QueryString);
                    if ($TableModified) {
                        # for each cached query
                        foreach (
                            self::$QueryResultCache as $CachedQueryString => $CachedQueryResult
                        ) {
                            # if we know what tables were accessed
                            if ($CachedQueryResult["TablesAccessed"]) {
                                # if tables accessed include the one we may modify
                                if (in_array(
                                    $TableModified,
                                    $CachedQueryResult["TablesAccessed"]
                                )) {
                                    # clear cached query results
                                    unset(self::$QueryResultCache[$CachedQueryString]);
                                }
                            } else {
                                # clear cached query results
                                unset(self::$QueryResultCache[$CachedQueryString]);
                            }
                        }
                    } else {
                        # clear entire query result cache
                        self::$QueryResultCache = [];
                    }
                } else {
                    # clear entire query result cache
                    self::$QueryResultCache = [];
                }

                # execute SQL statement
                $this->QueryHandle = $this->runQuery($QueryString);
                if ($this->QueryHandle === false) {
                    return false;
                }

                # set flag to indicate that query results should not be
                #       retrieved from cache
                $this->GetResultsFromCache = false;
            }

            # reset row counter
            $this->RowCounter = 0;

            # increment query counter
            self::$QueryCounter++;
        } else {
            # execute SQL statement
            $this->QueryHandle = $this->runQuery($QueryString);
            if ($this->QueryHandle === false) {
                return false;
            }
        }

        if ($FieldName !== null) {
            return $this->fetchField($FieldName);
        } else {
            return $this->QueryHandle;
        }
    }

    /**
     * Query specific value from database (with caching if enabled).
     * @param string $QueryString SQL query string.
     * @param string $FieldName Name of field for which to return value to caller.
     * @return string|null Retrieved value or NULL if no value available.
     */
    public function queryValue(string $QueryString, string $FieldName)
    {
        $QueryResult = $this->query($QueryString, $FieldName);
        if (!is_string($QueryResult) && ($QueryResult !== null)) {
            throw new Exception("Error when attempting to query value.");
        }
        return $QueryResult;
    }

    /**
     * Execute queries from specified file.  Comment lines are ignored.
     * Multiple queries on a single line are not handled.  Execution continues
     * until all queries are run or an error occurs that has not been
     * previously specified to be ignored.  If a query fails, information
     * about the failure can be retrieved with queryErrMsg() and queryErrNo().
     * @param string $FileName Name of file to load queries from.
     * @return integer|null Number of queries executed or NULL if query failed.
     * @see Database::setQueryErrorsToIgnore()
     * @see Database::queryErrMsg()
     * @see Database::queryErrNo()
     */
    public function executeQueriesFromFile(string $FileName)
    {
        $QueryCount = 0;

        # open file
        $FHandle = fopen($FileName, "r");

        # if file open succeeded
        if ($FHandle !== false) {
            # while lines left in file
            $Query = "";
            while (!feof($FHandle)) {
                # read in line from file
                $Line = (string)fgets($FHandle, 32767);

                # trim whitespace from line
                $Line = trim($Line);

                # if line is not empty and not a comment
                if (!preg_match("/^#/", $Line)
                    && !preg_match("/^--/", $Line)
                    && strlen($Line)) {
                    # add line to current query
                    $Query .= " " . $Line;

                    # if line completes a query
                    if (preg_match("/;$/", $Line)) {
                        # run query
                        $QueryCount++;
                        $Result = $this->query($Query);
                        $Query = "";

                        # if query resulted in an error that is not ignorable
                        if ($Result === false) {
                            # stop processing queries and set error code
                            $QueryCount = null;
                            break;
                        }
                    }
                }
            }

            # close file
            fclose($FHandle);
        }

        # return number of executed queries to caller
        return $QueryCount;
    }

    /**
     * Get most recent error message text set by query().
     * @return string Error message text from database server.
     * @see queryErrNo()
     */
    public function queryErrMsg(): string
    {
        return $this->ErrMsg;
    }

    /**
     * Get most recent error code set by query().
     * @return int Error code from database server.
     * @see queryErrMsg()
     */
    public function queryErrNo(): int
    {
        return $this->ErrNo;
    }

    /**
     * Get/set whether query() errors will be displayed.  By default errors
     *       are not displayed.
     * @param bool $NewValue TRUE to display errors or FALSE to not display.  (OPTIONAL)
     * @return bool Current value of whether query() errors will be displayed.
     */
    public static function displayQueryErrors(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            self::$DisplayErrors = $NewValue;
        }
        return self::$DisplayErrors;
    }

    /**
     * Get number of rows returned by last SELECT or SHOW query.
     * @return int Number of database rows selected by last query.
     */
    public function numRowsSelected(): int
    {
        # if caching is enabled and query was cached
        if (self::$CachingFlag && $this->GetResultsFromCache) {
            # return cached number of rows to caller
            return $this->NumRows;
        } else {
            # call to this method after an unsuccessful query
            if (!$this->QueryHandle instanceof mysqli_result) {
                return 0;
            }

            # retrieve number of rows and return to caller
            return (int)mysqli_num_rows($this->QueryHandle);
        }
    }

    /**
     * Get number of rows affected by last INSERT, UPDATE, REPLACE,
     * or DELETE query.
     * @return int Number of database rows affected by last query.
     */
    public function numRowsAffected(): int
    {
        # call to this method after an unsuccessful query
        if ($this->QueryHandle === false) {
            return 0;
        }

        # retrieve number of rows and return to caller
        return (int)mysqli_affected_rows($this->Handle);
    }

    /**
     * Get next database row retrieved by most recent query.
     * @return array|false Array of database values with field names for
     *       indexes.  Returns FALSE if no more rows are available.
     */
    public function fetchRow()
    {
        # if caching is enabled and query was cached
        if (self::$CachingFlag && $this->GetResultsFromCache) {
            # if rows left to return
            if ($this->RowCounter < $this->NumRows) {
                # retrieve row from cache
                $Result = $this->QueryResults[$this->RowCounter];

                # increment row counter
                $this->RowCounter++;
            } else {
                # return nothing
                $Result = false;
            }
        } else {
            # call to this method after successful query
            if ($this->QueryHandle instanceof mysqli_result) {
                $Result = mysqli_fetch_assoc($this->QueryHandle);
                if ($Result === null) {
                    $Result = false;
                }
                # call to this method after unsuccessful query
            } else {
                $Result = false;
            }
        }

        # return row to caller
        return $Result;
    }

    /**
     * Get specified number of database rows retrieved by most recent query.
     * @param int $NumberOfRows Maximum number of rows to return.  (OPTIONAL -- if
     *       not specified then all available rows are returned)
     * @return array Array of rows.  Each row is an associative array indexed
     *       by field name.
     */
    public function fetchRows(?int $NumberOfRows = null): array
    {
        # assume no rows will be returned
        $Result = [];

        # for each available row
        $RowsFetched = 0;
        while ((($RowsFetched < $NumberOfRows) || ($NumberOfRows == null))
            && ($Row = $this->fetchRow())) {
            # add row to results
            $Result[] = $Row;
            $RowsFetched++;
        }

        # return array of rows to caller
        return $Result;
    }

    /**
     * Get all available values for specified database field retrieved by most
     * recent query.  If a second database field name is specified then the array
     * returned will be indexed by the values from that field.  If all index field
     * values are not unique then some values will be overwritten.
     *
     * A common use for this method is to retrieve a set of values with an ID field
     * specified for the index:<br>
     *  <code>$CNames = $DB->fetchColumn("ControlledName", "ControlledNameId");</code>
     * @param string $FieldName Name of database field.
     * @param string $IndexFieldName Name of second database field to use for
     *       array index.  (OPTIONAL)
     * @return array Array of values from specified field, indexed numerically.  If
     *       IndexFieldName is supplied then array will be indexed by
     *       corresponding values from that field.
     */
    public function fetchColumn(string $FieldName, ?string $IndexFieldName = null): array
    {
        $Array = [];
        while ($Record = $this->fetchRow()) {
            if ($IndexFieldName != null) {
                $Array[$Record[$IndexFieldName]] = $Record[$FieldName];
            } else {
                $Array[] = $Record[$FieldName];
            }
        }
        return $Array;
    }

    /**
     * Pull next row from last DB query and get a specific value from that row.
     * This is a convenience method that in effect combines a fetchRow() with getting
     * a value from the array returned.  This method <b>does</b> advance the pointer
     * to the next row returned by the query each time it is called.
     * @param string $FieldName Name of field.
     * @return string|null Value from specified field or NULL if no value available.
     */
    public function fetchField(string $FieldName)
    {
        $Record = $this->fetchRow();
        return ($Record && isset($Record[$FieldName])) ? $Record[$FieldName] : null;
    }

    /**
     * Get ID of row added by the last SQL "INSERT" statement.  It should be
     * called immediately after the INSERT statement query.  This method uses the
     * SQL "LAST_INSERT_ID()" function.
     * @return int Numerical ID value.
     */
    public function getLastInsertId(): int
    {
        $QueryResult = $this->queryValue(
            "SELECT LAST_INSERT_ID() AS InsertId",
            "InsertId"
        );
        if ($QueryResult === null) {
            throw new Exception("Unable to retrieve last insert ID.");
        }
        return (int)$QueryResult;
    }

    /**
     * For tables that have an AUTO_INCREMENT column, get the next
     * value that will be assigned. Callers are likely to want to LOCK
     * the table to make sure that new rows are not inserted after this
     * function is called.
     * @param string $TableName Table to examine.
     * @return int Next insert id (always zero for tables with no
     *   AUTO_INCREMENT column).
     * @throws Exception If table does not exist.
     */
    public function getNextInsertId(string $TableName): int
    {
        if (!$this->tableExists($TableName)) {
            throw new Exception(
                "Table " . $TableName . " does not exist"
            );
        }

        $QueryResult = $this->queryValue(
            "SELECT `AUTO_INCREMENT` AS Id FROM INFORMATION_SCHEMA.TABLES "
            . "WHERE TABLE_SCHEMA='" . addslashes($this->DBName()) . "' "
            . "AND TABLE_NAME = '" . addslashes($TableName) . "'",
            "Id"
        );
        if ($QueryResult === null) {
            throw new Exception("Unable to retrieve next insert ID.");
        }
        return (int)$QueryResult;
    }

    /**
     * A convenience function to get or set a value in the database.
     * @param string $FieldName Name of database column.
     * @param string|false $NewValue New value to set.  Use FALSE to clear the
     *       present database value (i.e. set it to null).  (OPTIONAL)
     * @return string|false Current value, or FALSE if database value is not
     *       set (i.e. is null).
     */
    public function updateValue(string $FieldName, $NewValue = null)
    {
        if ($NewValue !== null) {
            $NewValue = ($NewValue === false) ? null : $NewValue;
            $CurrentValue = $this->updateValueForColumn($FieldName, $NewValue);
        } else {
            $CurrentValue = $this->updateValueForColumn($FieldName);
        }
        return ($CurrentValue === null) ? false : $CurrentValue;
    }

    /**
     * A convenience function to get or set an integer value in the database.
     * @param string $FieldName Name of database field.
     * @param int|false $NewValue New value to set.  Use FALSE to clear the
     *       present database value (i.e. set it to null).  (OPTIONAL)
     * @return int|false Requested value or FALSE if no value is set (i.e.
     *       database value is null).
     */
    public function updateIntValue(string $FieldName, $NewValue = null)
    {
        $NewValue = is_int($NewValue) ? (string)$NewValue : $NewValue;
        $Value = $this->updateValue($FieldName, $NewValue);
        return ($Value === false) ? false : (int)$Value;
    }

    /**
     * A convenience function to get or set a float value in the database.
     * @param string $FieldName Name of database field.
     * @param float|false $NewValue New value to set.  Use FALSE to clear the
     *       present database value (i.e. set it to null).  (OPTIONAL)
     * @return float|false Requested value or FALSE if no value is set (i.e.
     *       database value is null).
     */
    public function updateFloatValue(string $FieldName, $NewValue = null)
    {
        $NewValue = is_float($NewValue) ? (string)$NewValue : $NewValue;
        $Value = $this->updateValue($FieldName, $NewValue);
        return ($Value === false || $Value === '') ? false : (float)$Value;
    }

    /**
     * A convenience function to get or set a boolean value in the database.
     * Unknown values in the database (i.e. the column set to null) are not
     * supported by this method.
     * @param string $FieldName Name of database field.
     * @param bool $NewValue New value to set.  (OPTIONAL)
     * @return bool Requested value.
     */
    public function updateBoolValue(string $FieldName, ?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $CurrentValue = $this->updateValueForColumn(
                $FieldName,
                ($NewValue ? "1" : "0")
            );
        } else {
            $CurrentValue = $this->updateValueForColumn($FieldName);
        }
        return (bool)$CurrentValue;
    }

    /**
     * A convenience function to get or set a date or timestamp value in the
     * database.  The incoming date/time string must be parseable by strtotime().
     * @param string $FieldName Name of database field.
     * @param string|false $NewValue New value to set.  Use FALSE to clear the
     *       present database value (i.e. set it to null).  (OPTIONAL)
     * @return string|false Requested value or FALSE if no value is set (i.e.
     *       database value is null).
     */
    public function updateDateValue(string $FieldName, $NewValue = null)
    {
        if (($NewValue !== null) && ($NewValue !== false)) {
            $Timestamp = strtotime($NewValue);
            if ($Timestamp === false) {
                throw new InvalidArgumentException(
                    "Unable to parse incoming date (" . $NewValue . ")."
                );
            }
            $NewValue = date(StdLib::SQL_DATE_FORMAT, $Timestamp);
        }
        return $this->updateValue($FieldName, $NewValue);
    }

    /**
     * Set parameters required for Update*Value() functions.
     * @param string $TableName Name of database table.
     * @param string $Condition SQL query conditional usable in SELECT or
     *       UPDATE statements (should not include "WHERE").  (OPTIONAL)
     * @param array $Values Values to use to populate a value cache (preferably
     *       returned from Database::fetchRow()), with database column names
     *       for the index.  (OPTIONAL)
     * @see Database::updateValue()
     */
    public function setValueUpdateParameters(
        string $TableName,
        string $Condition = "",
        ?array $Values = null
    ): void {
        $this->VUTableName = $TableName;
        $this->VUCondition = "";
        if (strlen($Condition)) {
            $this->VUCondition = " WHERE ".$Condition;
        }
        $CacheKey = $TableName.$this->VUCondition;

        if ($Values !== null) {
            self::$VUCache[$CacheKey] = $Values;
        } else {
            unset(self::$VUCache[$CacheKey]);
        }
    }

    /**
     * A convenience function to copy values from one row to another.  The ID
     * column value will not be copied.
     * @param string $TableName Name of table.
     * @param string $IdColumn Name of column containing ID value.
     * @param string $SrcId Value of ID column in source row.
     * @param mixed $DstId Value of ID column or array of values of ID columns
     *       in destination row(s).
     * @param array $ColumnsToExclude Names of additional columns to exclude
     *       from copy.  (OPTIONAL)
     */
    public function copyValues(
        string $TableName,
        string $IdColumn,
        string $SrcId,
        $DstId,
        $ColumnsToExclude = []
    ): void {
        # retrieve names of all columns in table
        $AllColumns = $this->getColumns($TableName);

        # remove columns to be excluded from copy
        $ColumnsToExclude[] = $IdColumn;
        $ColumnsToCopy = array_diff($AllColumns, $ColumnsToExclude);

        # normalize destination IDs
        $DstIds = is_array($DstId) ? $DstId : array($DstId);
        $DstIds = array_diff($DstIds, array($SrcId));

        # if there are columns to copy and we have destinations
        if (count($ColumnsToCopy) && count($DstIds)) {
            # construct and execute query to perform copy
            $Query = "UPDATE `" . $TableName . "` AS Target"
                . " LEFT JOIN `" . $TableName . "` AS Source"
                . " ON Source.`" . $IdColumn . "` = '" . addslashes($SrcId) . "'";
            $QuerySets = [];
            foreach ($ColumnsToCopy as $ColumnName) {
                $QuerySets[] = "Target.`" . $ColumnName . "` = Source.`" . $ColumnName . "`";
            }
            $Query .= " SET " . implode(", ", $QuerySets);
            $QueryConditions = [];
            foreach ($DstIds as $Id) {
                $QueryConditions[] = "Target.`" . $IdColumn . "` = '" . addslashes($DstId) . "'";
            }
            $Query .= " WHERE " . implode(" OR ", $QueryConditions);
            $this->query($Query);
        }
    }

    /**
     * Insert an array of values with a minimum number of INSERT statements.
     * If a key column name is specified, then the array keys will be set in
     * each row, along with the array values.
     * @param string $Table Name of the table to insert into.
     * @param string $ValueField Name of column to insert array values into.
     * @param array $Values Array values.
     * @param string $KeyField Name of column to insert array keys into.
     *       (OPTIONAL)
     * @param int $AvgDataLength Average length of value (and keys, if
     *       key column name supplied) in characters.  (OPTIONAL, defaults
     *       to 20)
     */
    public function insertArray(
        string $Table,
        string $ValueField,
        $Values,
        ?string $KeyField = null,
        int $AvgDataLength = 20
    ): void {
        # pick some ballpark values
        $ChunkSizeAssumedSafe = 100;
        $QueryLengthAssumedSafe = 10486576;  # (1 MB)

        # exit without doing anything if there are no values
        $ValueCount = count($Values);
        if ($ValueCount == 0) {
            return;
        }

        # determine size of array chunk per INSERT statement
        $NonValueCharCount = 100;
        if ($ValueCount > $ChunkSizeAssumedSafe) {
            $MaxQueryLen = $this->getMaxQueryLength();
            $ValueSegmentLen = $AvgDataLength + 6;
            if ($KeyField !== null) {
                $ValueSegmentLen = $ValueSegmentLen * 2;
            }
            $ValueChunkSize = (int)floor($MaxQueryLen / $ValueSegmentLen);
        } else {
            $ValueChunkSize = $ChunkSizeAssumedSafe;
        }

        # for each chunk of values
        $ValueChunks = array_chunk($Values, $ValueChunkSize, true);
        foreach ($ValueChunks as $ValueChunk) {
            # begin building query
            $Query = "INSERT INTO `" . $Table . "` (`" . $ValueField . "`";

            # if key field was specified
            if ($KeyField !== null) {
                # add key field to query
                $Query .= ", `" . $KeyField . "`";

                # assemble value segment with keys
                $ValueSegFunc = function ($Carry, $Key) use ($ValueChunk) {
                    $Carry .= "('" . addslashes($ValueChunk[$Key]) . "','"
                        . addslashes($Key) . "'),";
                    return $Carry;
                };
                $ValueSegment = array_reduce(array_keys($ValueChunk), $ValueSegFunc);
            } else {
                # assemble value segment
                $ValueSegFunc = function ($Carry, $Value) {
                    $Carry .= "('" . addslashes($Value) . "'),";
                    return $Carry;
                };
                $ValueSegment = array_reduce($ValueChunk, $ValueSegFunc);
            }

            # trim extraneous comma off of value segment
            $ValueSegment = substr($ValueSegment, 0, -1);

            # add value segment to query
            $Query .= ") VALUES " . $ValueSegment;

            # double check to make sure query isn't too long
            $QueryLen = strlen($Query);
            if ($QueryLen > $QueryLengthAssumedSafe) {
                if (!isset($MaxQueryLen)) {
                    $MaxQueryLen = $this->getMaxQueryLength();
                }
                if ($QueryLen > $MaxQueryLen) {
                    throw new Exception("Maximum query length ("
                        . $MaxQueryLen . ") exceeded (" . $QueryLen . ").");
                }
            }

            # run query
            $this->query($Query);
        }
    }

    /*@)*/ /* Data Manipulation */
    /** @name Miscellaneous */ /*@(*/

    /**
     * Escape a string that may contain null bytes.  Normally,
     * addslashes() should be used for escaping.  However, addslashes()
     * does not correctly handle null bytes which can come up when
     * serializing PHP objects or dealing with binary data.
     * @param string $String String to escape.
     * @return string Escaped data
     */
    public function escapeString(string $String): string
    {
        return mysqli_real_escape_string($this->Handle, $String);
    }

    /**
     * Peform query that consists of SQL comment statement.  This is used primarily
     * when query debug output is turned on, to insert additional information into
     * the query stream.
     * @param string $String Debug string.
     */
    public function logComment(string $String): void
    {
        $this->query("-- " . $String);
    }


    /**
     * Check if connection info is valid.
     * @param string $HostName Host name of system on which database server resides.
     * @param string $UserName User name to use to log in to database server.
     * @param string $Password Password to use to log in to database server.
     * @return bool TRUE when the provided info is valid, FALSE otherwise.
     */
    public static function connectionInfoIsValid(
        string $HostName,
        string $UserName,
        string $Password
    ) : bool {
        try {
            self::connectToDatabaseServer($HostName, $UserName, $Password);
        } catch (\Exception $Ex) {
            return false;
        }

        return true;
    }

    /**
     * Get database server version number.
     * @param bool $FullVersion TRUE for the whole version string or
     *     FALSE for just the version number (OPTIONAL, default FALSE).
     * @return string Version number.
     */
    public static function getServerVersion(bool $FullVersion = false): string
    {
        $Handle = self::connectToDatabaseServer(
            self::$GlobalDBHostName,
            self::$GlobalDBUserName,
            self::$GlobalDBPassword
        );

        $QueryHandle = mysqli_query($Handle, "SELECT VERSION() AS ServerVer");
        if (!($QueryHandle instanceof mysqli_result)) {
            throw new Exception("Unable to retrieve SQL server version number.");
        }

        # retrieve version string
        $Row = mysqli_fetch_assoc($QueryHandle);
        if ($Row === false) {
            throw new Exception("Unable to retrieve SQL server version number.");
        }

        $Version = $Row["ServerVer"];
        if (!is_string($Version)) {
            throw new Exception("Unable to retrieve SQL server version number.");
        }

        if (!$FullVersion) {
            # strip off any build/config suffix
            $Pieces = explode("-", $Version);
            $Version = array_shift($Pieces);
        }

        # return version number to caller
        return $Version;
    }

    /**
     * Get whether specified database exists.  This method assumes that a global
     * database server user name, password, and host name have already been set.
     * @param string $DatabaseName Name of database.
     * @return bool TRUE if database exists, or FALSE otherwise.
     */
    public static function databaseExists(string $DatabaseName): bool
    {
        $Handle = self::connectToDatabaseServer(
            self::$GlobalDBHostName,
            self::$GlobalDBUserName,
            self::$GlobalDBPassword
        );
        $QueryHandle = mysqli_query(
            $Handle,
            "SHOW DATABASES LIKE '".mysqli_real_escape_string($Handle, $DatabaseName)."'"
        );
        return ($QueryHandle instanceof mysqli_result)
                ? (mysqli_num_rows($QueryHandle) ? true : false)
                : false;
    }

    /**
     * Create a database.  This method assumes that a global database server
     * user name, password, and host name have already been set.
     * @param string $DatabaseName Name of database.
     * @return bool TRUE if database was created, false otherwise.
     */
    public static function createDatabase(string $DatabaseName): bool
    {
        $Handle = self::connectToDatabaseServer(
            self::$GlobalDBHostName,
            self::$GlobalDBUserName,
            self::$GlobalDBPassword
        );

        try {
            $Result = mysqli_query(
                $Handle,
                "CREATE DATABASE `".mysqli_real_escape_string($Handle, $DatabaseName)."`"
            );
            return ($Result === true) ? true : false;
        } catch (\mysqli_sql_exception $Ex) {
            return false;
        }
    }

    /**
     * Drop a database.
     * This method assumes that a global database server
     * user name, password, and host name have already been set.
     * @param string $DatabaseName Name of database.
     * @return bool TRUE if database was created, false otherwise.
     */
    public static function dropDatabase(string $DatabaseName): bool
    {
        $Handle = self::connectToDatabaseServer(
            self::$GlobalDBHostName,
            self::$GlobalDBUserName,
            self::$GlobalDBPassword
        );

        try {
            $Result = mysqli_query(
                $Handle,
                "DROP DATABASE `".mysqli_real_escape_string($Handle, $DatabaseName)."`"
            );
            return ($Result === true) ? true : false;
        } catch (\mysqli_sql_exception $Ex) {
            return false;
        }
    }

    /**
     * Get whether specified table exists.
     * @param string $TableName Name of database table.
     * @return bool TRUE if table exists, or FALSE otherwise.
     */
    public function tableExists(string $TableName): bool
    {
        $this->query("SHOW TABLES LIKE '" . addslashes($TableName) . "'");
        return $this->numRowsSelected() ? true : false;
    }

    /**
     * Get whether specified column exists in specified table.
     * @param string $TableName Name of database table.
     * @param string $ColumnName Name of database column.
     * @return bool TRUE if table and column exist, or FALSE otherwise.
     */
    public function columnExists(string $TableName, string $ColumnName): bool
    {
        $this->query("DESC " . $TableName);
        while ($CurrentColumnName = $this->fetchField("Field")) {
            if ($CurrentColumnName == $ColumnName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get whether specified field exists in specified table.
     * @param string $TableName Name of database table.
     * @param string $FieldName Name of database field.
     * @return bool TRUE if table and field exist, or FALSE otherwise.
     * @deprecated
     */
    public function fieldExists(string $TableName, string $FieldName): bool
    {
        return $this->columnExists($TableName, $FieldName);
    }

    /**
     * Get column type.  Types are normalized to force them to upper
     * case and remove length info (so "int(11)"  becomes just "INT").
     * @param string $TableName Name of database table.
     * @param string $ColumnName Name of column in table.
     * @return string|null Field type or NULL if column was not found.
     */
    public function getColumnType(string $TableName, string $ColumnName)
    {
        $this->query("DESC " . $TableName);
        $AllTypes = $this->fetchColumn("Type", "Field");
        if (!isset($AllTypes[$ColumnName])) {
            return null;
        }
        $Normalizations = [
            '%INT\([0-9]+\)%' => "INT",
        ];
        return preg_replace(
            array_keys($Normalizations),
            array_values($Normalizations),
            strtoupper($AllTypes[$ColumnName])
        );
    }

    /**
     * Get field (column) type.  Types are normalized to
     * @param string $TableName Name of database table.
     * @param string $FieldName Name of database field.
     * @return string|null Field type or NULL if field was not found.
     * @deprecated
     */
    public function getFieldType(string $TableName, string $FieldName)
    {
        return $this->getColumnType($TableName, $FieldName);
    }

    /**
     * Get column (database field) names.
     * @param string $TableName Name of database table.
     * @return array Field names.
     */
    public function getColumns(string $TableName): array
    {
        $this->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
            . " WHERE TABLE_SCHEMA = '" . addslashes($this->DBName)
            . "' AND TABLE_NAME = '" . addslashes($TableName) . "'");
        return $this->fetchColumn("COLUMN_NAME");
    }

    /**
     * Get maximum size for query string.
     * @return int Maximum size in bytes.
     */
    public static function getMaxQueryLength(): int
    {
        return (int)self::getServerVariable("max_allowed_packet");
    }

    /**
     * Get minimum word length supported by full-text searches.
     * @return int Minimum word length.
     */
    public static function getFullTextSearchMinWordLength(): int
    {
        return (int)self::getServerVariable("ft_min_word_len");
    }

    /**
     * Get maximum word length supported by full-text searches.
     * @return int Maximum word length.
     */
    public static function getFullTextSearchMaxWordLength(): int
    {
        return (int)self::getServerVariable("ft_max_word_len");
    }

    /**
     * Get stop word list (used by full-text database searches).
     * @return array Word list.
     */
    public static function getStopWordList(): array
    {
        static $StopWordList;
        if (!isset($StopWordList)) {
            $StopWordFile = self::getServerVariable("ft_stopword_file");
            if (is_readable($StopWordFile)) {
                $FileContents = file_get_contents($StopWordFile);
                if ($FileContents !== false) {
                    $StopWordList = explode("\n", $FileContents);
                }
            }
            if (!isset($StopWordList)) {
                $StopWordList = static::$DefaultStopWordList;
            }
        }
        return $StopWordList;
    }

    /**
     * Get estimated maximum size of data chunks to use when concatenating
     * integer data for use in a query.
     * @param array $Data Integer data.
     * @param int $LengthOfRestOfQuery Max number of characters to assume
     *      for remainder of query string.  (OPTIONAL, defaults to 1000)
     * @param int $ConcatenatorLength Number of chars in string used to
     *      concatenate data.  (OPTIONAL, defaults to 1)
     * @return int Estimated number of data items to include per chunk.
     * @throws InvalidArgumentException If data array is empty.
     */
    public static function getIntegerDataChunkSize(
        array $Data,
        int $LengthOfRestOfQuery = 1000,
        int $ConcatenatorLength = 1
    ): int {
        if (count($Data) == 0) {
            throw new InvalidArgumentException("Empty data array supplied.");
        }

        $HighestValue = max($Data);
        $LowestValue = min($Data);
        if (($HighestValue == 0) && ($LowestValue == 0)) {
            $LongestLength = 1;
        } elseif ((0 - ($LowestValue * 10)) > $HighestValue) {
            $LongestLength = (int)floor(log10(0 - $LowestValue) + 2);
        } else {
            $LongestLength = (int)floor(log10($HighestValue) + 1);
        }

        return (int)((static::getMaxQueryLength() - $LengthOfRestOfQuery)
                     / ($LongestLength + $ConcatenatorLength));
    }

    /**
     * Enable or disable debugging output for queries.  Output is disabled by default.
     * This setting applies to <b>all</b> instances of the Database class.
     * @param bool $NewSetting TRUE to enable output or FALSE to disable output.
     */
    public static function queryDebugOutput(bool $NewSetting): void
    {
        self::$QueryDebugOutputFlag = $NewSetting;
    }

    /**
     * Get/set the number of rows below which results are always stored in the
     * query cache.
     * @param int $NewSetting New setting (OPTIONAL).
     * @eturn int Current value.
     */
    public static function cacheRowsThreshold(?int $NewSetting = null) : int
    {
        if (!is_null($NewSetting)) {
            self::$CacheRowsThreshold = $NewSetting;
        }

        return self::$CacheRowsThreshold;
    }

    /**
     * Get/set the result size (in KiB) below which results will be stored in
     * the query cache even when they exceed the Cache Rows Threshold.
     * (Results with fewer rows than Cache Rows Threshold will be cached
     * without regard to the Cache Size Threshold).
     * @param int $NewSetting New setting (OPTIONAL).
     * @eturn int Current value.
     */
    public static function cacheSizeThreshold(?int $NewSetting = null) : int
    {
        if (!is_null($NewSetting)) {
            self::$CacheSizeThreshold = $NewSetting;
        }

        return self::$CacheSizeThreshold;
    }

    /*
     * Enable/disable query timing. Disabled by default.
     * @param bool $NewSetting TRUE to enable.
     */
    public static function queryTimeRecordingIsEnabled(?bool $NewSetting = null) : bool
    {
        if (!is_null($NewSetting)) {
            self::$RecordQueryTiming = $NewSetting;
        }

        return self::$RecordQueryTiming;
    }

    /**
     * Get the number of queries that have been run since program execution began.
     * The value returned is for <b>all</b> instances of the Database class.
     * @return int Number of queries.
     */
    public static function numQueries(): int
    {
        return self::$QueryCounter;
    }

    /**
     * Get the number of queries that have resulted in cache hits since program
     * execution began.
     * The value returned is for <b>all</b> instances of the Database class.
     * @return int Number of queries that resulted in cache hits.
     */
    public static function numCacheHits(): int
    {
        return self::$CachedQueryCounter;
    }

    /**
     * Get the ratio of query cache hits to queries as a percentage.
     * The value returned is for <b>all</b> instances of the Database class.
     * @return int Percentage of queries that resulted in hits.
     */
    public static function cacheHitRate(): int
    {
        if (self::$QueryCounter) {
            return (int)((self::$CachedQueryCounter / self::$QueryCounter) * 100);
        } else {
            return 0;
        }
    }

    /**
     * Get/set current threshold for what is considered a "slow" SQL query.
     * (Slow queries can be noted in a special log by the database server.)
     * @param int $NewValue New threshold time, in seconds.  (OPTIONAL)
     * @return int Current threshold time.
     */
    public static function slowQueryThreshold(?int $NewValue = null): int
    {
        if (!is_null($NewValue)) {
            self::$LongQueryTime = (int)$NewValue;
            self::setServerVariable("long_query_time", $NewValue);
        }

        return (int) self::getServerVariable("long_query_time");
    }

    /**
     * Set a slow query log function.
     * @param Callable $NewValue Query logging function. The first parameter
     * will be the query string, the second will be the elapsed time.
     */
    public static function setSlowQueryLoggingFn(callable $NewValue): void
    {
        if (!is_callable($NewValue)) {
            throw new InvalidArgumentException(
                "Slow query log function not callable."
            );
        }

        self::$SlowQueryLoggingFn = $NewValue;
    }

    /**
     * Set a cache prune logging function.
     * @param Callable $NewValue Query cache prune logging function. Called
     *   immediately before the query cache is pruned, first parameter gives
     *   the call site of the query that prompted pruning, second parameter is
     *   the query result cache before pruning.
     */
    public static function setCachePruneLoggingFn(callable $NewValue): void
    {
        if (!is_callable($NewValue)) {
            throw new InvalidArgumentException(
                "Cache prune log function not callable."
            );
        }

        self::$CachePruneLoggingFn = $NewValue;
    }

    /**
     * Normalize supplied string so it can be used as column name.
     * @param string $Value String to normalize.
     * @return string String suitable for use as database column name.
     * @throws InvalidArgumentException If supplied string cannot be
     *      normalized in a way to make it suitable for use as a database
     *      column name.
     */
    public static function normalizeToColumnName(string $Value): string
    {
        $ColumnName = preg_replace("%[^A-Za-z0-9_]%", "", $Value);
        $ColumnNameLength = strlen($ColumnName);
        if ($ColumnNameLength == 0) {
            throw new InvalidArgumentException("String supplied (\"".$Value
                    ."\") that could not be normalized into a valid database"
                    ." column name.");
        } elseif ($ColumnNameLength > static::MAX_COLUMN_NAME_LENGTH) {
            $ColumnName = substr($ColumnName, 0, static::MAX_COLUMN_NAME_LENGTH);
        }
        return $ColumnName;
    }

    /**
     * Report the queries that took the longest to run based on the sum of all
     *   times the query was executed.
     * @param int $NumQueries Number of queries to return (OPTIONAL)
     * @return array Array of slow queries, keys give the query string,
     *   values are arrays containing Count (number of times the query was run),
     *   TotalTime, LongestTime (max time for a single instance of the query), and
     *   Locations (call stack that executed the query). Values are sorted
     *   in descending order of total time.
     */
    public static function getMostTimeConsumingQueries(?int $NumQueries = null)
    {
        # sort in descending order of total time
        uasort(
            self::$QueryTimingStats,
            function ($a, $b) {
                return $b["TotalTime"] <=> $a["TotalTime"];
            }
        );

        if ($NumQueries === null) {
            return self::$QueryTimingStats;
        }

        return array_slice(self::$QueryTimingStats, 0, $NumQueries, true);
    }

    /**
     * Convenience method for creating database tables.
     * @param array $Tables Array of table creation SQL, with table names for
     *      the index.
     * @return string|null Error message or NULL if creation succeeded.
     */
    public function createTables(array $Tables): ?string
    {
        # check that all supplied commands appear to create tables
        foreach ($Tables as $TableName => $TableSql) {
            if (!preg_match('/^\s*CREATE\s+TABLE/i', $TableSql)) {
                return "Table creation SQL command does not appear to create"
                        ." a table. (COMMAND: \"".$TableSql."\")";
            }
        }

        # create tables
        foreach ($Tables as $TableName => $TableSql) {
            $Result = $this->query($TableSql);
            if ($Result === false) {
                return "Unable to create ".$TableName." database table."
                    ."  (ERROR: ".$this->queryErrMsg().")";
            }
        }
        return null;
    }

    /**
     * Convenience method for creating missing database tables.  This will not
     * error out if the table creation SQL includes tables that already exist.
     * @param array $Tables Array of table creation SQL, with table names for
     *      the index.
     * @return string|null Error message or NULL if creation succeeded.
     */
    public function createMissingTables(array $Tables): ?string
    {
        $SqlErrorsWeCanIgnore = [
            "/CREATE TABLE /i" => "/Table '[a-z0-9_]+' already exists/i"
        ];
        $this->setQueryErrorsToIgnore($SqlErrorsWeCanIgnore);
        return $this->createTables($Tables);
    }

    /**
     * Convenience method for dropping database tables, using tables specified
     * in the same format as createTables().  (This method as implemented here
     * currently will not return an error message, but callers should still
     * check for and potentially handle an error message because child classes
     * or future updates to this method may do so.)
     * @param array $Tables Array of table creation SQL, with table names for
     *      the index.
     * @return string|null Error message or NULL if table drops succeeded.
     */
    public function dropTables(array $Tables): ?string
    {
        foreach ($Tables as $TableName => $TableSql) {
            $this->query("DROP TABLE IF EXISTS " . $TableName);
        }
        return null;
    }

    /**
     * Get PDO (PHP Data Object) instance for database.
     * @return PDO PDO instance connected to database.
     */
    public static function getPDO(): PDO
    {
        $DataSourceName = "mysql:"
                ."dbname=".self::$GlobalDBName.";"
                ."host=".self::$GlobalDBHostName;
        try {
            $PDO = new PDO(
                $DataSourceName,
                self::$GlobalDBUserName,
                self::$GlobalDBPassword
            );
        } catch (PDOException $Ex) {
            throw new Exception("Could not open PDO connection for database: "
                .$Ex->getMessage()
                ." (code: ".mysqli_connect_errno().")");
        }
        return $PDO;
    }

    /*@)*/ /* Miscellaneous */

    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $DBUserName;
    protected $DBPassword;
    protected $DBHostName;
    protected $DBName;

    private $Handle;
    private $QueryHandle;
    private $QueryResults;
    private $RowCounter;
    private $NumRows;
    private $GetResultsFromCache;
    private $ErrorIgnored = false;
    private $ErrorsToIgnore = null;
    private $ErrMsg = null;
    private $ErrNo = null;
    private $VUCondition = "";
    private $VUTableName;

    private static $DisplayErrors = false;

    private static $GlobalDBUserName = "";
    private static $GlobalDBPassword = "";
    private static $GlobalDBHostName = "localhost";
    private static $GlobalDBName = "";
    private static $VUCache = [];
    private static $SupportedEngines = null;

    private static $RecordQueryTiming = false;
    private static $QueryTimingStats = [];
    private static $QueryTimingMinimum = 0.001;

    private static $SlowQueryLoggingFn = null;
    private static $LongQueryTime = 10;

    private static $CachePruneLoggingFn = null;

    # debug output flag
    private static $QueryDebugOutputFlag = false;
    # flag for whether caching is turned on
    private static $CachingFlag = true;
    # query result advanced caching flag
    private static $AdvancedCachingFlag = false;
    # global cache for query results
    private static $QueryResultCache = [];
    # stats counters
    private static $QueryCounter = 0;
    private static $CachedQueryCounter = 0;
    # database connection link handles
    private static $ConnectionHandles = [];
    # always cache results returning less than this number of rows
    private static $CacheRowsThreshold = 250;
    # for queries returning more than CRT, cache if they take less than this number of bytes
    private static $CacheSizeThreshold = 512 * 1024;
    # prune the query cache if there is less than this amount of memory free
    private static $CacheMemoryThreshold;
    # number of rows to leave in cache when pruning
    private static $CacheRowsToLeave = 10;
    # number of retry attempts to make to connect to database
    private static $ConnectRetryAttempts = 3;
    # number of seconds to wait between connection retry attempts
    private static $ConnectRetryInterval = 5;
    private static $ServerVariableCache;

    /**
     * Default stop word list for full-text searches.
     * Taken from the MySQL documentation:
     *      https://dev.mysql.com/doc/refman/8.0/en/fulltext-stopwords.html
     */
    protected static $DefaultStopWordList = [
        "a's", "able", "about", "above", "according",
        "accordingly", "across", "actually", "after", "afterwards",
        "again", "against", "ain't", "all", "allow",
        "allows", "almost", "alone", "along", "already",
        "also", "although", "always", "am", "among",
        "amongst", "an", "and", "another", "any",
        "anybody", "anyhow", "anyone", "anything", "anyway",
        "anyways", "anywhere", "apart", "appear", "appreciate",
        "appropriate", "are", "aren't", "around", "as",
        "aside", "ask", "asking", "associated", "at",
        "available", "away", "awfully", "be", "became",
        "because", "become", "becomes", "becoming", "been",
        "before", "beforehand", "behind", "being", "believe",
        "below", "beside", "besides", "best", "better",
        "between", "beyond", "both", "brief", "but",
        "by", "c'mon", "c's", "came", "can",
        "can't", "cannot", "cant", "cause", "causes",
        "certain", "certainly", "changes", "clearly", "co",
        "com", "come", "comes", "concerning", "consequently",
        "consider", "considering", "contain", "containing", "contains",
        "corresponding", "could", "couldn't", "course", "currently",
        "definitely", "described", "despite", "did", "didn't",
        "different", "do", "does", "doesn't", "doing",
        "don't", "done", "down", "downwards", "during",
        "each", "edu", "eg", "eight", "either",
        "else", "elsewhere", "enough", "entirely", "especially",
        "et", "etc", "even", "ever", "every",
        "everybody", "everyone", "everything", "everywhere", "ex",
        "exactly", "example", "except", "far", "few",
        "fifth", "first", "five", "followed", "following",
        "follows", "for", "former", "formerly", "forth",
        "four", "from", "further", "furthermore", "get",
        "gets", "getting", "given", "gives", "go",
        "goes", "going", "gone", "got", "gotten",
        "greetings", "had", "hadn't", "happens", "hardly",
        "has", "hasn't", "have", "haven't", "having",
        "he", "he's", "hello", "help", "hence",
        "her", "here", "here's", "hereafter", "hereby",
        "herein", "hereupon", "hers", "herself", "hi",
        "him", "himself", "his", "hither", "hopefully",
        "how", "howbeit", "however", "i'd", "i'll",
        "i'm", "i've", "ie", "if", "ignored",
        "immediate", "in", "inasmuch", "inc", "indeed",
        "indicate", "indicated", "indicates", "inner", "insofar",
        "instead", "into", "inward", "is", "isn't",
        "it", "it'd", "it'll", "it's", "its",
        "itself", "just", "keep", "keeps", "kept",
        "know", "knows", "known", "last", "lately",
        "later", "latter", "latterly", "least", "less",
        "lest", "let", "let's", "like", "liked",
        "likely", "little", "look", "looking", "looks",
        "ltd", "mainly", "many", "may", "maybe",
        "me", "mean", "meanwhile", "merely", "might",
        "more", "moreover", "most", "mostly", "much",
        "must", "my", "myself", "name", "namely",
        "nd", "near", "nearly", "necessary", "need",
        "needs", "neither", "never", "nevertheless", "new",
        "next", "nine", "no", "nobody", "non",
        "none", "noone", "nor", "normally", "not",
        "nothing", "novel", "now", "nowhere", "obviously",
        "of", "off", "often", "oh", "ok",
        "okay", "old", "on", "once", "one",
        "ones", "only", "onto", "or", "other",
        "others", "otherwise", "ought", "our", "ours",
        "ourselves", "out", "outside", "over", "overall",
        "own", "particular", "particularly", "per", "perhaps",
        "placed", "please", "plus", "possible", "presumably",
        "probably", "provides", "que", "quite", "qv",
        "rather", "rd", "re", "really", "reasonably",
        "regarding", "regardless", "regards", "relatively", "respectively",
        "right", "said", "same", "saw", "say",
        "saying", "says", "second", "secondly", "see",
        "seeing", "seem", "seemed", "seeming", "seems",
        "seen", "self", "selves", "sensible", "sent",
        "serious", "seriously", "seven", "several", "shall",
        "she", "should", "shouldn't", "since", "six",
        "so", "some", "somebody", "somehow", "someone",
        "something", "sometime", "sometimes", "somewhat", "somewhere",
        "soon", "sorry", "specified", "specify", "specifying",
        "still", "sub", "such", "sup", "sure",
        "t's", "take", "taken", "tell", "tends",
        "th", "than", "thank", "thanks", "thanx",
        "that", "that's", "thats", "the", "their",
        "theirs", "them", "themselves", "then", "thence",
        "there", "there's", "thereafter", "thereby", "therefore",
        "therein", "theres", "thereupon", "these", "they",
        "they'd", "they'll", "they're", "they've", "think",
        "third", "this", "thorough", "thoroughly", "those",
        "though", "three", "through", "throughout", "thru",
        "thus", "to", "together", "too", "took",
        "toward", "towards", "tried", "tries", "truly",
        "try", "trying", "twice", "two", "un",
        "under", "unfortunately", "unless", "unlikely", "until",
        "unto", "up", "upon", "us", "use",
        "used", "useful", "uses", "using", "usually",
        "value", "various", "very", "via", "viz",
        "vs", "want", "wants", "was", "wasn't",
        "way", "we", "we'd", "we'll", "we're",
        "we've", "welcome", "well", "went", "were",
        "weren't", "what", "what's", "whatever", "when",
        "whence", "whenever", "where", "where's", "whereafter",
        "whereas", "whereby", "wherein", "whereupon", "wherever",
        "whether", "which", "while", "whither", "who",
        "who's", "whoever", "whole", "whom", "whose",
        "why", "will", "willing", "wish", "with",
        "within", "without", "won't", "wonder", "would",
        "wouldn't", "yes", "yet", "you", "you'd",
        "you'll", "you're", "you've", "your", "yours",
        "yourself", "yourselves", "zero",
    ];

    # server connection error codes
    const CR_CONNECTION_ERROR = 2002;   # Can't connect to local MySQL server
    #   through socket '%s' (%d)
    const CR_CONN_HOST_ERROR = 2003;    # Can't connect to MySQL server on '%s' (%d)
    const CR_SERVER_GONE_ERROR = 2006;  # MySQL server has gone away
    const CR_SERVER_LOST = 2013;        # Lost connection to MySQL server during query

    # limits on int variables
    # https://dev.mysql.com/doc/refman/5.7/en/integer-types.html
    const TINYINT_MAX_VALUE = 127;
    const SMALLINT_MAX_VALUE = 32767;
    const MEDIUMINT_MAX_VALUE = 8388607;
    const INT_MAX_VALUE = 2147483647;
    const BIGINT_MAX_VALUE = 9223372036854775807;

    const MAX_COLUMN_NAME_LENGTH = 64;

    # connection error codes that may be recoverable
    private static $RecoverableConnectionErrors = array(
        self::CR_CONNECTION_ERROR,
    );

    /**
     * Connect to database server.
     * @param string $DBHostName Name of host database server is on.
     * @param string $DBUserName User name for logging in to server.
     * @param string $DBPassword Password for logging in to server.
     * @return mysqli Handle for database server connection.
     * @throws Exception When unable to connect to database server or select
     *       specified database.
     */
    private static function connectToDatabaseServer(
        string $DBHostName,
        string $DBUserName,
        string $DBPassword
    ) {
        if (!strlen($DBUserName)) {
            throw new InvalidArgumentException("Database server user name not set.");
        }

        $ConnectAttemptsLeft = self::$ConnectRetryAttempts + 1;
        do {
            # if this is not our first connection attempt
            if (isset($Handle)) {
                # wait for the retry interval
                sleep(self::$ConnectRetryInterval);
            }

            # attempt to connect to server
            try {
                $Handle = @mysqli_connect($DBHostName, $DBUserName, $DBPassword);
            } catch (\mysqli_sql_exception $Ex) {
                $Handle = false;
            }
            $ConnectAttemptsLeft--;
            # repeat if we do not have a connection and there are retry attempts
            #       left and the connection error code indicates a retry may succeed
        } while (($Handle === false) && $ConnectAttemptsLeft && in_array(
            mysqli_connect_errno(),
            self::$RecoverableConnectionErrors
        ));

        # throw exception if connection attempts failed
        if ($Handle === false) {
            throw new Exception("Could not connect to database: "
                    .mysqli_connect_error()
                    ." (errno: ".mysqli_connect_errno().")");
        }

        # return new connection to caller
        return $Handle;
    }

    /**
     * Select database.
     * @param string $DBName Name of database to select.
     * @throws Exception When unable to select specified database.
     */
    private function selectDatabase(string $DBName): void
    {
        if (!strlen($DBName)) {
            throw new InvalidArgumentException("Database name not set.");
        }

        $Result = mysqli_select_db($this->Handle, $DBName);
        if ($Result !== true) {
            throw new Exception("Could not select database: "
                    .mysqli_error($this->Handle)
                    ." (errno: " . mysqli_errno($this->Handle) . ")");
        }
    }

    /**
     * Attempt to determine whether a specified SQL statement may modify data.
     * @param string $QueryString SQL query string to examine.
     * @return bool TRUE if statement is unlikely to modify data, otherwise FALSE.
     */
    private function isReadOnlyStatement(string $QueryString): bool
    {
        return preg_match("/^[ ]*(SELECT|DESC|DESCRIBE|SHOW) /i", $QueryString) ? true : false;
    }

    /**
     * Attempt to determine which tables might be modified by an SQL statement.
     * @param string $QueryString SQL query string to examine.
     * @return string|false Table name that will be modified, or FALSE if no
     *      table is modified or it is unclear what table will be modified.
     */
    private function tableModified(string $QueryString)
    {
        # assume we're not going to be able to determine table
        $TableName = false;

        # split query into pieces
        $QueryString = trim($QueryString);
        $Words = preg_split("/\s+/", $QueryString);
        if ($Words === false) {
            return false;
        }

        # if INSERT statement
        $WordIndex = 1;
        if (strtoupper($Words[0]) == "INSERT") {
            # skip over modifying keywords
            while ((strtoupper($Words[$WordIndex]) == "LOW_PRIORITY")
                || (strtoupper($Words[$WordIndex]) == "DELAYED")
                || (strtoupper($Words[$WordIndex]) == "IGNORE")
                || (strtoupper($Words[$WordIndex]) == "INTO")) {
                $WordIndex++;
            }

            # next word is table name
            $TableName = $Words[$WordIndex];
            # else if UPDATE statement
        } elseif (strtoupper($Words[0]) == "UPDATE") {
            # skip over modifying keywords
            while ((strtoupper($Words[$WordIndex]) == "LOW_PRIORITY")
                || (strtoupper($Words[$WordIndex]) == "IGNORE")) {
                $WordIndex++;
            }

            # if word following next word is SET
            if (strtoupper($Words[$WordIndex + 1]) == "SET") {
                # next word is table name
                $TableName = $Words[$WordIndex];
            }
            # else if DELETE statement
        } elseif (strtoupper($Words[0]) == "DELETE") {
            # skip over modifying keywords
            while ((strtoupper($Words[$WordIndex]) == "LOW_PRIORITY")
                || (strtoupper($Words[$WordIndex]) == "IGNORE")
                || (strtoupper($Words[$WordIndex]) == "QUICK")) {
                $WordIndex++;
            }

            # if next term is FROM
            if (strtoupper($Words[$WordIndex]) == "FROM") {
                # next word is table name
                $WordIndex++;
                $TableName = $Words[$WordIndex];
            }
        }

        # discard table name if it looks at all suspicious
        if ($TableName) {
            if (!preg_match("/[a-zA-Z0-9]+/", $TableName)) {
                $TableName = false;
            }
        }

        # return table name (or lack thereof) to caller
        return $TableName;
    }

    /**
     * Attempt to determine which tables might be accessed by an SQL statement.
     * @param string $QueryString SQL query string to examine.
     * @return array|false Array of table name that may be accessed, or FALSE if no
     *       table is accessed or it is unclear what tables may be accessed.
     */
    private function tablesAccessed(string $QueryString)
    {
        # assume we're not going to be able to determine tables
        $TableNames = false;

        # split query into pieces
        $QueryString = trim($QueryString);
        $Words = preg_split("/\s+/", $QueryString);
        $UQueryString = strtoupper($QueryString);
        $UWords = preg_split("/\s+/", $UQueryString);

        # if SELECT statement
        if (is_array($UWords) && is_array($Words) && ($UWords[0] == "SELECT")) {
            # keep going until we hit FROM or last word
            $WordIndex = 1;
            while (($UWords[$WordIndex] != "FROM")
                && strlen($UWords[$WordIndex])) {
                $WordIndex++;
            }

            # if we hit FROM
            if ($UWords[$WordIndex] == "FROM") {
                # for each word after FROM
                $WordIndex++;
                while (strlen($UWords[$WordIndex])) {
                    # if current word ends with comma
                    if (preg_match("/,$/", $Words[$WordIndex])) {
                        # strip off comma and add word to table name list
                        $TableNames[] = substr($Words[$WordIndex], 0, -1);
                    } else {
                        # add word to table name list
                        $TableNames[] = $Words[$WordIndex];

                        # if next word is not comma
                        $WordIndex++;
                        if ($Words[$WordIndex] != ",") {
                            # if word begins with comma
                            if (preg_match("/^,/", $Words[$WordIndex])) {
                                # strip off comma (NOTE: modifies $Words array!)
                                $Words[$WordIndex] = substr($Words[$WordIndex], 1);

                                # decrement index so we start with this word next pass
                                $WordIndex--;
                            } else {
                                # stop scanning words (non-basic JOINs not yet handled)
                                break;
                            }
                        }
                    }

                    # move to next word
                    $WordIndex++;
                }
            }
        }

        # discard table names if they look at all suspicious
        if ($TableNames) {
            foreach ($TableNames as $Name) {
                if (!preg_match("/^[a-zA-Z0-9]+$/", $Name)) {
                    $TableNames = false;
                    break;
                }
            }
        }

        # return table name (or lack thereof) to caller
        return $TableNames;
    }

    /**
     * Run SQL query, ignoring or reporting errors as appropriate.
     * @param string $QueryString SQL query to run.
     * @return mysqli_result|bool SQL query handle or FALSE if query failed
     *       or TRUE if query failed but error was ignored.
     */
    private function runQuery(string $QueryString)
    {
        # run query against database
        $StartTime = microtime(true);
        try {
            $this->QueryHandle = mysqli_query($this->Handle, $QueryString);
        } catch (\mysqli_sql_exception $Ex) {
            $this->QueryHandle = false;
        }
        $QueryDuration = microtime(true) - $StartTime;

        # print query and execution time if debugging output is enabled
        if (self::$QueryDebugOutputFlag) {
            print "DB: " . $QueryString . " ["
                . sprintf("%.2f", $QueryDuration)
                . "s]" . "<br>\n";
        }

        if (!is_null(self::$SlowQueryLoggingFn) && $QueryDuration > self::$LongQueryTime) {
            $Trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

            # go back the right number of frames to find the function that called
            # Database::query()
            $CallingFrame = $Trace[2];

            call_user_func_array(
                self::$SlowQueryLoggingFn,
                [$QueryString, $CallingFrame, $QueryDuration]
            );
        }

        if (self::$RecordQueryTiming) {
            $this->recordTimingStatsForQuery($QueryString, $QueryDuration);
        }

        # if query failed and there are errors that we can ignore
        if (($this->QueryHandle === false) && $this->ErrorsToIgnore) {
            # for each pattern for an error that we can ignore
            foreach ($this->ErrorsToIgnore as $SqlPattern => $ErrMsgPattern) {
                # if error matches pattern
                $ErrorMsg = mysqli_error($this->Handle);
                if (preg_match($SqlPattern, $QueryString)
                    && preg_match($ErrMsgPattern, $ErrorMsg)) {
                    # set return value to indicate error was ignored
                    $this->QueryHandle = true;

                    # set internal flag to indicate that an error was ignored
                    $this->ErrorIgnored = $ErrorMsg;

                    # stop looking at patterns
                    break;
                }
            }
        }

        # if query failed
        if ($this->QueryHandle === false) {
            # clear stored value for number of rows retrieved
            $this->NumRows = 0;

            # retrieve error info
            $this->ErrMsg = mysqli_error($this->Handle);
            $this->ErrNo = mysqli_errno($this->Handle);

            # if we are supposed to be displaying errors
            if (self::$DisplayErrors) {
                # print error info
                $ErrString = "<b>SQL Error:</b> <i>".$this->ErrMsg
                        ."</i> (".$this->ErrNo.")<br/>\n";
                $ErrString .= "<b>SQL Statement:</b> <i>"
                    .htmlspecialchars($QueryString)."</i><br/>\n";

                # retrieve execution trace that got us to this point
                $Trace = debug_backtrace();     // phpcs:ignore

                # remove current context from trace
                array_shift($Trace);

                # make sure file name and line number are available
                foreach ($Trace as $Index => $Loc) {
                    if (!array_key_exists("file", $Loc)) {
                        $Trace[$Index]["file"] = "UNKNOWN";
                    }
                    if (!array_key_exists("line", $Loc)) {
                        $Trace[$Index]["line"] = "??";
                    }
                }

                # determine length of leading path common to all file names in trace
                $LocString = "";
                $OurFile = __FILE__;
                $PrefixLen = 9999;
                foreach ($Trace as $Loc) {
                    if (isset($Loc["file"]) && $Loc["file"] != "UNKNOWN") {
                        $Index = 0;
                        $FNameLength = strlen($Loc["file"]);
                        while ($Index < $FNameLength &&
                            $Loc["file"][$Index] == $OurFile[$Index]) {
                            $Index++;
                        }
                        $PrefixLen = min($PrefixLen, $Index);
                    }
                }

                foreach ($Trace as $Loc) {
                    $Sep = "";
                    $ArgString = "";
                    if (isset($Loc["args"])) {
                        foreach ($Loc["args"] as $Arg) {
                            $ArgString .= $Sep;
                            switch (gettype($Arg)) {
                                case "boolean":
                                    $ArgString .= $Arg ? "TRUE" : "FALSE";
                                    break;

                                case "integer":
                                case "double":
                                    $ArgString .= $Arg;
                                    break;

                                case "string":
                                    $ArgString .= '"<i>' . htmlspecialchars(substr($Arg, 0, 40))
                                        . ((strlen($Arg) > 40) ? "..." : "") . '</i>"';
                                    break;

                                case "array":
                                case "resource":
                                case "NULL":
                                    $ArgString .= strtoupper(gettype($Arg));
                                    break;

                                case "object":
                                    $ArgString .= get_class($Arg);
                                    break;

                                case "unknown type":
                                    $ArgString .= "UNKNOWN";
                                    break;
                            }
                            $Sep = ",";
                        }
                    }
                    $Loc["file"] = isset($Loc["file"]) ?
                        substr($Loc["file"], $PrefixLen) :
                        "UNKNOWN";
                    $LocString .= "&nbsp;&nbsp;";
                    if (array_key_exists("class", $Loc)) {
                        $LocString .= $Loc["class"] . "::";
                    }
                    $LocString .= $Loc["function"] . "(" . $ArgString . ")"
                        . " - " . $Loc["file"] . ":" . ($Loc["line"] ?? "UNKNOWN")
                        . "<br>\n";
                }
                $ErrString .= "<b>Trace:</b><br>\n".$LocString;

                if (php_sapi_name() == "cli") {
                    $ErrString = strip_tags($ErrString);
                    $ErrString = htmlspecialchars_decode($ErrString);
                    $ErrString = str_replace("&nbsp;", " ", $ErrString);
                }

                print $ErrString;
            }
        }
        return $this->QueryHandle;
    }

    /**
     * Determine if the result of the most recent query should be stored in
     *   the query result cache. Assumes that a query has been run such that
     *   mysqli_fetch_fields($this->QueryHandle) can be run and that
     *   $this->NumRows was set to the number of rows the query returned.
     * @return bool TRUE to cache results, FALSE otherwise
     */
    private function shouldCacheResult() : bool
    {
        # cache if smaller than Cache Rows Threshold
        if ($this->NumRows < self::$CacheRowsThreshold) {
            return true;
        }

        # otherwise, add up the max lengths of all the columns in the result
        $MaxRowLength = 0;
        $Fields = mysqli_fetch_fields($this->QueryHandle);
        foreach ($Fields as $Field) {
            $MaxRowLength += $Field->max_length;
        }

        # if every row were the max length and it's still less than the
        # cache size threshold, store the result
        if ($MaxRowLength * $this->NumRows < self::$CacheSizeThreshold) {
            return true;
        }

        # otherwise, do not cache
        return false;
    }

    /**
     * Prune the query results cache.
     */
    private function pruneQueryResultsCache(): void
    {
        # prevent recursion (can come up if CachePruneLoggingFn is set to
        # something that issues DB queries)
        static $CurrentlyPruning = false;
        if ($CurrentlyPruning) {
            return;
        }

        $CurrentlyPruning = true;

        # log that we had to prune the cache
        if (!is_null(self::$CachePruneLoggingFn)) {
            $Trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

            # go back the right number of frames to find the caller of
            # Database::query()
            $CallingFrame = $Trace[2];

            call_user_func_array(
                self::$CachePruneLoggingFn,
                [$CallingFrame, &self::$QueryResultCache]
            );
        }

        # clear out all but last few rows from caches
        self::$QueryResultCache = array_slice(
            self::$QueryResultCache,
            (0 - self::$CacheRowsToLeave)
        );
        self::$VUCache = array_slice(
            self::$VUCache,
            (0 - self::$CacheRowsToLeave)
        );
        gc_collect_cycles();

        # if we are still low on memory, just nuke the caches entirely
        if (StdLib::getFreeMemory() < self::$CacheMemoryThreshold) {
            self::$QueryResultCache = [];
            self::$VUCache = [];
            gc_collect_cycles();
        }

        $CurrentlyPruning = false;
    }

    /**
     * Get/set value for specified column.
     * @param string $ColName Name of database column.
     * @param string|null $NewValue New value to set.  (OPTIONAL)
     * @return string|null Current value or NULL if no value is set.
     */
    private function updateValueForColumn(string $ColName, $NewValue = null)
    {
        # error out if required parameters have not been set
        if (!isset($this->VUTableName)) {
            throw new Exception("Value update parameters have not been set.");
        }
        $TableName = $this->VUTableName;
        $Condition = $this->VUCondition;
        $CacheKey = $TableName.$Condition;

        # if value to set was supplied
        if (func_num_args() > 1) {
            # update value in database
            $Value = ($NewValue === null)
                    ? "NULL"
                    : "'".$this->escapeString($NewValue)."'";
            $Query = "UPDATE `".$TableName
                    ."` SET `".$ColName."` = ".$Value." ".$Condition;
            $this->query($Query);

            # reload cache from database
            $Query = "SELECT * FROM `" . $TableName . "` ".$Condition;
            $this->query($Query);
            $Row = $this->fetchRow();

            # check to make sure reload succeeded
            if ($Row === false) {
                throw new Exception("Could not reload row from ".$TableName
                    .(strlen($Condition) ? " (condition: '".$Condition."')" : "")
                    .".");
            }

            # save reloaded row to cache
            self::$VUCache[$CacheKey] = $Row;
        } else {
            # if cache not loaded
            if (!isset(self::$VUCache[$CacheKey])) {
                # read row from database into cache
                $Query = "SELECT * FROM `" . $TableName . "` ".$Condition;
                $this->query($Query);
                $Row = $this->fetchRow();

                # error out if no row was found
                if ($Row === false) {
                    throw new Exception("No row found in ".$TableName
                        .(strlen($Condition) ? " where '".$Condition."'" : "")
                        .".");
                }

                # store row to cache
                self::$VUCache[$CacheKey] = $Row;
            } else {
                # read row from cache
                $Row = self::$VUCache[$CacheKey];
            }
        }

        # error out if specified column does not exist in row loaded from database
        if (!array_key_exists($ColName, $Row)) {
            throw new InvalidArgumentException("Column '".$ColName
                    ."' not found in table '".$TableName."'.");
        }

        # return value from cached row to caller
        return $Row[$ColName];
    }

    /**
     * Record query timing statistics.
     * @param string $QueryString Query that was run.
     * @param float $Elapsed Time the query took.
     */
    private function recordTimingStatsForQuery(
        string $QueryString,
        float $Elapsed
    ): void {
        # don't bother recording anything if query was fast
        if ($Elapsed < self::$QueryTimingMinimum) {
            return;
        }

        # otherwise ensure we have a row in our table for this query
        if (!isset(self::$QueryTimingStats[$QueryString])) {
            self::$QueryTimingStats[$QueryString]["Count"] = 0;
            self::$QueryTimingStats[$QueryString]["TotalTime"] = 0;
            self::$QueryTimingStats[$QueryString]["LongestTime"] = 0;
            self::$QueryTimingStats[$QueryString]["Locations"] = [];
        }

        # add in data from this invocation
        self::$QueryTimingStats[$QueryString]["Count"]++;
        self::$QueryTimingStats[$QueryString]["TotalTime"] += $Elapsed;
        self::$QueryTimingStats[$QueryString]["LongestTime"] = max(
            self::$QueryTimingStats[$QueryString]["LongestTime"],
            $Elapsed
        );

        # get a backtrace for the query location
        $Trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        while (count($Trace) && isset($Trace[0]["class"]) &&
               $Trace[0]["class"] == 'ScoutLib\Database') {
            array_shift($Trace);
        }

        # convert it to a call location
        $CallStack = [];
        foreach ($Trace as $Call) {
            if (isset($Call["class"]) && isset($Call["function"])) { // @phpstan-ignore-line
                $CallStack[] = $Call["class"]."::".$Call["function"]."()";
            } elseif (isset($Call["file"]) && isset($Call["line"])) {
                $CallStack[] = $Call["file"].":".$Call["line"];
            }
        }
        $Location = implode(" -> ", array_reverse($CallStack));

        # increment our count of calls from this location
        if (!isset(self::$QueryTimingStats[$QueryString]["Locations"][$Location])) {
            self::$QueryTimingStats[$QueryString]["Locations"][$Location] = 0;
        }
        self::$QueryTimingStats[$QueryString]["Locations"][$Location]++;
    }

    /**
     * Get database server system variable.
     * @param string $VarName Server system variable name.
     * @return string Current value for variable.
     */
    private static function getServerVariable(string $VarName): string
    {
        if (!isset(self::$ServerVariableCache[$VarName])) {
            static $DB;
            if (!isset($DB)) {
                $DB = new self();
            }
            $Query = "SHOW VARIABLES LIKE '".addslashes($VarName)."'";
            self::$ServerVariableCache[$VarName] = $DB->queryValue($Query, "Value");
        }
        return self::$ServerVariableCache[$VarName];
    }

    /**
     * Get/set database server system variable.  When setting a new value,
     * the type of the incoming value is significant (e.g. if setting an
     * integer value, make sure you're passing in an integer, not a string).
     * @param string $VarName System variable name.
     * @param mixed|null $NewValue New value for system variable.  (OPTIONAL,
     *       or use NULL to not set a new value)
     */
    private static function setServerVariable(string $VarName, $NewValue): void
    {
        static $DB;
        if (!isset($DB)) {
            $DB = new self();
        }
        if (is_string($NewValue)) {
            $NewValue = "'".addslashes($NewValue)."'";
        }
        self::$ServerVariableCache[$VarName] = $NewValue;
        $DB->query("SET ".$VarName." = ".$NewValue);
    }
}
