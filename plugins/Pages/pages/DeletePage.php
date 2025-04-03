<?PHP
#
#   FILE:  DeletePage.php (Pages plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Plugins\Pages\Page;
use Metavus\Plugins\Pages\PageFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();

# if page was not specified
$PFactory = new PageFactory();
if (!isset($_GET["ID"])) {
    # set error display
    $H_DisplayMode = "NoPageSpecified";
} elseif (!$PFactory->itemExists($_GET["ID"])) {
    # else if specified page does not exist
    # set error display
    $H_DisplayMode = "PageDoesNotExist";
} else {
    # load page
    $H_Page = new Page($_GET["ID"]);

    # make sure user has privileges to delete page
    if (!$H_Page->userCanEdit(User::getCurrentUser())) {
        DisplayUnauthorizedAccessPage();
        return;
    }

    # if we are processing confirmation
    if (isset($_GET["AC"]) && ($_GET["AC"] == "Confirmation")) {
        # if delete was confirmed
        if (isset($_POST["Submit"]) && ($_POST["Submit"] == "Delete")) {
            # hook function to delete page after HTML is displayed
            function DeletePage(int $Id): void
            {
                $Page = new Page($Id);
                $Page->destroy();
            }
            $AF->addPostProcessingCall("DeletePage", $_GET["ID"]);

            # inform user that page was deleted
            $H_DisplayMode = "PageDeleted";
        } elseif (isset($_POST["Submit"]) && ($_POST["Submit"] == "Cancel")) {
            # else if delete was cancelled
            # return to referring page
            $AF->setJumpToPage(isset($_POST["F_Referer"])
                    ? $_POST["F_Referer"] : "Pages_ListPages");
        }
    } else {
        # else assume that confirmation is needed
        $H_DisplayMode = "ConfirmationNeeded";
    }
}
