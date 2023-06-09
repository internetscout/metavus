<?PHP
#
#   FILE:  CleanSpam.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\RecordFactory;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

if (!CheckAuthorization(PRIV_COLLECTIONADMIN, PRIV_USERADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

$PosterId = StdLib::getFormValue("PI");
$H_ResourceId = StdLib::getFormValue("RI");

if ($PosterId === null) {
    $H_ErrorMessages[] = "No poster ID specified.";
} elseif (!(new UserFactory())->userExists($PosterId)) {
    $H_ErrorMessages[] = "Invalid poster ID specified.";
}

if ($H_ResourceId === null) {
    $H_ErrorMessages[] = "No resource ID specified.";
} elseif (!RecordFactory::recordExistsInAnySchema($H_ResourceId)) {
    $H_ErrorMessages[] = "Invalid resource ID specified.";
}

if (!isset($H_ErrorMessages)) {
    $H_TgtUser = new User($PosterId);
}
