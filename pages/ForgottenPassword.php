<?PHP
#
#   FILE:  ForgottenPassword.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Search for user and send recovery email
 * @param string $Username username or email to search
 * @return array|string email info to build html query if successful or error info if unsuccessful
 */
function SendRecoveryEmail(string $Username)
{
    # instantiate user factory for searching users
    $UserFactory = new UserFactory();

    if (User::isValidLookingEmailAddress($Username)) {
        # if input looks like an email address, search for users by email
        $SearchField = "EMail";
        $SearchString = User::normalizeEmailAddress($Username);
    } elseif (User::isValidUserName($Username)) {
        # if input looks like a username, search by name
        $SearchField = "UserName";
        $SearchString = User::normalizeUserName($Username);
    } else {
        # if input does not look like anything, then nothing to search for
        return "Invalid name supplied.";
    }

    # search for matching user
    $Users = $UserFactory->findUsers(
        $SearchString,
        $SearchField,
        "CreationDate",
        0,
        1
    );

    # error out if not found
    if (count($Users) == 0) {
        return $Username." not found.";
    }

    $TargetUser = reset($Users);

    $TemplateId = SystemConfiguration::getInstance()
        ->getInt("PasswordChangeTemplateId");
    $BaseUrl = ApplicationFramework::baseUrl();

    # set up any needed substitutions to message text
    $ResetUrlParameters = "&UN=".urlencode($TargetUser->get("UserName"))
        ."&RC=".$TargetUser->getResetCode();
    $ResetUrl = $BaseUrl."index.php?P=ResetPassword"
        .$ResetUrlParameters;
    $ManualResetUrl = $BaseUrl
        ."index.php?P=ManuallyResetPassword";
    $OurSubstitutions = [
        "RESETURL" => $ResetUrl,
        "RESETPARAMETERS" => $ResetUrlParameters,
        "MANUALRESETURL" => $ManualResetUrl,
        "RESETCODE" => $TargetUser->getResetCode(),
        "IPADDRESS" => @$_SERVER["REMOTE_ADDR"],
        "EMAILADDRESS" => $TargetUser->get("EMail"),
        "USERNAME" => $TargetUser->get("UserName"),
    ];

    # get mailer plugin
    $Mailer = PluginManager::getInstance()
        ->getPlugin("Mailer");

    # send forgotten password e-mail to user
    $MessagesSent = $Mailer->sendEmail(
        $TemplateId,
        $TargetUser->get("EMail"),
        [],
        $OurSubstitutions
    );
    $EmailSent = ($MessagesSent > 0) ? true : false;

    # return values for use in ForgottenPassword
    return [
        "SearchField" => $SearchField,
        "SearchString" => $SearchString,
        "EmailSent" => $EmailSent,
    ];
}

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

# request that this page not be indexed by search engines
$AF->addMetaTag(["robots" => "noindex"]);

# do not cache this page
$AF->doNotCacheCurrentPage();

# form fields for setting up formui, takes in one field (username)
$FormFields = [
    "Username" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "User Name or Email Address",
        "Size" => 30,
        "MaxLength" => 120,
        "Placeholder" => "",
        "Required" => true,
    ],
];

# instantiate form UI from form fields
$H_FormUI = new FormUI($FormFields);

# get username from GET/POST if available (currently used by plugin pages)
$UserName = StdLib::getFormValue("UN");

# if username was found in url, send recovery email
if (!is_null($UserName)) {
    $Result = SendRecoveryEmail(urldecode($UserName));
    if (is_string($Result)) {
        FormUI::logError($Result);
    } else {
        $H_UserValues = $Result;
    }
    return;
}

# act on form submission
$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Send Reset Email":
        $FormValues = $H_FormUI->getNewValuesFromForm();
        $Result = SendRecoveryEmail($FormValues["Username"]);
        if (is_string($Result)) {
            FormUI::logError($Result);
        } else {
            $H_UserValues = $Result;
        }
        break;

    case "Cancel":
        $AF->setJumpToPage("Home");
        break;
}
