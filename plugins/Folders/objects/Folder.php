<?PHP
#
#   FILE:  Folder.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Folders;

use Metavus\MetadataSchema;
use Metavus\Record;
use ScoutLib\ApplicationFramework;

/**
 * Class used to add additional functionality to the Folder class.
 */
class Folder extends \Metavus\Folder
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get the safe, i.e., OK to print to HTML, version of the given resource's
     * title.
     * @param Record $Resource Resource object.
     * @return string Safe resource title.
     */
    public static function getSafeResourceTitle(Record $Resource): string
    {
        static $Schema;
        if (!isset($Schema)) {
            $Schema = new MetadataSchema();
        }

        $TitleField = $Schema->getFieldByMappedName("Title");
        $SafeTitle = $Resource->getMapped("Title");

        if (!is_null($TitleField) && !$TitleField->allowHtml()) {
            $SafeTitle = defaulthtmlentities($SafeTitle);
        }

        return $SafeTitle;
    }

    /**
     * Get the share URL for the given folder.
     * @param Folder $Folder Folder to get URL for.
     * @return string Share URL for the folder.
     */
    public static function getShareUrl(Folder $Folder): string
    {
        $AF = ApplicationFramework::getInstance();

        $Id = $Folder->id();
        $ShareUrl = ApplicationFramework::baseUrl()."index.php"
            ."?P=P_Folders_ViewFolder&FolderId=".$Id;

        # make the share URL prettier if .htaccess support exists
        # (folders/folder_id/normalized_folder_name)
        if ($AF->cleanUrlSupportAvailable()) {
            $PaddedId = str_pad((string)$Id, 4, "0", STR_PAD_LEFT);
            $NormalizedName = $Folder->normalizedName();

            $ShareUrl = ApplicationFramework::baseUrl() . "folders/";
            $ShareUrl .= $PaddedId . "/" . $NormalizedName;
        }

        return $ShareUrl;
    }
}
