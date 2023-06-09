<?PHP
#
#   FILE:  CollectionReports.php (MetricsReporter plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\MetricsReporter;
use Metavus\InterfaceConfiguration;

# ----- MAIN -----------------------------------------------------------------

PageTitle("User Statistics");

# make sure user has sufficient permission to view report
if (!CheckAuthorization(PRIV_COLLECTIONADMIN)) {
    return;
}

# grab ahold of the relevant metrics objects
$Recorder = $GLOBALS["G_PluginManager"]->GetPlugin("MetricsRecorder");
$Reporter = $GLOBALS["G_PluginManager"]->GetPlugin("MetricsReporter");

# regular users vs time
$H_RegUserCount = [];
foreach ($Recorder->GetSampleData(
    MetricsRecorder::ST_REGUSERCOUNT
) as $SampleDate => $SampleValue) {
    $TS = strtotime(date("Y-m-d", strtotime($SampleDate)));
    $H_RegUserCount[$TS] = $SampleValue;
}
ksort($H_RegUserCount);

# privileged users vs time
$H_PrivUserCount = [];
foreach ($Recorder->GetSampleData(
    MetricsRecorder::ST_PRIVUSERCOUNT
) as $SampleDate => $SampleValue) {
    $TS = strtotime(date("Y-m-d", strtotime($SampleDate)));
    $H_PrivUserCount[$TS] = $SampleValue;
}
ksort($H_PrivUserCount);

# new users per day
$H_NewUsersPerDay   = [];
foreach ($Recorder->GetSampleData(
    MetricsRecorder::ST_DAILYNEWACCTS
) as $SampleDate => $SampleValue) {
    # discard the 'time' part of the timestamp
    $SampleTS = strtotime($SampleDate);
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
foreach ($Recorder->GetSampleData(
    MetricsRecorder::ST_DAILYLOGINCOUNT
) as $SampleDate => $SampleValue) {
    # discard the 'time' part of the timestamp
    $SampleTS = strtotime($SampleDate);
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
    $GLOBALS["AF"]->SuppressHTMLOutput();
    header("Content-Type: application/json; charset="
           .InterfaceConfiguration::getInstance()->getString("DefaultCharacterSet"), true);

    print json_encode(
        [
            "RegisteredUsers" => MetricsReporter::FormatDateKeys($H_RegUserCount),
            "PrivilegedUsers" => MetricsReporter::FormatDateKeys($H_PrivUserCount),
            "NewUsers" => MetricsReporter::FormatDateKeys($H_NewUsersPerDay),
            "Logins" => MetricsReporter::FormatDateKeys($H_LoginsPerDay)
        ]
    );
    return;
}
