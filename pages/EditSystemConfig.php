<?PHP
#
#   FILE:  EditSystemConfig.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_Form - Configuration form, with fields and values set.
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   (none)
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$SysCfg = SystemConfiguration::getInstance();

CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

# set up form
$FormParams = $SysCfg->getFormParameters();
$H_Form = new FormUI($FormParams);

# act on any button push
switch ($H_Form->getSubmitButtonValue()) {
    case "Save":
        # check values and bail out if any are invalid
        if ($H_Form->validateFieldInput()) {
            return;
        }

        # retrieve submitted values from form
        $NewValues = $H_Form->getNewValuesFromForm();

        # save updated values
        $SysCfg->updateValues($NewValues);

        # return to admin menu page
        $AF->setJumpToPage("SysAdmin");
        break;

    case "Cancel":
        # return to admin menu page without saving anything
        $AF->setJumpToPage("SysAdmin");
        break;
}
