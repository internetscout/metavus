<?PHP
#
#   FILE:  LTILogin.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan
#
# Handle OpenId Connect Login requests. See docstring for EduLink plugin for a
# description of the request flow.

namespace Metavus;

use Exception;
use Metavus\Plugins\EduLink;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

# suppress the full Metavus start/end to favor the smaller, LTI-specific ones
$AF->suppressStandardPageStartAndEnd();

# cannot cache current page because the State var extracted from the launch
# will change each time
$AF->doNotCacheCurrentPage();

if ($_SERVER['REQUEST_METHOD'] == "GET" && count($_GET) == 0) {
    # message for the user about the problem
    $H_Error = "Request was not a valid LTI login "
        ."(GET with no parameters provided).";

    # log a message about it (at INFO level because it's almost certainly an
    # invalid request from the client rather than an issue we can do anything
    # about)
    $AF->logMessage(
        ApplicationFramework::LOGLVL_INFO,
        $H_Error
        ." IP: " . ($_SERVER["REMOTE_ADDR"] ?? "(unknown)")
        ." User-Agent: '".($_SERVER["HTTP_USER_AGENT"] ?? "(unknown)") ."'"
    );
    return;
}

$LaunchUrl = ApplicationFramework::baseUrl()."lti/launch";

# otherwise, we're being loaded from an LTI request; get the associated launch
$Redirect = EduLink::getInstance()
    ->getLogin()
    ->do_oidc_login_redirect($LaunchUrl);

# if the _enabled cookie is set, then we've already set up cookie perms and
# can just use normal redirect instead of any front-end JS dance
if (isset($_COOKIE["lti1p3_enabled"]) && $_COOKIE["lti1p3_enabled"] == "1") {
    $Redirect->do_redirect();
    return;
}

# otherwise continue along so that our JS can set up cookie perms
$H_RedirectUrl = $Redirect->get_redirect_url();

# extract the 'state' parameter from redirect URL
$QueryString = parse_url($H_RedirectUrl, PHP_URL_QUERY);
if (!is_string($QueryString)) {
    throw new Exception(
        "Unable to parse query from redirect url (should be impossible)."
    );
}

parse_str($QueryString, $RedirectParams);
$H_State = $RedirectParams["state"];
