<?PHP

global $AF;

use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

$AF->SuppressHTMLOutput();
$AF->DoNotCacheCurrentPage();
ApplicationFramework::ReachedViaAjax(true);

# record that this IP loaded the canary
$DB = new Database();

# record in the database that the canary was shown
$DB->Query(
    "INSERT INTO BotDetector_CanaryData (IPAddress, CanaryLastShown, CanaryLastLoaded) "
    ."VALUES (INET_ATON('".addslashes($_SERVER["REMOTE_ADDR"])
    ."'), NOW(), NOW()) "
    ." ON DUPLICATE KEY UPDATE CanaryLastLoaded=NOW()"
);

# browser caches can store this for up to 30m, but public caches should not
header("Cache-Control: private, max-age=1800");

if (isset($_GET["JS"])) {
    header('Content-Type: text/javascript');
} else {
    header('Content-Type: text/css');
}

print "/* Canary */";
