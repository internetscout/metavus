<?PHP
#
#   FILE:  RunBackgroundTasks.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();
$AF->DoNotCacheCurrentPage();

$TaskCount = $AF->GetTaskQueueSize();
$AF->LogMessage(
    ApplicationFramework::LOGLVL_DEBUG,
    "Explicit background task execution page load - ".$TaskCount
    ." tasks currently in queue."
);
