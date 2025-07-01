<?PHP
#
#   FILE:  DeleteRegistration.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_Id - Registration ID to delete (guaranteed to be a valid ID).
#
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   $H_Error - Error messages if there was a problem

namespace Metavus;

use Metavus\Plugins\EduLink;
use Metavus\Plugins\EduLink\LMSRegistration;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

User::requirePrivilege(PRIV_SYSADMIN);

$H_Id = $_GET["ID"] ?? null;
if (is_null($H_Id)) {
    $H_Error = "Id parameter must be provided.";
    return;
}

$Plugin = EduLink::getInstance();

if (!LMSRegistration::itemExists($H_Id)) {
    $H_Error = "Provided Registration Id is invalid.";
    return;
}

$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Delete":
        $Registration = new LMSRegistration($H_Id);
        $Registration->destroy();
        /* fall through */

    case "Cancel":
        ApplicationFramework::getInstance()
            ->setJumpToPage(
                "P_EduLink_ListRegistrations"
            );
        break;

    default:
        break;
}
