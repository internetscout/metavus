<?PHP
#
#   FILE:  BatchEdit.php (Rules plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Retrieve rule settings from form.
 * @return Array with rule settings for values and rule IDs for index.
 */
function getRuleList()
{
    global $G_PluginManager;

    $List = [];
    $RulesPlugin = $G_PluginManager->GetPlugin("Rules");
    $Rules = $RulesPlugin->GetRules();

    foreach ($_POST as $Key => $Value) {
        # if the variable matches what is expected when a rule is selected
        if (@preg_match('/F_Selected_([0-9]+)/', $Key, $Matches)) {
            $RuleId = $Matches[1];
            $Rule = StdLib::getArrayValue($Rules, $RuleId);

            # if the rule ID is actually valid and a rule exists
            if (!is_null($Rule)) {
                $List[$RuleId] = $Rule;
            }
        }
    }

    return $List;
}

# ----- MAIN -----------------------------------------------------------------

# check that user should be on this page
CheckAuthorization(PRIV_COLLECTIONADMIN, PRIV_SYSADMIN);

$AF->SuppressHTMLOutput();

$RulesPlugin = $G_PluginManager->GetPlugin("Rules");
$Rules = getRuleList();
$Action = StdLib::getArrayValue($_POST, "F_SelectionAction");

# enable/disable rules
if ($Action == "enable" || $Action == "disable") {
    $Enabled = $Action == "enable" ? 1 : 0;

    foreach ($Rules as $RuleId => $Rule) {
        $Rule["Enabled"] = $Enabled;
        $RulesPlugin->UpdateRule($Rule);
    }
} elseif ($Action == "remove") {
    # remove rules
    foreach ($Rules as $RuleId => $Rule) {
        $RulesPlugin->DeleteRule($RuleId);
    }
}

$AF->SetJumpToPage("P_Rules_List");
