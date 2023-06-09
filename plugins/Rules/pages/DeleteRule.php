<?PHP
#
#   FILE:  DeleteRule.php (Rules plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Rules\Rule;
use Metavus\Plugins\Rules\RuleFactory;

# if rule was not specified
$PFactory = new RuleFactory();
if (!isset($_GET["ID"])) {
    # set error display
    $H_DisplayMode = "NoRuleSpecified";
} elseif (!$PFactory->ItemExists($_GET["ID"])) {
    # else if specified rule does not exist
    # set error display
    $H_DisplayMode = "RuleDoesNotExist";
} else {
    # load rule
    $H_Rule = new Rule($_GET["ID"]);

    # make sure user has privileges to delete rule
    CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

    # if we are processing confirmation
    if (isset($_GET["AC"]) && ($_GET["AC"] == "Confirmation")) {
        # if delete was confirmed
        if (isset($_POST["Submit"]) && ($_POST["Submit"] == "Delete")) {
            # hook function to delete rule after HTML is displayed
            $GLOBALS["AF"]->AddPostProcessingCall(function ($Id) {
                        $Rule = new Rule($Id);
                        $Rule->destroy();
            }, $_GET["ID"]);

            # inform user that rule was deleted
            $H_DisplayMode = "RuleDeleted";
        } elseif (isset($_POST["Submit"]) && ($_POST["Submit"] == "Cancel")) {
            # else if delete was cancelled
            # return to rule list
            $GLOBALS["AF"]->SetJumpToPage("P_Rules_ListRules");
        }
    } else {
        # else assume that confirmation is needed
        $H_DisplayMode = "ConfirmationNeeded";
    }
}
