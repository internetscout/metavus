<?PHP
#
#   FILE:  CollectionReports.php (MetricsReporter plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\InterfaceConfiguration;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\MetricsReporter;
use Metavus\User;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

$AF->setPageTitle("User Statistics");

# make sure user has sufficient permission to view report
if (!User::requirePrivilege(PRIV_COLLECTIONADMIN)) {
    return;
}

# grab ahold of the relevant metrics objects
$Recorder = MetricsRecorder::getInstance();
$Reporter = MetricsReporter::getInstance();

# regular users vs time
$H_RegUserCount = [];
foreach ($Recorder->getSampleData(
    MetricsRecorder::ST_REGUSERCOUNT
) as $SampleDate => $SampleValue) {
    $SampleDateAsTime = strtotime($SampleDate);
    if ($SampleDateAsTime === false) {
        continue;
    }
    $TS = strtotime(date("Y-m-d", $SampleDateAsTime));
    $H_RegUserCount[$TS] = $SampleValue;
}
ksort($H_RegUserCount);

# privileged users vs time
$H_PrivUserCount = [];
foreach ($Recorder->getSampleData(
    MetricsRecorder::ST_PRIVUSERCOUNT
) as $SampleDate => $SampleValue) {
    $SampleDateAsTime = strtotime($SampleDate);
    if ($SampleDateAsTime === false) {
        continue;
    }
    $TS = strtotime(date("Y-m-d", $SampleDateAsTime));
    $H_PrivUserCount[$TS] = $SampleValue;
}
ksort($H_PrivUserCount);

# new users per day
$H_NewUsersPerDay   = [];
foreach ($Recorder->getSampleData(
    MetricsRecorder::ST_DAILYNEWACCTS
) as $SampleDate => $SampleValue) {
    # discard the 'time' part of the timestamp
    $SampleTS = strtotime($SampleDate);
    if ($SampleTS === false) {
        continue;
    }
    $DayTS  = strtotime(date("Y-m-d", $SampleTS));

    # make sure we haven't seen this day already,
    # don't record stats when we had zero things,
    # and make sure that the value recorded is sane
    if (isset($H_NewUsersPerDay[$DayTS]) ||
        $SampleValue == 0 ||
        $SampleValue > 1000) {
        continue;
    }

    # compute summaries
    $H_NewUsersPerDay[$DayTS] = $SampleValue;
}

# number of logins per (day/week/mo) bar charts
$H_LoginsPerDay = [];
foreach ($Recorder->getSampleData(
    MetricsRecorder::ST_DAILYLOGINCOUNT
) as $SampleDate => $SampleValue) {
    # discard the 'time' part of the timestamp
    $SampleTS = strtotime($SampleDate);
    if ($SampleTS === false) {
        continue;
    }
    $DayTS  = strtotime(date("Y-m-d", $SampleTS));

    # Make sure we haven't seen this day before,
    #  skip stats for days with no logins,
    #  and make sure the value we're getting makes sense:
    if (isset($H_LoginsPerDay[$DayTS]) ||
        $SampleValue == 0 ||
        $SampleValue > 2000) {
        continue;
    }

    # compute metrics summaries
    $H_LoginsPerDay[$DayTS] = $SampleValue;
}

if (isset($_GET["JSON"])) {
    $AF->suppressHtmlOutput();
    header("Content-Type: application/json; charset="
           .InterfaceConfiguration::getInstance()->getString("DefaultCharacterSet"), true);

    print json_encode(
        [
            "RegisteredUsers" => MetricsReporter::formatDateKeys($H_RegUserCount),
            "PrivilegedUsers" => MetricsReporter::formatDateKeys($H_PrivUserCount),
            "NewUsers" => MetricsReporter::formatDateKeys($H_NewUsersPerDay),
            "Logins" => MetricsReporter::formatDateKeys($H_LoginsPerDay)
        ]
    );
    return;
}
