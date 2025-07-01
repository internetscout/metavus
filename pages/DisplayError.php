<?PHP
#
#   FILE:  DisplayError.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2001-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

# prepare error message for print
if (isset($_SESSION["ErrorMessage"])) {
    $H_ErrorMessage = $_SESSION["ErrorMessage"];
    unset($_SESSION["ErrorMessage"]);
} else {
    $H_ErrorMessage = "(No error message available)";
}

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Error");
