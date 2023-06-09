<?PHP
#
#   FILE:  Folder.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Folders;

use ScoutLib\ApplicationFramework;

/**
 * Class used to add additional functionality to the Folder class.
 */
class Folder extends \Metavus\Folder
{
    /**
     * Get sharing (viewing) URL for folder.  A clean URL will be returned if
     * support for them is available.
     * @return string URL for viewing folder.
     */
    public function getSharingUrl()
    {
        $AF = ApplicationFramework::getInstance();

        # if clean URL support is available
        if ($AF->cleanUrlSupportAvailable()) {
            # assemble clean URL
            $Url = ApplicationFramework::baseUrl()."folders/".sprintf("%04d", $this->id())
                    ."/".$this->normalizedName();
        } else {
            # assemble regular URL
            $Url = ApplicationFramework::rootUrl().$_SERVER["SCRIPT_NAME"]
                    ."?P=P_Folders_ViewFolder&FolderId=".$this->id();
        }

        # return assembled URL to caller
        return $Url;
    }
}
