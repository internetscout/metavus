<?PHP
#
#   FILE:  LeaveFeedback.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;
use ScoutLib\Email;
use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
 * Get the remote address of the user agent. Attempts to get the host name if
 * possible.
 * @return string Remote address.
 */
function GetRemoteAddress()
{
    $Host = gethostbyaddr($_SERVER["REMOTE_ADDR"]);

    return $Host !== false ? $Host : $_SERVER["REMOTE_ADDR"];
}

/**
 * Get the user name or an appropriate string if the user is not logged in.
 * @param User $User User.
 * @return string User name or appropriate string if not logged in.
 */
function GetUserName(User $User)
{
    return $User->isLoggedIn() ? $User->get("UserName") : "(not logged in)";
}

/**
 * Get the real name or an appropriate string if the user is not logged in.
 * @param User $User User.
 * @return string Real name or appropriate string if not logged in.
 */
function GetUserRealName(User $User)
{
    if ($User->isLoggedIn()) {
        $RealName = $User->get("RealName");
        if ($RealName === null || strlen($RealName) == 0) {
            return "(not set)";
        }
        $TrimmedRealName = trim($User->get("RealName"));
        return $TrimmedRealName ? $TrimmedRealName : "(not set)";
    }

    return "(not logged in)";
}

/**
 * Get the best e-mail address available to use as the sender.
 * @param User $User User.
 * @param string $Secondary A secondary e-mail address to try.
 * @return string The best e-mail address available to use as the sender.
 */
function GetSenderEmail(User $User, $Secondary = null)
{
    $IntConfig = InterfaceConfiguration::getInstance();

    # assemble e-mail address for user
    if ($User->isLoggedIn()) {
        # try to use the user's real name
        $RealName = $User->get("RealName");
        if ($RealName !== null && strlen($RealName) > 0 && trim($RealName)) {
            $Name = trim($RealName);

        # use the user name if the real name is blank
        } else {
            $Name = $User->get("UserName");
        }

        $Email = $User->get("EMail");
        # use the secondary address
    } elseif ($Secondary && User::isValidLookingEmailAddress($Secondary)) {
        $Name = $IntConfig->getString("PortalName")." User";
        $Email = $Secondary;
        # just use the admin's e-mail address
    } else {
        $Name = "Anonymous ".$IntConfig->getString("PortalName")." User";
        $Email = $IntConfig->getString("AdminEmail");
    }

    return trim($Name) . " <" . trim($Email) . ">";
}

/**
 * Get the header for the page based on the feedback type, optionally passing in
 * additional headers or headers used to override the defaults.
 * @param string $FeedbackType The type of feedback.
 * @param array $AdditionalHeaders Additional or overriding headers.
 * @return string the appropriate page header
 */
function GetPageHeader($FeedbackType, array $AdditionalHeaders = array())
{
    $Headers = $AdditionalHeaders + array(
        "ResourceSuggestion" => "Suggest a Resource",
        "ResourceFeedback" => "Resource Feedback"
    );

    return StdLib::getArrayValue($Headers, $FeedbackType, "Leave Feedback");
}

/**
 * Get the subheader for the page based on the feedback type
 * @param string $FeedbackType
 * @return string Subheader to display on page
 */
function getSubheader($FeedbackType)
{
    $DefaultSubHeader = "<p>To send feedback or comments, enter your ".
        "message below and click on <i>Send Feedback</i>.</p>";

    $SubHeaders = [
        "ResourceSuggestion" =>
            "<p>We are always looking for suggestions for useful new resources! ".
            "If you know of an online resource that you think that others might ".
            "find of interest, please tell us about it below.</p>",
        "ResourceFeedback" => "<p>If you know about a problem with one of our ".
            "resource records, we want to know about it!</p>"
    ];

    return StdLib::getArrayValue($SubHeaders, $FeedbackType, $DefaultSubHeader);
}

/**
 * Get the message to display when feedback has been sent (dependent on feedback type)
 * @param string $FeedbackType Type of feedback to get message for
 * @return string Message to display to user when feedback has been sent
 */
function getSentMessage($FeedbackType)
{
    $DefaultSentMessage = "<p>Your feedback has been recorded. Thank you for your comments!</p>";

    $SentMessages = [
        "ResourceSuggestion" => "<p>Your submission has been recorded. "
            ."Thank you for your suggestion!</p>",
        "ResourceFeedback" => "<p>Your feedback has been recorded. Thank you for your help!</p>"
    ];

    return StdLib::getArrayValue($SentMessages, $FeedbackType, $DefaultSentMessage);
}

# ----- MAIN -----------------------------------------------------------------
# request that this page not be indexed by search engines
$AF = ApplicationFramework::getInstance();
$AF->addMetaTag(["robots" => "noindex"]);

# retrieve user currently logged in
$User = User::getCurrentUser();

$H_FeedbackType = StdLib::getArrayValue(
    $_GET,
    "FT",
    StdLib::getFormValue("F_FeedbackType", "Feedback")
);
$ParameterOne = StdLib::getArrayValue($_GET, "P1", StdLib::getFormValue("F_RecordId", null));
$ParameterTwo = StdLib::getArrayValue($_GET, "P2");
$ParameterThree = StdLib::getArrayValue($_GET, "P3");
$H_ReturnTo = StdLib::getFormValue("F_ReturnTo", StdLib::getFormValue(
    "ReturnTo",
    StdLib::getArrayValue($_SERVER, "HTTP_REFERER")
));
$H_FeedbackSent = false;
$H_FormErrors = array();
$IntConfig = InterfaceConfiguration::getInstance();
$EmailRequired = $IntConfig->getBool("RequireEmailWithFeedback");

# go to the home page after form submission if the redirect URL is blank or
# appears malicious
if (!$H_ReturnTo || !IsSafeRedirectUrl($H_ReturnTo)) {
    $H_ReturnTo = "index.php?P=Home";
}

# set the page title based on the feedback type
$H_PageHeader = GetPageHeader($H_FeedbackType);

$H_SentMessage = getSentMessage($H_FeedbackType);
$H_Subheader = getSubheader($H_FeedbackType);

$Fields = [];
$DescriptionLabel = "Description";
$H_InvalidRecord = false;

if ($H_FeedbackType == "ResourceFeedback") {
    # check if record exists for RecordID specified in parameter 1, return with error if not found
    if (!Record::itemExists($ParameterOne)) {
        $H_InvalidRecord = true;
        return;
    }

    # construct the record from ParameterOne
    $Record = new Record($ParameterOne);
    if ($Record->getSchemaId() == MetadataSchema::SCHEMAID_USER) {
        $H_InvalidRecord = true;
        return;
    }

    $SafeResourceId = defaulthtmlentities($ParameterOne);
    $TitleField = $Record->getSchema()->getFieldByMappedName("Title");

    if ($TitleField === null) {
        $CanViewTitleField = false;
        $SafeResourceTitle = "";
    } else {
        $CanViewTitleField = $Record->userCanViewField($User, $TitleField);
        $SafeResourceTitle = defaulthtmlentities($Record->get($TitleField));
    }

    $UrlField = $Record->getSchema()->getFieldByMappedName("Url");
    if ($UrlField === null) {
        $CanViewUrlField = false;
        $SafeResourceUrl = "";
    } else {
        $CanViewUrlField = $Record->userCanViewField($User, $UrlField);
        $SafeResourceUrl = defaulthtmlentities($Record->get($UrlField));
    }

    # construct fields as necessary, according to User privileges
    if ($CanViewTitleField && $SafeResourceTitle) {
        $Fields["Resource"] = [
            "Label" => "Resource",
            "Type" => FormUI::FTYPE_CUSTOMCONTENT,
            "ReadOnly" => true,
            "Content" => "<a href=\"index.php?P=FullRecord&ID=".$SafeResourceId.
                "\" target=\"_blank\">".$SafeResourceTitle."</a>",
        ];
    }

    if ($CanViewUrlField && $SafeResourceUrl) {
        $Fields["URL"] = [
            "Label" => "URL",
            "Type" => FormUI::FTYPE_CUSTOMCONTENT,
            "ReadOnly" => true,
            "Content" => "<a href=\"".$SafeResourceUrl
                ."\" target=\"_blank\">".$SafeResourceUrl."</a>",
        ];
    }

    $DescriptionLabel = "Problem Description";
} elseif ($H_FeedbackType == "ResourceSuggestion") {
    $Fields += [
        "Title" =>  [
            "Label" => "Resource Name or Title",
            "Type" => FormUI::FTYPE_TEXT,
            "Required" => true,
        ],
        "URL" => [
            "Label" => "Resource URL",
            "Type" => FormUI::FTYPE_URL,
            "Required" => true,
        ]
    ];

    $DescriptionLabel = "Resource Description";
} else {
    $Fields["Subject"] = [
        "Label" => "Subject",
        "Type" => FormUI::FTYPE_TEXT,
        "Required" => true,
    ];

    $DescriptionLabel = "Message";
}

# Add the description field, which will be here regardless of feedback type
$Fields["Description"] = [
    "Label" => $DescriptionLabel,
    "Type" => FormUI::FTYPE_PARAGRAPH,
    "Required" => true,
];

if (!$User->isLoggedIn()) {
    $Fields["Email"] = [
        "Label" => "Your E-mail Address",
        "Type" => FormUI::FTYPE_TEXT,
        "ValidateFunction" => ["Metavus\\FormUI", "ValidateEmail"],
        "Help" => "(if you would like a response)",
        "Required" => $EmailRequired,
    ];
}

$Fields["Verification"] = [
    "Label" => "Verification Code",
    "Type" => FormUI::FTYPE_CAPTCHA,
];

$H_FormUI = new FormUI($Fields);
$H_FormUI->addHiddenField("F_Spambot", "");
$H_FormUI->addHiddenField("F_FeedbackType", $H_FeedbackType);
$H_FormUI->addHiddenField("F_ReturnTo", $H_ReturnTo);

if ($H_FeedbackType == "ResourceFeedback") {
    $H_FormUI->addHiddenField("F_RecordId", $ParameterOne);
}

$ButtonPushed = StdLib::getFormValue("Submit", false);
if ($ButtonPushed) {
    switch ($ButtonPushed) {
        case "Submit Feedback":
            if ($H_FormUI->validateFieldInput()) {
                return;
            }

            # start out with blank subject and body
            $Subject = "";
            $Body = "";

            $FormValues = $H_FormUI->getNewValuesFromForm();

            # new resource suggestion
            if ($H_FeedbackType == "ResourceSuggestion") {
                $Subject .= "New Resource Suggestion";
                $Body .= "Title: " . trim($FormValues["Title"]) . "\n";
                $Body .= "URL: " . trim($FormValues["URL"]) . "\n";
                $Body .= "Description: ".trim(
                    $FormValues["Description"]
                ) . "\n";
            } elseif ($H_FeedbackType == "ResourceFeedback") {
                # resource feedback
                # if resource is invalid just redirect
                $RecordId = StdLib::getFormValue("F_RecordId");

                if (!Record::itemExists($RecordId)) {
                    $H_InvalidRecord = true;
                }
                if (($RecordId === null || !Record::itemExists($RecordId))) {
                    $AF->setJumpToPage($H_ReturnTo);
                    return;
                }

                $Record = new Record($RecordId);

                $Subject .= "Resource Feedback";
                $Body .= "Title: " . trim($Record->getMapped("Title")) . "\n";
                $Body .= "URL: " . trim($Record->getMapped("Url")) . "\n";
                $Body .= "View: ".ApplicationFramework::baseUrl()."?P=FullRecord&ID="
                    .$Record->id() . "\n";
                $Body .= "Edit: ".ApplicationFramework::baseUrl()
                    ."?P=EditResource&ID=".$Record->id()."\n";
                $Body .= "Problem Description: "
                    .trim($FormValues["Description"])."\n";
            } else {
                # generic feedback
                $Subject .= trim($FormValues["Subject"]);
                $Body .= "Message: ".trim($FormValues["Description"]);
            }

            # add portal name to the subject
            $Subject = "[".$IntConfig->getString("PortalName")."] ".$Subject;

            $Email = $User->isLoggedIn() ? $User->get("EMail") : $FormValues["Email"];

            # append context info to the mail body
            $Body .= "\n";
            $Body .= "User: " . GetUserName($User) . "\n";
            $Body .= "Name: " . GetUserRealName($User) . "\n";
            $Body .= "E-mail: " . $Email . "\n";
            $Body .= "Address: " . GetRemoteAddress() . "\n";

            # get final information necessary to send the message
            $Msg = new Email();
            $Msg->to($IntConfig->getString("AdminEmail"));
            $Sender = GetSenderEmail(
                $User,
                $FormValues["Email"] ?? null
            );
            $Msg->from($Sender);

            # send the e-mail message
            $Msg->subject($Subject);
            $Msg->body($Body);
            $Msg->send();

            # signal that the feedback has been lodged
            $H_FeedbackSent = true;
            break;
        default:
            $AF->setJumpToPage($H_ReturnTo);
            return;
    }
}
