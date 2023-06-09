<?PHP
#
#   FILE:  Plugin.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * This class extends the base Plugin class with CWIS-specific functionality.
 */
abstract class Plugin extends \ScoutLib\Plugin
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Retrieve current list of administration menu entries for plugins.
     * @return array Two-dimensional array, with base plugin names for the
     *      first index, menu entry links for the second index, and menu
     *      entry labels for the values.
     */
    public static function getAdminMenuEntries(): array
    {
        $User = User::getCurrentUser();
        $PluginMgr = PluginManager::getInstance();
        $AllEntries = [];

        # for each plugin
        foreach (self::$AdminMenuEntries as $BaseName => $PluginEntries) {
            # skip plugin if not ready
            if (!$PluginMgr->pluginReady($BaseName)) {
                continue;
            }

            # for each menu entry for plugin
            foreach ($PluginEntries as $Link => $Label) {
                # skip entry if current user does not have privileges needed to see it
                $Privs = self::$AdminMenuPrivileges[$BaseName][$Link];
                if ($Privs instanceof PrivilegeSet) {
                    if (!$Privs->meetsRequirements($User)) {
                        continue;
                    }
                } else {
                    if (!$User->hasPriv($Privs)) {
                        continue;
                    }
                }

                # normalize link values (expand just page names to URLs)
                if (strpos($Link, ".php") === false) {
                    $NormalizedLink = "index.php?P=P_".$BaseName."_".$Link;
                } else {
                    $NormalizedLink = $Link;
                }

                # add entry to list
                $AllEntries[$BaseName][$NormalizedLink] = $Label;
            }

            # if menu entries were added for plugin
            if (isset($AllEntries[$BaseName])) {
                # sort entries by label
                asort($AllEntries[$BaseName]);
            }
        }

        # sort menus by plugin name
        uksort($AllEntries, function ($A, $B) use ($PluginMgr) {
            return $PluginMgr->getPlugin((string)$A)->getName()
                    <=> $PluginMgr->getPlugin((string)$B)->getName();
        });

        return $AllEntries;
    }

    /**
     * Determine if the current page load is for the configuration page for
     * our plugin.
     * @return bool TRUE on the plugin config page, FALSE otherwise.
     */
    public function onOurConfigPage() : bool
    {
        if (isset($_GET["P"]) && $_GET["P"] == "PluginConfig" &&
            isset($_GET["PN"]) && $_GET["PN"] == $this->getBaseName()) {
            return true;
        }

        return false;
    }

    # ---- PROTECTED INTERFACE -----------------------------------------------

    /**
     * Add administration menu entry for plugin.
     * @param string $Label Label for display of menu entry.
     * @param string $Link URL or page that menu entry should link to when displayed.
     * @param array|PrivilegeSet $Privs Privilege(s) required to see entry.
     */
    protected function addAdminMenuEntry(string $Link, string $Label, $Privs)
    {
        $BaseName = $this->getBaseName();
        self::$AdminMenuEntries[$BaseName][$Link] = $Label;
        self::$AdminMenuPrivileges[$BaseName][$Link] = $Privs;
    }

    /**
     * Load fields into metadata schema from XML file.  The XML file is
     * assumed to be in install/MetadataSchema--SCHEMANAME.xml under the
     * plugin's directory.
     * @param mixed $Schema Schema or ID of schema to load fields into.
     * @return string|null Error message or NULL if load succeeded.
     * @throws Exception If no XML file found.
     */
    protected function addMetadataFieldsFromXml($Schema)
    {
        # load schema
        if (!($Schema instanceof MetadataSchema)) {
            $Schema = new MetadataSchema($Schema);
        }

        # assemble XML file name
        $PossibleSuffixes = [
            StdLib::singularize($Schema->resourceName()),
            StdLib::pluralize($Schema->resourceName())
        ];
        foreach ($PossibleSuffixes as $Suffix) {
            $FileName = "plugins/".static::getBaseName()
                    ."/install/MetadataSchema--"
                    .str_replace(" ", "", $Suffix).".xml";
            if (is_file($FileName)) {
                $XmlFile = $FileName;
                break;
            }
        }
        if (!isset($XmlFile)) {
            throw new Exception("No XML file found to load metadata fields from"
                    ." for ".$Schema->name()." schema.");
        }

        # load fields from file
        $Result = $Schema->addFieldsFromXmlFile($XmlFile);

        # if load failed
        if ($Result === false) {
            # return error message(s) to caller
            return "Error loading User metadata fields from XML: ".implode(
                " ",
                $Schema->errorMessages("AddFieldsFromXmlFile")
            );
        }

        # report success to caller
        return null;
    }

    /**
     * Delete any metadata fields owned by plugin from specified schema.
     * @param int $SchemaId ID of schema to drop fields from.
     * @return string|null Error message or NULL if drop succeeded.
     */
    protected function deleteMetadataFields(int $SchemaId)
    {
        # load schema
        $Schema = new MetadataSchema($SchemaId);

        # for each field in schema
        foreach ($Schema->getFields() as $FieldId => $Field) {
            # drop field if we own it
            if ($Field->owner() == static::getBaseName()) {
                $Schema->dropField($FieldId);
            }
        }

        # report success to caller
        return null;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected static $AdminMenuEntries = [];
    protected static $AdminMenuPrivileges = [];
}
