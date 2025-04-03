<?PHP
#
#   FILE:  BlackboardSetup.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\EduLink;
use Metavus\Plugins\EduLink\LMSRegistration;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;
use ScoutLib\HtmlTable;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Get configuration settings for Blackboard.
 * @return string Settings in HTML.
 */
function getBlackboardSettings() : string
{
    $Plugin = EduLink::getInstance();
    $AF = ApplicationFramework::getInstance();

    $BaseUrl = $AF->baseUrl();

    $ServiceName = $Plugin->getConfigSetting("ServiceName");
    $ServiceDescription = $Plugin->getConfigSetting("ServiceDescription");

    $HtmlTable = new HtmlTable();
    $HtmlTable->setTableClass("table table-striped");
    $HtmlTable->addRowsWithHeaders([
        ["Application Name", "<pre>".$ServiceName."</pre>"],
        ["Tool description", "<pre>".$ServiceDescription."</pre>"],
        ["Domain(s)", "<pre>".$BaseUrl."</pre>" ],
        ["Login Initiation URL", "<pre>".$BaseUrl."lti/login</pre>"],
        ["Tool Redirect URL(s)", "<pre>".$BaseUrl."lti/launch</pre>"],
        ["Tool JWKS URL", "<pre>".$BaseUrl."lti/jwks</pre>"],
        ["Signing Algorithm", "<pre>RS256</pre>"],
        ["Custom Parameters", "<i>(leave blank)</i>"],
    ]);

    return $HtmlTable->getHtml();
}


# ----- MAIN -----------------------------------------------------------------

CheckAuthorization(PRIV_SYSADMIN);

$H_Plugin = EduLink::getInstance();
$User = User::getCurrentUser();

$FormValues = [];
$FormFields = [
    "HEADER_Instructions" => [
        "Type" => FormUI::FTYPE_HEADING,
        "Label" => "Instructions",
    ],
    "Blackboard_Instructions" => [
        "Type" => FormUI::FTYPE_CUSTOMCONTENT,
        "Label" => "",
        # (use a callback so that this function can be defined in the HTML)
        "Callback" => function () {
            // @phpstan-ignore-next-line
            print getBlackboardInstructions();
        },
    ],
    "HEADER_Settings" => [
        "Type" => FormUI::FTYPE_HEADING,
        "Label" => "LMS Settings",
    ],
    "Blackboard_Settings" => [
        "Type" => FormUI::FTYPE_CUSTOMCONTENT,
        "Label" => "Enter into Blackboard",
        "Content" => getBlackboardSettings(),
    ],
];

if (is_null($H_Plugin->getBlackboardClientId())) {
    $FormFields += [
        "HEADER_Info" => [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "Information From LMS",
        ],
        "ContactEmail" => [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "Contact Email",
            "ValidateFunction" => ["\\Metavus\\FormUI", "validateEmail"],
            "Required" => true,
        ],
        "ClientId" => [
            "Label" => "Application ID",
            "Type" => FormUI::FTYPE_TEXT,
            "Required" => true,
            "ValidateFunction" => [$H_Plugin, 'validateIdParameter'],
        ],
    ];

    if ($User->isLoggedIn()) {
        $FormValues["ContactEmail"] = $User->get("EMail");
    }
}

$H_FormUI = new FormUI(
    $FormFields,
    $FormValues
);

$H_Error = null;
$H_Status = null;

$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Register":
        if ($H_FormUI->validateFieldInput()) {
            return;
        }

        $FormValues = $H_FormUI->getNewValuesFromForm();

        $NewValues = [
            "LMS" => "Blackboard",
            "ContactEmail" => $FormValues["ContactEmail"],
            "ClientId" => $FormValues["ClientId"],
            "Issuer" => "https://blackboard.com",
            "AuthLoginUrl" => "https://developer.blackboard.com/api/v1/gateway/oidcauth",
            "AuthTokenUrl" => "https://developer.blackboard.com/api/v1/gateway/oauth2/jwttoken",
            "KeySetUrl" => "https://developer.blackboard.com/api/v1/management/applications/"
                .$FormValues["ClientId"]
        ];

        if (!LMSRegistration::registrationExists($NewValues)) {
            LMSRegistration::create($NewValues);
            $H_Status = "Registration successfully created.";
        } else {
            $H_Error = "Duplicate registration.";
        }
        break;

    default:
        break;
}
