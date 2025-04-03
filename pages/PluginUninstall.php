<?PHP
#
#   FILE:  PluginUninstall.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

PageTitle("Plugin Uninstall");

# check that the user has sufficient privileges
if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();
$PluginName = isset($_GET["PN"]) ? $_GET["PN"] : null;
$H_Plugin = $GLOBALS["G_PluginManager"]->GetPlugin($PluginName, true);
$H_UninstallFailed = false;
$H_UninstallResult = null;

# if specified plugin was found and action was requested
if ($H_Plugin && isset($_POST["Submit"])) {
    # if uninstall was confirmed
    if (($_POST["Submit"] == "Uninstall")) {
        # disable plugins that depend on this one
        $Dependents = $GLOBALS["G_PluginManager"]->GetDependents($PluginName);
        foreach ($Dependents as $DependentName) {
            $GLOBALS["G_PluginManager"]->PluginEnabled($DependentName, false);
        }

        # uninstall plugin
        $H_UninstallResult = $GLOBALS["G_PluginManager"]->UninstallPlugin($PluginName);

        # failed to uninstall the plugin
        if (!is_null($H_UninstallResult)) {
            $H_UninstallFailed = true;
            return;
        }

        # force a reload to the plugin list
        # (to avoid issues caused by hooks hanging around from uninstalled
        #       or disabled plugins)
        ?><html>
        <head>
            <meta http-equiv="refresh" content="0; URL=index.php?P=Plugins">
        </head>
        <body bgcolor="white">
        </body>
        </html><?PHP
        exit(0);
    } else {
        # go back to plugin list
        $AF->SetJumpToPage("Plugins");
    }
} else {
    # if specified plugin was found
    if ($H_Plugin) {
        # load list of enabled plugins that depend on this one
        $H_Dependents = $GLOBALS["G_PluginManager"]->GetDependents($PluginName);
        foreach ($H_Dependents as $Index => $Dependent) {
            if (!$GLOBALS["G_PluginManager"]->PluginEnabled($Dependent)) {
                unset($H_Dependents[$Index]);
            }
        }
    }
}
