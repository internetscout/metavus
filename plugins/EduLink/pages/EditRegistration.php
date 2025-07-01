<?PHP
#
#   FILE:  EditRegistration.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan
#
#  VALUES PROVIDED to INTERFACE (REQUIRED):
#    $H_Id - Registration ID being edited or NEW when creating a new registration.
#
#  VALUES PROVIDED to INTERFACE (OPTIONAL):
#    $H_Error - Error messages if there was a problem
#    $H_FormUI - Editing form for the registration (may be absent when an
#      invalid ID was provided)
#
namespace Metavus;

use Exception;
use Metavus\Plugins\EduLink;
use Metavus\Plugins\EduLink\LMSRegistration;
use Metavus\Plugins\EduLink\LMSRegistrationFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

User::requirePrivilege(PRIV_SYSADMIN);

$H_Id = $_GET["ID"] ?? null;
if (is_null($H_Id)) {
    $H_Error = "Id parameter must be provided.";
    return;
}

$AF = ApplicationFramework::getInstance();
$Plugin = EduLink::getInstance();

if ($H_Id != "NEW") {
    if (!LMSRegistration::itemExists($H_Id)) {
        $H_Error = "Provided Id is invalid.";
        return;
    }

    $Registration = new LMSRegistration($H_Id);
    $Values = [
        "ContactEmail" => $Registration->getContactEmail(),
        "LMS" => $Registration->getLms(),
        "Issuer" => $Registration->getIssuer(),
        "ClientId" => $Registration->getClientId(),
        "AuthLoginUrl" => $Registration->getAuthLoginUrl(),
        "AuthTokenUrl" => $Registration->getAuthTokenUrl(),
        "KeySetUrl" => $Registration->getKeySetUrl(),
        "SearchParameters" => $Registration->getSearchParameters(),
    ];
} else {
    $Values = [];
}

$FormFields = [
    "SearchParameters" => [
        "Label" => "Search Parameters",
        "Type" => FormUI::FTYPE_SEARCHPARAMS,
        "Help" => "Additional search parameters that will be added to "
            ."the default parameters configured for the plugin.",
        "Required" => false,
    ],
    "LMS" => [
        "Type" => FormUI::FTYPE_OPTION,
        "Label" => "LMS",
        "Help" => "Learning Management System",
        "Options" => [
            "Other" => "Other LMS",
            "Blackboard" => "Blackboard",
            "Canvas" => "Canvas",
            "Moodle" => "Moodle",
        ],
        "OptionType" => FormUI::OTYPE_LIST,
        "OptionThreshold" => 0,
    ],
    "ContactEmail" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Contact Email",
        "ValidateFunction" => ["\\Metavus\\FormUI", "validateEmail"],
        "Required" => true,
    ],
    "Issuer" => [
        "Label" => "Issuer",
        "Help" => "Typically the URL of the LMS.<br/>"
            ."(See <a href='https://www.imsglobal.org/spec/security/v1p0/#terminology'"
            .">1EdTech Security Framework 1.0 &sect; 1.2</a>)<br/><br/>"
            ."Blackboard: <i>Issuer</i><br/>"
            ."Canvas: <i>Site URL</i><br/>"
            ."Moodle: <i>Platform ID</i>",
        "Type" => FormUI::FTYPE_TEXT,
        "Required" => true,
    ],
    "ClientId" => [
        "Label" => "Client ID",
        "Help" => "Opaque string provided by the LMS to identify itself.<br/>"
            ."(See <a href='https://www.imsglobal.org/spec/security/v1p0/#terminology'"
            .">1EdTech Security Framework 1.0 &sect; 1.2</a> and "
            ."<a href='https://www.imsglobal.org/spec/lti/v1p3/#client_id-login-parameter'"
            .">LTI 1.3 &sect; 4.1.3</a>)<br/><br/>"
            ."Blackboard: <i>Application ID</i><br/>"
            ."Canvas: <i>LTI Key Details</i><br/>"
            ."Moodle: <i>Client ID</i>"
        ,
        "Type" => FormUI::FTYPE_TEXT,
        "Required" => true,
        "ValidateFunction" => [$Plugin, 'validateIdParameter'],
    ],
    "AuthLoginUrl" => [
        "Label" => "Auth Login URL",
        "Type" => FormUI::FTYPE_URL,
        "Help" => "URL used to begin the OpenID Connect authentication flow.<br/>"
            ."(Called the &quot;OIDC Authorization end-point&quot; in "
            ."<a href='https://www.imsglobal.org/spec/security/v1p0/#openid_connect_launch_flow'>"
            ."1EdTech Security Framework 1.0 &sect; 5.1.1</a>. See also "
            ."<a href='https://openid.net/specs/openid-connect-core-1_0.html#AuthorizationEndpoint'"
            .">OpenID Connect Core 1.0 &sect; 3.1.2</a>)<br/><br/>"
            ."(not provided by Blackboard/Canvas/Moodle, default value determined from "
            ."developer docs)",
        "Size" => 80,
        "MaxLength" => 1024,
        "Required" => true,
    ],
    "AuthTokenUrl" => [
        "Label" => "Auth Token URL",
        "Help" =>
            "URL used to obtain an access token after authentication has completed.<br/>"
            ."(See <a href='https://openid.net/specs/openid-connect-core-1_0.html#TokenEndpoint'"
            .">OpenID Connect Core 1.0 &sect; 3.1.3</a>)<br/><br/>"
            ."(not provided by Blackboard/Canvas/Moodle, default value determined from "
            ."developer docs)",
        "Type" => FormUI::FTYPE_URL,
        "Size" => 80,
        "MaxLength" => 1024,
        "Required" => true,
    ],
    "KeySetUrl" => [
        "Label" => "Key Set URL",
        "Help" => "URL providing the LMS's public key in JSON Web Key Set format.<br/>"
            ."(See <a href='https://www.imsglobal.org/spec/security/v1p0/#h_key-set-url'"
            .">1EdTech Security Framework 1.0 &sect; 6.3</a>)<br/><br/>"
            ."(not provided by Blackboard/Canvas/Moodle, default value determined from "
            ."developer docs)",
        "Type" => FormUI::FTYPE_URL,
        "Size" => 80,
        "MaxLength" => 1024,
        "Required" => true,
    ],
];

$H_FormUI = new FormUI(
    $FormFields,
    $Values
);

$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Save":
        /* fall through */
    case "Add":
        if ($H_FormUI->validateFieldInput()) {
            return;
        }

        $NewValues = array_map(
            function ($x) {
                return is_string($x) ? $x : $x;
            },
            $H_FormUI->getNewValuesFromForm()
        );

        if ($ButtonPushed == "Add") {
            LMSRegistration::create($NewValues);
        } else {
            if (!isset($Registration)) {
                throw new Exception(
                    "Registration not set when trying to save "
                    ."new parameters (should be impossible)."
                );
            }

            $Registration->setContactEmail($NewValues["ContactEmail"]);
            $Registration->setLms($NewValues["LMS"]);
            $Registration->setIssuer($NewValues["Issuer"]);
            $Registration->setClientId($NewValues["ClientId"]);
            $Registration->setAuthLoginUrl($NewValues["AuthLoginUrl"]);
            $Registration->setAuthTokenUrl($NewValues["AuthTokenUrl"]);
            $Registration->setKeySetUrl($NewValues["KeySetUrl"]);
            $Registration->setSearchParameters($NewValues["SearchParameters"]);
        }
        /* fall through */

    case "Cancel":
        $AF->setJumpToPage(
            "P_EduLink_ListRegistrations"
        );
        break;

    default:
        break;
}
