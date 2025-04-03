<?PHP
#
#   FILE:  UserLogin.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\SecureLoginHelper;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;

# retrieve user currently logged in
$AF = ApplicationFramework::getInstance();
$User = User::getCurrentUser();

/**
 * This function is used to handle the result of the page.
 * Depending on whether this page was reached by AJAX or not,
 * it either sets JumpToPage or emits an AjaxMessage
 * @param string $JumpToPage The page to jump to
 * @param string $AjaxMessage The message to return through AJAX call
 */
function RespondToUser($JumpToPage, $AjaxMessage): void
{
    # print message if reached by AJAX, otherwise set the appropriate JumpToPage
    $AF = ApplicationFramework::getInstance();
    if (ApplicationFramework::reachedViaAjax()) {
        $AF->beginAjaxResponse();
        $Response = [
            "Status" => $AjaxMessage,
            "Redirect" => $JumpToPage
        ];

        print json_encode($Response);
    } else {
        $AF->setJumpToPage($JumpToPage);
    }
}

# try to log user in
$LoginResult = null;
if (isset($_POST["F_UserName"]) && isset($_POST["F_Password"])) {
    $Password = "";
    $UserName = $_POST["F_UserName"];

    # if an encrypted password was sent
    if (isset($_POST["F_CryptPassword"]) && isset($_POST["UseSecure"])) {
        $Password = SecureLoginHelper::decryptPassword(
            $UserName,
            $_POST["F_CryptPassword"]
        );
    } elseif (isset($_POST["F_HashPassword"]) && strlen($_POST["F_HashPassword"]) > 0) {
    # if a hashed password was sent (for backward compatibility with
    # the first version of secure login)
        $Password = " ".$_POST["F_HashPassword"];
    } else {
    # otherwise this is a plain text password
        $Password = $_POST["F_Password"];
    }

    # allow plugins to override authentication by a signal
    $SignalResult = $AF->SignalEvent(
        "EVENT_USER_AUTHENTICATION",
        [
            "UserName" => $UserName,
            "Password" => $Password,
        ]
    );

    # if the login was not forced to success or failure, proceed as normal
    if ($SignalResult === null) {
        $LoginResult = $User->Login($UserName, $Password);
    } elseif ($SignalResult === true) {
        # if success was forced, log the user in unconditionally
        $LoginResult = $User->Login($UserName, $Password, true);
    } else {
        # otherwise, fail the login and stop processing
        RespondToUser("LoginError", "Failed");
        return;
    }
}

# if login was successful
if ($LoginResult === User::U_OKAY) {
    # is user account disabled?
    if ($User->HasPriv(PRIV_USERDISABLED)) {
        # log user out
        $User->Logout();
        RespondToUser("LoginError", "Failed");
        return;
    }

    # signal successful user login
    $AF->SignalEvent(
        "EVENT_USER_LOGIN",
        [
            "UserId" => $User->Id(),
            // @phpstan-ignore variable.undefined
            "Password" => $Password,
        ]
    );

    # list of pages we do not want to return to
    $DoNotReturnToPages = [
        "Login",
        "UserLogin",
        "LoginError",
        "ForgottenPasswordComplete",
        "RequestAccount",
        "RequestAccountComplete",
        "ActivateAccount",
        "ResendAccountActivation",
        "ResetPassword",
        "ForgottenPasswordComplete",
        "EditResourceComplete",
    ];

    #  if referer isn't available
    if (!isset($_POST["HTTP_REFERER"])
        && !isset($_SERVER["HTTP_REFERER"])) {
        # go to front page
        $ReturnPage = "Home";
    } else {
        # if we know what internal page we are returning to
        $ReturnPage = isset($_POST["HTTP_REFERER"])
            ? $_POST["HTTP_REFERER"]
            : $_SERVER["HTTP_REFERER"];
        $UnmappedReturnPage = $AF->getUncleanRelativeUrlWithParamsForPath($ReturnPage);
        $QueryString = parse_url($UnmappedReturnPage, PHP_URL_QUERY);
        if ($QueryString !== null && $QueryString !== false) {
            parse_str((string) $QueryString, $QueryVars);
            if (isset($QueryVars["P"])) {
                # go to front page if page is on "Do Not Return To" list
                if (in_array($QueryVars["P"], $DoNotReturnToPages)) {
                    $ReturnPage = "Home";
                }
            }
        }
    }

    # give any hooked filters a chance to modify return page
    $SignalResult = $AF->SignalEvent(
        "EVENT_USER_LOGIN_RETURN",
        array("ReturnPage" => $ReturnPage)
    );
    $ReturnPage = $SignalResult["ReturnPage"];

    # set destination to return to after login
    RespondToUser($ReturnPage, "Success");
    return;
} elseif ($LoginResult == User::U_NOTACTIVATED) {
    # go to "needs activation" page
    // @phpstan-ignore variable.undefined
    $ReturnPage = "index.php?P=UserNotActivated&UN=".urlencode($UserName);
    RespondToUser($ReturnPage, "Redirect");
    return;
} elseif (isset($Password) && isset($UserName)
        && (preg_match("/^[0-9A-F]{6}([0-9A-F]{4})?$/", $Password) == 1)) {
    # login failed, but password looks like it was a reset or an
    # activation code

    # see if the provided username or email exists
    $UFact = new UserFactory();
    if ($UFact->UserNameExists($UserName) ||
        $UFact->EMailAddressIsInUse($UserName)) {
        $TargetUser = new User($UserName);

        # if the account is already activated
        if ($TargetUser->IsActivated()) {
            # see if the code provided was a valid password reset code
            if ($TargetUser->IsResetCodeGood($Password)) {
                # jump to the reset page if so
                $ReturnPage = "index.php?P=ResetPassword&UN=".$UserName."&RC=".$Password;
                RespondToUser($ReturnPage, "Redirect");
                return;
            }
        } elseif ($TargetUser->IsActivationCodeGood($Password)) {
            # otherwise, see if the code provided was a valid activation code
            # jump to the activation page if so
            $ReturnPage = "index.php?P=ActivateAccount&"."UN=".$UserName."&".
                "AC=".$Password;
            RespondToUser($ReturnPage, "Redirect");
            return;
        }
    }
}

# fail any other logins
RespondToUser("LoginError", "Failed");
