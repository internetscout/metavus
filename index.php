<?PHP
#
#   FILE:  index.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
use Metavus\Bootloader;

# if we are in maintenance mode
if (file_exists(".maintenance")) {
    # display maintenance page and exit
    header("HTTP/1.1 503 Service Unavailable");
    $MaintFile = "interface/default/MaintenanceMode.html";
    if (is_readable("local/".$MaintFile)) {
        include("local/".$MaintFile);
    } elseif (is_readable($MaintFile)) {
        include($MaintFile);
    } else {
        print "Briefly unavailable for scheduled maintenance."
                ."  Please check back in a minute.";
    }
    exit(0);
}

# make sure that output buffering is initially turned off
if (ob_get_level()) {
    ob_end_clean();
}

# if it appears that the software has not yet been installed
if (((!file_exists("local/config.php") && !file_exists("config.php"))
     || file_exists("NEWVERSION")) && file_exists("installmv.php")) {
    # jump to installation
    ?><html>
    <head><meta http-equiv="refresh" content="0; URL=installmv.php"></head>
    <body bgcolor="white"></body>
    </html><?PHP
    exit();
}

# if on plugin configuration page
$PluginCfgPages = [
    "Plugins",
    "PluginConfig",
    "PluginUninstall",
];

if (array_key_exists("P", $_GET) && in_array($_GET["P"], $PluginCfgPages)) {
    # ensure that plugin configurations are all loaded
    $GLOBALS["StartUpOpt_FORCE_PLUGIN_CONFIG_LOAD"] = true;
}

# set up operating environment
require_once("objects/Bootloader.php");
Bootloader::getInstance()->boot();

# default to 404 error page
# (this may be ignored by AF if the page was loaded via a clean URL)
$Page = "404";

# construct regex to match the index page
$BasePathRegex = preg_quote(ApplicationFramework::basePath(), "%");
$IndexPattern = "%^".$BasePathRegex ."(|index\.php|index\.php\?.+|\?.+)$%";

# if this is a request for the index page
if (preg_match($IndexPattern, $_SERVER["REQUEST_URI"])) {
    if (count($_GET) == 0) {
        # when no params provided, use the home page
        $Page = "Home";
    } elseif (isset($_GET["P"]) && strlen($_GET["P"])) {
        # when page is provided as a parameter, use that
        $Page = $_GET["P"];
    }
}

# add any configured site keywords to page
$IntConfig = InterfaceConfiguration::getInstance();
$SiteKeywords = trim($IntConfig->getString("SiteKeywords"));
$AF = ApplicationFramework::getInstance();
if (strlen($SiteKeywords)) {
    $AF->addMetaTagOnce([
        "name" => "keywords",
        "content" => $SiteKeywords,
    ]);
}

# retrieve user currently logged in
$User = User::getCurrentUser();

# if we have a user logged in
$IsLoggedIn = $User->isLoggedIn();
if ($IsLoggedIn && !ApplicationFramework::reachedViaAjax()) {
    # mark session as in use so that it is not cleaned up prematurely
    $AF->sessionInUse(true);
}

# tell application framework to load page
$AF->loadPage($Page);

# if we have a user logged in
if ($IsLoggedIn && !ApplicationFramework::reachedViaAjax()) {
    # update user location
    $User->lastLocation($AF->getPageName());
}
