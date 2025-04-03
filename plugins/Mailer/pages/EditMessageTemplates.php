<?PHP
#
#   FILE:  EditMessageTemplate.php (Mailer plugin)
#
#   Copyright 2012-2024 Edward Almasy and Internet Scout
#   http://scout.wisc.edu
#
# @scout:phpstan

use Metavus\Plugins\Mailer;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\User;

# check that user should be on this page
CheckAuthorization(PRIV_COLLECTIONADMIN, PRIV_SYSADMIN);

# load up current templates
$H_Plugin = Mailer::getInstance();

$H_Templates = $H_Plugin->getConfigSetting("Templates");

# take action based on which button was pushed or which action was requested
$Action = isset($_POST["Submit"]) ? $_POST["Submit"]
        : (isset($_GET["AC"]) ? $_GET["AC"] : null);
$TemplateId = isset($_POST["F_Id"]) ? $_POST["F_Id"]
        : (isset($_GET["ID"]) ? $_GET["ID"] : null);
$H_TestSeed = isset($_POST["F_TestSeed"]) ? $_POST["F_TestSeed"]
        : (isset($_GET["TS"]) ? $_GET["TS"] : floor(time() / (60 * 60 * 24)));
$H_TestIds = isset($_POST["F_TestIds"]) ? $_POST["F_TestIds"]
        : (isset($_GET["TI"]) ? $_GET["TI"]
                : ($H_Plugin->getConfigSetting("TestResourceIds")
                        ? $H_Plugin->getConfigSetting("TestResourceIds") : ""));
$H_Plugin->setConfigSetting("TestResourceIds", $H_TestIds);
switch ($Action) {
    case "Add Template":
        # set up blank template
        $TemplateId = "NEW";
        $H_Templates[$TemplateId]["Name"] = "";
        $H_Templates[$TemplateId]["From"] = "X-PORTALNAME-X <X-ADMINEMAIL-X>";
        $H_Templates[$TemplateId]["Subject"] = "";
        $H_Templates[$TemplateId]["Body"] = "";
        $H_Templates[$TemplateId]["ItemBody"] = "";
        $H_Templates[$TemplateId]["PlainTextBody"] = "";
        $H_Templates[$TemplateId]["PlainTextItemBody"] = "";
        $H_Templates[$TemplateId]["Headers"] = "";
        $H_Templates[$TemplateId]["CollapseBodyMargins"] = false;
        $H_Templates[$TemplateId]["EmailPerResource"] = false;

        # set flag to display template editing form
        $H_DisplayMode = "Adding";
        break;

    case "Edit":
    case "Delete":
        # set display mode flag to editing or deletion confirmation as appropriate
        $H_DisplayMode = ($Action == "Delete") ? "Confirm" : "Editing";
        break;

    case "Confirm":
        # delete template if it is not owned
        if (count($H_Plugin->FindTemplateUsers($TemplateId)) == 0) {
            unset($H_Templates[$TemplateId]);
            $H_Plugin->setConfigSetting("Templates", $H_Templates);
        }

        # set flag to display template list
        $H_DisplayMode = "Listing";
        break;

    case "Save":
    case "Test":
        # if new template
        if ($TemplateId == "NEW") {
            # get next template ID
            $TemplateId = (($H_Templates === null) || !count($H_Templates)) ? 0
                    : (intval(max(array_keys($H_Templates))) + 1);
        }

        # save template
        $H_Templates[$TemplateId] = [
            "Name" => $_POST["F_Name"],
            "From" => $_POST["F_From"],
            "Subject" => $_POST["F_Subject"],
            "Body" => $_POST["F_Body"],
            "CollapseBodyMargins" => !!$_POST["F_CollapseBodyMargins"],
            "ItemBody" => $_POST["F_ItemBody"],
            "PlainTextBody" => $_POST["F_PlainTextBody"],
            "PlainTextItemBody" => $_POST["F_PlainTextItemBody"],
            "Headers" => $_POST["F_Headers"],
            "EmailPerResource" => isset($_POST["F_EmailPerResource"]) ? true : false,
        ];
        $H_Plugin->setConfigSetting("Templates", $H_Templates);
        $H_Msgs[] = "<i>".htmlspecialchars($H_Templates[$TemplateId]["Name"])
                ."</i> template saved.";

        # if we are to send a test email
        if ($Action == "Test") {
            # if we have resources specified to use for testing
            $Resources = [];
            if (strlen(trim($H_TestIds))) {
                # split list of resource IDs
                $Ids = explode(" ", trim(
                    preg_replace("/[^0-9]+/", " ", $H_TestIds)
                ));

                # attempt to retrieve resources
                foreach ($Ids as $Id) {
                    # force value type to match argument type for subsequent calls
                    $Id = (int)$Id;
                    if (Record::ItemExists($Id)) {
                        $Resources[$Id] = new Record($Id);
                    }
                }
            }

            # if we don't yet have resources to use for test email
            if (!count($Resources)) {
                # retrieve random resources to use for test email
                $RFactory = new RecordFactory();
                $Ids = $RFactory->GetItemIds();
                srand($H_TestSeed);
                $ResourceCount = rand(1, 20);
                $Resources = [];
                for ($Index = 0; $Index < $ResourceCount; $Index++) {
                    $Id = $Ids[rand(0, count($Ids) - 1)];
                    $Resources[$Id] = new Record($Id);
                }
            }

            # send out test email to current user
            $H_Plugin->SendEmail(
                $TemplateId,
                User::getCurrentUser()->Id(),
                $Resources
            );
            $H_Msgs[] = "Test email sent for <i>"
                    .htmlspecialchars($H_Templates[$TemplateId]["Name"])
                    ."</i> template.";
        }

        # set display mode flag to editing or template list depending on action
        $H_DisplayMode = ($Action == "Test") ? "Editing" : "Listing";
        break;

    case "Cancel":
    default:
        # set flag to display template list
        $H_DisplayMode = "Listing";
        break;
}

# load values for selected template if needed
if (in_array($H_DisplayMode, ["Editing", "Adding", "Confirm"])) {
        $H_Id = $TemplateId;
        $H_Name = $H_Templates[$TemplateId]["Name"];
        $H_From = $H_Templates[$TemplateId]["From"];
        $H_Subject = $H_Templates[$TemplateId]["Subject"];
        $H_Body = $H_Templates[$TemplateId]["Body"];
        $H_CollapseBodyMargins =
                $H_Templates[$TemplateId]["CollapseBodyMargins"];
        $H_ItemBody = $H_Templates[$TemplateId]["ItemBody"];
        $H_PlainTextBody = $H_Templates[$TemplateId]["PlainTextBody"];
        $H_PlainTextItemBody = $H_Templates[$TemplateId]["PlainTextItemBody"];
        $H_Headers = $H_Templates[$TemplateId]["Headers"];
        $H_EmailPerResource = $H_Templates[$TemplateId]["EmailPerResource"];
}
