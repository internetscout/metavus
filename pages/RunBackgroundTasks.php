<?PHP
#
#   FILE:  RunBackgroundTasks.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use ScoutLib\ApplicationFramework;

$GLOBALS["AF"]->DoNotCacheCurrentPage();

$TaskCount = $GLOBALS["AF"]->GetTaskQueueSize();
$GLOBALS["AF"]->LogMessage(
    ApplicationFramework::LOGLVL_DEBUG,
    "Explicit background task execution page load - ".$TaskCount
    ." tasks currently in queue."
);