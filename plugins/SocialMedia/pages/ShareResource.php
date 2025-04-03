<?PHP
#
#   FILE:  ShareResource.php (SocialMedia plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\SocialMedia;
use Metavus\Record;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

# get the parameters from the URL
$ResourceId = StdLib::getArrayValue($_GET, "ResourceId");
$Site = StdLib::getArrayValue($_GET, "Site");
$UserId = StdLib::getArrayValue($_GET, "UserId");

# get the resource and SocialMedia plugin
if (Record::ItemExists($ResourceId)) {
    $Resource = new Record($ResourceId);
    $Plugin = SocialMedia::getInstance();

    # share the resource
    $Plugin->ShareResource($Resource, $Site, $UserId);
} else {
    CheckAuthorization(-1);
    return;
}
