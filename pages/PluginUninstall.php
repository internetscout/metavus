<?PHP
#
#   FILE:  PluginUninstall.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Plugin Uninstall");

# check that the user has sufficient privileges
if (!User::requirePrivilege(PRIV_SYSADMIN)) {
    return;
}

$PluginMgr = PluginManager::getInstance();

$PluginName = isset($_GET["PN"]) ? $_GET["PN"] : null;
$H_Plugin = $PluginMgr->getPlugin($PluginName, true);
$H_UninstallFailed = false;
$H_UninstallResult = null;

# if specified plugin was found and action was requested
if ($H_Plugin && isset($_POST["Submit"])) {
    # if uninstall was confirmed
    if (($_POST["Submit"] == "Uninstall")) {
        # disable plugins that depend on this one
        $Dependents = $PluginMgr->getDependents($PluginName);
        foreach ($Dependents as $DependentName) {
            $PluginMgr->pluginEnabled($DependentName, false);
        }

        # uninstall plugin
        $H_UninstallResult = $PluginMgr->uninstallPlugin($PluginName);

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
        $AF->setJumpToPage("Plugins");
    }
} else {
    # if specified plugin was found
    if ($H_Plugin) {
        # load list of enabled plugins that depend on this one
        $H_Dependents = $PluginMgr->getDependents($PluginName);
        foreach ($H_Dependents as $Index => $Dependent) {
            if (!$PluginMgr->pluginEnabled($Dependent)) {
                unset($H_Dependents[$Index]);
            }
        }
    }
}
