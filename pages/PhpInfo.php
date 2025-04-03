<?PHP
#
#   FILE:  PhpInfo.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

PageTitle("PHP Configuration Info");

# require certain privileges to view the page
CheckAuthorization(PRIV_SYSADMIN, PRIV_USERADMIN, PRIV_COLLECTIONADMIN);

$AF = ApplicationFramework::getInstance();

# if being reloaded within iframe
if (isset($_GET["IF"])) {
    # print PHP info
    phpinfo();

    # prevent any additional HTML output
    $AF->SuppressHTMLOutput();
}
