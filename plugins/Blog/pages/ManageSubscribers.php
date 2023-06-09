<?PHP
#
#   FILE:  ManageSubscribers.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataSchema;
use Metavus\User;
use Metavus\Plugins\Blog;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$H_Blog = PluginManager::getInstance()->getPluginForCurrentPage();
$H_Schema = new MetadataSchema($H_Blog->getSchemaId());
$AF = ApplicationFramework::getInstance();

# don't allow unauthorized access
if (!$H_Schema->userCanEdit(User::getCurrentUser())) {
    CheckAuthorization(false);
    return;
}

# set the current blog
$H_Blog->setCurrentBlog($H_Blog->configSetting("EmailNotificationBlog"));

$H_Subscribers = $H_Blog->getSubscribers();

# if user asked to add a subscriber
$Submit = StdLib::getFormValue("Submit");
if ($Submit == "Add User") {
    $UserName = StdLib::getArrayValue($_POST, "F_UserName")[0];
    if (empty($UserName)) {
        $H_Error = "UserName field cannot be blank.";
        return;
    } else {
        $User = new User($UserName);
        $UserSchema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);
        $SubscribeField = $UserSchema->getField(Blog::SUBSCRIPTION_FIELD_NAME);
        $User->set($SubscribeField, 1);
        $AF->SetJumpToPage("index.php?P=P_Blog_ManageSubscribers");
    }
} elseif (isset($_GET["ID"])) { # if user asked to remove a subscriber
    $User = new User($_GET["ID"]);
    $UserSchema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);
    $SubscribeField = $UserSchema->getField(Blog::SUBSCRIPTION_FIELD_NAME);
    $User->set($SubscribeField, 0);
    $AF->SetJumpToPage("index.php?P=P_Blog_ManageSubscribers");
}
