<?PHP
#
#   FILE:  AFTaskManagerTrait.php
#
#   Part of the ScoutLib application support library
#   Copyright 2009-2026 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use Exception;

/**
 * Task manager components of top-level framework for web applications.
 */
trait AFTaskManagerTrait
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**  Names of available task priorities. */
    public static $AvailablePriorities = [
        self::PRIORITY_BACKGROUND => "Background",
        self::PRIORITY_LOW => "Low",
        self::PRIORITY_MEDIUM => "Medium",
        self::PRIORITY_HIGH => "High",
    ];

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
        ?array $Parameters = null,
        int $Priority = self::PRIORITY_LOW,
        string $Description = ""
    ): void {
        $this->queueTaskWithRunAfter(
            $Callback,
            $Parameters,
            $Priority,
            $Description,
            null
        );
    }

    /**
     * Add task to queue to run at a specific time.  If $Callback refers to a
     * function (rather than an object method) that function must be available
     * in a global scope on all pages.
     * If $Priority is out-of-bounds, it wil be normalized to be within bounds.
     * @param callable $Callback Function or method to call to perform task.
     * @param int $RunAt Absolute UNIX timestamp for the earliest execution time.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL, pass NULL for no parameters)
     * @param int $Priority Priority to assign to task.  (OPTIONAL, defaults
     *       to PRIORITY_LOW)
     * @param string $Description Text description of task.  (OPTIONAL)
     */
    public function queueTaskToRunAt(
        $Callback,
        int $RunAt,
        ?array $Parameters = null,
        int $Priority = self::PRIORITY_LOW,
        string $Description = ""
    ): void {
        $this->queueTaskWithRunAfter(
            $Callback,
            $Parameters,
            $Priority,
            $Description,
            $this->getSqlDateTimeFromTimestamp($RunAt)
        );
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
     * @see AFTaskManagerTrait::taskIsInQueue()
     */
    public function queueUniqueTask(
        $Callback,
        ?array $Parameters = null,
        int $Priority = self::PRIORITY_LOW,
        string $Description = ""
    ): bool {
        return $this->queueUniqueTaskWithRunAfter(
            $Callback,
            $Parameters,
            $Priority,
            $Description,
            null
        );
    }

    /**
     * Add task to queue to run at a specific time if not already in queue or
     * currently running.
     * If task is already in queue with a lower priority than specified, the task's
     * priority will be increased to the new value.
     * If task is already in queue with a later run time than specified, the
     * task's run time will be changed to the earlier time.
     * If $Callback refers to a function (rather than an object method) that function
     * must be available in a global scope on all pages.
     * If $Priority is out-of-bounds, it wil be normalized to be within bounds.
     * @param callable $Callback Function or method to call to perform task.
     * @param int $RunAt Absolute UNIX timestamp for the earliest execution time.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL, pass NULL for no parameters)
     * @param int $Priority Priority to assign to task.  (OPTIONAL, defaults
     *       to PRIORITY_LOW)
     * @param string $Description Text description of task.  (OPTIONAL)
     * @return bool TRUE if task was added, otherwise FALSE.
     * @see AFTaskManagerTrait::taskIsInQueue()
     */
    public function queueUniqueTaskToRunAt(
        $Callback,
        int $RunAt,
        ?array $Parameters = null,
        int $Priority = self::PRIORITY_LOW,
        string $Description = ""
    ): bool {
        return $this->queueUniqueTaskWithRunAfter(
            $Callback,
            $Parameters,
            $Priority,
            $Description,
            $this->getSqlDateTimeFromTimestamp($RunAt)
        );
    }

    /**
     * Check if task is already in queue or currently running (not orphaned).
     * When no $Parameters value is specified the task is checked against
     * any other entries with the same $Callback.
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL)
     * @return bool TRUE if task is already in queue, otherwise FALSE.
     */
    public function taskIsInQueue($Callback, ?array $Parameters = null): bool
    {
        $TaskIds = $this->getTaskIds($Callback, $Parameters);
        return (count($TaskIds) > 0);
    }

    /**
     * Get ID of task (running or queued) that has the specified callback and
     * parameters (if supplied).  If there are multiple tasks with the specified
     * callback and parameters, the task with the lowest ID will be returned.
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL)
     * @return int|false Task ID or FALSE if no matching task found.
     */
    public function getTaskId(callable $Callback, ?array $Parameters = null)
    {
        $TaskIds = $this->getTaskIds($Callback, $Parameters);
        return count($TaskIds) !== 0 ? reset($TaskIds) : false;
    }

    /**
     * Get ID of any tasks (running or queued) that have the specified
     * callback and parameters (if supplied).
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL)
     * @return array Task IDs.
     */
    public function getTaskIds(callable $Callback, ?array $Parameters = null): array
    {
        $QueuedTaskIds = $this->getQueuedTaskIds($Callback, $Parameters);
        $RunningTaskIds = $this->getRunningTaskIds($Callback, $Parameters);
        $TaskIds = array_merge($QueuedTaskIds, $RunningTaskIds);
        sort($TaskIds);
        return $TaskIds;
    }

    /**
     * Get ID of queued task that has the specified callback and parameters
     * (if supplied).  If there are multiple tasks with the specified callback
     * and parameters, the task with the lowest ID will be returned.
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL)
     * @return int|false Task ID or FALSE if no matching task found.
     */
    public function getQueuedTaskId(callable $Callback, ?array $Parameters = null)
    {
        $TaskIds = $this->getQueuedTaskIds($Callback, $Parameters);
        return count($TaskIds) !== 0 ? reset($TaskIds) : false;
    }

    /**
     * Get ID of any queued tasks that have the specified callback and
     * parameters (if supplied).
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL)
     * @return array Task IDs.
     */
    public function getQueuedTaskIds(callable $Callback, ?array $Parameters = null): array
    {
        $Query = "SELECT TaskId FROM TaskQueue WHERE Callback = '"
                .$this->DB->escapeString(serialize($Callback)) . "'";
        if ($Parameters !== null && $Parameters !== []) {
            $Query .= " AND Parameters = '"
                    .$this->DB->escapeString(serialize($Parameters))."'";
        }
        $Query .= " ORDER BY TaskId";
        $this->DB->query($Query);
        return $this->DB->fetchColumn("TaskId");
    }

    /**
     * Get ID of running task that has the specified callback and
     * parameters (if supplied).  If there are multiple tasks with the specified
     * callback and parameters, the task with the lowest ID will be returned.
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL)
     * @return int|false Task ID or FALSE if no matching task found.
     */
    public function getRunningTaskId(callable $Callback, ?array $Parameters = null)
    {
        $TaskIds = $this->getRunningTaskIds($Callback, $Parameters);
        return count($TaskIds) !== 0 ? reset($TaskIds) : false;
    }

    /**
     * Get ID of any tasks (running or queued) that have the specified
     * callback and parameters (if supplied).
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL)
     * @return array Task IDs.
     */
    public function getRunningTaskIds(callable $Callback, ?array $Parameters = null): array
    {
        $DB = $this->DB;
        $RunningCutoffTime = $this->getRunningCutoffTime();

        # retrieve matching tasks that are still recent enough to count as running
        $Query = "SELECT TaskId FROM RunningTasks WHERE Callback = '"
                .$DB->escapeString(serialize($Callback))."'"
                . " AND StartedAt >= '".$RunningCutoffTime."'";
        if ($Parameters !== null && $Parameters !== []) {
            $Query .= " AND Parameters = '"
                    .$DB->escapeString(serialize($Parameters))."'";
        }
        $Query .= " ORDER BY TaskId";
        $DB->query($Query);
        return $DB->fetchColumn("TaskId");
    }

    /**
     * Retrieve current number of tasks in queue.
     * @param int $Priority Priority of tasks.  (OPTIONAL, defaults to all priorities)
     * @return int Number of tasks currently in queue.
     */
    public function getTaskQueueSize(?int $Priority = null): int
    {
        return $this->getQueuedTaskCount(null, null, $Priority);
    }

    /**
     * Retrieve list of tasks currently in queue.
     * @param int $Count Number to retrieve.  (OPTIONAL, defaults to all)
     * @param int $Offset Offset into queue to start retrieval.  (OPTIONAL)
     * @return array Array with task IDs for index and task info for values.
     *      Task info is stored as associative array with "Callback",
     *      "Parameters", "Priority", "Description", and "RunAfter" indices.
     */
    public function getQueuedTaskList(int $Count = -1, int $Offset = 0): array
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
        ?array $Parameters = null,
        ?int $Priority = null,
        ?string $Description = null
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
     * @param int $Count Number to retrieve.  (OPTIONAL, defaults to all)
     * @param int $Offset Offset into queue to start retrieval.  (OPTIONAL)
     * @return array Array with task IDs for index and task info for values.
     *      Task info is stored as associative array with "Callback",
     *      "Parameters", "Priority", "Description", "RunAfter", "StartedAt",
     *      and "CrashInfo" values.
     */
    public function getRunningTaskList(int $Count = -1, int $Offset = 0): array
    {
        $RunningCutoffTime = $this->getRunningCutoffTime();

        # retrieve tasks whose start time still places them in the running set
        return $this->getTaskList("SELECT * FROM RunningTasks"
            . " WHERE StartedAt >= '" . $RunningCutoffTime . "'"
            . " ORDER BY StartedAt", $Count, $Offset);
    }

    /**
     * Retrieve count of tasks currently running.
     * @return int Number of running tasks.
     */
    public function getRunningTaskCount(): int
    {
        $RunningCutoffTime = $this->getRunningCutoffTime();

        # count tasks whose start time still places them in the running set
        return $this->DB->query(
            "SELECT COUNT(*) AS Count FROM RunningTasks"
                    . " WHERE StartedAt >= '" . $RunningCutoffTime . "'",
            "Count"
        );
    }

    /**
     * Retrieve list of tasks currently orphaned.
     * @param int $Count Number to retrieve.  (OPTIONAL, defaults to all)
     * @param int $Offset Offset into queue to start retrieval.  (OPTIONAL)
     * @return array Array with task IDs for index and task info for values.
     *      Task info is stored as associative array with "Callback",
     *      "Parameters", "Priority", "Description", "RunAfter", "StartedAt",
     *      and "CrashInfo" values.
     */
    public function getOrphanedTaskList(int $Count = -1, int $Offset = 0): array
    {
        $RunningCutoffTime = $this->getRunningCutoffTime();

        # retrieve tasks old enough to have transitioned into the orphaned set
        return $this->getTaskList("SELECT * FROM RunningTasks"
            . " WHERE StartedAt < '" . $RunningCutoffTime . "'"
            . " ORDER BY StartedAt", $Count, $Offset);
    }

    /**
     * Retrieve current number of orphaned tasks.
     * @return int Number of orphaned tasks.
     */
    public function getOrphanedTaskCount(): int
    {
        $RunningCutoffTime = $this->getRunningCutoffTime();

        # count tasks old enough to have transitioned into the orphaned set
        return $this->DB->query(
            "SELECT COUNT(*) AS Count FROM RunningTasks"
                    . " WHERE StartedAt < '" . $RunningCutoffTime . "'",
            "Count"
        );
    }

    /**
     * Move orphaned task back into queue.
     * @param int $TaskId Task ID.
     * @param int $NewPriority New priority for task being requeued.  (OPTIONAL)
     */
    public function requeueOrphanedTask(int $TaskId, ?int $NewPriority = null): void
    {
        $this->beginAtomicTaskOperation();

        # copy the orphaned task back into the queue while preserving RunAfter
        $this->DB->query("INSERT INTO TaskQueue"
            . " (Callback,Parameters,Priority,Description,RunAfter) "
            . "SELECT Callback, Parameters, Priority, Description, RunAfter"
            . " FROM RunningTasks WHERE TaskId = " . intval($TaskId));
        if ($NewPriority !== null) {
            $NewTaskId = $this->DB->getLastInsertId();

            # update the requeued task priority without changing RunAfter
            $this->DB->query("UPDATE TaskQueue SET Priority = "
                . intval($NewPriority)
                . " WHERE TaskId = " . intval($NewTaskId));
        }

        # remove the orphaned task after its queued copy has been created
        $this->DB->query("DELETE FROM RunningTasks WHERE TaskId = " . intval($TaskId));
        $this->endAtomicTaskOperation();
    }

    /**
     * Set whether to requeue the currently-running background task when
     * it completes.
     * @param bool $NewValue If TRUE, current task will be requeued.  (OPTIONAL,
     *       defaults to TRUE)
     */
    public function requeueCurrentTask(bool $NewValue = true): void
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
     *      "Callback","Parameters", "Priority", "Description", and
     *      "RunAfter" indices.  Running or orphaned tasks will also have
     *      "StartedAt" and "CrashInfo" values.
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
            $Task = self::unpackTaskData($this->DB->fetchRow());
        }

        # return task to caller
        return $Task;
    }

    /**
     * Signal the beginning of a series of task operations that must be
     * done atomically (i.e. without any other task operations happening
     * in between).
     */
    public function beginAtomicTaskOperation(): void
    {
        $this->DB->query("LOCK TABLES TaskQueue WRITE, RunningTasks WRITE");
        $this->AtomicTaskOperationStartTime = microtime(true);
    }

    /**
     * Signal the end of a series of task operations that must be
     * done atomically (i.e. without any other task operations happening
     * in between).
     */
    public function endAtomicTaskOperation(): void
    {
        $Duration = microtime(true) - $this->AtomicTaskOperationStartTime;
        $this->DB->query("UNLOCK TABLES");

        # (the cutoff for logging slow atomic tasks operations (SATOs) is
        #       the lesser of the SATO threshold and the long DB lock hold
        #       threshold, because making a task run atomically requires a
        #       DB lock to be held)
        $SATOCutoff = min(
            $this->SlowAtomicTaskOperationThreshold,
            $this->longDBLockThreshold()
        );
        if ($Duration > $SATOCutoff) {
            $this->logMessage(
                ApplicationFramework::LOGLVL_INFO,
                "Slow atomic task operation ("
                .round($Duration, 2)
                ." s) from ".StdLib::getMyCaller()
            );
        }
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
        ?bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->updateBoolSetting(
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
    public function maxTasks(?int $NewValue = null, bool $Persistent = false): int
    {
        return $this->updateIntSetting(
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
     * @see AFTaskManagerTrait::getTask()
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
    public function getNextHigherBackgroundPriority(?int $Priority = null)
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
    public function getNextLowerBackgroundPriority(?int $Priority = null)
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
    public function runQueuedTasks(): void
    {
        # if we have no runnable tasks, are already running the max number of
        # tasks, or don't have enough free workers, then bail
        if (!$this->hasRunnableQueuedTask()
                || $this->getRunningTaskCount() >= $this->maxTasks()
                || !$this->enoughWorkersAreFree()) {
            return;
        }

        # run any callbacks that have been registered
        foreach ($this->PreTaskExecutionCallbacks as $Callback) {
            ($Callback)();
        }

        # tell PHP to garbage collect to give as much memory as possible for tasks
        gc_collect_cycles();

        # turn on output buffering to (hopefully) record any crash output
        ob_start();

        # while there are enough time, memory, and workers available
        $MinPercentFreeMemory = $this->BackgroundTaskMinFreeMemPercent;
        while (($this->getSecondsBeforeTimeout() > self::$MinTimeToRunAnotherTask)
               && (StdLib::getPercentFreeMemory() > $MinPercentFreeMemory)
               && $this->enoughWorkersAreFree()) {
            # claim task to run (also checks if execution slot is available)
            $Task = $this->claimNextQueuedTask();

            # stop running tasks if no task or no execution slot available
            if ($Task === null) {
                break;
            }

            # run task
            $this->runTask($Task);

            # clear output buffer since task has (presumably) run successfully
            ob_clean();
        }

        # prune orphans from running task list if more than configured cap
        $this->pruneRunningTasksListIfNecessary();

        # reset task queue IDs if ID value is near max and no tasks in queue
        $this->resetTaskIdGeneratorIfNecessary();

        # turn off output buffering
        ob_end_flush();
    }

    /**
     * Register function to be called before background task execution begins.
     * @param callable $Callback Function to be called.
     */
    public function registerPreTaskExecutionCallback(callable $Callback): void
    {
        $this->PreTaskExecutionCallbacks[] = $Callback;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /* (convert this to a const as soon as minimum PHP version is 8.2) */
    private static $MinTimeToRunAnotherTask = 65;    # (time in seconds)

    private $AtomicTaskOperationStartTime = null;
    private $BackgroundTaskMemLeakLogThreshold = 10;    # percentage of max mem
    private $BackgroundTaskMinFreeMemPercent = 25;
    private $DB;
    private $MaxRunningTasksToTrack = 250;
    private $PreTaskExecutionCallbacks = [];
    private $RequeueCurrentTask;
    private $RunningTask;
    private $SlowAtomicTaskOperationThreshold = 0.5; # seconds

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
     * @param string $Query Database query.
     * @param int $Count Number to retrieve, or -1 to retrieve all.
     * @param int $Offset Offset into queue to start retrieval.
     * @return array Array with task IDs for index and task info for values.
     *      Task info is stored as associative array with "Callback",
     *      "Parameters", "Priority", "Description", and "RunAfter" indices.
     *      Running or orphaned tasks will also have "StartedAt" and
     *      "CrashInfo" values.
     */
    private function getTaskList(string $Query, int $Count, int $Offset): array
    {
        if (($Count != -1) || ($Offset != 0)) {
            # (18446744073709551615 is MySQL's max value for BIGINT)
            $Query .= " LIMIT ".$Offset.","
                    .(($Count == -1) ? "18446744073709551615" : $Count);
        }
        $this->DB->query($Query);
        $Tasks = [];
        while ($Row = $this->DB->fetchRow()) {
            $Tasks[$Row["TaskId"]] = self::unpackTaskData($Row);
        }
        return $Tasks;
    }

    /**
     * Determine whether there is at least one queued task ready to run.
     * @return bool TRUE if a queued task is runnable, otherwise FALSE.
     */
    private function hasRunnableQueuedTask(): bool
    {
        # look for any task whose scheduled run time has arrived
        $this->DB->query(
            "SELECT TaskId FROM TaskQueue"
            . " WHERE (RunAfter IS NULL) OR (RunAfter <= NOW())"
            . " LIMIT 1"
        );
        return ($this->DB->numRowsSelected() !== 0);
    }

    /**
     * Unpack task data retrieved from database.
     * @param array $Row Row of task data retrieved by database query.
     * @return array Unpacked task info, with indexes matching columns
     *      in the appropriate database table.
     */
    private static function unpackTaskData(array $Row): array
    {
        $TaskInfo = $Row;

        # if task was periodic
        if ($Row["Callback"] ==
                serialize(["ApplicationFramework", "RunPeriodicEvent"])) {
            # unpack periodic task callback
            $WrappedCallback = unserialize($Row["Parameters"]);
            $TaskInfo["Callback"] = $WrappedCallback[1];
            $TaskInfo["Parameters"] = null;
        } else {
            # unpack regular task callback and parameters
            $TaskInfo["Callback"] = unserialize($Row["Callback"]);
            $TaskInfo["Parameters"] = unserialize($Row["Parameters"]);
        }

        return $TaskInfo;
    }

    /**
     * Run given task.
     * @param array $Task Array of task info.
     */
    private function runTask(array $Task): void
    {
        # unpack stored task info
        $TaskId = $Task["TaskId"];
        $Callback = unserialize($Task["Callback"]);
        $Parameters = unserialize($Task["Parameters"]);

        # if task does not look runable
        if (!is_callable($Callback)) {
            # log error message and leave task orphaned
            $TaskSynopsis = self::getTaskCallbackSynopsis($Task);
            $this->logError(
                ApplicationFramework::LOGLVL_ERROR,
                "Task " . $TaskSynopsis . " had invalid callback."
            );
            return;
        }

        # clear task requeue flag
        $this->RequeueCurrentTask = false;

        # save amount of free memory for later comparison
        $BeforeFreeMem = StdLib::getFreeMemory();

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
            if ($this->getTask($TaskId) === null) {
                $this->logError(
                    ApplicationFramework::LOGLVL_WARNING,
                    "Failed to requeue task with ID: ".$TaskId
                );
            } else {
                # move task from running tasks list to queue
                $this->requeueRunningTask($TaskId);
            }
        } elseif ($this->getTask($TaskId) !== null) {
            # remove task from running tasks list
            # (this clears a finished task that is not being requeued)
            $this->DB->query("DELETE FROM RunningTasks"
                . " WHERE TaskId = " . intval($TaskId));
        }
    }

    /**
     * Requeue running task, moving it from the running tasks list (in the DB)
     * to the queued tasks list while preserving its original RunAfter time.
     * @param int $TaskId ID of running task.
     */
    private function requeueRunningTask(int $TaskId): void
    {
        $this->beginAtomicTaskOperation();

        # copy the running task back into the queue for another pass
        $this->DB->query("INSERT INTO TaskQueue"
            . " (Callback,Parameters,Priority,Description,RunAfter)"
            . " SELECT Callback,Parameters,Priority,Description,RunAfter"
            . " FROM RunningTasks WHERE TaskId = " . intval($TaskId));

        # remove the original running-task record after it has been requeued
        $this->DB->query("DELETE FROM RunningTasks WHERE TaskId = " . intval($TaskId));
        $this->endAtomicTaskOperation();
    }

    /**
     * Claim the next queued task.  This method also checks whether any free
     * task execution slots are available, because that needs to be done while
     * task-related tables are locked for the process of claiming a task.
     * @return ?array Queued task data, or NULL if no task could be claimed
     *      or no task execution slots were available.
     */
    private function claimNextQueuedTask(): ?array
    {
        # lock task tables so claiming the next task is atomic
        $this->DB->query("LOCK TABLES TaskQueue WRITE, RunningTasks WRITE");

        # stop if all background task slots are already in use
        if ($this->getRunningTaskCount() >= $this->maxTasks()) {
            $this->DB->query("UNLOCK TABLES");
            return null;
        }

        # fetch the highest-priority task whose RunAfter time has arrived
        $this->DB->query(
            "SELECT * FROM TaskQueue"
            . " WHERE (RunAfter IS NULL) OR (RunAfter <= NOW())"
            . " ORDER BY Priority, TaskId LIMIT 1"
        );
        $Task = $this->DB->fetchRow();
        if ($Task === false) {
            $this->DB->query("UNLOCK TABLES");
            return null;
        }

        # copy the claimed task into the running-task list
        $this->DB->query(
            "INSERT INTO RunningTasks "
                . "(TaskId,Callback,Parameters,Priority,Description,RunAfter) "
                . "SELECT TaskId,Callback,Parameters,Priority,Description,"
                . " RunAfter FROM TaskQueue WHERE TaskId = "
                . intval($Task["TaskId"])
        );

        # remove the claimed task from the queue so no other worker can take it
        $this->DB->query(
            "DELETE FROM TaskQueue WHERE TaskId = ".intval($Task["TaskId"])
        );

        # unlock tables before running task so that others can claim tasks
        $this->DB->query("UNLOCK TABLES");

        return $Task;
    }

    /**
     * If task IDs are nearing their max value and there are no tasks in
     * the queue, TRUNCATE the task queue table to reset ID numbers.
     * Necessary because MySQL will refuse to INSERT new rows after an
     * AUTO_INCREMENT ID hits its max value.
     */
    private function resetTaskIdGeneratorIfNecessary(): void
    {
        $this->DB->query("LOCK TABLES TaskQueue WRITE");

        # if enough free time and no tasks queued and next task ID is near max value
        if (($this->getSecondsBeforeTimeout() > 30)
                && ($this->getTaskQueueSize() == 0)
                && ($this->DB->getNextInsertId("TaskQueue")
                    > (Database::INT_MAX_VALUE * 0.90))) {
            # truncate task queue table to reset task ID generation
            $this->DB->query("TRUNCATE TABLE TaskQueue");
        }

        $this->DB->query("UNLOCK TABLES");
    }

    /**
     * Calculate the cutoff time between tasks considered running and orphaned.
     * @return string Cutoff time formatted for direct use in SQL DATETIME queries.
     */
    private function getRunningCutoffTime(): string
    {
        return date(StdLib::SQL_DATE_FORMAT, (time() - $this->maxExecutionTime()));
    }

    /**
     * Trim orphans from running task list if the list has grown beyond the
     * configured cap.
     */
    private function pruneRunningTasksListIfNecessary(): void
    {
        $RunningTasksCount = $this->DB->query(
            "SELECT COUNT(*) AS TaskCount FROM RunningTasks",
            "TaskCount"
        );

        # if there are more entries in running task list than our threshold
        if ($RunningTasksCount > $this->MaxRunningTasksToTrack) {
            # discard the oldest orphaned (non-running) tasks
            $RunningCutoffTime = $this->getRunningCutoffTime();
            $NumberOfTasksToPrune = $RunningTasksCount - $this->MaxRunningTasksToTrack;
            $this->DB->query("DELETE FROM RunningTasks"
                    ." WHERE StartedAt < '".$RunningCutoffTime."'"
                    ." ORDER BY StartedAt"
                    ." LIMIT ".$NumberOfTasksToPrune);
        }
    }

    /**
     * Log memory leak (if any) when task was executed.
     * @param int $TaskId ID of task.
     * @param int $StartingFreeMemory Amount of free memory in bytes before
     *       task was run.
     */
    private function logTaskMemoryLeakIfAny(int $TaskId, int $StartingFreeMemory): void
    {
        # tell PHP to garbage collect to free up any memory no longer used
        gc_collect_cycles();

        # calculate the logging threshold
        $LeakThreshold = StdLib::getPhpMemoryLimit()
            * ($this->BackgroundTaskMemLeakLogThreshold / 100);

        # calculate the amount of memory used by task
        $EndingFreeMemory = StdLib::getFreeMemory();
        $MemoryUsed = $StartingFreeMemory - $EndingFreeMemory;

        # if amount of memory used is over threshold
        if ($MemoryUsed > $LeakThreshold) {
            # log memory leak
            $Task = $this->getTask($TaskId);
            $TaskSynopsis = ($Task === null)
                    ? "Deleted Task with ID ".$TaskId
                    : self::getTaskCallbackSynopsis($Task);
            $this->logError(
                ApplicationFramework::LOGLVL_DEBUG,
                "Task " . $TaskSynopsis . " leaked "
                . number_format($MemoryUsed) . " bytes."
            );
        }
    }

    /**
     * Determine if there are enough free workers to run background
     * tasks. Under PHP-FPM, tasks will run if at least maxTasks() workers are
     * free. Under other environments, tasks always run.
     * @return bool TRUE if tasks should be run.
     */
    private function enoughWorkersAreFree(): bool
    {
        # if we're running under php-fpm
        if (PHP_SAPI == "fpm-fcgi" && function_exists("fpm_get_status")) {
            # attempt to get the pool status
            $FpmStatus = fpm_get_status();

            # if status could not be retrieved, assume enough workers are free
            if ($FpmStatus === false) {
                return true;
            }

            # if we've never reached the max number of children, assume we're
            # okay to run tasks
            if ($FpmStatus["max-children-reached"] == 0) {
                return true;
            }

            # estimate how many workers we have to work with
            # (max-active is peak concurrently active since pool was started)
            # (total is current count including both active and idle)
            $AvailableWorkers = max(
                $FpmStatus["max-active-processes"],
                $FpmStatus["total-processes"]
            );

            # if at least maxTasks() workers are free, then we're okay to run tasks
            $FreeWorkers = $AvailableWorkers - $FpmStatus["active-processes"];
            if ($FreeWorkers >= $this->maxTasks()) {
                return true;
            }

            # otherwise, not
            return false;
        }

        # under other SAPIs, assume that enough workers are free
        return true;
    }

    /**
     * Add task to queue if not already in queue or currently running.
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL, pass NULL for no parameters)
     * @param int $Priority Priority to assign to task.
     * @param string $Description Text description of task.
     * @param string|null $RunAfterSql SQL DATETIME string for the earliest
     *       execution time, or NULL for immediate execution.
     * @return bool TRUE if task was added, otherwise FALSE.
     */
    private function queueUniqueTaskWithRunAfter(
        $Callback,
        ?array $Parameters,
        int $Priority,
        string $Description,
        ?string $RunAfterSql
    ): bool {
        # examine and possibly update the existing task while locked
        $this->beginAtomicTaskOperation();
        $TaskId = $this->getTaskId($Callback, $Parameters);
        if ($TaskId !== false) {
            $TaskInfo = $this->getTask($TaskId);
            if (($TaskInfo !== null) && !isset($TaskInfo["StartedAt"])) {
                $this->updateQueuedTaskTimingAndPriority(
                    $TaskId,
                    $TaskInfo,
                    $Priority,
                    $RunAfterSql
                );
            }
            $Result = false;
        } else {
            # create a new queued task when no matching task already exists
            $this->queueTaskWithRunAfter(
                $Callback,
                $Parameters,
                $Priority,
                $Description,
                $RunAfterSql
            );
            $Result = true;
        }

        # release the lock after deciding whether to update or insert
        $this->endAtomicTaskOperation();
        return $Result;
    }

    /**
     * Add task to queue, optionally with a RunAfter time.
     * @param callable $Callback Function or method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL, pass NULL for no parameters)
     * @param int $Priority Priority to assign to task.
     * @param string $Description Text description of task.
     * @param string|null $RunAfterSql SQL DATETIME string for the earliest
     *       execution time, or NULL for immediate execution.
     */
    private function queueTaskWithRunAfter(
        $Callback,
        ?array $Parameters,
        int $Priority,
        string $Description,
        ?string $RunAfterSql
    ): void {
        # normalize values before storing the queued task
        $Priority = $this->normalizeTaskPriority($Priority);
        if ($Parameters === null) {
            $Parameters = [];
        }

        # store the queued task with its optional RunAfter time
        $this->DB->query("INSERT INTO TaskQueue"
            . " (Callback, Parameters, Priority, Description, RunAfter)"
            . " VALUES ('"
            . $this->DB->escapeString(serialize($Callback))
            . "', '"
            . $this->DB->escapeString(serialize($Parameters))
            . "', "
            . intval($Priority)
            . ", '"
            . $this->DB->escapeString($Description)
            . "', "
            . $this->getSqlValueForDateTime($RunAfterSql)
            . ")");
    }

    /**
     * Update an existing queued task when a new unique queue request is earlier
     * or higher-priority than the existing request.
     * @param int $TaskId ID of queued task to update.
     * @param array $TaskInfo Current queued task info.
     * @param int $Priority Priority requested for the new queue request.
     * @param string|null $RunAfterSql SQL DATETIME string for the earliest
     *       execution time, or NULL for immediate execution.
     */
    private function updateQueuedTaskTimingAndPriority(
        int $TaskId,
        array $TaskInfo,
        int $Priority,
        ?string $RunAfterSql
    ): void {
        $Updates = [];
        $Priority = $this->normalizeTaskPriority($Priority);

        # raise the task priority if the new request is more urgent
        if ($TaskInfo["Priority"] > $Priority) {
            $Updates[] = "Priority = " . intval($Priority);
        }

        # move the task earlier when the new request should run sooner
        if ($this->shouldReplaceRunAfter($TaskInfo["RunAfter"] ?? null, $RunAfterSql)) {
            $Updates[] = "RunAfter = " . $this->getSqlValueForDateTime($RunAfterSql);
        }

        # write any requested updates back to the queued task row
        if (count($Updates) !== 0) {
            $this->DB->query("UPDATE TaskQueue SET "
                . implode(", ", $Updates)
                . " WHERE TaskId = " . intval($TaskId));
        }
    }

    /**
     * Determine whether a queued task should have its RunAfter value replaced.
     * @param string|null $CurrentRunAfter Current SQL DATETIME value, or NULL for
     *       immediate execution.
     * @param string|null $NewRunAfter New SQL DATETIME value, or NULL for
     *       immediate execution.
     * @return bool TRUE if the queued task should be updated.
     */
    private function shouldReplaceRunAfter(
        ?string $CurrentRunAfter,
        ?string $NewRunAfter
    ): bool {
        # immediate execution is earlier than any scheduled time
        if ($NewRunAfter === null) {
            return ($CurrentRunAfter !== null);
        }

        # an already-immediate task should not be delayed by a later request
        if ($CurrentRunAfter === null) {
            return false;
        }

        # earlier scheduled times should replace later scheduled times
        return (strtotime($NewRunAfter) < strtotime($CurrentRunAfter));
    }

    /**
     * Normalize a task priority so it falls within the supported range.
     * @param int $Priority Requested task priority.
     * @return int Normalized task priority.
     */
    private function normalizeTaskPriority(int $Priority): int
    {
        return min(
            self::PRIORITY_BACKGROUND,
            max(self::PRIORITY_HIGH, $Priority)
        );
    }

    /**
     * Convert a UNIX timestamp to an SQL DATETIME string.
     * @param int $Timestamp Absolute UNIX timestamp.
     * @return string SQL DATETIME string.
     */
    private function getSqlDateTimeFromTimestamp(int $Timestamp): string
    {
        return date(StdLib::SQL_DATE_FORMAT, $Timestamp);
    }

    /**
     * Format an SQL DATETIME value for use in a query.
     * @param string|null $Value SQL DATETIME value or NULL.
     * @return string SQL value suitable for direct inclusion in a query.
     */
    private function getSqlValueForDateTime(?string $Value): string
    {
        if ($Value === null) {
            return "NULL";
        }
        return "'" . $this->DB->escapeString($Value) . "'";
    }
}
