<?PHP
#
#   FILE:  Preferences.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Validate a potential new password for a specified user.
 * @param string $NewPassword New password to check.
 * @return ?string NULL on success, error string describing the problem otherwise.
 */
function validateUserPassword(string $NewPassword): ?string
{
    # retrieve user currently logged in
    $User = User::getCurrentUser();

    if (!strlen($NewPassword)) {
        return null;
    }

    $PasswordErrors = User::checkPasswordForErrors(
        $NewPassword,
        $User->get("UserName"),
        $User->get("EMail")
    );

    if (count($PasswordErrors) == 0) {
        return null;
    }

    return implode(
        ", ",
        array_map(
            "\\Metavus\\User::getStatusMessageForCode",
            $PasswordErrors
        )
    );
}

/**
 * Change user's password if necessary
 * @param UserEditingUI $EditingUI UEUI containing the new values.
 */
function changeUserPasswordIfNecessary(UserEditingUI $EditingUI): void
{
    # retrieve user currently logged in
    $User = User::getCurrentUser();

    $NewPassword = $EditingUI->getFieldValue("NewPassword");
    if (strlen($NewPassword) == 0) {
        return;
    }

    # if user didn't have an existing password, set the one that was provided
    if ($User->get("Has No Password")) {
        $User->setPassword($NewPassword);
        $User->set("Has No Password", 0);
        $EditingUI->logStatusMessage("Password was successfully set.");
        $EditingUI->isFormVisible(false);
        return;
    }

    # otherwise, verify that the user provided the correct old password
    $OldPassword = $EditingUI->getFieldValue("OldPassword");
    $NewPasswordAgain = $EditingUI->getFieldValue("NewPasswordAgain");
    $Status = $User->changePassword(
        $OldPassword,
        $NewPassword,
        $NewPasswordAgain
    );

    # if password change was unsuccessful, log the error and return
    if ($Status != User::U_OKAY) {
        $EditingUI->logError(User::getStatusMessageForCode($Status));
        return;
    }

    # otherwise inform the user of success
    $EditingUI->logStatusMessage("Password was successfully changed.");
    $EditingUI->isFormVisible(false);
}

/**
 * Change user's email if necessary.
 * @param UserEditingUI $EditingUI UEUI containing the new values.
 */
function changeUserEmailIfNecessary(UserEditingUI $EditingUI): void
{
    $NewEmail = $EditingUI->getFieldValue("Email");

    $Message = User::getCurrentUser()->changeUserEmail($NewEmail);
    if ($Message !== null) {
        $EditingUI->logStatusMessage($Message);
        $EditingUI->isFormVisible(false);
    }
}

# ----- MAIN -----------------------------------------------------------------

# retrieve user currently logged in
$User = User::getCurrentUser();

# make sure the user is logged in
if (!$User->isLoggedIn()) {
    CheckAuthorization(false);
    return;
}

# get the address of the page we should return to after updating information
$H_ReturnTo = StdLib::getArrayValue(
    $_POST,
    "F_ReturnTo",
    StdLib::getArrayValue(
        $_GET,
        "ReturnTo",
        StdLib::getArrayValue(
            $_SERVER,
            "HTTP_REFERER",
            "index.php?P=Home"
        )
    )
);

# if we came from the ActivateAccount page because the user needs to set a
# password, we'll want to go to P=Home afterward rather than bouncing back to
# ActivateAccount
if (strpos($H_ReturnTo, "P=ActivateAccount") !== false) {
    $H_ReturnTo = "index.php?P=Home";
}

# configure additional fields for changing the user's password and email
$FormFields = [
    "HEADING-AccountInformation" => [
        "Type" => FormUI::FTYPE_HEADING,
        "Label" => "Account Information",
    ],
];

$Record = $User->getResource();
$FormFields["UserId"] = RecordEditingUI::getFormConfigForField(
    $Record,
    $Record->getSchema()->getField("UserId"),
    false
);
$FormFields["UserId"]["Value"] = [
    $User->id()
];

$FormFields += [
    "Email" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Email",
        "Value" => $User->get("EMail"),
    ],

    "HEADING-Password" => [
        "Type" => FormUI::FTYPE_HEADING,
        "Label" => "Password",
    ],
];

if (!$User->get("Has No Password")) {
    $FormFields += [
        "OldPassword" => [
            "Type" => FormUI::FTYPE_PASSWORD,
            "Label" => "Old Password",
            "MaxLength" => 20,
            "Size" => 20,
            "ValidateFunction" => function ($FieldName, $Value, $Values) {
                if (strlen($Value) && !User::getCurrentUser()->isPasswordCorrect($Value)) {
                    return "Old password provided was not correct.";
                }

                if (strlen($Value) == 0 && strlen($Values["NewPassword"]) > 0) {
                    return "Old password must be provided for password changes.";
                }
                return null;
            },
        ],
    ];
}

$FormFields += [
    "NewPassword" => [
        "Type" => FormUI::FTYPE_PASSWORD,
        "Label" => "New Password",
        "MaxLength" => 20,
        "Size" => 20,
        "Help" => User::getPasswordRulesDescription(),
        "ValidateFunction" => function ($FieldName, $Value, $Values) {
            if (!User::getCurrentUser()->get("Has No Password")) {
                if (strlen($Values["OldPassword"]) && strlen($Value) == 0) {
                    return "New password must be provided for password changes";
                }
            }

            return validateUserPassword($Value);
        },
    ],
    "NewPasswordAgain" => [
        "Type" => FormUI::FTYPE_PASSWORD,
        "Label" => "New Password Again",
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

$H_UserEditingUI = new UserEditingUI(
    $User,
    $FormFields
);

# if a button in the EditingUI was pushed, process its actions
switch ($H_UserEditingUI->getSubmitButtonValue()) {
    case "Upload":
        $H_UserEditingUI->handleUploads();
        break;

    case "Delete":
        $H_UserEditingUI->handleDeletes();
        break;

    default:
        break;
}

# handle button press for top-level form
$ButtonPushed = $_POST["Submit"] ?? null;
switch ($ButtonPushed) {
    case "Save":
        if ($H_UserEditingUI->validateFieldInput() == 0) {
            $H_UserEditingUI->saveChanges();
            changeUserPasswordIfNecessary($H_UserEditingUI);
            changeUserEmailIfNecessary($H_UserEditingUI);

            $UpdateSuccessful = !UserEditingUI::errorsLogged();
            if ($UpdateSuccessful) {
                ApplicationFramework::getInstance()
                    ->setJumpToPage($H_ReturnTo);
            }
        }
        break;

    default:
        break;
}
