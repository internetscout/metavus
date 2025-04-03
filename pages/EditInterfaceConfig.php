<?PHP
#
#   FILE:  EditInterfaceConfig.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_Form - Configuration form, with fields and values set.
#   $H_SelectedInterface - Interface for which values are to be edited.
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   (none)
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$AF = ApplicationFramework::getInstance();

# determine which interface configuration we are editing
# (in descending order of preference:
#       F_SelectedInterface - from interface selection option list on editing form
#       IF - from hidden field on editing form
#       current active interface)
$H_SelectedInterface = StdLib::getFormValue(
    "F_SelectedInterface",
    StdLib::getFormValue("IF", $AF->activeUserInterface())
);

# clear form values if user just selected a new interface
if (isset($_POST["F_SelectedInterface"]) && isset($_POST["IF"])
    && ($_POST["F_SelectedInterface"] != $_POST["IF"])) {
    $_POST = [];
}

# load interface configuration
$IntCfg = InterfaceConfiguration::getInstance($H_SelectedInterface);

# set up form (force values if we have switched to editing a difference interface)
$FormParams = $IntCfg->getFormParameters();
$FormValues = $IntCfg->getFormValues();
$H_Form = new FormUI($FormParams);

# act on any button push
switch ($H_Form->getSubmitButtonValue()) {
    case "Upload":
        $H_Form->handleUploads();
        break;

    case "Delete":
        $H_Form->handleDeletes();
        break;

    case "Save":
        # check values and bail out if any are invalid
        if ($H_Form->validateFieldInput()) {
            return;
        }

        # retrieve submitted values from form
        $NewValues = $H_Form->getNewValuesFromForm();

        # save updated values
        $IntCfg->updateValues($NewValues);

        # return to admin menu page
        $AF->setJumpToPage("SysAdmin");
        break;

    case "Cancel":
        # return to admin menu page without saving anything
        $AF->setJumpToPage("SysAdmin");
        break;
}
