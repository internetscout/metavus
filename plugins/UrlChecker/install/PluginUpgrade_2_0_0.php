<?PHP
#
#   FILE:  PluginUpgrade_2_0_0.php (UrlChecker plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the UrlChecker plugin to version 2.0.0.
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

        // make the upgrade process fault tolerant
        $DB->setQueryErrorsToIgnore([
            '/ALTER\s+TABLE\s+[^\s]+\s+CHANGE\s+.+/i'
                => '/(Unknown\s+column\s+[^\s]+\s+in\s+[^\s]+|'
                    .'Table\s+[^\s]+\s+doesn\'t\s+exist)/i',
            '/ALTER\s+TABLE\s+[^\s]+\s+ADD\s+.+/i'
                => '/(Duplicate\s+column\s+name\s+[^\s]+|'
                    .'/Table\s+[^\s]+\s+doesn\'t\s+exist)/i',
            '/RENAME\s+TABLE\s+[^\s]+\s+TO\s+[^\s]+/i'
                => '/Table\s+[^\s]+\s+already\s+exists/i',
            '/CREATE\s+TABLE\s+[^\s]+\s+\([^)]+\)/i'
                => '/Table\s+[^\s]+\s+already\s+exists/i'
        ]);

        # rename columns
        if (false === $DB->query("ALTER TABLE UrlChecker_Failures
            CHANGE DateChecked CheckDate TIMESTAMP")) {
            return "Could not update the URL history CheckDate column";
        }
        if (false === $DB->query("ALTER TABLE UrlChecker_Failures
            CHANGE TimesFailed TimesInvalid INT")) {
            return "Could not update the TimesInvalid column";
        }
        if (false === $DB->query("ALTER TABLE UrlChecker_Failures
            CHANGE StatusNo StatusCode INT")) {
            return "Could not update the StatusCode column";
        }
        if (false === $DB->query("ALTER TABLE UrlChecker_Failures
            CHANGE StatusText ReasonPhrase TEXT")) {
            return "Could not update the ReasonPhrase column";
        }
        if (false === $DB->query("ALTER TABLE UrlChecker_Failures
            CHANGE DataOne FinalStatusCode INT DEFAULT -1")) {
            return "Could not update the FinalStatusCode column";
        }
        if (false === $DB->query("ALTER TABLE UrlChecker_Failures
            CHANGE DataTwo FinalUrl TEXT")) {
            return "Could not update the FinalUrl column";
        }
        if (false === $DB->query("ALTER TABLE UrlChecker_History
            CHANGE DateChecked CheckDate TIMESTAMP")) {
            return "Could not update the resource history CheckDate column";
        }

        # add columns
        if (false === $DB->query("ALTER TABLE UrlChecker_Failures
            ADD Hidden INT DEFAULT 0 AFTER FieldId")) {
            return "Could not add the Hidden column";
        }
        if (false === $DB->query("ALTER TABLE UrlChecker_Failures
            ADD IsFinalUrlInvalid INT DEFAULT 0 AFTER ReasonPhrase")) {
            return "Could not add the IsFinalUrlInvalid column";
        }
        if (false === $DB->query("ALTER TABLE UrlChecker_Failures
            ADD FinalReasonPhrase TEXT")) {
            return "Could not add the FinalReasonPhrase column";
        }

        # rename history tables
        if (false === $DB->query("RENAME TABLE UrlChecker_Failures
            TO UrlChecker_UrlHistory")) {
            return "Could not rename the URL history table";
        }
        if (false === $DB->query("RENAME TABLE UrlChecker_History
            TO UrlChecker_ResourceHistory")) {
            return "Could not rename the resource history table";
        }

        # remove any garbage data
        if (false === $DB->query("DELETE FROM UrlChecker_UrlHistory WHERE ResourceId < 0")) {
            return "Could not remove stale data from the URL history";
        }
        if (false === $DB->query("DELETE FROM UrlChecker_ResourceHistory
            WHERE ResourceId < 0")) {
            return "Could not remove stale data from the resource history";
        }

        # add settings table
        if (false === $DB->query("
            CREATE TABLE UrlChecker_Settings (
                NextNormalUrlCheck     INT,
                NextInvalidUrlCheck    INT
            );")) {
            return "Could not create the settings table";
        }

        # repair and optimize tables after the changes. if this isn't done,
        # weird ordering issues might pop up
        if (false === $DB->query("REPAIR TABLE UrlChecker_UrlHistory")) {
            return "Could not repair the URL history table";
        }
        if (false === $DB->query("REPAIR TABLE UrlChecker_ResourceHistory")) {
            return "Could not repair the resource history table";
        }
        if (false === $DB->query("OPTIMIZE TABLE UrlChecker_UrlHistory")) {
            return "Could not optimize the URL history table";
        }
        if (false === $DB->query("OPTIMIZE TABLE UrlChecker_ResourceHistory")) {
            return "Could not optimize the resource history table";
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
