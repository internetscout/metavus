<?PHP
#
#   FILE: Developer.php (BrowserCapabilities plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\BrowserCapabilities;
use ScoutLib\ApplicationFramework;

if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

$BrowserCaptabilitiesPlugin = BrowserCapabilities::getInstance();
if (!$BrowserCaptabilitiesPlugin->getConfigSetting("EnableDeveloper")) {
    CheckAuthorization(false);
}

$H_BrowserData = $AF->SignalEvent(
    "BROWSCAP_GET_BROWSER",
    [null, true]
);
