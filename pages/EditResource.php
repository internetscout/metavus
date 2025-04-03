<?PHP
#
#   FILE:  EditResource.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Record;
use Metavus\RecordEditingUI;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# retrieve user currently logged in
$User = User::getCurrentUser();

# start off assuming editing mode
$H_Mode = "Edit";

# if ResourceId was absent or invalid
$H_ResourceId = StdLib::getFormValue("ID");
if (is_null($H_ResourceId) ||
    ($H_ResourceId != "NEW" && !Record::itemExists($H_ResourceId))) {
    # for users without (Personal)? Resource Admin or Release Admin,
    # use checkAuthorization() to display a permission denied message
    CheckAuthorization(
        PRIV_RESOURCEADMIN,
        PRIV_COLLECTIONADMIN,
        PRIV_RELEASEADMIN
    );

    # for users that do have these privs, we'll give a more specific error
    return;
}

# if this is a view (from editing complete form) or cancel
$AF = ApplicationFramework::getInstance();
if (isset($_POST["Submit"]) && $_POST["Submit"] == "View") {
    $Record = Record::getRecord($H_ResourceId);
    $AF->setJumpToPage(
        $Record->isTempRecord() ? "Home" : $Record->getViewPageUrl()
    );
    return;
}

# if creating a new resource was requested
if ($H_ResourceId == "NEW") {
    $H_Mode = "Add";

    # retrieve schema for new resource
    $SchemaId = isset($_GET["SC"]) ? $_GET["SC"]
        : MetadataSchema::SCHEMAID_DEFAULT;
    $Schema = new MetadataSchema($SchemaId);

    # bail out if user is not authorized to create new resources
    if (!$Schema->userCanAuthor($User)) {
        CheckAuthorization(-1);
        return;
    }

    # create new resource
    $H_Resource = Record::create($SchemaId);
} else {
    # load resource to be edited
    $H_Resource = Record::getRecord($H_ResourceId);
    $H_Mode = $H_Resource->isTempRecord() ? "Add" : "Edit";
}

# bail out if user is not authorized to modify this resource
if (!$H_Resource->userCanModify($User)) {
    CheckAuthorization(-1);
    return;
}

# redirect to correct URL if this is not the right editing page for record
$EditUrl = $H_Resource->getSchema()->getEditPage();
$EditUrlArgString = parse_url($EditUrl, PHP_URL_QUERY);
$EditUrl = str_replace('$ID', (string)$H_ResourceId, $EditUrl);
if ($EditUrlArgString !== false) {
    parse_str($EditUrlArgString, $EditUrlArgs);
    if (isset($EditUrlArgs["P"]) && ($EditUrlArgs["P"] != "EditResource")) {
        if ($AF->cleanUrlSupportAvailable()) {
            $EditUrl = $AF->getCleanRelativeUrlForPath($EditUrl);
        }
        $AF->setJumpToPage(ApplicationFramework::baseUrl().$EditUrl);
    }
}

# pull out the button pushed
$H_ButtonPushed = StdLib::getFormValue("Submit");
if ($H_ButtonPushed == "Delete") {
    $H_Mode = "Confirm Delete";
} elseif ($H_ButtonPushed == "Confirm") {
    $H_Mode = "Delete";
}

# set up editing user interface
$H_RecordEditingUI = new RecordEditingUI($H_Resource);

if (in_array($H_Mode, ["Confirm Delete", "Delete"])) {
    $H_RecordEditingUI->setAllFieldsReadOnly();
}

# if no POST was provided, then update the ONRECORDEDIT fields and stop as
# there's nothing more to do
if (empty($_POST)) {
    $H_Resource->updateAutoupdateFields(
        MetadataField::UPDATEMETHOD_ONRECORDEDIT,
        $User
    );
    return;
}

# if a button in the EditingUI was pushed, process its actions
switch ($H_RecordEditingUI->getSubmitButtonValue()) {
    case "Upload":
        $H_RecordEditingUI->handleUploads();
        break;

    case "Delete":
        $H_RecordEditingUI->handleDeletes();
        break;

    default:
        break;
}

# take action as required
switch ($H_ButtonPushed) {
    case "Save":
    case "Add":
        # if the input provided was valid
        if ($H_RecordEditingUI->validateFieldInput() == 0) {
            if ($H_ButtonPushed == "Add") {
                $H_Resource->isTempRecord(false);
            }

            $H_RecordEditingUI->saveChanges();

            $AF->setJumpToPage(
                "FullRecord&ID=".$H_Resource->id()
            );
        }
        break;

    case "Confirm":
        $AF->addPostProcessingCall(
            function ($Resource) {
                $Resource->destroy();
            },
            $H_Resource
        );
        break;

    case "Duplicate":
        $DupResource = Record::duplicate($H_Resource->id());

        # return to editing page with duplicate
        $AF->setJumpToPage(
            "EditResource&ID=".$DupResource->id()
        );
        break;

    case "Cancel":
        $H_RecordEditingUI->deleteUploads();
        $AF->setJumpToPage(
            $H_Resource->isTempRecord() ? "Home" : $H_Resource->getViewPageUrl()
        );
        break;

    default:
        break;
}
