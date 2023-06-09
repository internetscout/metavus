<?PHP
#
#   FILE:  InterfaceConfiguration.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

/**
 * Interface configuration setting storage, retrieval, and editing definitions class.
 */
class InterfaceConfiguration extends Configuration
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get universal instance of class for specified interface.
     * @param string $SelectedInterface Name of interface.  (OPTIONAL,
     *      defaults to current active interface)
     * @return self Class instance.
     */
    public static function getInstance(string $SelectedInterface = null)
    {
        # select active interface if none specified by caller
        if ($SelectedInterface === null) {
            $SelectedInterface =
                    ApplicationFramework::getInstance()->activeUserInterface();
        }

        if (!isset(static::$Instances[$SelectedInterface])) {
            static::$Instances[$SelectedInterface] = new static($SelectedInterface);
        }
        return static::$Instances[$SelectedInterface];
    }

    /**
     * Migrate legacy values from old (now departed) system configuration
     * settings to new interface configuration settings.  Intended to be
     * called from site upgrade, using an interface configuration instance
     * for the default interface.
     */
    public function migrateSystemSettingsToInterfaceSettings()
    {
        # make sure we are running with default interface
        if ($this->SelectorValue != "default") {
            throw new Exception("Must be called from default interface.");
        }

        # make sure rows for all interfaces have been added to database
        $Interfaces = (ApplicationFramework::getInstance())->getUserInterfaces();
        foreach ($Interfaces as $CanonicalInterfaceName => $InterfaceLabel) {
            $Interface = self::getInstance($CanonicalInterfaceName);
        }

        # for each field in this (default) interface
        foreach ($this->Fields as $FieldName => $FieldInfo) {
            # if field existed in system configuration
            $ColumnName = Database::normalizeToColumnName($FieldName);
            if ($this->DB->fieldExists("SystemConfiguration", $ColumnName)) {
                # set all empty interface config fields to system config value
                $Query = "UPDATE InterfaceConfiguration IC, SystemConfiguration SC"
                        ." SET IC.".$ColumnName." = SC.".$ColumnName
                        ." WHERE ISNULL(IC.".$ColumnName.")";
                $this->DB->query($Query);
            }
        }
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected static $Instances;

    /**
     * Class constructor.
     * @param string $SelectedInterface Canonical name of interface.  (OPTIONAL,
     *      defaults to current active interface)
     */
    protected function __construct(string $SelectedInterface = null)
    {
        # select active interface if none specified by caller
        if ($SelectedInterface === null) {
            $SelectedInterface =
                    ApplicationFramework::getInstance()->activeUserInterface();
        }

        # load setting definitions for default interface
        $DefaultIntCfg = new InterfaceSettings_Default();
        $this->SettingDefinitions = $DefaultIntCfg->getSettingDefinitions();

        # if interface other than default is selected
        if ($SelectedInterface != "default") {
            # if selected interface has its own configuration settings
            $SelectedInterfaceClass = "InterfaceSettings_".$SelectedInterface;
            if (class_exists($SelectedInterfaceClass)) {
                # load setting definitions for selected interface
                $ActiveIntCfg = new $SelectedInterfaceClass();
                if (!($ActiveIntCfg instanceof InterfaceSettings)) {
                    throw new Exception($SelectedInterfaceClass." class is not"
                            ." descended from InterfaceSettings abstract class.");
                }
                $ActiveDefinitions = $ActiveIntCfg->getSettingDefinitions();

                # for each definition in selected interface
                foreach ($ActiveDefinitions as $SettingName => $SettingDefinition) {
                    # if definition indicates default config setting should be removed
                    if ($SettingDefinition === null) {
                        # remove setting from list of setting definitions
                        unset($this->SettingDefinitions[$SettingName]);
                    } else {
                        # add setting to list of setting definitions
                        $this->SettingDefinitions[$SettingName] = $SettingDefinition;
                    }
                }
            }
        }

        # tell parent what collection of settings to use from database
        $this->setSelector("InterfaceName", $SelectedInterface);

        $this->DatabaseTableName = "InterfaceConfiguration";
        parent::__construct();
    }
}
