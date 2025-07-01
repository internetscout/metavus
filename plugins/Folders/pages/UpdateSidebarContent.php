<?PHP
#
#   FILE:  UpdateSidebarContent.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

use Metavus\Plugins\Folders\FolderDisplayUI;
use ScoutLib\ApplicationFramework;


# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$AF->suppressHtmlOutput();
$AF->setBrowserCacheExpirationTime(0);

if (User::getCurrentUser()->isLoggedIn()) {
    FolderDisplayUI::printFolderSidebarContent();
}
