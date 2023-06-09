<?PHP
#
#   FILE: Redirect.php (CleanURLs plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

global $H_CleanUrl;

use ScoutLib\ApplicationFramework;

$GLOBALS["AF"]->SuppressHTMLOutput();

header($_SERVER["SERVER_PROTOCOL"]." 301 Moved Permanently");

# if relative url given, convert to absolute URL to comply with RFC2616
# (yes, RFC7231 relaxes this constraint, but we'd rather be
#   conservative about what we expect browsers to understand)
if (!preg_match('%^https?://%', $H_CleanUrl)) {
    $H_CleanUrl = ApplicationFramework::BasePath().$H_CleanUrl;
}

header("Location: ".$H_CleanUrl);
