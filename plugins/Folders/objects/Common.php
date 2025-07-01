<?PHP
#
#   FILE:  Common.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Folders;
use Metavus\MetadataSchema;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;
use Metavus\Record;

/**
 * Class used to create a namespace for common Folders plugin functions.
 */
class Common
{
    /**
     * The maximum number of resources allowed per add.
     */
    const MAX_RESOURCES_PER_ADD = PHP_INT_MAX;

    /**
     * Get the safe, i.e., OK to print to HTML, version of the given resource's
     * title.
     * @param Record $Resource Resource object
     * @return string safe resource title
     */
    public static function getSafeResourceTitle(Record $Resource)
    {
        return self::escapeResourceTitle($Resource->getMapped("Title"));
    }

    /**
     * Get the safe, i.e., OK to print to HTML, version of the given resource
     * title.
     * @param string $Title Resource title
     * @return string safe resource title
     */
    public static function escapeResourceTitle($Title)
    {
        if (!isset(self::$Schema)) {
            self::$Schema = new MetadataSchema();
        }

        $TitleField = self::$Schema->getFieldByMappedName("Title");
        $SafeTitle = $Title;

        if (!is_null($TitleField) &&
            !$TitleField->allowHtml()) {
            $SafeTitle = defaulthtmlentities($SafeTitle);
        }

        return $SafeTitle;
    }

    /**
     * Get the share URL for the given folder.
     * @param Folder $Folder Folder
     * @return string share URL for the folder
     */
    public static function getShareUrl(Folder $Folder)
    {
        $AF = ApplicationFramework::getInstance();

        $Id = $Folder->id();
        $ShareUrl = ApplicationFramework::rootUrl().$_SERVER["SCRIPT_NAME"]
                . "?P=P_Folders_ViewFolder&FolderId=" . $Id;

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

    /**
     * @var ?MetadataSchema $Schema metadata schema object
     */
    protected static $Schema;
}
