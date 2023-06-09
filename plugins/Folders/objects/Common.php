<?PHP
#
#   FILE:  Common.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Folders;

use Metavus\MetadataSchema;
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
     * Setup page completion by suppressing HTML output and/or redirecting to
     * a certain page based on the GET parameters sent.
     * @param string $DefaultReturnTo URL to use for redirect (OPTIONAL)
     * @param bool $DefaultSuppressHtmlOutput TRUE to suppress HTML output (OPTIONAL)
     * @return bool TRUE if page completion should be allowed to continue, FALSE otherwise
     */
    public static function apiPageCompletion(
        $DefaultReturnTo = null,
        $DefaultSuppressHtmlOutput = false
    ) {

        $AF = ApplicationFramework::getInstance();

        # first check that the user is logged in
        if (!CheckAuthorization()) {
            return false;
        }

        $ReturnTo = StdLib::getArrayValue($_GET, "ReturnTo", $DefaultReturnTo);
        $SuppressHtmlOutput = StdLib::getArrayValue(
            $_GET,
            "SuppressHtmlOutput",
            $DefaultSuppressHtmlOutput
        );

        # set a redirect if given and it's safe to do so
        if ($ReturnTo && IsSafeRedirectUrl($ReturnTo) &&
            !ApplicationFramework::reachedViaAjax()) {
            $AF->setJumpToPage($ReturnTo);
        }

        if ($SuppressHtmlOutput) {
            $AF->suppressHTMLOutput();

            # don't redirect if suppressing HTML output
            $AF->setJumpToPage(null);
        }

        return true;
    }

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
            !$TitleField->allowHTML()) {
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
