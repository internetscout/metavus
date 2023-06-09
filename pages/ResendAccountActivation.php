<?PHP
#
#   FILE:  ResendAccountActivation.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2006-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

use ScoutLib\StdLib;

# retrieve user name or e-mail address from URL or form
$H_UserName = StdLib::getFormValue("F_UserName", StdLib::getFormValue("UN"));
$H_EMailAddress = StdLib::getFormValue("F_EMailAddress", StdLib::getFormValue("EM"));

# values intended for use in HTML
$H_EMailAddressUsed = false;
$H_UserFound = false;
$H_EMailSent = false;
$H_AccountAlreadyActivated = false;

# if user name was supplied
$TargetUser = null;
if (strlen($H_UserName) && (new UserFactory())->userNameExists($H_UserName)) {
    # attempt to find user with specified name
    $H_UserName = User::NormalizeUserName($H_UserName);
    $TargetUser = new User($H_UserName);
} elseif (strlen($H_EMailAddress)) {
# else if e-mail address was supplied

    # attempt to find user with specified e-mail address
    $Factory = new UserFactory();
    $H_EMailAddress = User::NormalizeEMailAddress($H_EMailAddress);
    $TargetUser = $Factory->GetMatchingUsers($H_EMailAddress, "EMail");

    # set flag indicating that we used e-mail address
    $H_EMailAddressUsed = true;
}

# if requested user was found
if ($TargetUser instanceof User) {
    # set flag indicating that user was found
    $H_UserFound = true;

    # if user account was already activated
    if ($TargetUser->IsActivated()) {
        # set flag indicating account already activated
        $H_AccountAlreadyActivated = true;
    } else {
        $H_EMailSent = $TargetUser->sendActivationEmail();
    }
}
