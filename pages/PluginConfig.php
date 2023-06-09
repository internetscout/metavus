<?PHP
#
#   FILE:  PluginConfig.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\FormUI;

# check that the user has sufficient privileges
if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

$PluginName = isset($_GET["PN"]) ? $_GET["PN"] : null;
$H_Plugin = $GLOBALS["G_PluginManager"]->GetPlugin($PluginName, true);
$H_PluginCssClass = "cw-plugin-".strtolower(str_replace(' ', '-', $H_Plugin->getName()));
$Attribs = $H_Plugin->GetAttributes();
$CfgValues = array();
foreach ($Attribs["CfgSetup"] as $Name => $Params) {
    $CfgValues[$Name] = $H_Plugin->GetSavedConfigSetting($Name);
}

FormUI::IgnoreFieldParameter("SettingFilter");
$H_CfgUI = new FormUI($Attribs["CfgSetup"], $CfgValues);

if ($H_Plugin && isset($_POST["Submit"])) {
    # if user requested new config settings be saved
    if ($_POST["Submit"] == "Save Changes") {
        # if errors were found
        if ($H_CfgUI->ValidateFieldInput()) {
            # redisplay editing page
            return;
        } else {
            # retrieve form values
            $H_CfgUI->SetEventToSignalOnChange(
                "EVENT_PLUGIN_CONFIG_CHANGE",
                array("PluginName" => $Attribs["Name"],
                            "SettingName" => null,
                            "OldValue" => null,
                "NewValue" => null)
            );
            $NewValues = $H_CfgUI->GetNewValuesFromForm();

            # save new values
            foreach ($NewValues as $Name => $NewValue) {
                $H_Plugin->ConfigSetting($Name, $NewValue);
            }

            # return to plugin list page
            $AF->SetJumpToPage("Plugins");
        }
    } else {
        # return to plugin list page
        $AF->SetJumpToPage("Plugins");
    }
}
