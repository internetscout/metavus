<?PHP
#
#   FILE:  Canary.php (BotDetector plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Metavus\Plugins\BotDetector;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

$AF = ApplicationFramework::getInstance();
$AF->suppressHtmlOutput();
$AF->doNotCacheCurrentPage();
$AF->setBrowserCacheExpirationTime(BotDetector::CANARY_TTL);

ApplicationFramework::reachedViaAjax(true);

# record that this IP loaded the canary
$DB = new Database();
$DB->query(
    "INSERT INTO BotDetector_CanaryData (IPAddress, CanaryLastShown, CanaryLastLoaded) "
        ."VALUES (INET_ATON('".addslashes($_SERVER["REMOTE_ADDR"])
        ."'), NOW(), NOW()) "
        ." ON DUPLICATE KEY UPDATE CanaryLastLoaded=NOW()"
);

if (isset($_GET["JS"])) {
    header('Content-Type: text/javascript');
} else {
    header('Content-Type: text/css');
}

print "/* Canary */";
