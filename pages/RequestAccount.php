<?PHP
#
#   FILE:  RequestAccount.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\FormUI;
use Metavus\MetadataSchema;
use Metavus\Record;
use Metavus\RecordEditingUI;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# request that this page not be indexed by search engines
$AF = ApplicationFramework::getInstance();
$AF->addMetaTag(["robots" => "noindex"]);

# ----- MAIN -----------------------------------------------------------------

$AF->setPageTitle("New User Sign-Up");

$PluginMgr = PluginManager::getInstance();

# don't allow new user signups from spambots
if ($PluginMgr->pluginReady("BotDetector") &&
    $PluginMgr->getPlugin("BotDetector")->CheckForSpamBot()) {
    $AF->setJumpToPage("UnauthorizedAccess");
    return;
}

# create temporary user record for permission checks
$Record = Record::create(MetadataSchema::SCHEMAID_USER);

# set up form fields
$FormFields = [
    "LoginInformation" => [
        "Type" => FormUI::FTYPE_HEADING,
        "Label" => "Login Information",
    ],
];

$UserFields = ["UserName", "Password", "PasswordAgain", "EMail", "EMailAgain"];
foreach ($UserFields as $UserField) {
    $FormFields[$UserField] = User::getFormSettingsForField($UserField);
}

$FormFields["Verification"] = [
    "Label" => "Verification Code",
    "Type" => FormUI::FTYPE_CAPTCHA,
];

$FormFields["UserInformation"] = [
    "Type" => FormUI::FTYPE_HEADING,
    "Label" => "User Information"
];

$UserFields = User::getDefaultUserFields() + User::getCustomUserFields();
foreach ($UserFields as $MField) {
    if (!$Record->userCanAuthorField(User::getAnonymousUser(), $MField)) {
        continue;
    }

    $FormFields[$MField->name()] = RecordEditingUI::getFormConfigForField(
        $Record,
        $MField,
        true
    );

    $DefaultValue = $MField->defaultValue();
    if ($DefaultValue !== false ||
        $MField->type() == MetadataSchema::MDFTYPE_FLAG) {
        $FormFields[$MField->name()]["Value"] = $DefaultValue;
    }
}

# delete temporary record after page load is complete
$AF->addPostProcessingCall(
    function ($Record) {
        $Record->destroy();
    },
    $Record
);

# check for additional fields
$FormFields += User::getAdditionalSignupFormFields();

# set up FormUI
$H_FormUI = new FormUI($FormFields);
$H_AccountCreatedSuccessfully = false;

$ButtonPushed = StdLib::getFormValue("Submit");
if ($ButtonPushed == "Create Account") {
    if ($H_FormUI->validateFieldInput() == 0) {
        $Values = $H_FormUI->getNewValuesFromForm();

        $UFactory = new UserFactory();

        $UserErrorCodes = $UFactory->testNewUserValues(
            $Values["UserName"],
            $Values["Password"],
            $Values["PasswordAgain"],
            $Values["EMail"],
            $Values["EMailAgain"]
        );

        if (count($UserErrorCodes) == 0) {
            $UFactory->createNewUserFromFormData($Values);
            $H_AccountCreatedSuccessfully = true;
        } else {
            foreach ($UserErrorCodes as $Error) {
                $H_FormUI->logError(User::getStatusMessageForCode($Error));
            }
        }
    }
}
