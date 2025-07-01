<?PHP
#
#   FILE: Developer.php (BrowserCapabilities plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\BrowserCapabilities;
use Metavus\User;
use ScoutLib\ApplicationFramework;

if (!User::requirePrivilege(PRIV_SYSADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

$BrowserCaptabilitiesPlugin = BrowserCapabilities::getInstance();
if (!$BrowserCaptabilitiesPlugin->getConfigSetting("EnableDeveloper")) {
    User::handleUnauthorizedAccess();
    return;
}

$H_BrowserData = $AF->SignalEvent(
    "BROWSCAP_GET_BROWSER",
    [null, true]
);
