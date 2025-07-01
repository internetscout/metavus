<?PHP
#
#   FILE:  Plugins.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;
use ScoutLib\Plugin;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Enable or disable plugin, enabling prerequisite plugins as needed.
* @param Plugin $Plugin Plugin.
* @param bool $Enable TRUE to enable, or FALSE to disable.
* @param array $StatusMsgs Current list of status messages.
* @param Plugin|null $Dependent Plugin that depends on this one, that is causing
*       it to be enabled.  (OPTIONAL)
* @return array List of status messages (potentially updated).
*/
function togglePlugin(
    Plugin $Plugin,
    bool $Enable,
    array $StatusMsgs,
    ?Plugin $Dependent = null
): array {
    # if plugin is to be enabled
    if ($Enable) {
        # retrieve list of plugins it depends on
        $RequiredPlugins = $Plugin->getDependencies();

        # for each plugin
        foreach ($RequiredPlugins as $RequiredPluginName => $RequiredPluginVersion) {
            # if required plugin exists and is not enabled
            $ReqPlugin = $GLOBALS["G_PluginManager"]->getPlugin(
                $RequiredPluginName,
                true
            );
            if (($ReqPlugin !== null) && !$ReqPlugin->isEnabled()) {
                # enable plugin
                $StatusMsgs = togglePlugin($ReqPlugin, true, $StatusMsgs, $Plugin);
            }
        }
    }

    # update plugin status
    $Plugin->isEnabled($Enable);

    # record message about change
    $PluginName = $Plugin->getBaseName();
    $Msg = "Plugin <b>".$Plugin->getName()."</b> has been "
            .($Enable ? "enabled" : "disabled").".";
    if ($Dependent) {
        $Msg .= "  (Required by <b>".$Dependent->getName()."</b>.)";
    }
    $StatusMsgs[$PluginName][] = $Msg;

    return $StatusMsgs;
}

# ----- MAIN -----------------------------------------------------------------
# check that the user has sufficient privileges
if (!User::requirePrivilege(PRIV_SYSADMIN)) {
    return;
}

$H_ErrMsgs = [];
$H_StatusMsgs = [];

# if form was submitted
if (isset($_POST["SUBMITTED"])) {
    $AF = ApplicationFramework::getInstance();
    # load current enabled state of all plugins
    # (needed in case one is auto-enabled because of a dependency)
    $Plugins = $GLOBALS["G_PluginManager"]->getPlugins();
    $PEnabledCurrent = [];
    foreach ($Plugins as $PluginName => $Plugin) {
        $PEnabledCurrent[$PluginName] = $Plugin->isEnabled();
    }

    # for each plugin
    $StatusMsgs = array();
    foreach ($Plugins as $PluginName => $Plugin) {
        # never disable core plugin
        if ($PluginName == "CWISCore" || $PluginName == "MetavusCore") {
            continue;
        }

        # if plugin has been enabled or disabled
        $FField = "EnabledCheckbox-".$PluginName;
        $PEnabledNew = isset($_POST[$FField]) ? true : false;
        if ($PEnabledNew != $PEnabledCurrent[$PluginName]) {
            # updated enable/disable setting to reflect form checkboxes
            $StatusMsgs = togglePlugin($Plugin, $PEnabledNew, $StatusMsgs);

            # note that plugin has been enabled/disabled
            $PEnabledChange = true;
        }
    }


    # if plugin enabled/disabled status has changed
    if (isset($PEnabledChange)) {
        # clear page cache
        $AF->clearPageCache();
        $AF->clearObjectLocationCache();
    }

    # save any resulting status messages before page is reloaded
    $_SESSION["PluginManagerStatusMessages"] = $StatusMsgs;

    # reload page so that correct set of plugins are loaded
    $AF->setJumpToPage("Plugins");
} else {
    # load any status or error messages
    $H_ErrMsgs = $GLOBALS["G_PluginManager"]->getErrorMessages();
    if (array_key_exists("PluginManagerStatusMessages", $_SESSION)) {
        $H_StatusMsgs = $_SESSION["PluginManagerStatusMessages"];
        unset($_SESSION["PluginManagerStatusMessages"]);
    }
}
