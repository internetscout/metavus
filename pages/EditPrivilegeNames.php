<?PHP
#
#   FILE:  EditPrivilegeNames.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2001-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\PrivilegeFactory;
use Metavus\User;

if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
* Print text form entries for privileges.
* @param int $NumberOfEntries How many entries to print.
*/
function PrintTextFormEntries($NumberOfEntries)
{
    global $Privileges;

    # for each requested entry
    $Index = 0;
    foreach ($Privileges as $Privilege) {
        # Determine if the privilege is predefined
        if (!$Privilege->IsPredefined()) {
            # print entry and increment index
            PrintTextFormEntry(
                "F_PermissionText".$Index,
                $Privilege->Name(),
                $Privilege->Id()
            );
            $Index++;
        }
    }

    # Print remaining blank entries
    for ($Index; $Index < $NumberOfEntries; $Index++) {
        $IsLast = ($Index + 1 == $NumberOfEntries) ? true : false;
        PrintTextFormEntry("F_PermissionText".$Index, "", "", $IsLast);
    }
}

/**
* function to print any error messages at top of the page
*/
function PrintErrorMessages()
{
    global $ErrorMessages;

    # if error messages were passed from PreferencesComplete
    if (isset($ErrorMessages) && is_array($ErrorMessages) &&
        count($ErrorMessages) > 0) {
        # print error messages
        print("<ul><b>\n");
        foreach ($ErrorMessages as $Message) {
            printf("<li>%s</li>\n", $Message);
        }
        print("</ul></b>\n");
    }
}

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
global $ErrorMessages;
global $Privileges;

if (User::getCurrentUser()->IsLoggedIn()) {
    $PrivilegeFactory = new PrivilegeFactory();
    $Privileges = $PrivilegeFactory->GetPrivileges();
}

PageTitle("Edit Per-Field User Permission Names");
