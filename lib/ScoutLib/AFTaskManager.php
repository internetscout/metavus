<?PHP
#
#   FILE:  AFTaskManager.php
#
#   Part of the ScoutLib application support library
#   Copyright 2009-2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

use Exception;

/**
 * Task manager component of top-level framework for web applications.
 * \nosubgrouping
 */
class AFTaskManager
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** @cond */
    /**
     * Object constructor.
     * @param ApplicationFramework $AF App framework that owns us.
     */
    public function __construct(ApplicationFramework &$AF)
    {
        # set up our internal environment
        $this->AF = $AF;
        $this->DB = new Database();

        # load our settings from database
        $this->loadSettings();
    }
    /** @endcond */

    /**  Highest priority. */
    const PRIORITY_HIGH = 1;
    /**  Medium (default) priority. */
    const PRIORITY_MEDIUM = 2;
    /**  Lower priority. */
    const PRIORITY_LOW = 3;
    /**  Lowest priority. */
    const PRIORITY_BACKGROUND = 4;

    /**
     * Add task to queue.  If $Callback refers to a function (rather than an
     * object method) that function must be available in a global scope on all
     * pages.
     * If $Priority is out-of-bounds, it wil be normalized to be within bounds.
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL, pass NULL for no parameters)
     * @param int $Priority Priority to assign to task.  (OPTIONAL, defaults
     *       to PRIORITY_LOW)
     * @param string $Description Text description of task.  (OPTIONAL)
     */
    public function queueTask(
        $Callback,
        array $Parameters = null,
        int $Priority = self::PRIORITY_LOW,
        string $Description = ""
    ) {
        # make sure priority is within bounds
        $Priority = min(
            self::PRIORITY_BACKGROUND,
            max(self::PRIORITY_HIGH, $Priority)
        );

        # pack task info and write to database
        if ($Parameters === null) {
            $Parameters = array();
        }
        $this->DB->query("INSERT INTO TaskQueue"
            . " (Callback, Parameters, Priority, Description)"
            . " VALUES ('" . addslashes(serialize($Callback)) . "', '"
            . addslashes(serialize($Parameters)) . "', " . intval($Priority) . ", '"
            . addslashes($Description) . "')");
    }

    /**
     * Add task to queue if not already in queue or currently running.
     * If task is already in queue with a lower priority than specified, the task's
     * priority will be increased to the new value.
     * If $Callback refers to a function (rather than an object method) that function
     * must be available in a global scope on all pages.
     * If $Priority is out-of-bounds, it wil be normalized to be within bounds.
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL, pass NULL for no parameters)
     * @param int $Priority Priority to assign to task.  (OPTIONAL, defaults
     *       to PRIORITY_LOW)
     * @param string $Description Text description of task.  (OPTIONAL)
     * @return bool TRUE if task was added, otherwise FALSE.
     * @see AFTaskManager::taskIsInQueue()
     */
    public function queueUniqueTask(
        $Callback,
        array $Parameters = null,
        int $Priority = self::PRIORITY_LOW,
        string $Description = ""
    ): bool {
        if ($this->taskIsInQueue($Callback, $Parameters)) {
            $QueryResult = $this->DB->query("SELECT TaskId,Priority FROM TaskQueue"
                . " WHERE Callback = '" . addslashes(serialize($Callback)) . "'"
                . ($Parameters ? " AND Parameters = '"
                    . addslashes(serialize($Parameters)) . "'" : ""));
            if ($QueryResult !== false) {
                # make sure priority is within bounds
                $Priority = min(
                    self::PRIORITY_BACKGROUND,
                    max(self::PRIORITY_HIGH, $Priority)
                );

                $Record = $this->DB->fetchRow();
                if ($Record && ($Record["Priority"] > $Priority)) {
                    $this->DB->query("UPDATE TaskQueue"
                        . " SET Priority = " . intval($Priority)
                        . " WHERE TaskId = " . intval($Record["TaskId"]));
                }
            }
            return false;
        } else {
            $this->queueTask($Callback, $Parameters, $Priority, $Description);
            return true;
        }
    }

    /**
     * Check if task is already in queue or currently running.
     * When no $Parameters value is specified the task is checked against
     * any other entries with the same $Callback.
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL)
     * @return bool TRUE if task is already in queue, otherwise FALSE.
     */
    public function taskIsInQueue($Callback, array $Parameters = null): bool
    {
        $QueuedCount = $this->DB->query(
            "SELECT COUNT(*) AS FoundCount FROM TaskQueue"
            . " WHERE Callback = '" . addslashes(serialize($Callback)) . "'"
            . ($Parameters ? " AND Parameters = '"
                . addslashes(serialize($Parameters)) . "'" : ""),
            "FoundCount"
        );
        $RunningCount = $this->DB->query(
            "SELECT COUNT(*) AS FoundCount FROM RunningTasks"
            . " WHERE Callback = '" . addslashes(serialize($Callback)) . "'"
            . ($Parameters ? " AND Parameters = '"
                . addslashes(serialize($Parameters)) . "'" : ""),
            "FoundCount"
        );
        $FoundCount = $QueuedCount + $RunningCount;
        return ($FoundCount ? true : false);
    }

    /**
     * Retrieve current number of tasks in queue.
     * @param int $Priority Priority of tasks.  (OPTIONAL, defaults to all priorities)
     * @return int Number of tasks currently in queue.
     */
    public function getTaskQueueSize(int $Priority = null): int
    {
        return $this->getQueuedTaskCount(null, null, $Priority);
    }

    /**
     * Retrieve list of tasks currently in queue.
     * @param int $Count Number to retrieve.  (OPTIONAL, defaults to 100)
     * @param int $Offset Offset into queue to start retrieval.  (OPTIONAL)
     * @return array Array with task IDs for index and task info for values.  Task info
     *       is stored as associative array with "Callback" and "Parameter" indices.
     */
    public function getQueuedTaskList(int $Count = 100, int $Offset = 0): array
    {
        return $this->getTaskList("SELECT * FROM TaskQueue"
            . " ORDER BY Priority, TaskId ", $Count, $Offset);
    }

    /**
     * Get number of queued tasks that match supplied values.  Tasks will
     * not be counted if the values do not match exactly, so callbacks with
     * methods for different objects (even of the same class) will not match.
     * @param callable $Callback Function or method to call to perform task.
     *       (OPTIONAL)
     * @param array $Parameters Array containing parameters to pass to function
     *       or method.  Pass in empty array to match tasks with no parameters.
     *       (OPTIONAL)
     * @param int $Priority Priority to assign to task.  (OPTIONAL)
     * @param string $Description Text description of task.  (OPTIONAL)
     * @return int Number of tasks queued that match supplied parameters.
     */
    public function getQueuedTaskCount(
        $Callback = null,
        array $Parameters = null,
        int $Priority = null,
        string $Description = null
    ): int {
        $Query = "SELECT COUNT(*) AS TaskCount FROM TaskQueue";
        $Sep = " WHERE";
        if ($Callback !== null) {
            $Query .= $Sep . " Callback = '" . addslashes(serialize($Callback)) . "'";
            $Sep = " AND";
        }
        if ($Parameters !== null) {
            $Query .= $Sep . " Parameters = '" . addslashes(serialize($Parameters)) . "'";
            $Sep = " AND";
        }
        if ($Priority !== null) {
            $Query .= $Sep . " Priority = " . intval($Priority);
            $Sep = " AND";
        }
        if ($Description !== null) {
            $Query .= $Sep . " Description = '" . addslashes($Description) . "'";
        }
        return $this->DB->query($Query, "TaskCount");
    }

    /**
     * Retrieve list of tasks currently running.
     * @param int $Count Number to retrieve.  (OPTIONAL, defaults to 100)
     * @param int $Offset Offset into queue to start retrieval.  (OPTIONAL)
     * @return array Array with task IDs for index and task info for values.  Task info
     *       is stored as associative array with "Callback" and "Parameter" indices.
     */
    public function getRunningTaskList(int $Count = 100, int $Offset = 0): array
    {
        return $this->getTaskList("SELECT * FROM RunningTasks"
            . " WHERE StartedAt >= '" . date(
                "Y-m-d H:i:s",
                (time() - $this->AF->MaxExecutionTime())
            ) . "'"
            . " ORDER BY StartedAt", $Count, $Offset);
    }

    /**
     * Retrieve count of tasks currently running.
     * @return int Number of running tasks.
     */
    public function getRunningTaskCount(): int
    {
        return $this->DB->query(
            "SELECT COUNT(*) AS Count FROM RunningTasks"
            . " WHERE StartedAt >= '" . date(
                "Y-m-d H:i:s",
                (time() - $this->AF->MaxExecutionTime())
            ) . "'",
            "Count"
        );
    }

    /**
     * Retrieve list of tasks currently orphaned.
     * @param int $Count Number to retrieve.  (OPTIONAL, defaults to 100)
     * @param int $Offset Offset into queue to start retrieval.  (OPTIONAL)
     * @return array Array with task IDs for index and task info for values.  Task info
     *       is stored as associative array with "Callback" and "Parameter" indices.
     */
    public function getOrphanedTaskList(int $Count = 100, int $Offset = 0): array
    {
        return $this->getTaskList("SELECT * FROM RunningTasks"
            . " WHERE StartedAt < '" . date(
                "Y-m-d H:i:s",
                (time() - $this->AF->MaxExecutionTime())
            ) . "'"
            . " ORDER BY StartedAt", $Count, $Offset);
    }

    /**
     * Retrieve current number of orphaned tasks.
     * @return int Number of orphaned tasks.
     */
    public function getOrphanedTaskCount(): int
    {
        return $this->DB->query(
            "SELECT COUNT(*) AS Count FROM RunningTasks"
            . " WHERE StartedAt < '" . date(
                "Y-m-d H:i:s",
                (time() - $this->AF->MaxExecutionTime())
            ) . "'",
            "Count"
        );
    }

    /**
     * Move orphaned task back into queue.
     * @param int $TaskId Task ID.
     * @param int $NewPriority New priority for task being requeued.  (OPTIONAL)
     */
    public function requeueOrphanedTask(int $TaskId, int $NewPriority = null)
    {
        $this->DB->query("LOCK TABLES TaskQueue WRITE, RunningTasks WRITE");
        $this->DB->query("INSERT INTO TaskQueue"
            . " (Callback,Parameters,Priority,Description) "
            . "SELECT Callback, Parameters, Priority, Description"
            . " FROM RunningTasks WHERE TaskId = " . intval($TaskId));
        if ($NewPriority !== null) {
            $NewTaskId = $this->DB->getLastInsertId();
            $this->DB->query("UPDATE TaskQueue SET Priority = "
                . intval($NewPriority)
                . " WHERE TaskId = " . intval($NewTaskId));
        }
        $this->DB->query("DELETE FROM RunningTasks WHERE TaskId = " . intval($TaskId));
        $this->DB->query("UNLOCK TABLES");
    }

    /**
     * Set whether to requeue the currently-running background task when
     * it completes.
     * @param bool $NewValue If TRUE, current task will be requeued.  (OPTIONAL,
     *       defaults to TRUE)
     */
    public function requeueCurrentTask(bool $NewValue = true)
    {
        $this->RequeueCurrentTask = $NewValue;
    }

    /**
     * Remove task from task queues.
     * @param int $TaskId Task ID.
     * @return int Number of tasks removed.
     */
    public function deleteTask(int $TaskId): int
    {
        $this->DB->query("DELETE FROM TaskQueue WHERE TaskId = " . intval($TaskId));
        $TasksRemoved = $this->DB->numRowsAffected();
        $this->DB->query("DELETE FROM RunningTasks WHERE TaskId = " . intval($TaskId));
        $TasksRemoved += $this->DB->numRowsAffected();
        return $TasksRemoved;
    }

    /**
     * Retrieve task info from queue (either running or queued tasks).
     * @param int $TaskId Task ID.
     * @return array|null Array with task info for values or NULL if task
     *      is not found.  Task info is stored as associative array with
     *      "Callback" and "Parameters" indices.
     */
    public function getTask(int $TaskId)
    {
        # assume task will not be found
        $Task = null;

        # look for task in task queue
        $this->DB->query("SELECT * FROM TaskQueue WHERE TaskId = " . intval($TaskId));

        # if task was not found in queue
        if (!$this->DB->numRowsSelected()) {
            # look for task in running task list
            $this->DB->query("SELECT * FROM RunningTasks WHERE TaskId = "
                . intval($TaskId));
        }

        # if task was found
        if ($this->DB->numRowsSelected()) {
            # if task was periodic
            $Row = $this->DB->fetchRow();
            if ($Row["Callback"] ==
                serialize(array("ApplicationFramework", "RunPeriodicEvent"))) {
                # unpack periodic task callback
                $WrappedCallback = unserialize($Row["Parameters"]);
                $Task["Callback"] = $WrappedCallback[1];
                $Task["Parameters"] = $WrappedCallback[2];
            } else {
                # unpack task callback and parameters
                $Task["Callback"] = unserialize($Row["Callback"]);
                $Task["Parameters"] = unserialize($Row["Parameters"]);
            }
        }

        # return task to caller
        return $Task;
    }

    /**
     * Get/set whether automatic task execution is enabled.  (This does not
     * prevent tasks from being manually executed.)
     * @param bool $NewValue TRUE to enable or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool Returns TRUE if automatic task execution is enabled or
     *       otherwise FALSE.
     */
    public function taskExecutionEnabled(
        bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->AF->updateBoolSetting(
            ucfirst(__FUNCTION__),
            $NewValue,
            $Persistent
        );
    }

    /**
     * Get/set maximum number of tasks to have running simultaneously.
     * @param int $NewValue New setting for max number of tasks.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return int Current maximum number of tasks to run at once.
     */
    public function maxTasks(int $NewValue = null, bool $Persistent = false): int
    {
        return $this->AF->updateIntSetting(
            "MaxTasksRunning",
            $NewValue,
            $Persistent
        );
    }

    /**
     * Get printable synopsis for task callback.  Any string values in the
     * callback parameter list will be escaped with htmlspecialchars().
     * @param array $TaskInfo Array of task info as returned by getTask().
     * @return string Task callback synopsis string.
     * @see AFTaskManager::getTask()
     */
    public static function getTaskCallbackSynopsis(array $TaskInfo): string
    {
        # if task callback is function use function name
        $Callback = $TaskInfo["Callback"];
        $Name = "";
        if (!is_array($Callback)) {
            $Name = $Callback;
        } else {
            # if task callback is object
            if (is_object($Callback[0])) {
                # if task callback is encapsulated ask encapsulation for name
                if (method_exists($Callback[0], "GetCallbackAsText")) {
                    $Name = $Callback[0]->GetCallbackAsText();
                    # else assemble name from object
                } else {
                    $Name = get_class($Callback[0]) . "::" . $Callback[1];
                }
                # else assemble name from supplied info
            } else {
                $Name = $Callback[0] . "::" . $Callback[1];
            }
        }

        # if parameter array was supplied
        $Parameters = $TaskInfo["Parameters"];
        $ParameterString = "";
        if (is_array($Parameters)) {
            # assemble parameter string
            $Separator = "";
            foreach ($Parameters as $Parameter) {
                $ParameterString .= $Separator;
                if (is_int($Parameter) || is_float($Parameter)) {
                    $ParameterString .= $Parameter;
                } elseif (is_string($Parameter)) {
                    $ParameterString .= "\"" . htmlspecialchars($Parameter) . "\"";
                } elseif (is_array($Parameter)) {
                    $ParameterString .= "ARRAY";
                } elseif (is_object($Parameter)) {
                    $ParameterString .= "OBJECT";
                } elseif (is_null($Parameter)) {
                    $ParameterString .= "NULL";
                } elseif (is_bool($Parameter)) {
                    $ParameterString .= $Parameter ? "TRUE" : "FALSE";
                } elseif (is_resource($Parameter)) {
                    $ParameterString .= get_resource_type($Parameter);
                } else {
                    $ParameterString .= "????";
                }
                $Separator = ", ";
            }
        }

        # assemble name and parameters and return result to caller
        return $Name . "(" . $ParameterString . ")";
    }

    /**
     * Determine current priority if running in background.
     * @return int|null Current background priority (PRIORITY_ value), or NULL
     *       if not currently running in background.
     */
    public function getCurrentBackgroundPriority()
    {
        return isset($this->RunningTask)
            ? $this->RunningTask["Priority"] : null;
    }

    /**
     * Get next higher possible background task priority.  If already at the
     * highest priority, the same value is returned.
     * @param int|null $Priority Background priority (PRIORITY_ value).  (OPTIONAL,
     *       defaults to current priority if running in background, or NULL if
     *       running in foreground)
     * @return integer|null Next higher background priority, or NULL if no priority
     *       specified and currently running in foreground.
     */
    public function getNextHigherBackgroundPriority(int $Priority = null)
    {
        if ($Priority === null) {
            $Priority = $this->getCurrentBackgroundPriority();
            if ($Priority === null) {
                return null;
            }
        }
        return ($Priority > self::PRIORITY_HIGH)
            ? ($Priority - 1) : self::PRIORITY_HIGH;
    }

    /**
     * Get next lower possible background task priority.  If already at the
     * lowest priority, the same value is returned.
     * @param int|null $Priority Background priority (PRIORITY_ value).  (OPTIONAL,
     *       defaults to current priority if running in background, or NULL if
     *       running in foreground)
     * @return integer|null Next lower background priority, or NULL if no priority
     *       specified and currently running in foreground.
     */
    public function getNextLowerBackgroundPriority(int $Priority = null)
    {
        if ($Priority === null) {
            $Priority = $this->getCurrentBackgroundPriority();
            if ($Priority === null) {
                return null;
            }
        }
        return ($Priority < self::PRIORITY_BACKGROUND)
            ? ($Priority + 1) : self::PRIORITY_BACKGROUND;
    }

    /**
     * Run any queued background tasks until either remaining PHP execution
     * time or available memory run too low.
     */
    public function runQueuedTasks()
    {
        # if there are tasks in the queue
        if ($this->getTaskQueueSize()) {
            # tell PHP to garbage collect to give as much memory as possible for tasks
            gc_collect_cycles();

            # turn on output buffering to (hopefully) record any crash output
            ob_start();

            # lock tables to prevent anyone else from running a task
            $LockingQuery = "LOCK TABLES TaskQueue WRITE, RunningTasks WRITE,"
                    ." ApplicationFrameworkSettings READ";
            $this->DB->query($LockingQuery);

            # while there is time and memory left
            #       and a task to run
            #       and an open slot to run it in
            $MinimumTimeToRunAnotherTask = 65;
            while (($this->AF->GetSecondsBeforeTimeout()
                    > $MinimumTimeToRunAnotherTask)
                && (ApplicationFramework::getPercentFreeMemory()
                    > $this->BackgroundTaskMinFreeMemPercent)
                && ($this->getTaskQueueSize() != 0) // @phpstan-ignore-line
                && ($this->getRunningTaskCount() < $this->maxTasks())) {
                # look for task at head of queue
                $this->DB->query("SELECT * FROM TaskQueue"
                    . " ORDER BY Priority, TaskId LIMIT 1");
                $Task = $this->DB->fetchRow();

                # move task from queued list to running tasks list
                $this->DB->query("INSERT INTO RunningTasks "
                    . "(TaskId,Callback,Parameters,Priority,Description) "
                    . "SELECT * FROM TaskQueue WHERE TaskId = "
                    . intval($Task["TaskId"]));
                $this->DB->query("DELETE FROM TaskQueue WHERE TaskId = "
                    . intval($Task["TaskId"]));

                # release table locks to again allow other sessions to run tasks
                $this->DB->query("UNLOCK TABLES");

                # update the "last run" time
                $this->DB->query("UPDATE ApplicationFrameworkSettings"
                    . " SET LastTaskRunAt = '" . date("Y-m-d H:i:s") . "'");

                # run task
                $this->runTask($Task);

                # lock tables to prevent anyone else from running a task
                $this->DB->query($LockingQuery);
            }

            $this->resetTaskIdGeneratorIfNecessary();

            # make sure tables are released
            $this->DB->query("UNLOCK TABLES");
        }
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $AF;
    private $BackgroundTaskMemLeakLogThreshold = 10;    # percentage of max mem
    private $BackgroundTaskMinFreeMemPercent = 25;
    private $DB;
    private $MaxRunningTasksToTrack = 250;
    private $RequeueCurrentTask;
    private $RunningTask;
    private $Settings;

    /**
     * Load our settings from database, initializing them if needed.
     * @throws Exception If unable to load settings.
     */
    private function loadSettings()
    {
        # read settings in from database
        $this->DB->query("SELECT * FROM ApplicationFrameworkSettings");
        $this->Settings = $this->DB->fetchRow();

        # if settings were not previously initialized
        if ($this->Settings === false) {
            # initialize settings in database
            $this->DB->query("INSERT INTO ApplicationFrameworkSettings"
                . " (LastTaskRunAt) VALUES ('2000-01-02 03:04:05')");

            # read new settings in from database
            $this->DB->query("SELECT * FROM ApplicationFrameworkSettings");
            $this->Settings = $this->DB->fetchRow();

            # bail out if reloading new settings failed
            if ($this->Settings === false) {
                throw new Exception(
                    "Unable to load application framework settings."
                );
            }
        }
    }

    /**
     * Retrieve list of tasks with specified query.
     * @param string $DBQuery Database query.
     * @param int $Count Number to retrieve.
     * @param int $Offset Offset into queue to start retrieval.
     * @return array Array with task IDs for index and task info for values.
     *       Task info is stored as associative array with "Callback" and
     *       "Parameter" indices.
     */
    private function getTaskList(string $DBQuery, int $Count, int $Offset): array
    {
        $this->DB->query($DBQuery . " LIMIT " . intval($Offset) . "," . intval($Count));
        $Tasks = array();
        while ($Row = $this->DB->fetchRow()) {
            $Tasks[$Row["TaskId"]] = $Row;
            if ($Row["Callback"] ==
                serialize(array("ApplicationFramework", "RunPeriodicEvent"))) {
                $WrappedCallback = unserialize($Row["Parameters"]);
                $Tasks[$Row["TaskId"]]["Callback"] = $WrappedCallback[1];
                $Tasks[$Row["TaskId"]]["Parameters"] = null;
            } else {
                $Tasks[$Row["TaskId"]]["Callback"] = unserialize($Row["Callback"]);
                $Tasks[$Row["TaskId"]]["Parameters"] = unserialize($Row["Parameters"]);
            }
        }
        return $Tasks;
    }

    /**
     * Run given task.
     * @param array $Task Array of task info.
     */
    private function runTask(array $Task)
    {
        # unpack stored task info
        $TaskId = $Task["TaskId"];
        $Callback = unserialize($Task["Callback"]);
        $Parameters = unserialize($Task["Parameters"]);

        # if task does not look runable
        if (!is_callable($Callback)) {
            # log error message and leave task orphaned
            $TaskSynopsis = self::getTaskCallbackSynopsis($Task);
            $this->AF->LogError(
                ApplicationFramework::LOGLVL_ERROR,
                "Task " . $TaskSynopsis . " had invalid callback."
            );
            return;
        }

        # clear task requeue flag
        $this->RequeueCurrentTask = false;

        # save amount of free memory for later comparison
        $BeforeFreeMem = ApplicationFramework::getFreeMemory();

        # run task
        $this->RunningTask = $Task;
        if ($Parameters) {
            call_user_func_array($Callback, $Parameters);
        } else {
            call_user_func($Callback);
        }
        unset($this->RunningTask);

        # log if task leaked significant memory
        $this->logTaskMemoryLeakIfAny($TaskId, $BeforeFreeMem);

        # if task requeue requested (may be set by task that was run)
        if ($this->RequeueCurrentTask) {        /* @phpstan-ignore-line */
            # if task was deleted, log warning
            if (is_null($this->getTask($TaskId))) {
                $this->AF->logError(
                    ApplicationFramework::LOGLVL_WARNING,
                    "Failed to requeue task with ID: ".(string)$TaskId
                );
            } else {
                # move task from running tasks list to queue
                $this->requeueRunningTask($TaskId);
            }
        } elseif (!is_null($this->getTask($TaskId))) {
            # remove task from running tasks list
            $this->DB->query("DELETE FROM RunningTasks"
                . " WHERE TaskId = " . intval($TaskId));
        }

        # prune running tasks list if necessary
        $RunningTasksCount = $this->DB->query(
            "SELECT COUNT(*) AS TaskCount FROM RunningTasks",
            "TaskCount"
        );
        if ($RunningTasksCount > $this->MaxRunningTasksToTrack) {
            $this->DB->query("DELETE FROM RunningTasks ORDER BY StartedAt"
                . " LIMIT " . ($RunningTasksCount - $this->MaxRunningTasksToTrack));
        }
    }

    /**
     * Requeue running task, moving it from the running tasks list (in the DB)
     * to the queued tasks list.
     * @param int $TaskId ID of running task.
     */
    private function requeueRunningTask(int $TaskId)
    {
        $this->DB->query("LOCK TABLES TaskQueue WRITE, RunningTasks WRITE");
        $this->DB->query("INSERT INTO TaskQueue"
            . " (Callback,Parameters,Priority,Description)"
            . " SELECT Callback,Parameters,Priority,Description"
            . " FROM RunningTasks WHERE TaskId = " . intval($TaskId));
        $this->DB->query("DELETE FROM RunningTasks WHERE TaskId = " . intval($TaskId));
        $this->DB->query("UNLOCK TABLES");
    }

    /**
     * If TaskIds are nearing their max value, TRUNCATE the TaskQueue
     * table to reset them. Necessary because MySQL will refuse to
     * INSERT new rows after an AUTO_INCREMENT id hits its max value.
     */
    private function resetTaskIdGeneratorIfNecessary()
    {
        $this->DB->query("LOCK TABLES TaskQueue WRITE");
        if (($this->AF->GetSecondsBeforeTimeout() > 30)
            && ($this->getTaskQueueSize() == 0)
            && ($this->DB->getNextInsertId("TaskQueue")
                > (Database::INT_MAX_VALUE * 0.90))) {
            $this->DB->query("TRUNCATE TABLE TaskQueue");
        }
        $this->DB->query("UNLOCK TABLES");
    }

    /**
     * Log memory leak (if any) when task was executed.
     * @param int $TaskId ID of task.
     * @param int $StartingFreeMemory Amount of free memory in bytes before
     *       task was run.
     */
    private function logTaskMemoryLeakIfAny(int $TaskId, int $StartingFreeMemory)
    {
        # tell PHP to garbage collect to free up any memory no longer used
        gc_collect_cycles();

        # calculate the logging threshold
        $LeakThreshold = StdLib::getPhpMemoryLimit()
            * ($this->BackgroundTaskMemLeakLogThreshold / 100);

        # calculate the amount of memory used by task
        $EndingFreeMemory = ApplicationFramework::getFreeMemory();
        $MemoryUsed = $StartingFreeMemory - $EndingFreeMemory;

        # if amount of memory used is over threshold
        if ($MemoryUsed > $LeakThreshold) {
            # log memory leak
            $Task = $this->getTask($TaskId);
            $TaskSynopsis = is_null($Task)
                    ? "Deleted Task with ID ".$TaskId
                    : self::getTaskCallbackSynopsis($Task);
            $this->AF->LogError(
                ApplicationFramework::LOGLVL_DEBUG,
                "Task " . $TaskSynopsis . " leaked "
                . number_format($MemoryUsed) . " bytes."
            );
        }
    }
}
