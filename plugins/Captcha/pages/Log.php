<?PHP
#
#   FILE:  Log.php (Captcha plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\ItemListUI;
use Metavus\Plugins\Captcha;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

$CommonLogFields = [
    "LastSeen" => [
        "Heading" => "Last Seen",
        "ValueFunction" => function ($Row) {
            return StdLib::getPrettyTimestamp($Row["LastSeen"]);
        },
    ],
    "Views" => [
        "Heading" => "Views",
    ],
    "Successes" => [
        "Heading" => "Successes",
    ],
    "Failures" => [
        "Heading" => "Failures",
    ],
];

$UserLogFields = [
    "UserName" => [
        "Heading" => "User",
        "ValueFunction" => function ($Row) {
            static $UFactory = null;
            if (is_null($UFactory)) {
                $UFactory = new UserFactory();
            }

            $UserName = $Row["UserName"];
            if ($UFactory->userNameExists($UserName)) {
                $TgtUser = new User($UserName);
                return '<a href="index.php?P=CleanSpam&amp;PI='
                    .$TgtUser->Id().'&amp;RI=-1">'
                    .htmlspecialchars($UserName).'</a>';
            } else {
                return $UserName;
            }
        },
        "AllowHTML" => true,
    ],
] + $CommonLogFields;

$IpLogFields = [
    "ClientIp" => [
        "Heading" => "Client IP",
    ],
] + $CommonLogFields;


$CPlugin = Captcha::getInstance();

if (!($CPlugin instanceof \Metavus\Plugins\Captcha)) {
    throw new Exception("Retrieved plugin is not Captcha (should be impossible).");
}

$ItemsPerPage = 50;

$H_UserListUI = new ItemListUI($UserLogFields);
$H_UserListUI->itemsPerPage($ItemsPerPage);
$H_UserListUI->fieldsSortableByDefault(false);
$H_UserListUI->noItemsMessage(
    "No per-user Captcha logs recorded"
);

$H_IpListUI = new ItemListUI($IpLogFields);
$H_IpListUI->itemsPerPage($ItemsPerPage);
$H_IpListUI->fieldsSortableByDefault(false);
$H_IpListUI->noItemsMessage(
    "No per-IP Captcha logs recorded."
);


$H_UserLogEntries = $CPlugin->getUserLog(
    $H_UserListUI->transportUI()->startingIndex(),
    $H_UserListUI->transportUI()->itemsPerPage()
);
$H_NumUserLogEntries = $CPlugin->getUserLogCount();

$H_IpLogEntries = $CPlugin->getIpLog(
    $H_IpListUI->transportUI()->startingIndex(),
    $H_IpListUI->transportUI()->itemsPerPage()
);
$H_NumIpLogEntries = $CPlugin->getIpLogCount();
