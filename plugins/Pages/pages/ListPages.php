<?PHP
#
#   FILE:  ListPages.php (Pages plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\MetadataSchema;
use Metavus\Plugins\Pages;
use Metavus\Plugins\Pages\Page;
use Metavus\Plugins\Pages\PageFactory;
use Metavus\TransportControlsUI;
use Metavus\User;
use ScoutLib\StdLib;

# retrieve user currently logged in
$User = User::getCurrentUser();

# check authorization to see page list
$Plugin = Pages::getInstance();
$H_SchemaId = $Plugin->getConfigSetting("MetadataSchemaId");
$Schema = new MetadataSchema($H_SchemaId);
if (!$Schema->userCanAuthor($User) && !$Schema->userCanEdit($User)) {
    User::handleUnauthorizedAccess();
    return;
}

# load page IDs
$PFactory = new PageFactory();
$PageIds = $PFactory->getItemIds();

# load pages from page IDs
$H_Pages = [];
foreach ($PageIds as $Id) {
    $H_Pages[$Id] = new Page($Id);
}
