<?PHP
#
#   FILE:  PluginUpgrade.php
#
#   Part of the ScoutLib application support library
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Base class for plugin upgrades.
 */
abstract class PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to new version.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    abstract public function performUpgrade();

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Create missing database tables.  This method assumes that the plugin
     * class has its database tables defined in a public constant named
     * "SQL_TABLES" that contains array of table creation SQL, with table
     * names for the index.  The class prefix may be omitted from the tables
     * names used for the index (i.e. "MyTable" instead of "MyPlugin_MyTable").
     * @return string|null Error message or NULL if creation succeeded.
     */
    protected function createMissingTables()
    {
        $DB = new Database();
        $SqlErrorsWeCanIgnore = [
            "/CREATE TABLE /i" => "/Table '[a-z0-9_]+' already exists/i"
        ];
        $DB->setQueryErrorsToIgnore($SqlErrorsWeCanIgnore);

        $PluginClassName = static::getNameOfPluginClass();
        $Tables = $PluginClassName::SQL_TABLES;
        foreach ($Tables as $TableName => $TableSql) {
            $Result = $DB->query($TableSql);
            if ($Result === false) {
                if (strpos($TableName, $PluginClassName) !== 0) {
                    $TableName = $PluginClassName."_".$TableName;
                }
                return "Unable to create ".$TableName." database table."
                    ."  (ERROR: ".$DB->queryErrMsg().")";
            }
        }

        return null;
    }

    /**
     * Get qualified name of plugin class for which this upgrade is intended.
     * Assumes that this class has a full class name like
     *   Metavus\Plugins\PluginName\PluginUpgrade_X.
     * @return string Name of plugin class.
     */
    protected static function getNameOfPluginClass(): string
    {
        # find position of last separator in fully-qualified class name
        $SepPos = strrpos(static::class, "\\");
        assert($SepPos !== false);
        # use position to extract plugin portion
        return substr(static::class, 0, $SepPos);
    }
}
