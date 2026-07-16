<?PHP
#
#   FILE:  DeletePage.php (Pages plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Plugins\Pages\Page;
use Metavus\Plugins\Pages\PageFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();

$PFactory = new PageFactory();

# if page was not specified
if (!isset($_GET["ID"])) {
    $H_DisplayMode = "NoPageSpecified";
    return;
}

# if specified page does not exist
if (!$PFactory->itemExists($_GET["ID"])) {
    $H_DisplayMode = "PageDoesNotExist";
    return;
}

# load page
$H_Page = new Page($_GET["ID"]);

# make sure user has privileges to delete page
# (uCD() checks edit perms and then delete perms)
if (!$H_Page->userCanDelete(User::getCurrentUser())) {
    User::handleUnauthorizedAccess();
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
        return;
    }

    # if delete was cancelled
    if (isset($_POST["Submit"]) && ($_POST["Submit"] == "Cancel")) {
        $AF->setJumpToPage(isset($_POST["F_Referer"])
            ? $_POST["F_Referer"] : "Pages_ListPages");
        return;
    }
}

# else assume that confirmation is needed
$H_DisplayMode = "ConfirmationNeeded";
