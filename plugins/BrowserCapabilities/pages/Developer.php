<?PHP
#
#   FILE: Developer.php (BrowserCapabilities plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

$MyPlugin = $GLOBALS["G_PluginManager"]->GetPluginForCurrentPage();
if (!$MyPlugin->ConfigSetting("EnableDeveloper")) {
    CheckAuthorization(false);
}

$H_BrowserData = $GLOBALS["AF"]->SignalEvent(
    "BROWSCAP_GET_BROWSER",
    [null, true]
);
