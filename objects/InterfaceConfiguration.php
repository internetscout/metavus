<?PHP
#
#   FILE:  InterfaceConfiguration.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023-2025 Edward Almasy and Internet Scout Research Group
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
    public static function getInstance(?string $SelectedInterface = null)
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
     * @return void
     */
    public function migrateSystemSettingsToInterfaceSettings(): void
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

    /**
     * Get menu items, parsed from specified Paragraph setting value.
     * List of items may filtered based on the current user and any privilege
     * requirements included in the setting value.  Menu items are assumed to
     * be defined in the setting value one per line, beginning with the label,
     * then the link, and (optionally) any privileges required to see the item,
     * with each component separated by a pipe (|) character.  Multiple
     * privileges should be separated by commas, and the user having any one
     * of the specified privileges will allow them to see the item.
     * @param string $SettingName Name of Paragraph setting.
     * @return array Menu items, with item links for the index and item
     *      labels for the values.
     */
    public function getMenuItems(string $SettingName): array
    {
        $Items = [];
        $Setting = $this->getString($SettingName);
        $Lines = explode("\n", $Setting);
        foreach ($Lines as $Line) {
            $Pieces = explode("|", $Line);

            # if we have both a label and a link
            if (count($Pieces) >= 2) {
                # clean off any leading or trailing whitespace
                $Pieces = array_map("trim", $Pieces);

                # if we may have privilege restrictions
                if (isset($Pieces[2])) {
                    $Privs = self::parseOutPrivileges($Pieces[2]);
                    if (count($Privs)) {
                        # discard this menu item if current user does not have
                        #       one of the specified privileges
                        $User = User::getCurrentUser();
                        if (!$User->hasPriv($Privs)) {
                            continue;
                        }
                    }
                }

                # add item to list if label and link are both present
                $Label = $Pieces[0];
                $Link = $Pieces[1];
                if (strlen($Link) && strlen($Label)) {
                    $Items[$Link] = $Label;
                }
            }
        }

        return $Items;
    }

    /**
     * Validate menu items, parsed from the supplied string.
     * @param string $FieldName Name of form field.
     * @param string|array $FieldValues Form values being validated.
     * @return string|null Error message or NULL if value appears valid.
     * @see getMenuItems()
     */
    public static function validateMenuItems(string $FieldName, $FieldValues)
    {
        if (!is_array($FieldValues)) {
            $FieldValues = [$FieldValues];
        }

        foreach ($FieldValues as $Value) {
            # split value into individual lines
            $Lines = explode("\n", $Value);
            # trim any leading or trailing whitespace off of lines
            $Lines = array_map("trim", $Lines);
            foreach ($Lines as $Line) {
                if (strlen($Line) == 0) {
                    continue;
                }

                # split line into individual components
                $Pieces = explode("|", $Line);
                # trim any leading or trailing whitespace off of pieces
                $Pieces = array_map("trim", $Pieces);

                if (count($Pieces) == 1) {
                    return "Menu item encountered without both label and link"
                            ." (\"".$Line."\")";
                }
                if (count($Pieces) > 3) {
                    return "Menu item encountered with too many elements "
                            ." (\"".$Line."\")";
                }
                if (isset($Pieces[2])) {
                    try {
                        $Privs = self::parseOutPrivileges($Pieces[2]);
                    } catch (Exception $Ex) {
                        return "Menu item encountered with invalid privilege "
                                ." (\"".$Line."\")";
                    }
                }
            }
        }

        return null;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected static $Instances;

    /**
     * Class constructor.
     * @param string $SelectedInterface Canonical name of interface.  (OPTIONAL,
     *      defaults to current active interface)
     */
    protected function __construct(?string $SelectedInterface = null)
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
            $SelectedInterfaceClass = "\Metavus\InterfaceSettings_".$SelectedInterface;
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

    /**
     * Parse comma-separated privilege values out from specified string.
     * @param string $StringToParse String containing privilege flags.
     * @return array Privilege IDs.
     */
    private static function parseOutPrivileges(string $StringToParse): array
    {
        # parse privileges from string and trim off any leading or trailing whitespace
        $Pieces = explode(",", $StringToParse);
        $Pieces = array_map("trim", $Pieces);

        $Privs = [];
        foreach ($Pieces as $Piece) {
            if (strlen($Piece)) {
                $Priv = Privilege::translateNameToId($Piece);
                if ($Priv === false) {
                    throw new Exception("Unknown privilege name (\"".$Piece."\").");
                }
                $Privs[] = $Priv;
            }
        }

        return $Privs;
    }
}
