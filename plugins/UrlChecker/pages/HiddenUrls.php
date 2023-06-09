<?PHP
#
#   FILE:  HiddenUrls.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

#   FUNCTIONS PROVIDED:
#       PrintInvalidResourceUrls($InvalidUrls)
#           - Print the data values for each invalid URL
#       PrintStatusCodeOptions($Selected=-1)
#           - Print the status codes as options for limiting the results displayed
#
#   FUNCTIONS EXPECTED:
#       PrintInvalidResourceUrl($Values)
#           - Print out a single invalid resource URL's data
#       PrintStatusCodeOption($StatusCode, $Count, $IsSelected)
#           - Print out the status code option
#

use Metavus\MetadataSchema;
use Metavus\Plugins\UrlChecker\Constraint;
use Metavus\Plugins\UrlChecker\ConstraintList;
use Metavus\Plugins\UrlChecker\InvalidUrl;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
* Print the data values for each invalid URL.
* @param array $InvalidUrls Data values for the invalid URLs
*/
function PrintInvalidResourceUrls($InvalidUrls)
{
    foreach ($InvalidUrls as $Values) {
        PrintInvalidResourceUrl($Values);
    }
}

/**
* Print list of options for status codes.
* @param int $Selected Status code that should be selected
*/
function PrintStatusCodeOptions($Selected = -1)
{
    global $Info;
    foreach (GenerateStatusCodeGroups(
        $Info["HiddenInvalidUrlsForStatusCodes"]
    ) as $StatusCodeText => $Count) {
        PrintStatusCodeOption($StatusCodeText, $Count, $Selected === $StatusCodeText);
    }
}

/**
* Map a status code to its descriptive text form.
* @param int $StatusCode Status code
* @return string Status code description.
*/
function StatusCodeToText($StatusCode)
{
    $StatusString = strval($StatusCode);

    if ($StatusCode == 404) {
        return "Page Not Found";
    } elseif ($StatusString[0] == "3") {
        return "Redirection";
    } elseif ($StatusCode == 401 || $StatusCode == 403) {
        return "Permission Denied";
    } elseif ($StatusString[0] == "4") {
        return "Client Error";
    } elseif ($StatusString[0] == "5") {
        return "Server Error";
    } elseif ($StatusString[0] == "0") {
        return "Could Not Connect";
    } elseif ($StatusString[0] == "2") {
        return "Page Not Found";
    } elseif ($StatusString[0] == "1") {
        return "Information";
    } else {
        return "Unknown";
    }
}

/**
* Map a status code to its long descriptive text form.
* @param string $StatusString Status code
* @return string Status code description.
 */
function StatusCodeToLongText($StatusString)
{
    if ($StatusString == "Page Not Found") {
        // or if they're 200s and have a certain phrase in them
        return "The web servers hosting these URLs respond with an HTTP status"
            ." code of 404.";
    } elseif ($StatusString == "Redirection") {
        return "The web servers hosting these URLs respond with an HTTP status"
            ." code of 3xx.";
    } elseif ($StatusString == "Permission Denied") {
        return "The web servers hosting these URLs respond with an HTTP status"
            ." code of 401 or 403.";
    } elseif ($StatusString == "Client Error") {
        return "The web servers hosting these URLs respond with an HTTP status"
            ." code of 4xx, excluding 401, 403, and 404.";
    } elseif ($StatusString == "Server Error") {
        return "The web servers hosting these URLs respond with an HTTP status"
            ." code of 5xx.";
    } elseif ($StatusString == "Could Not Connect") {
        return "A connection could not be made to the web servers hosting"
            ." these URLs.";
    } elseif ($StatusString == "Information") {
        return "The web servers hosting these URLs respond with an HTTP status"
            ." code of 1xx.";
    } else {
        return "";
    }
}

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Create a group of status code descriptions from an array of status codes.
* @param array $StatusCodes Status codes
* @return array Status code descriptions.
*/
function GenerateStatusCodeGroups($StatusCodes)
{
    $Groups = [];

    foreach ($StatusCodes as $StatusCode => $Count) {
        $StatusAsText = StatusCodeToText($StatusCode);

        if (!isset($Groups[$StatusAsText])) {
            $Groups[$StatusAsText] = $Count;
        } else {
            $Groups[$StatusAsText] += $Count;
        }
    }

    return $Groups;
}

# ----- MAIN -----------------------------------------------------------------

# non-standard globals
global $Limit;
global $Offset;
global $StatusCode;
global $FieldId;
global $InvalidUrls;
global $Info;
global $InvalidCount;
global $UrlFields;
global $NumUrlFields;
global $PageNumber;
global $NumPages;
global $TitleField;

$AF = ApplicationFramework::getInstance();
$MyPlugin = PluginManager::getInstance()->getPluginForCurrentPage();

# setup
PageTitle("URL Checker Hidden URLs");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);
$Schema = new MetadataSchema();
$TitleField = $Schema->GetFieldByMappedName("Title");

# values that don't depend on URL checker info
$UrlFields = $Schema->GetFields(MetadataSchema::MDFTYPE_URL);
$NumUrlFields = count($UrlFields);

# limits
$OrderBy = (isset($_SESSION["P_UrlChecker_OrderBy"]))
        ? $_SESSION["P_UrlChecker_OrderBy"] : "StatusCode";
$OrderDirection = (isset($_SESSION["P_UrlChecker_OrderDirection"]))
        ? $_SESSION["P_UrlChecker_OrderDirection"] : "ASC";
$Limit = (isset($_SESSION["P_UrlChecker_Limit"]))
        ? intval($_SESSION["P_UrlChecker_Limit"]) : 15;
$Offset = (isset($_SESSION["P_UrlChecker_Offset"]))
        ? $_SESSION["P_UrlChecker_Offset"] : 0;
$StatusCode = (isset($_SESSION["P_UrlChecker_StatusCode"]) &&
        strlen($_SESSION["P_UrlChecker_StatusCode"]) &&
        $_SESSION["P_UrlChecker_StatusCode"] != "All")
        ? $_SESSION["P_UrlChecker_StatusCode"] : null;
$FieldId = (isset($_SESSION["P_UrlChecker_FieldId"]))
        ? $_SESSION["P_UrlChecker_FieldId"] : null;
$Constraints = [];
$Options = [];

# don't show hidden URLs
$DefaultConstraintList = new ConstraintList();
$DefaultConstraintList->AddConstraint(new Constraint("Hidden", 1, "="));

# update limits if form values exist
$OrderBy = (isset($_GET["OrderBy"])) ? $_GET["OrderBy"] : $OrderBy;
$OrderDirection = (isset($_GET["OrderDirection"])) ?
        $_GET["OrderDirection"] : $OrderDirection;
$Limit = (isset($_GET["Limit"])) ? intval($_GET["Limit"]) : $Limit;
$Offset = (isset($_GET["Page"])) ? (intval($_GET["Page"]) - 1) * $Limit : $Offset;
$StatusCode = (isset($_GET["StatusCode"])) ? $_GET["StatusCode"] : $StatusCode;
$FieldId = (isset($_GET["FieldId"])) ? $_GET["FieldId"] : $FieldId;

# reset the ordering if only within one type of invalid URLs and if ordering
# by status, since it would be useless
if (!is_null($StatusCode) && $StatusCode != "All" && $OrderBy == "StatusCode") {
    $OrderBy = "CheckDate";
}

# reset the offset if the limit has changed or if it's below 0
if ((isset($_SESSION["P_UrlChecker_Limit"])
    && $Limit != $_SESSION["P_UrlChecker_Limit"])
    || (isset($_SESSION["P_UrlChecker_StatusCode"])
    && $StatusCode != $_SESSION["P_UrlChecker_StatusCode"])
    || $Offset < 0) {
    $Offset = 0;
}

if (!is_null($FieldId) && strlen($FieldId) > 0) {
    $DefaultConstraintList->AddConstraint(
        new Constraint("FieldId", $FieldId, "=")
    );
}

# constraints, if they exist (others are added below)
if ($StatusCode !== false && strval($StatusCode) != "") {
    $PrimaryConstraintList = clone $DefaultConstraintList;
    $Constraints[] = $PrimaryConstraintList;

    switch ($StatusCode) {
        case "Could Not Connect":
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 0, "=")
            );
            break;
        case "Information":
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 99, ">")
            );
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 200, "<")
            );
            break;
        case "Redirection":
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 299, ">")
            );
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 400, "<")
            );
            break;
        case "Client Error":
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 399, ">")
            );
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 500, "<")
            );
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 401, "!=")
            );
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 403, "!=")
            );
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 404, "!=")
            );
            break;
        case "Server Error":
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 499, ">")
            );
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 600, "<")
            );
            break;
        case "Permission Denied":
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 401, "=")
            );

            # also need to lump in 403 with permission denied
            $SecondaryConstraintList = clone $DefaultConstraintList;
            $Constraints[] = $SecondaryConstraintList;

            $SecondaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 403, "=")
            );
            break;
        case "Page Not Found":
            $PrimaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 404, "=")
            );

            # also need to lump in 200s with permission denied
            $SecondaryConstraintList = clone $DefaultConstraintList;
            $Constraints[] = $SecondaryConstraintList;

            $SecondaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 199, ">")
            );
            $SecondaryConstraintList->AddConstraint(
                new Constraint("StatusCode", 300, "<")
            );
            break;
    }
} else {
    $PrimaryConstraintList = clone $DefaultConstraintList;
    $Constraints[] = $PrimaryConstraintList;
}

# finally save the limits for next time
$_SESSION["P_UrlChecker_OrderBy"] = $OrderBy;
$_SESSION["P_UrlChecker_OrderDirection"] = $OrderDirection;
$_SESSION["P_UrlChecker_Limit"] = $Limit;
$_SESSION["P_UrlChecker_Offset"] = $Offset;
$_SESSION["P_UrlChecker_StatusCode"] = $StatusCode;
$_SESSION["P_UrlChecker_FieldId"] = $FieldId;

# if ordering by a resource field, we need to pass a MetadataField object
if ($OrderBy == "Title") {
    $OrderBy = $TitleField;
}

# invalid urls
$InvalidUrls = $MyPlugin->GetInvalidUrls(
    $Constraints,
    $OrderBy,
    $OrderDirection,
    $Limit,
    $Offset,
    $Options
);
$InvalidCount = $MyPlugin->GetInvalidCount(
    $Constraints
);


# info
$Info = $MyPlugin->GetInformation();
$PageNumber = ($Limit > 0 && $Offset > 0) ? ceil($Offset / $Limit) + 1 : 1;
$NumPages = ($Limit > 0) ? ceil($InvalidCount / $Limit) : 1;

# set the offset to its max if it's greater than it
if ($Offset != 0 && $Offset > ($NumPages - 1) * $Limit && $Info["NumInvalid"] > 0) {
    $_SESSION["P_UrlChecker_Offset"] = ($NumPages > 1) ? ($NumPages - 1) * $Limit : 0;
    $AF->suppressHTMLOutput();
    $AF->setJumpToPage("index.php?P=P_UrlChecker_HiddenUrls");
    return;
}

# if given GET data, then refresh the page to avoid the "are you sure you want
# to resend..." message
if (count($_GET) > 1) {
    $AF->suppressHTMLOutput();
    $AF->setJumpToPage("index.php?P=P_UrlChecker_HiddenUrls");
    return;
}
