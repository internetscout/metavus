<?PHP
#
#   FILE:  EditPrivilegeNamesComplete.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2006-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Privilege;
use Metavus\PrivilegeFactory;

if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

# ----- CONFIGURATION  -------------------------------------------------------

# ----- EXPORTED FUNCTIONS ---------------------------------------------------
# (functions intended for use in corresponding HTML file)

# ----- LOCAL FUNCTIONS ------------------------------------------------------
# (functions intended for use only within this file)
function UpdatePrivileges()
{
    # Create privilege factory and get all privileges
    $PrivilegeFactory = new PrivilegeFactory();
    $Privileges = $PrivilegeFactory->GetPrivileges();

    # Set starting index
    $Index = 0;
    $Counter = 0;

    # Loop through each privilege
    foreach ($Privileges as $Privilege) {
        # Determine if current privilege is not predefined
        if (!$Privilege->IsPredefined() &&
                isset($_POST["F_PermissionText".$Index])) {
            # Initialize status
            $Status = 0;

            # Determine privilege's update/delete status
            if (isset($_POST["F_PermissionText".$Index."_Delete"])) {
                # Delete the privilege and update status
                $Privilege->Delete();
                $Status = 1;
                $Counter--;
            } elseif ($Privilege->Name() !== $_POST["F_PermissionText".$Index]) {
                # Update privilege and status
                $Privilege->Name(addslashes($_POST["F_PermissionText".$Index]));
                $Status = 2;
            }

            # Display privilege and increment index
            DisplayField($Privilege->Name(), $Status);
            $Index++;
            $Counter++;
        }
    }

    # Loop through remaining permission values
    for ($Index; isset($_POST["F_PermissionText".$Index]); $Index++) {
        $Name = trim($_POST["F_PermissionText".$Index]);
        $MarkedForDeletion = isset($_POST["F_PermissionText".$Index."_Delete"]);
        $PrivilegeExists = $PrivilegeFactory->PrivilegeNameExists($Name);

        # If not whitespace or an existing privilege, add new privilege and
        # display it
        if (strlen($Name) > 0 && !$MarkedForDeletion && !$PrivilegeExists) {
            $EscapedName = $Name;
            $Privilege = new Privilege(null, $EscapedName);
            DisplayField($Privilege->Name(), 3);
            $Counter++;
        }
    }

    # Determine if any privileges exist
    if ($Counter < 1) {
        DisplayField("No privileges", 0);
    }
}

function DisplayPrivileges()
{
    # Create privilege factory and get all privileges
    $PrivilegeFactory = new PrivilegeFactory();
    $Privileges = $PrivilegeFactory->GetPrivileges();

    # Determine if any privileges exist
    $PrivFactory = new PrivilegeFactory();
    if (count($Privileges) - count($PrivFactory->GetPredefinedPrivilegeConstants()) < 1) {
        DisplayField("No privileges", 0);
    } else {
        # Loop through each privilege
        foreach ($Privileges as $Privilege) {
            # Determine if current privilege is not predefined
            if (!$Privilege->IsPredefined()) {
                # Display privilege and increment index
                DisplayField($Privilege->Name(), 0);
            }
        }
    }
}

# ----- MAIN -----------------------------------------------------------------

PageTitle("Privilege Editing Complete");

# Check if the privileges have already been updated
if (isset($_POST["AlreadyLoaded"])) {
    # Go to all privileges  (needs the "?ID=" portion, fixes refresh bug)
    $AF->SetJumpToPage("EditPrivilegeNames&ID=");
    return;
}

$_POST["AlreadyLoaded"] = "TRUE";
