<?PHP
#
#   FILE:  PluginUpgrade_2_1_23.php (UrlChecker plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

use Metavus\Plugins\UrlChecker;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the UrlChecker plugin to version 2.1.23.
 */
class PluginUpgrade_2_1_23 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.23.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = UrlChecker::getInstance();

        # some previous versions of UrlChecker created an INDEX (RecordId)
        # on the RecordHistory table rather than a PRIMARY KEY; the former
        # allows duplicate RecordIds whereas the latter does not

        $DB = new Database();

        # build a list of indexes and what columns they cover on the
        # RecordHistory table
        $Indexes = [];
        $DB->query("SHOW KEYS FROM UrlChecker_RecordHistory");
        foreach ($DB->fetchRows() as $Row) {
            $Indexes[$Row["Key_name"]][] = $Row["Column_name"];
        }

        # if we don't have a primary key, or we have one that covers too many columns,
        # or we have one that covers the wrong column(s), then we've got
        # some cleanup to do
        if (!isset($Indexes["PRIMARY"]) || count($Indexes["PRIMARY"]) > 1 ||
            $Indexes["PRIMARY"][0] != "RecordId") {
            # create a new version of the table with the correct indexes
            $DB->query(
                str_replace(
                    "UrlChecker_RecordHistory",
                    "UrlChecker_RecordHistory_New",
                    $Plugin::SQL_TABLES["RecordHistory"]
                )
            );

            # wrap an explicit lock around the data migration so that
            # tasks running in other threads (including those *not* inside
            # an upgrade() run) cannot insert new data
            $DB->query(
                "LOCK TABLES UrlChecker_RecordHistory WRITE, "
                ."UrlChecker_RecordHistory_New WRITE"
            );

            # put de-duped data into the new table
            $DB->query(
                "INSERT INTO UrlChecker_RecordHistory_New (RecordId, CheckDate) "
                ."SELECT RecordId, MAX(CheckDate) FROM UrlChecker_RecordHistory "
                ."GROUP BY RecordId"
            );

            # Per MySQL's "RENAME TABLE Statement" at
            #   https://dev.mysql.com/doc/refman/5.6/en/rename-table.html
            # "To execute RENAME TABLE, there must be no active
            #  transactions or tables locked with LOCK TABLES. With the
            #  transaction table locking conditions satisfied, the rename
            #  operation is done atomically; no other session can access any
            #  of the tables while the rename is in progress."
            # So we must unlock before we can rename.
            $DB->query("UNLOCK TABLES");

            # (brief race condition here where new data could be inserted
            #  into the old table before the 'RENAME TABLE' starts; any
            #  such data will be lost, but this will just mean that the
            #  UrlChecker re-checks those records sooner than it needs to)
            $DB->query(
                "RENAME TABLE "
                ."UrlChecker_RecordHistory TO UrlChecker_RecordHistory_Old, "
                ."UrlChecker_RecordHistory_New TO UrlChecker_RecordHistory"
            );
            $DB->query(
                "DROP TABLE UrlChecker_RecordHistory_Old"
            );
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
