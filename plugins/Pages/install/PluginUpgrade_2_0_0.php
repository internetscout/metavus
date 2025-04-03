<?PHP
#
#   FILE:  PluginUpgrade_2_0_0.php (Pages plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Pages;
use Metavus\Plugins\Pages;
use Metavus\PrivilegeSet;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Pages plugin to version 2.0.0.
 */
class PluginUpgrade_2_0_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.0.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        if (!$DB->tableExists("Records")) {
            return "Records table does not exist";
        }

        # switch to metadata-schema-based storage of pages
        $Result = $this->upgradeToMetadataPageStorage();
        if ($Result !== null) {
            return $Result;
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
    /**
     * Migrate data from database-based format to metadata-schema-based format.
     * @return null|string NULL upon success, or error string upon failure.
     */
    private function upgradeToMetadataPageStorage(): ?string
    {
        $DB = new Database();

        # bail out if conversion has already been done or is under way
        if (!$DB->tableExists("Pages_Pages") ||
            !$DB->query("RENAME TABLE Pages_Pages TO Pages_Pages_OLD")) {
            return null;
        }

        $Plugin = Pages::getInstance(true);

        # set up metadata schema
        $Result = $Plugin->setUpSchema();
        if ($Result !== null) {
            return $Result;
        }

        # load old privilege information
        $DB->query("SELECT * FROM Pages_Privileges");
        $OldPrivs = [];
        while ($Row = $DB->fetchRow()) {
            $OldPrivs[$Row["PageId"]][] = $Row["Privilege"];
        }

        # create new privileges table
        $DB->query("DROP TABLE Pages_Privileges");
        $DB->query(
            "CREATE TABLE IF NOT EXISTS Pages_Privileges (
                    PageId              INT NOT NULL,
                    ViewingPrivileges   BLOB,
                    INDEX       (PageId))"
        );

        # for each page in database
        $DB->query("SELECT * FROM Pages_Pages_OLD");
        while ($Row = $DB->fetchRow()) {
            # add new record for page
            $Page = Page::create();

            # transfer page values
            $Page->set("Title", $Row["PageTitle"]);
            $Page->set("Content", $Row["PageContent"]);
            $Page->set("Clean URL", $Row["CleanUrl"]);
            $Page->set("Creation Date", $Row["CreatedOn"]);
            $Page->set("Added By Id", $Row["AuthorId"]);
            $Page->set("Date Last Modified", $Row["UpdatedOn"]);
            $Page->set("Last Modified By Id", $Row["EditorId"]);

            # set viewing privileges
            $PrivSet = new PrivilegeSet();
            if (isset($OldPrivs[$Row["PageId"]])) {
                $PrivSet->addPrivilege($OldPrivs[$Row["PageId"]]);
            }
            $Page->viewingPrivileges($PrivSet);

            # make page permanent
            $Page->isTempRecord(false);
        }

        # drop content table from database
        $DB->query("DROP TABLE IF EXISTS Pages_Pages_OLD");

        # report success to caller
        return null;
    }
}
