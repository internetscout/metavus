<?PHP
#
#   FILE:  ResetPassword.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2006-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\FormUI;
use Metavus\User;
use Metavus\UserFactory;

/**
 * Extract a provided parameter, looking in _GET for an abbreviated name and
 * then _POST for a longer name.
 * @param string $ShortName Abbreviated name to look for.
 * @param string $LongName Full name to look for.
 * @return Extracted value or NULL if none found.
 */
function getParameter(string $ShortName, string $LongName)
{
    if (isset($_GET[$ShortName])) {
        return trim($_GET[$ShortName]);
    }

    if (isset($_POST[$LongName])) {
        return trim($_POST[$LongName]);
    }

    return null;
}

# ----- MAIN -----------------------------------------------------------------

PageTitle("Password Reset");

$UserName = getParameter("UN", "F_UserName");
$ResetCode = getParameter("RC", "F_ResetCode");

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
    "ResetCode" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Reset Code",
        "Required" => true,
        "MaxLength" => 10,
        "Size" => 10,
    ],
    "NewPassword" => [
        "Type" => FormUI::FTYPE_PASSWORD,
        "Label" => "New Password",
        "Required" => true,
        "MaxLength" => 20,
        "Size" => 20,
        "Help" => User::getPasswordRulesDescription(),
    ],
    "NewPasswordAgain" => [
        "Type" => FormUI::FTYPE_PASSWORD,
        "Label" => "New Password Again",
        "Required" => true,
        "ValidateFunction" => function ($FieldName, $Value, $Values) {
            if ($Value != $Values["NewPassword"]) {
                return "Passwords must match.";
            }
            return null;
        },
        "MaxLength" => 20,
        "Size" => 20,
    ],
];
$FormValues = [
    "UserName" => $UserName,
    "ResetCode" => $ResetCode,
];

$H_FormUI = new FormUI($FormFields, $FormValues);
$H_SuccessMessages = [];

# if no values were provided, bail so that we just display the form
if (count($_POST) == 0) {
    return;
}

# if provided values contain errors, bail
if ($H_FormUI->validateFieldInput() > 0) {
    return;
}

# load the specified user
$User = new User($UserName);

# check that the provided reset code is valid
if (!$User->isResetCodeGood($ResetCode)) {
    $H_FormUI->logError(
        "Invalid password reset code.",
        "ResetCode"
    );
    return;
}

# check the provided password for errors
$PasswordErrors =  User::checkPasswordForErrors(
    $H_FormUI->getFieldValue("NewPassword"),
    $User->get("UserName"),
    $User->get("EMail")
);
if (count($PasswordErrors) > 0) {
    $ErrorMessage = implode(
        ", ",
        array_map(
            "\\Metavus\\User::getStatusMessageForCode",
            $PasswordErrors
        )
    );

    $H_FormUI->logError(
        $ErrorMessage,
        "NewPassword"
    );

    return;
}

# reset the user's password
$User->isActivated(true);
$User->setPassword($H_FormUI->getFieldValue("NewPassword"));
$H_SuccessMessages[] = "Password successfully set.";
