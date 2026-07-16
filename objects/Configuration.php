<?PHP
#
#   FILE:  Configuration.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use InvalidArgumentException;
use ScoutLib\StdLib;

/**
 * Configuration settings storage manager.
 */
abstract class Configuration extends \ScoutLib\Datastore
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get universal instance of class.
     * @return static Class instance.
     */
    public static function getInstance()
    {
        if (!isset(static::$Instance)) {
            static::$Instance = new static();
        }
        return static::$Instance;
    }

    /**
     * Get configuration settings in form usabled with FormUI, with "Value"
     * for each set to the current setting value.
     * @return array Setting form field definitions, with base setting names
     *      for index.
     */
    public function getFormParameters(): array
    {
        $Params = [];
        $VisSetDefs = $this->getVisibleSettingDefinitions();
        foreach ($VisSetDefs as $SettingName => $SettingDefinition) {
            # retrieve and set current value for setting
            $Value = $this->getSettingValueForUseByForm($SettingName);
            if ($Value !== null) {
                $SettingDefinition["Value"] = $Value;
            }

            $Params[$SettingName] = $SettingDefinition;
        }
        return $Params;
    }

    /**
     * Get values for configuration settings in form usabled with FormUI.
     * @return array Setting form field values, with base setting names
     *      for index.
     */
    public function getFormValues(): array
    {
        $Values = [];
        $VisSetDefs = $this->getVisibleSettingDefinitions();
        foreach ($VisSetDefs as $SettingName => $SettingDefinition) {
            # retrieve current value for setting
            $Value = $this->getSettingValueForUseByForm($SettingName);
            if ($Value !== null) {
                $Values[$SettingName] = $Value;
            }
        }
        return $Values;
    }

    /**
     * Update setting values.
     * @param array $NewValues Array of values, with setting names for the
     *      index.
     * @return void
     */
    public function updateValues(array $NewValues): void
    {
        foreach ($NewValues as $SettingName => $SettingValue) {
            if (!isset($this->SettingDefinitions[$SettingName])) {
                throw new InvalidArgumentException("Unrecognized setting name \""
                        .$SettingName."\".");
            }
            $SettingDefinition = $this->SettingDefinitions[$SettingName];

            # skip saving fields that are set to read-only
            if ((isset($SettingDefinition["ReadOnly"])
                        && $SettingDefinition["ReadOnly"])
                    || (isset($SettingDefinition["ReadOnlyFunction"])
                        && $SettingDefinition["ReadOnlyFunction"]($SettingName))) {
                continue;
            }

            # if a "set" function is provided, use that to set the value
            if (isset($SettingDefinition["SetFunction"])) {
                $SettingDefinition["SetFunction"]($SettingName, $SettingValue);
            # else if field is not one that we do not store
            } elseif (!isset(static::$TypesNotStored[
                    $SettingDefinition["Type"]
            ])) {
                # save value based on storage type
                $StorageType = $this->getStorageType($SettingName);
                switch ($StorageType) {
                    case self::TYPE_ARRAY:
                        $this->setArray($SettingName, $SettingValue);
                        break;

                    case self::TYPE_BOOL:
                        $this->setBool($SettingName, $SettingValue);
                        break;

                    case self::TYPE_DATETIME:
                        $this->setDatetime($SettingName, $SettingValue);
                        break;

                    case self::TYPE_INT:
                        $this->setInt($SettingName, $SettingValue);
                        break;

                    case self::TYPE_STRING:
                    case self::TYPE_EMAIL:
                    case self::TYPE_IPADDRESS:
                    case self::TYPE_URL:
                        $this->setString($SettingName, $SettingValue);
                        break;

                    default:
                        $TypeConstantName = StdLib::getConstantName(
                            "Metavus\\FormUI",
                            $SettingDefinition["Type"],
                            "FTYPE_"
                        );
                        throw new Exception("Setting type (".$TypeConstantName
                                .") not supported for field \"".$SettingName
                                ."\" when setting value for form.");
                }
            }
        }
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $DatabaseTableName;
    protected $SettingDefinitions;
    protected $StorageTypes = [];
    protected $VisibleSettingDefinitions;

    protected static $Instance;

    # translations between FormUI field types and Datastore field types
    # (entries with FALSE values on the right must be handled in some
    #       manner other than a simple translation)
    protected static $TypeTranslations = [
        FormUI::FTYPE_DATETIME => self::TYPE_DATETIME,
        FormUI::FTYPE_FILE => self::TYPE_ARRAY,
        FormUI::FTYPE_FLAG => self::TYPE_BOOL,
        FormUI::FTYPE_IMAGE => self::TYPE_ARRAY,
        FormUI::FTYPE_METADATAFIELD => self::TYPE_INT,
        FormUI::FTYPE_NUMBER => self::TYPE_INT,
        FormUI::FTYPE_OPTION => false,
        FormUI::FTYPE_PARAGRAPH => self::TYPE_STRING,
        FormUI::FTYPE_PASSWORD => self::TYPE_STRING,
        FormUI::FTYPE_POINT => false,
        FormUI::FTYPE_PRIVILEGES => false,
        FormUI::FTYPE_QUICKSEARCH => false,
        FormUI::FTYPE_SEARCHPARAMS => false,
        FormUI::FTYPE_TEXT => self::TYPE_STRING,
        FormUI::FTYPE_URL => self::TYPE_URL,
        FormUI::FTYPE_USER => false,
    ];
    # FormUI field types for which we do not store any data
    protected static $TypesNotStored = [
        FormUI::FTYPE_CAPTCHA => true,
        FormUI::FTYPE_CUSTOMCONTENT => true,
        FormUI::FTYPE_GROUPEND => true,
        FormUI::FTYPE_HEADING => true,
    ];

    /**
     * Object constructor.
     */
    protected function __construct()
    {
        if (!isset($this->DatabaseTableName)) {
            throw new Exception("Database table name not set for ".__CLASS__);
        }
        if (!isset($this->SettingDefinitions)) {
            throw new Exception("Setting definitions not set for ".__CLASS__);
        }
        static::checkSettingDefinitions($this->SettingDefinitions);
        $DatastoreFields = static::convertSettingsToDatastoreFields(
            $this->SettingDefinitions
        );
        static::checkFieldsList($DatastoreFields);
        parent::__construct($DatastoreFields, $this->DatabaseTableName);
    }

    /**
     * Check integrity of setting definitions.
     * @param array $Settings Setting definitions.
     * @return void
     */
    protected static function checkSettingDefinitions(array $Settings): void
    {
        foreach ($Settings as $SettingName => $SettingValues) {
            # check to make sure required fields are supplied
            $RequiredFields = [
                "Label",
                "Type",
            ];
            foreach ($RequiredFields as $RequiredField) {
                if (!isset($SettingValues[$RequiredField])) {
                    throw new Exception("No \"".$RequiredField."\" provided"
                            ." for configuration field \"".$SettingName."\".");
                }
            }

            # check to make sure that get/set functions are paired
            if (isset($SettingValues["GetFunction"])
                    && !isset($SettingValues["SetFunction"])) {
                throw new Exception("Get function provided for configuration field \""
                        .$SettingName."\" but no set function.");
            }
            if (isset($SettingValues["SetFunction"])
                    && !isset($SettingValues["GetFunction"])) {
                throw new Exception("Set function provided for configuration field \""
                        .$SettingName."\" but no get function.");
            }

            # check to make sure that field type is one we know about
            $SettingType = $SettingValues["Type"];
            if (!isset(static::$TypeTranslations[$SettingType])
                    && !isset(static::$TypesNotStored[$SettingType])) {
                throw new Exception("Unknown type (\"".$SettingType."\")"
                        ." for configuration field \"".$SettingName."\".");
            }
        }
    }

    /**
     * Check that supplied field list is valid for datastore-backed settings.
     * @param array $Fields Configuration storage field list, with "Type",
     *      "Default", and "Description" entries for each field.
     * @throws InvalidArgumentException If no type is specified for a field.
     * @throws InvalidArgumentException If an invalid type is specified.
     * @throws InvalidArgumentException If no default is specified for a field.
     * @throws InvalidArgumentException If no description is specified.
     */
    protected static function checkFieldsList(array $Fields): void
    {
        foreach ($Fields as $FieldName => $FieldInfo) {
            # check that type is specified
            if (!isset($FieldInfo["Type"])) {
                throw new InvalidArgumentException("No type specified for field \""
                    .$FieldName."\".");
            # check that specified type is valid
            } elseif (StdLib::getConstantName(__CLASS__, $FieldInfo["Type"], "TYPE_")
                    === null) {
                throw new InvalidArgumentException("Invalid type specified for field \""
                        .$FieldName."\".");
            }

            # check that valid value list has entries if specified
            if (isset($FieldInfo["ValidValues"])) {
                if (!is_array($FieldInfo["ValidValues"])) {
                    throw new InvalidArgumentException("Valid values list supplied"
                            ." that is not an array.");
                }
                if (!count($FieldInfo["ValidValues"])) {
                    throw new InvalidArgumentException("Valid values list supplied"
                            ." with no entries.");
                }
            }

            # if default value was specified
            if (array_key_exists("Default", $FieldInfo)) {
                # check that default value is correct type
                static::checkFieldDefaultType(
                    $FieldName,
                    $FieldInfo["Default"],
                    $FieldInfo["Type"]
                );
                # check that default value is valid
                if ($FieldInfo["Default"] !== null) {
                    self::checkValue($FieldInfo, $FieldName, $FieldInfo["Default"]);
                }
            # else if default-retrieval function was specified
            } elseif (array_key_exists("DefaultFunction", $FieldInfo)) {
                if (!is_callable($FieldInfo["DefaultFunction"])) {
                    throw new InvalidArgumentException("Uncallable default function"
                            ." specified for field \"".$FieldName."\".");
                }
            # else error out if field was not explicitly marked as not having a default
            } elseif (!($FieldInfo["NoDefault"] ?? false)) {
                throw new InvalidArgumentException("No default or"
                        ." default-retrieval function specified for field \""
                        .$FieldName."\".");
            }

            # check that description is specified
            if (!isset($FieldInfo["Description"])) {
                throw new InvalidArgumentException("No description specified for"
                        ." field \"".$FieldName."\".");
            }

            # check that minimum or maximum are not specified for non-numeric field
            if (!self::isNumericFieldType($FieldInfo["Type"])) {
                if (isset($FieldInfo["MinVal"])) {
                    throw new InvalidArgumentException("Minimum value specified"
                            ." for non-numeric field \"".$FieldName."\".");
                }
                if (isset($FieldInfo["MaxVal"])) {
                    throw new InvalidArgumentException("Maximum value specified"
                            ." for non-numeric field \"".$FieldName."\".");
                }
            }
        }
    }

    /**
     * Check that field default value has a valid type.
     * @param string $FieldName Name of field.
     * @param mixed $Default Default value.
     * @param string $Type Field type.
     * @return void
     */
    protected static function checkFieldDefaultType(
        string $FieldName,
        $Default,
        string $Type
    ): void {
        if ($Default !== null) {
            switch ($Type) {
                case self::TYPE_ARRAY:
                    if (!is_array($Default)) {
                        throw new InvalidArgumentException(
                            "Default value for field \"".$FieldName
                                    ."\" of type ARRAY is not an array."
                        );
                    }
                    break;

                case self::TYPE_BOOL:
                    if (!is_bool($Default)) {
                        throw new InvalidArgumentException(
                            "Default value for field \"".$FieldName
                                    ."\" of type BOOL is not true or false."
                        );
                    }
                    break;

                case self::TYPE_DATETIME:
                    if (!is_numeric($Default) && (strtotime($Default) === false)) {
                        throw new InvalidArgumentException(
                            "Default value for field \"".$FieldName
                                    ."\" of type DATETIME is not a Unix timestamp"
                                    ." or a parseable date."
                        );
                    }
                    break;

                case self::TYPE_FLOAT:
                case self::TYPE_INT:
                    if (!is_numeric($Default)) {
                        $TypeName = ($Type == self::TYPE_INT) ? "INT" : "FLOAT";
                        throw new InvalidArgumentException(
                            "Default value for field \"".$FieldName
                                    ."\" of type ".$TypeName." is not a number."
                        );
                    }
                    break;

                case self::TYPE_STRING:
                    if (!is_string($Default)) {
                        throw new InvalidArgumentException(
                            "Default value for field \"".$FieldName
                                    ."\" of type STRING is not a string."
                        );
                    }
                    break;
            }
        }
    }

    /**
     * Convert setting definitions (formatted for use by FormUI) to storage
     * field definitions (formatted for use by Datastore).
     * @param array $Settings Setting definitions.
     * @return array Field definitions.
     */
    protected static function convertSettingsToDatastoreFields(array $Settings): array
    {
        $DSFields = [];
        foreach ($Settings as $SettingName => $SettingValues) {
            # skip types that we do not store
            $SettingType = $SettingValues["Type"];
            if (isset(static::$TypesNotStored[$SettingType])) {
                continue;
            }
            # skip settings that have their own get/set functions
            if (isset($SettingValues["GetFunction"])) {
                continue;
            }

            $DSSettingValues = $SettingValues;

            # use explicit storage type if provided
            if (isset($SettingValues["StorageType"])) {
                $DSSettingValues["Type"] = $SettingValues["StorageType"];
            # else storage type is array if multiple values are allowed
            } elseif (($SettingValues["AllowMultiple"] ?? false) === true) {
                $DSSettingValues["Type"] = self::TYPE_ARRAY;
            # else use translated storage type if available
            } elseif (isset(static::$TypeTranslations[$SettingType])
                    && (static::$TypeTranslations[$SettingType] !== false)) {
                $DSSettingValues["Type"] = static::$TypeTranslations[$SettingType];
            } else {
                throw new Exception("Storage type unavailable for configuration"
                        ." field \"".$SettingName."\".");
            }

            # omit validation function if not callable because FormUI dynamic method
            if (isset($SettingValues["ValidateFunction"])
                    && is_array($SettingValues["ValidateFunction"])
                    && ($SettingValues["ValidateFunction"][0] == "Metavus\\FormUI")) {
                unset($DSSettingValues["ValidateFunction"]);
            }

            # use form label as storage field description
            $DSSettingValues["Description"] = $SettingValues["Label"];

            # if file or image field, set flag indicating no default value
            if (($SettingType == FormUI::FTYPE_FILE)
                    || ($SettingType == FormUI::FTYPE_IMAGE)) {
                if (!isset($SettingValues["Default"])
                        && !isset($SettingValues["DefaultFunction"])
                        && !isset($SettingValues["NoDefault"])) {
                    $DSSettingValues["NoDefault"] = true;
                }
            }

            # add storage definition to list
            $DSFields[$SettingName] = $DSSettingValues;
        }
        return $DSFields;
    }

    /**
     * Get current setting value, in format usable by FormUI, ignoring any
     * value overrides.
     * @param string $SettingName Name of setting.
     * @return string|bool|int|array|null Value suitable for use by FormUI,
     *      or NULL if no value available.
     */
    protected function getSettingValueForUseByForm(string $SettingName)
    {
        $SettingDefinition = $this->SettingDefinitions[$SettingName];
        $SettingType = $SettingDefinition["Type"];
        # if a "get" function is provided, use that to get the value
        if (isset($SettingDefinition["GetFunction"])) {
            $Value = $SettingDefinition["GetFunction"]($SettingName);
        # else if field is not one that we do not store and currently has a value
        } elseif (!isset(static::$TypesNotStored[$SettingType])
                && $this->isSet($SettingName)) {
            $StorageType = $this->getStorageType($SettingName);
            switch ($StorageType) {
                case self::TYPE_ARRAY:
                    $Value = $this->getRawArray($SettingName);
                    break;

                case self::TYPE_BOOL:
                    $Value = $this->getRawBool($SettingName);
                    break;

                case self::TYPE_DATETIME:
                    $Value = $this->getRawDatetime($SettingName);
                    break;

                case self::TYPE_INT:
                    $Value = $this->getRawInt($SettingName);
                    break;

                case self::TYPE_STRING:
                case self::TYPE_EMAIL:
                case self::TYPE_IPADDRESS:
                case self::TYPE_URL:
                    $Value = $this->getRawString($SettingName);
                    break;

                default:
                    $TypeConstantName = StdLib::getConstantName(
                        "Metavus\\FormUI",
                        $SettingDefinition["Type"],
                        "FTYPE_"
                    );
                    throw new Exception("Setting type (".$TypeConstantName
                            .") not supported for field \"".$SettingName
                            ."\" when getting value for form.");
            }
        } else {
            # return NULL if no value available
            $Value = null;
        }

        return $Value;
    }

    /**
     * Get storage type for specified setting.
     * @param string $SettingName Name of setting.
     * @return string Storage type (TYPE_* constant value).
     */
    protected function getStorageType(string $SettingName): string
    {
        if (!isset($this->StorageTypes[$SettingName])) {
            $SettingDefinition = $this->SettingDefinitions[$SettingName];
            # if the field allows multiple values, storage type is an array
            if ($SettingDefinition["AllowMultiple"] ?? false) {
                $this->StorageTypes[$SettingName] = self::TYPE_ARRAY;
            # else use storage type if one explicitly supplied, but otherwise
            #       translate form field type to storage type
            } else {
                $this->StorageTypes[$SettingName] = $SettingDefinition["StorageType"]
                        ?? self::$TypeTranslations[$SettingDefinition["Type"]];
            }
        }
        return $this->StorageTypes[$SettingName];
    }

    /**
     * Get visible setting definitions in form-ready format.
     * @return array Visible setting definitions.
     */
    protected function getVisibleSettingDefinitions(): array
    {
        if (!isset($this->VisibleSettingDefinitions)) {
            $this->VisibleSettingDefinitions = [];
            foreach ($this->SettingDefinitions as $SettingName => $Definition) {
                if ($Definition["Hidden"] ?? false) {
                    continue;
                }
                unset($Definition["StorageType"]);
                unset($Definition["GetFunction"]);
                unset($Definition["SetFunction"]);
                $this->VisibleSettingDefinitions[$SettingName] = $Definition;
            }
        }
        return $this->VisibleSettingDefinitions;
    }
}
