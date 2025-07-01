<?PHP
#
#   FILE:  PluginConfig.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\FormUI;
use Metavus\User;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

# check that the user has sufficient privileges
if (!User::requirePrivilege(PRIV_SYSADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

$PluginName = isset($_GET["PN"]) ? $_GET["PN"] : null;
$H_Plugin = $GLOBALS["G_PluginManager"]->getPlugin($PluginName, true);
$H_PluginCssClass = "cw-plugin-".strtolower(str_replace(' ', '-', $H_Plugin->getName()));
$Attribs = $H_Plugin->getAttributes();
$CfgValues = array();
foreach ($Attribs["CfgSetup"] as $Name => $Params) {
    $CfgValues[$Name] = $H_Plugin->getSavedConfigSetting($Name);
}

FormUI::ignoreFieldParameter("SettingFilter");
$H_CfgUI = new FormUI($Attribs["CfgSetup"], $CfgValues);

if (isset($_POST["Submit"])) {
    # if user requested new config settings be saved
    if ($_POST["Submit"] == "Save Changes") {
        # if errors were found
        if ($H_CfgUI->validateFieldInput()) {
            # redisplay editing page
            return;
        }

        # retrieve form values
        $H_CfgUI->setEventToSignalOnChange(
            "EVENT_PLUGIN_CONFIG_CHANGE",
            [
                "PluginName" => $Attribs["Name"],
                "SettingName" => null,
                "OldValue" => null,
                "NewValue" => null
            ]
        );
        $NewValues = $H_CfgUI->getNewValuesFromForm();

        # save new values
        foreach ($NewValues as $Name => $NewValue) {
            $H_Plugin->configSetting($Name, $NewValue);
        }
    }

    # return to plugin list page
    $AF->setJumpToPage("Plugins");
} else {
    # check form fields for problems with their values when
    # the form is initially loaded
    $H_CfgUI->validateInitialValues();
}
