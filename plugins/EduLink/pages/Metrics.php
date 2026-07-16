<?PHP
#
#   FILE:  Metrics.php (EduLink plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\EduLink;
use Metavus\Plugins\EduLink\LMSRegistration;
use Metavus\Plugins\EduLink\LMSRegistrationFactory;
use ScoutLib\ApplicationFramework;

/**
 * Get a string that describes the "context" of an LTI launch, typically an
 *         URL for the LMS, a 'Platform' name for the LMS, and some identifier
 *         (usually a course) describes where the view happened. Specific
 *         values are highly LMS specific.
 * @param array $Launch LTI Launch data corresponding to the event.
 * @return string Context description.
 */
function getContext(array $Launch): string
{
    $ContextKey = "https://purl.imsglobal.org/spec/lti/claim/context";
    $PlatformKey = "https://purl.imsglobal.org/spec/lti/claim/tool_platform";

    if (isset($Launch[$ContextKey]) && isset($Launch[$PlatformKey])
            && isset($Launch[$PlatformKey]["name"])
            && isset($Launch[$PlatformKey]["name"]) != "Blackboard, Inc.") {
        return $Launch[$ContextKey]["title"]." [".$Launch[$PlatformKey]["name"]."]";
    }

    if (isset($Launch[$ContextKey])) {
        return $Launch[$ContextKey]["title"];
    }

    $RLKey = "https://purl.imsglobal.org/spec/lti/claim/resource_link";
    if (isset($Launch[$RLKey])) {
        return "Brightspace Location ".$Launch[$RLKey]["id"];
    }

    if (isset($Launch["http://www.brightspace.com"]["link_id"])) {
        return "Brightspace Course ".$Launch["http://www.brightspace.com"]["link_id"];
    }

    return $Launch["sub"];
}

/**
 * Extract LTI Launch data from the older format of data we used to store in
 *         the MetricsRecorder tables.
 * @param string $LaunchData Stored data.
 * @return array LTI Launch in the same format returned by the LTI Library's
 *     get_launch_data().
 */
function extractLegacyLaunchData($LaunchData): array
{
    $Replacements = [
        "C>" => "https://purl.imsglobal.org/spec/lti/claim/",
        "LC>" => "https://purl.imsglobal.org/spec/lti-dl/claim/",
        "Vl>" => "http://purl.imsglobal.org/vocab/lis/v2/",

    ];
    $LaunchData = str_replace(
        array_keys($Replacements),
        array_values($Replacements),
        $LaunchData
    );
    return json_decode($LaunchData, true);
}

# ----- MAIN -----------------------------------------------------------------

$EduLink = EduLink::getInstance();
User::requirePrivilege(
    ...array_merge([PRIV_SYSADMIN], $EduLink->getViewMetricsPrivs())
);

$AF = ApplicationFramework::getInstance();
$User = User::getCurrentUser();

# get plugins we'll want to use
$Recorder = MetricsRecorder::getInstance();

# helper objects we shall need
$RegistrationFactory = new LMSRegistrationFactory();

# if coming from some other page
$BaseUrl = $AF->baseUrl()."index.php?P=P_EduLink_Metrics";
if (strpos($_SERVER["HTTP_REFERER"] ?? "", $BaseUrl) !== 0) {
    # load saved settings
    $Params = $User->getSettingWithFallback("EduLink", "MetricsParams");
    if (strlen($Params)) {
        $_GET = unserialize($Params);
    }
} else {
    # otherwise, save settings
    $User->setSetting("EduLink", "MetricsParams", serialize($_GET));
}

# extract limit settings
$TypeLimit = $_GET["T"] ?? null;
$RoleLimit = $_GET["R"] ?? null;
$IPLimit = $_GET["IP"] ?? null;
$RecordIdLimit = $_GET["RI"] ?? null;
$CourseLimit = $_GET["C"] ?? null;
$InstitutionLimit = $_GET["LI"] ?? null;

$H_CourseLimitName = "";

switch ($TypeLimit) {
    case "ViewRecord":
    case "SelectRecord":
        $EventTypes = [$TypeLimit];
        break;

    default:
        $EventTypes = [
            "ViewRecord",
            "SelectRecord",
        ];
}

$EventCounts = [
    "Student-ViewRecord" => 0,
    "Student-SelectRecord" => 0,
    "Instructor-ViewRecord" => 0,
    "Instructor-SelectRecord" => 0,
];

$H_EventsPerDay = [];
$H_Report = [
    "Institutions" => [],
    "IPAddresses" => [],
    "Classes" => [],
    "Records" => [],
];

foreach ($EventTypes as $EventType) {
    # get metrics data
    $Data = $Recorder->getEventData(
        "EduLink", # owner
        $EventType, # type
        null, # start date
        null, # end date,
        null, # user id,
        $RecordIdLimit, # DataOne (which is Record Id for our event types)
    );

    # iterate over the data entries
    foreach ($Data as $Item) {
        $RecordId = $Item["DataOne"];
        $LaunchData = $Item["DataTwo"];
        $IPAddress = $Item["IPAddress"];

        if ($IPLimit !== null && $IPAddress != $IPLimit) {
            continue;
        }

        if (strpos($LaunchData, "lti1p3_launch_") === 0) {
            $LaunchData = $EduLink->getCachedLaunch($LaunchData)->get_launch_data();
        } else {
            $LaunchData = extractLegacyLaunchData($LaunchData);
        }

        # skip the 'homework_submission" placement that should not have been included
        # to begin with
        $CanvasPlacementKey  = "https://www.instructure.com/placement";
        if (isset($LaunchData[$CanvasPlacementKey]) &&
            $LaunchData[$CanvasPlacementKey] == "homework_submission") {
            continue;
        }

        # determine the view type
        $Key = "https://purl.imsglobal.org/spec/lti/claim/roles";
        $Val = "http://purl.imsglobal.org/vocab/lis/v2/institution/person#Instructor";
        $ViewType = isset($LaunchData[$Key]) && in_array($Val, $LaunchData[$Key]) ?
            "Instructor" : "Student";

        if ($RoleLimit !== null && $ViewType != $RoleLimit) {
            continue;
        }

        # pull registration parameters out of the launch data
        $Issuer = $LaunchData["iss"];
        $ClientId = $LaunchData["aud"];

        # skip data coming from LMSes that are no longer registered with us
        $RegistrationValues = [
            "Issuer" => $Issuer,
            "ClientId" => $ClientId,

        ];
        if (!LMSRegistration::registrationExists($RegistrationValues)) {
            continue;
        }

        # get the registration for this LMS
        $Registration = new LMSRegistration(
            LMSRegistration::findRegistrationId($Issuer, $ClientId)
        );
        if ($Registration->getIsInternal()) {
            continue;
        }

        if ($InstitutionLimit !== null && $Registration->id() != $InstitutionLimit) {
            continue;
        }

        # figure out a description for the context of this request
        $Context = getContext($LaunchData);
        $ReportKey = $Registration->id().": ".$Context;

        if ($CourseLimit !== null) {
            if (md5($ReportKey) != $CourseLimit) {
                continue;
            }
            $H_CourseLimitName = $Context;
        }

        $EventCounts[$ViewType."-".$EventType]++;

        $Date = strtotime(date("Y-m-d", strtotime($Item["EventDate"])));

        if ($TypeLimit === null) {
            foreach (["SelectRecord", "ViewRecord", "Total"] as $Index) {
                if (!isset($H_EventsPerDay[$Date][$Index])) {
                    $H_EventsPerDay[$Date][$Index] = 0;
                }
            }

            $H_EventsPerDay[$Date]["Total"]++;
        } else {
            if (!isset($H_EventsPerDay[$Date][$EventType])) {
                $H_EventsPerDay[$Date][$EventType] = 0;
            }
        }

        $H_EventsPerDay[$Date][$EventType]++;

        $Data = [
            "IPAddresses" => $IPAddress,
            "Classes" => $ReportKey,
            "Records" => $RecordId,
            "Institutions" => $Registration->id(),
        ];

        foreach (["IPAddresses", "Classes", "Records", "Institutions"] as $Slot) {
            $Key = $Data[$Slot];
            if (!isset($H_Report[$Slot][$Key])) {
                $H_Report[$Slot][$Key] = 0;
            }
            $H_Report[$Slot][$Key]++;
        }
    }
}

# add zeros for institutions that had no activity
if ($InstitutionLimit === null) {
    $RegistrationIds = $RegistrationFactory->getItemIds("IsInternal = 0");
    foreach ($RegistrationIds as $Id) {
        if (!isset($H_Report["Institutions"][$Id])) {
            $H_Report["Institutions"][$Id] = 0;
        }
    }
}

$H_EventSummary = [];
foreach ($EventCounts as $Key => $Val) {
    $Items = explode("-", $Key);

    if ($RoleLimit !== null && $Items[0] != $RoleLimit) {
        continue;
    }
    if ($TypeLimit !== null && $Items[1] != $TypeLimit) {
        continue;
    }

    if (!isset($H_EventSummary[$Items[0]])) {
        $H_EventSummary[$Items[0]] = [
            "Role" => $Items[0],
            "SelectRecord" => 0,
            "ViewRecord" => 0,
            "Total" => 0,
        ];
    }

    $H_EventSummary[$Items[0]][$Items[1]] += $Val;
    $H_EventSummary[$Items[0]]["Total"] += $Val;
}
