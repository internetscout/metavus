<?PHP
#
#   FILE:  TaskQueue.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# the base URL for this page used when refreshing
$H_PageUrl = ApplicationFramework::baseUrl() . "index.php?P=TaskQueue";

# need sysadmin privileges for this page
if (!User::requirePrivilege(PRIV_SYSADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

# if task execution enable/disable requested
if (isset($_GET["CE"])) {
    # save new task execution state
    $AF->taskExecutionEnabled(
        StdLib::getFormValue("F_TaskExecutionEnabled", true),
        true
    );
}

# refresh the page every 30 seconds if appropriate. this must come after the
# code that sets whether or not task execution is enabled
if ($AF->taskExecutionEnabled()) {
    header("Refresh: 30; url=".$H_PageUrl);
}

# if action requested
if (isset($_GET["AC"]) && isset($_GET["ID"])) {
    $Action = $_GET["AC"];
    $TaskId = $_GET["ID"];

    # re-queue orphaned task if requested
    if ($Action == "REQUEUE") {
        $AF->requeueOrphanedTask($TaskId);
    # re-queue all orphaned tasks if requested
    } elseif ($Action == "REQUEUEALL") {
        $OrphanedTasks = $AF->getOrphanedTaskList();
        foreach ($OrphanedTasks as $Id => $Task) {
            $NewPriority = max(1, ($Task["Priority"] - 1));
            $AF->requeueOrphanedTask((int) $Id, (int) $NewPriority);
        }
    # remove orphaned task if requested
    } elseif ($Action == "DELETE") {
        $AF->deleteTask($TaskId);
    # run task in foreground if requested
    } elseif ($Action == "RUN") {
        # if this is a periodic event
        if (!is_numeric($TaskId)) {
            # attempt to retrieve task
            $PTasks = $AF->getKnownPeriodicEvents();

            # if specified task found
            if (isset($PTasks[$TaskId])) {
                # load task parameters
                $Task = $PTasks[$TaskId];
                $Task["Parameters"]["LastRunAt"] = date(
                    StdLib::SQL_DATE_FORMAT,
                    $Task["LastRun"]
                );

                # run task
                ApplicationFramework::runPeriodicEvent(
                    $Task["Period"],
                    $Task["Callback"],
                    $Task["Parameters"]
                );
            }
        } else {
            # attempt to retrieve task
            $Task = $AF->getTask((int) $TaskId);

            # if specified task found
            if ($Task) {
                # if callback appears callable
                if (is_callable($Task["Callback"])) {
                    # run task
                    if ($Task["Parameters"]) {
                        call_user_func_array($Task["Callback"], $Task["Parameters"]);
                    } else {
                        call_user_func($Task["Callback"]);
                    }

                    # remove task from queue
                    $AF->deleteTask((int) $TaskId);
                }
            }
        }
    }
}
