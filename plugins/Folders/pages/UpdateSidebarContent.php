<?PHP
#
#   FILE:  UpdateSidebarContent.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Folders\FolderDisplayUI;
use Metavus\User;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

ApplicationFramework::getInstance()->suppressHTMLOutput();

if (User::getCurrentUser()->isLoggedIn()) {
    FolderDisplayUI::printFolderSidebarContent();
}
