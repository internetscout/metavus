<?PHP
#
#   FILE:  EditBlog.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\FormUI;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

CheckAuthorization(PRIV_SYSADMIN);

$BlogPlugin = PluginManager::getInstance()->getPluginForCurrentPage();
$AF = ApplicationFramework::getInstance();

$H_BlogId = StdLib::getFormValue("BI", StdLib::getFormValue("F_BlogId"));

# pull out the requested settings, if they exist or a template otherwise
if ($H_BlogId == "NEW") {
    $MySettings = $BlogPlugin->GetBlogConfigTemplate();
} else {
    $H_BlogId = intval($H_BlogId);
    $MySettings = $BlogPlugin->BlogSettings($H_BlogId);
}

$H_ConfigUI = new FormUI($BlogPlugin->GetBlogConfigOptions(), $MySettings);
$H_ConfigUI->addHiddenField("F_BlogId", $H_BlogId);
$H_ConfigUI->addValidationParameters($H_BlogId);

# act on any button push
$ButtonPushed = StdLib::getFormValue("Submit");

switch ($ButtonPushed) {
    case "Save Changes":
        if ($H_ConfigUI->validateFieldInput() > 0) {
            return;
        }
        $BlogName = StdLib::getFormValue("F_BlogName");
        $H_BlogId = $H_BlogId == "NEW" ? $BlogPlugin->CreateBlog($BlogName) : $H_BlogId;

        $BlogPlugin->BlogSettings($H_BlogId, $H_ConfigUI->GetNewValuesFromForm());

        $AF->SetJumpToPage("index.php?P=P_Blog_ListBlogs");
        return;

    case "Cancel":
        $AF->SetJumpToPage("index.php?P=P_Blog_ListBlogs");
        return;
}
