<?PHP
#
#   FILE:  PageFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Pages;

use Metavus\Plugins\Pages;
use Metavus\RecordFactory;
use Metavus\UserFactory;
use ScoutLib\StdLib;

/**
 * Factory class for Page objects (from Pages plugin).
 */
class PageFactory extends RecordFactory
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.  The value for $PageSchemaId must be set before any
     * instances of this class are instantiated.
     */
    public function __construct()
    {
        # error out if schema ID has not been set
        if (!isset(self::$PageSchemaId)) {
            throw new \Exception("Attempt to create PageFactory without"
                    ." schema ID being set.");
        }

        # set up resource factory base class
        parent::__construct(self::$PageSchemaId);
    }

    /**
     * Retrieve list of clean URLs for all pages.
     * @return array with page indexes for index and an array of clean URLs for values.
     *   The first element in the array is the URL of the page itself, subsequent
     *   elements give the URLs for tabs within the page.
     */
    public function getCleanUrls(): array
    {
        # for each existing page
        $CleanUrls = [];
        $Ids = $this->GetItemIds();
        foreach ($Ids as $Id) {
            # retrieve clean URL
            $Page = new Page($Id);
            $Url = trim($Page->Get("Clean URL") ?? "");

            # add to list if non-empty, including tabs
            if (strlen($Url) > 0) {
                $CleanUrls[$Id] = [$Url];
                foreach ($Page->getTabNames() as $Tab) {
                    $CleanUrls[$Id][] = $Url."/".$Tab;
                }
            }
        }

        # return clean URLs to caller
        return $CleanUrls;
    }

    /**
     * Create Pages from an XML file using an XML file formatted
     *      for ItemFactory::importRecordsFromXmlFile()
     * @param string $FileName XML file to load pages from
     * @return array|string List of IDs of added pages, or error string if
     *      there's an issue
     */
    public function importPagesFromXmlFile(string $FileName)
    {
        # locate an administrative user - to be used as added by/last modified by ID
        $UFactory = new UserFactory();
        $AdminUserId = $UFactory->getSiteOwner();
        if ($AdminUserId === null) {
            return "No site owner was found";
        }

        # load updated help content
        $NewPages = $this->importRecordsFromXmlFile($FileName);
        $AddedPages = [];

        # for each page that was loaded
        foreach ($NewPages as $NewPageId) {
            # populate additional fields
            $NewPage = new Page($NewPageId);
            $NewPage->set("Added By Id", $AdminUserId);
            $NewPage->set("Last Modified By Id", $AdminUserId);
            $NewPage->set("Creation Date", date(StdLib::SQL_DATE_FORMAT));
            $NewPage->set("Date Last Modified", date(StdLib::SQL_DATE_FORMAT));

            # compute a content hash
            $ContentHash = hash(Pages::HASH_ALGO, $NewPage->get("Content"));
            $NewPage->set("Initial Content Hash", $ContentHash);

            $AddedPages[] = $NewPageId;
            $NewPage->isTempRecord(false);
        }

        # return list of added pages
        return $AddedPages;
    }

    /**
     * Update (or if not existing, import) Pages from a specified XML document
     * @param string $FileName path to XML document where page data are located
     * @return array|string array of page IDs of added/updated pages or string error
     */
    public function updatePagesFromXmlFile(string $FileName)
    {
        # create page factory for querying pages later, get list of Page IDs from
        #       importing XML Pages
        $PFactory = new PageFactory();
        $PageIds = $this->importPagesFromXmlFile($FileName);
        # return if error in importing
        if (!is_array($PageIds)) {
            return $PageIds;
        }
        $UpdatedPageIds = [];

        foreach ($PageIds as $PageId) {
            $Page = new Page($PageId);

            # get the list of pages using this url
            $Url = $Page->get("Clean URL");
            $Matches = $PFactory->getIdsOfMatchingRecords(
                ["Clean URL" => $Url]
            );

            # if there's not any other pages using this url, then we're done
            if (count($Matches) == 1) {
                $UpdatedPageIds[] = $PageId;
                continue;
            }

            # if there's an edited version of this page at our desired URL,
            # check to see if we've loaded our updated content somewhere else
            $ContentHash = $Page->get("Initial Content Hash");
            $Matches = $PFactory->getIdsOfMatchingRecords(
                ["Initial Content Hash" => $ContentHash]
            );

            # if so, then this page would be redundant and we can drop it
            if (count($Matches) > 1) {
                $Page->destroy();
            }

            # otherwise, we'll want to add a version suffix to this page and
            # keep it for the user to inspect so that they can marge any
            # changes in with the version at our desired URL and delete this
            # copy
            $Page->set("Clean URL", $Url."_".CWIS_VERSION);
            $UpdatedPageIds[] = $PageId;
        }

        return $UpdatedPageIds;
    }

    /**
     * Metadata schema ID for pages.  Must be set before any instances of
     * this class are instantiated.
     */
    public static $PageSchemaId;
}
