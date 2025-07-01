<?PHP
#
#   FILE:  RunBackgroundTasks.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();
$AF->doNotCacheCurrentPage();

$TaskCount = $AF->getTaskQueueSize();
$AF->logMessage(
    ApplicationFramework::LOGLVL_DEBUG,
    "Explicit background task execution page load - ".$TaskCount
    ." tasks currently in queue."
);
