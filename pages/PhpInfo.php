<?PHP
#
#   FILE:  PhpInfo.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\User;
use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("PHP Configuration Info");

# require certain privileges to view the page
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_USERADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

# if being reloaded within iframe
if (isset($_GET["IF"])) {
    # print PHP info
    phpinfo();

    # prevent any additional HTML output
    $AF->suppressHtmlOutput();
}
