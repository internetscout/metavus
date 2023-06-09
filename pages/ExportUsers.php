<?PHP
#
#   FILE:  ExportUsers.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\FormUI;
use Metavus\PrivilegeFactory;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# ----- MAIN -----------------------------------------------------------------

PageTitle("Export Users");

# check if current user is authorized
CheckAuthorization(PRIV_SYSADMIN, PRIV_USERADMIN);

# if user clicked away while doing export, these variales would not get unset
foreach (array("FileName", "UserCount", "ExportPath") as $Val) {
    if (isset($_SESSION[$Val])) {
        unset($_SESSION[$Val]);
    }
}

$PFactory = new PrivilegeFactory();
$FormFields = array(
    "UserPrivs" => array(
        "Type" => FormUI::FTYPE_OPTION,
        "Label" => "User Privileges",
        "AllowMultiple" => true,
        "Rows" => 15,
        "Options" => $PFactory->getPrivilegeOptions(),
        "Help" => "Determines the users that will be exported based on "
        ."assigned privileges. <b>To export all users, select none.</b>"
    ),
);

$H_Form = new FormUI($FormFields);
