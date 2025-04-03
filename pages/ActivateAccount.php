<?PHP
#
#   FILE:  ActivateAccount.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2006-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\FormUI;
use Metavus\User;
use Metavus\UserFactory;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Extract a provided parameter, looking in _GET for an abbreviated name and
 * then _POST for a longer name.
 * @param string $ShortName Abbreviated name to look for.
 * @param string $LongName Full name to look for.
 * @return ?string Extracted value or NULL if none found.
 */
function getParameter(string $ShortName, string $LongName) : ?string
{
    if (isset($_GET[$ShortName])) {
        return trim($_GET[$ShortName]);
    }

    if (isset($_POST[$LongName])) {
        return trim($_POST[$LongName]);
    }

    return null;
}

PageTitle("New Account Activation");

# ----- MAIN -----------------------------------------------------------------

# retrieve user name and confirmation code from URL or form
$UserName = getParameter("UN", "F_UserName");
$ActivationCode = getParameter("AC", "F_ActivationCode");

$FormFields = [
    "UserName" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "User Name",
        "Required" => true,
        "ValidateFunction" => function ($FieldName, $Value) {
            $UFactory = new UserFactory();
            if (is_null($Value) || !$UFactory->userNameExists($Value)) {
                return "Invalid username.";
            }
            return null;
        },
        "Size" => 23,
        "MaxLength" => 64,
        "Placeholder" => "(login name)",
    ],
    "ActivationCode" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Activation Code",
        "Required" => true,
        "Size" => 6,
        "MaxLength" => 12,
        "Placeholder" => "(code)",
    ],
];

$FormValues = [
    "UserName" => $UserName,
    "ActivationCode" => $ActivationCode,
];

$H_FormUI = new FormUI($FormFields, $FormValues);
$H_SuccessMessages = [];

# if no values were provided, just display the form
if (count($_POST) == 0 && is_null($UserName) && is_null($ActivationCode)) {
    return;
}

# if provided values contain errors, bail
if ($H_FormUI->validateFieldInput() > 0) {
    return;
}

# get the specified user
$User = new User($UserName);

# if account is not already activated
if (!$User->isActivated()) {
    # attempt to activate them
    if (!$User->activateAccount($ActivationCode)) {
        $H_FormUI->logError(
            "Invalid account activation code.",
            "ActivationCode"
        );
        return;
    }

    $H_SuccessMessages[] = "Account successfully activated.";
    if ($User->get("Has No Password")) {
        $H_SuccessMessages[] = "You have not yet provided a password. "
            ."To make use of your account, you will need to set a password "
            ."on the <a href='index.php?P=Preferences'>Preferences</a> page.";
        User::getCurrentUser()->login(
            $User->get("UserName"),
            "",
            true
        );
    }
    return;
}

# check if we're actually servicing a mail change request
if ($User->confirmEmailChange($ActivationCode)) {
    $H_SuccessMessages[] = "Email successfully changed";
} else {
    $H_FormUI->logError(
        "Invalid email change confirmation code.",
        "ActivationCode"
    );
}
