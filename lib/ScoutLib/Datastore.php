<?PHP
#
#   FILE:  Datastore.php
#
#   Part of the ScoutLib application support library
#   Copyright 2019-2024 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use Exception;
use InvalidArgumentException;

/**
 * Class for storing/retrieving a set of prescribed values in a database table.
 * Constructor is protected, so implementing classes must either override with a
 * public constructor, or provide another way to instantiate.
 *
 * The $Fields parameter to the constructor (which defines the values to be
 * stored) is an array of associative arrays, with the outer index being field
 * names, and the inner index consisting of the following value:
 *      REQUIRED
 *      "Default" - Default value for field.  (May be omitted if
 *          "DefaultFunction" or "NoDefault" is specified.)
 *      "Description" - Printable plain text description of field.
 *      "Type" - The field type (TYPE_ constant).
 *      OPTIONAL
 *      "DefaultFunction" - Function to call to obtain a default value if
 *          "Default" is not specified.  Passed the field name (a string)
 *          and expected to return a default value.
 *      "NoDefault" - If TRUE, field has no default value.
 *      "MaxVal" - Maximum value for field.  (TYPE_INT and TYPE_FLOAT only)
 *      "MinVal" - Maximum value for field.  (TYPE_INT and TYPE_FLOAT only)
 *      "ValidateFunction" - Function to call to validate values, should
 *          have signature:
 *              validateFunct(string $FieldName, $Value): string
 *          and return NULL if the value is valid, or a string with a message
 *          about why the value is invalid.  If this parameter is supplied,
 *          "MaxVal", "MinVal", and "ValidValues" will be ignored.
 *      "ValidValues" - Array of valid values for field.
 */
abstract class Datastore
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    # field types
    const TYPE_ARRAY = "TYPE_ARRAY";
    const TYPE_BOOL = "TYPE_BOOL";
    const TYPE_DATETIME = "TYPE_DATETIME";
    const TYPE_EMAIL = "TYPE_EMAIL";            # use get/setString() to get/set
    const TYPE_FLOAT = "TYPE_FLOAT";
    const TYPE_INT = "TYPE_INT";
    const TYPE_IPADDRESS = "TYPE_IPADDRESS";    # use get/setString() to get/set
    const TYPE_STRING = "TYPE_STRING";
    const TYPE_URL = "TYPE_URL";                # use get/setString() to get/set

    /**
     * Get array field value.  If no value has been set, this will throw an
     * exception.  (Use isSet() first, to check whether a value has been set.)
     * @param string $FieldName Name of field.
     * @return array Current value for field.
     * @throws Exception If no value is available.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     */
    public function getArray(string $FieldName): array
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_ARRAY ]);
        $this->checkThatValueIsAvailable($FieldName);
        return $this->OverrideValues[$FieldName] ?? $this->Values[$FieldName];
    }

    /**
     * Set array field value.
     * @param string $FieldName Name of field.
     * @param array $Value New value for field.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     * @throws InvalidArgumentException If value is invalid for field.
     */
    public function setArray(string $FieldName, array $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_ARRAY ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->updateValueInDatabase($FieldName, $Value);
        $this->Values[$FieldName] = $Value;
    }

    /**
     * Get boolean field value.  If no value has been set, this will throw an
     * exception.  (Use isSet() first, to check whether a value has been set.)
     * @param string $FieldName Name of field.
     * @return bool Current value for field.
     * @throws Exception If no value is available.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     */
    public function getBool(string $FieldName): bool
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_BOOL ]);
        $this->checkThatValueIsAvailable($FieldName);
        return $this->OverrideValues[$FieldName] ?? $this->Values[$FieldName];
    }

    /**
     * Set boolean field value.
     * @param string $FieldName Name of field.
     * @param bool $Value New value for field.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     * @throws InvalidArgumentException If value is invalid for field.
     */
    public function setBool(string $FieldName, bool $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_BOOL ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->updateValueInDatabase($FieldName, $Value);
        $this->Values[$FieldName] = $Value;
    }

    /**
     * Get date/time field value.  If no value has been set, this will throw an
     * exception.  (Use isSet() first, to check whether a value has been set.)
     * @param string $FieldName Name of field.
     * @return int Current value for field, as a Unix timestamp.
     * @throws Exception If no value is available.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     */
    public function getDatetime(string $FieldName): int
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_DATETIME ]);
        $this->checkThatValueIsAvailable($FieldName);
        return strtotime($this->OverrideValues[$FieldName]
                ?? $this->Values[$FieldName]);
    }

    /**
     * Set date/time field value.
     * @param string $FieldName Name of field.
     * @param int|string $Value New value for field, as a Unix timestamp or in
     *      any format parseable by strtotime().
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     * @throws InvalidArgumentException If value is invalid for field.
     */
    public function setDatetime(string $FieldName, $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_DATETIME ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->Values[$FieldName] = $this->convertDateToDatabaseFormat($Value);
        $this->updateValueInDatabase(
            $FieldName,
            $this->Values[$FieldName]
        );
    }

    /**
     * Get float field value.  If no value has been set, this will throw an
     * exception.  (Use isSet() first, to check whether a value has been set.)
     * @param string $FieldName Name of field.
     * @return float Current value for field.
     * @throws Exception If no value is available.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     */
    public function getFloat(string $FieldName): float
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_FLOAT ]);
        $this->checkThatValueIsAvailable($FieldName);
        return $this->OverrideValues[$FieldName] ?? $this->Values[$FieldName];
    }

    /**
     * Set float field value.
     * @param string $FieldName Name of field.
     * @param float $Value New value for field.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     * @throws InvalidArgumentException If value is invalid for field.
     */
    public function setFloat(string $FieldName, float $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_FLOAT ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->updateValueInDatabase($FieldName, (string)$Value);
        $this->Values[$FieldName] = $Value;
    }

    /**
     * Get integer field value.  If no value has been set, this will throw an
     * exception.  (Use isSet() first, to check whether a value has been set.)
     * @param string $FieldName Name of field.
     * @return int Current value for field.
     * @throws Exception If no value is available.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     */
    public function getInt(string $FieldName): int
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_INT ]);
        $this->checkThatValueIsAvailable($FieldName);
        return $this->OverrideValues[$FieldName] ?? $this->Values[$FieldName];
    }

    /**
     * Set integer field value.
     * @param string $FieldName Name of field.
     * @param int $Value New value for field.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     * @throws InvalidArgumentException If value is invalid for field.
     */
    public function setInt(string $FieldName, int $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_INT ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->updateValueInDatabase($FieldName, (string)$Value);
        $this->Values[$FieldName] = $Value;
    }

    /**
     * Get string-value (string, email, IP address, or URL) field value.
     * If no value has been set, this will throw an exception.  (Use isSet()
     * first, to check whether a value has been set.)
     * @param string $FieldName Name of field.
     * @return string Current value for field.
     * @throws Exception If no value is available.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     */
    public function getString(string $FieldName): string
    {
        $this->checkFieldNameAndType($FieldName, self::$StringBasedTypes);
        $this->checkThatValueIsAvailable($FieldName);
        return $this->OverrideValues[$FieldName] ?? $this->Values[$FieldName];
    }

    /**
     * Set string-value (string, email, IP address, or URL) field value.
     * @param string $FieldName Name of field.
     * @param string $Value New value for field.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type does not match.
     * @throws InvalidArgumentException If value is invalid for field.
     */
    public function setString(string $FieldName, string $Value): void
    {
        $this->checkFieldNameAndType($FieldName, self::$StringBasedTypes);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->updateValueInDatabase($FieldName, $Value);
        $this->Values[$FieldName] = $Value;
    }

    /**
     * Check whether a field has a value set.  (This is regardless of
     * whether there is default value for the field.)
     * @param string $FieldName Name of field.
     * @return bool TRUE if value is set, otherwise FALSE.
     * @see Datastore::unset()
     */
    public function isSet(string $FieldName): bool
    {
        $ColumnName = Database::normalizeToColumnName($FieldName);
        return ($this->RawValues[$ColumnName] === null) ? false : true;
    }

    /**
     * Unset a field, so that it has no value set.  This is independent of
     * whether there is a default value for the field;  if a field is unset
     * and it has a default, the value method will return the default.
     * @param string $FieldName Name of field.
     * @see Datastore::isSet()
     */
    public function unset(string $FieldName): void
    {
        $this->updateValueInDatabase($FieldName, null);
    }

    /**
     * Get field type.
     * @return string Field type (TYPE_ constant).
     */
    public function getFieldType(string $FieldName): string
    {
        if (!isset($this->Fields[$FieldName])) {
            throw new InvalidArgumentException("Unknown field name \"".$FieldName.".\".");
        }
        return $this->Fields[$FieldName]["Type"];
    }

    /**
     * Check whether field exists with specified name.
     * @param string $FieldName Name of field.
     * @return bool TRUE if field exists, otherwise FALSE.
     */
    public function fieldExists(string $FieldName): bool
    {
        return isset($this->Fields[$FieldName]) ? true : false;
    }

    /**
     * Get list of fields.
     * @return array Field names.
     */
    public function getFields(): array
    {
        return array_keys($this->Fields);
    }

    /**
     * Set override value for array field.  The override value will
     * returned by getArray() in place of any existing value, for the
     * duration of the current page load.
     * @param string $FieldName Name of field.
     * @param array $Value New value for field.
     */
    public function overrideArray(string $FieldName, array $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_ARRAY ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->OverrideValues[$FieldName] = $Value;
    }

    /**
     * Set override value for boolean field.  The override value will
     * returned by getBool() in place of any existing value, for the
     * duration of the current page load.
     * @param string $FieldName Name of field.
     * @param bool $Value New value for field.
     */
    public function overrideBool(string $FieldName, bool $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_BOOL ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->OverrideValues[$FieldName] = $Value;
    }

    /**
     * Set override value for date/time field.  The override value will
     * returned by getDatetime() in place of any existing value, for the
     * duration of the current page load.
     * @param string $FieldName Name of field.
     * @param string $Value New value for field.
     */
    public function overrideDatetime(string $FieldName, string $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_DATETIME ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->OverrideValues[$FieldName] = $Value;
    }

    /**
     * Set override value for float field.  The override value will
     * returned by getFloat() in place of any existing value, for the
     * duration of the current page load.
     * @param string $FieldName Name of field.
     * @param float $Value New value for field.
     */
    public function overrideFloat(string $FieldName, float $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_FLOAT ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->OverrideValues[$FieldName] = $Value;
    }

    /**
     * Set override value for integer field.  The override value will
     * returned by getInt() in place of any existing value, for the
     * duration of the current page load.
     * @param string $FieldName Name of field.
     * @param int $Value New value for field.
     */
    public function overrideInt(string $FieldName, int $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_INT ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->OverrideValues[$FieldName] = $Value;
    }

    /**
     * Set override value for string field.  The override value will
     * returned by getString() in place of any existing value, for the
     * duration of the current page load.
     * @param string $FieldName Name of field.
     * @param string $Value New value for field.
     */
    public function overrideString(string $FieldName, string $Value): void
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_STRING ]);
        self::checkValue($this->Fields[$FieldName], $FieldName, $Value);
        $this->OverrideValues[$FieldName] = $Value;
    }

    /**
     * Check whether a field currently has an override value set.
     */
    public function isOverridden(string $FieldName): bool
    {
        if (!isset($this->Fields[$FieldName])) {
            throw new InvalidArgumentException("Invalid field name \""
                    .$FieldName."\".");
        }
        return isset($this->OverrideValues[$FieldName]) ? true : false;
    }

    /**
     * Clear any existing override value for a field.
     * @param string $FieldName Name of field.
     */
    public function clearOverride(string $FieldName): void
    {
        if (!isset($this->Fields[$FieldName])) {
            throw new InvalidArgumentException("Invalid field name \""
                    .$FieldName."\".");
        }
        if (isset($this->OverrideValues[$FieldName])) {
            unset($this->OverrideValues[$FieldName]);
        }
    }

    /**
     * Get array field value, ignoring any override value set.
     * @param string $FieldName Name of field.
     * @return array Current value for field.
     */
    public function getRawArray(string $FieldName): array
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_ARRAY ]);
        $this->checkThatValueIsAvailable($FieldName);
        return $this->Values[$FieldName];
    }

    /**
     * Get boolean field value, ignoring any override value set.
     * @param string $FieldName Name of field.
     * @return bool Current value for field.
     */
    public function getRawBool(string $FieldName): bool
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_BOOL ]);
        $this->checkThatValueIsAvailable($FieldName);
        return $this->Values[$FieldName];
    }

    /**
     * Get date/time field value, ignoring any override value set.
     * @param string $FieldName Name of field.
     * @return int Current value for field, as a Unix timestamp.
     */
    public function getRawDatetime(string $FieldName): int
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_DATETIME ]);
        $this->checkThatValueIsAvailable($FieldName);
        return strtotime($this->Values[$FieldName]);
    }

    /**
     * Get float field value, ignoring any override value set.
     * @param string $FieldName Name of field.
     * @return float Current value for field.
     */
    public function getRawFloat(string $FieldName): float
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_FLOAT ]);
        $this->checkThatValueIsAvailable($FieldName);
        return $this->Values[$FieldName];
    }

    /**
     * Get integer field value, ignoring any override value set.
     * @param string $FieldName Name of field.
     * @return int Current value for field.
     */
    public function getRawInt(string $FieldName): int
    {
        $this->checkFieldNameAndType($FieldName, [ self::TYPE_INT ]);
        $this->checkThatValueIsAvailable($FieldName);
        return $this->Values[$FieldName];
    }

    /**
     * Get string-value (string, email, IP address, or URL) field value,
     * ignoring any override value set.
     * @param string $FieldName Name of field.
     * @return string Current value for field.
     */
    public function getRawString(string $FieldName): string
    {
        $this->checkFieldNameAndType($FieldName, self::$StringBasedTypes);
        $this->checkThatValueIsAvailable($FieldName);
        return $this->Values[$FieldName];
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $DB;
    protected $Fields;
    protected $FieldsWithDefaultNotYetLoaded = [];
    protected $DbTableName;
    protected $OverrideValues = [];
    protected $RawValues;
    protected $SelectorClause = "";
    protected $SelectorColumn;
    protected $SelectorValue;
    protected $Values;

    protected static $StringBasedTypes = [
        self::TYPE_EMAIL,
        self::TYPE_IPADDRESS,
        self::TYPE_STRING,
        self::TYPE_URL,
    ];

    /**
     * Object constructor.
     * @param array $Fields List of fields in which to store values, with field
     *      name for the index, and an associative array with "Type", "Default",
     *      and "Description" entries for each field.
     * @param string $DbTableName Name of database table in which to save values.
     */
    protected function __construct(array $Fields, string $DbTableName)
    {
        if (!isset($this->DB)) {
            $this->DB = new Database();
        }
        $this->Fields = $Fields;
        $this->DbTableName = $DbTableName;
        $this->checkDatabaseTable();
        $this->loadFieldsFromDatabase();
    }

    /**
     * Set parameters to select specific row when reading data from or updating
     * data to the database.  This is intended to be used from within child class
     * constructors, before they call the constructor for Datastore.
     * @param string $Column Name of TEXT column to search for value.
     * @param string $Value Value to match.
     */
    protected function setSelector(string $Column, string $Value): void
    {
        $this->SelectorColumn = $Column;
        $this->SelectorValue = $Value;

        if (!isset($this->DB)) {
            $this->DB = new Database();
        }
        $this->SelectorClause = " WHERE `".$this->DB->escapeString($Column)
                ."` = '".$this->DB->escapeString($Value)."'";
    }

    /**
     * Check that supplied fields table is all valid.
     * @param array $Fields System configuration fields list, with "Type",
     *      "Default", and "Description" entries for each field.
     * @throws InvalidArgumentException If no type is specified for a field.
     * @throws InvalidArgumentException If an invalid type is specified for a field.
     * @throws InvalidArgumentException If no default is specified for a field.
     * @throws InvalidArgumentException If no description is specified for a field.
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
                self::checkFieldDefaultType(
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
     * Check table in database and add any columns that are missing.
     * @throws InvalidArgumentException If database table does not exist.
     */
    protected function checkDatabaseTable(): void
    {
        if (!$this->DB->tableExists($this->DbTableName)) {
            $this->DB->query("CREATE TABLE ".$this->DbTableName
                    ." ( Placeholder_Column INT )");
            $ClearPlaceholder = true;
        }

        $ColumnTypes = [
            self::TYPE_ARRAY => "BLOB",
            self::TYPE_BOOL => "INT",
            self::TYPE_DATETIME => "DATETIME",
            self::TYPE_EMAIL => "TEXT",
            self::TYPE_FLOAT => "FLOAT",
            self::TYPE_INT => "INT",
            self::TYPE_IPADDRESS => "TEXT",
            self::TYPE_STRING => "TEXT",
            self::TYPE_URL => "TEXT",
        ];
        foreach ($this->Fields as $FieldName => $FieldInfo) {
            $ColumnName = Database::normalizeToColumnName($FieldName);
            if (!$this->DB->fieldExists($this->DbTableName, $ColumnName)) {
                if (!isset($ColumnTypes[$FieldInfo["Type"]])) {
                    throw new Exception("Unknown type (\"".$FieldInfo["Type"]."\")"
                            ." for field \"".$FieldName."\".");
                }
                $Query = "ALTER TABLE ".$this->DbTableName." ADD COLUMN `"
                        .$ColumnName."` ".$ColumnTypes[$FieldInfo["Type"]];
                $this->DB->query($Query);
            }
        }

        # add selector column if configured and not currently present
        if (isset($this->SelectorColumn)) {
            if (!$this->DB->fieldExists($this->DbTableName, $this->SelectorColumn)) {
                $Query = "ALTER TABLE ".$this->DbTableName." ADD COLUMN `"
                        .$this->SelectorColumn."` TEXT";
                $this->DB->query($Query);
            }
        }

        if (isset($ClearPlaceholder)) {
            $this->DB->query("ALTER TABLE ".$this->DbTableName
                    ." DROP COLUMN Placeholder_Column");
        }
    }

    /**
     * Check to make sure that value is available for field, loading a
     * default value if appropriate and one has not yet been loaded.
     * @param string $FieldName Name of field.
     * @throws Exception If no value is available.
     */
    protected function checkThatValueIsAvailable(string $FieldName): void
    {
        # if field is tagged to have default value loaded
        if (isset($this->FieldsWithDefaultNotYetLoaded[$FieldName])) {
            # retrieve default value for field
            $this->Values[$FieldName] = $this->getDefaultValue($FieldName);
            unset($this->FieldsWithDefaultNotYetLoaded[$FieldName]);

            # if no raw value for field was found in DB earlier
            #       and there is a default value now available
            if (($this->RawValues[$FieldName] === null)
                    && ($this->Values[$FieldName] !== null)) {
                # set field value in database to default
                $this->updateValueInDatabase(
                    $FieldName,
                    $this->Values[$FieldName]
                );
            }
        }
        if (($this->Values[$FieldName] === null)
                && !isset($this->OverrideValues[$FieldName])
                && !($this->Fields[$FieldName]["NoDefault"] ?? false)) {
            throw new Exception("No value is available for field \""
                    .$FieldName."\" in table \"".$this->DbTableName."\".");
        }
    }

    /**
     * Convert a date/time value to format suitable for storing in a DATETIME
     * column in the database.
     * @param int|string $Date Date value to convert (Unix timestamp or in any
     *      format parseable by strtotime()).
     * @return string Date normalized for database storage.
     */
    protected function convertDateToDatabaseFormat($Date): string
    {
        if (!is_numeric($Date)) {
            $Result = strtotime($Date);
            if ($Result === false) {
                throw new InvalidArgumentException("Unrecognized date format"
                        ." provided (\"".$Date."\").");
            }
            $Date = $Result;
        }
        return date(StdLib::SQL_DATE_FORMAT, (int)$Date);
    }

    /**
     * Load current fields values from database.
     */
    protected function loadFieldsFromDatabase(): void
    {
        # attempt to retrieve current values from database
        $this->DB->query("LOCK TABLES ".$this->DbTableName." WRITE");
        $Query = "SELECT * FROM `".$this->DbTableName."`".$this->SelectorClause;
        $this->DB->query($Query);

        # if no row with values was found in database
        $RowsSelected = $this->DB->numRowsSelected();
        if ($RowsSelected == 0) {
            # add row with values to database
            $this->addNewRowToDatabase();

            # re-query database to get values from newly-added row
            $this->DB->query($Query);
        # else if more than one row was found in database
        } elseif ($RowsSelected > 1) {
            # error out (should never be multiple matching rows)
            $this->DB->query("UNLOCK TABLES");
            throw new Exception("Multiple rows unexpectedly found in"
                    ." datastore table ".$this->DbTableName.".");
        }

        # retrieve raw values from query
        $this->RawValues = $this->DB->fetchRow();
        $this->DB->query("UNLOCK TABLES");

        # for each field
        foreach ($this->Fields as $FieldName => $FieldInfo) {
            # if no raw value was found for this field
            $ColumnName = Database::normalizeToColumnName($FieldName);
            if ($this->RawValues[$ColumnName] === null) {
                # if "lazy" loading of default values is appropriate for this field
                if ($this->isLazyDefaultForField($FieldName)) {
                    # tag field to have default value loaded when needed
                    $this->FieldsWithDefaultNotYetLoaded[$FieldName] = true;
                } else {
                    # load default value for field
                    $this->Values[$FieldName] = $this->getDefaultValue($FieldName);
                }
            } else {
                # convert raw value to form that we can use (return upon request)
                $this->Values[$FieldName] = self::convertValueFromStorage(
                    $this->RawValues[$ColumnName],
                    $FieldInfo["Type"]
                );
            }
        }
    }

    /**
     * Retrieve default value for specified field.
     * @param string $FieldName Name of field.
     * @return mixed Default value for field or NULL if field has no default.
     */
    protected function getDefaultValue(string $FieldName)
    {
        if (array_key_exists("Default", $this->Fields[$FieldName])) {
            return $this->Fields[$FieldName]["Default"];
        } elseif (array_key_exists("DefaultFunction", $this->Fields[$FieldName])) {
            return $this->Fields[$FieldName]["DefaultFunction"]($FieldName);
        } else {
            return null;
        }
    }

    /**
     * Check if field name is valid and of one of the specified types,
     * throwing an exception if not.
     * @param string $Name Name of field.
     * @param array $Types Possible received field types.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws InvalidArgumentException If field type is invalid.
     */
    protected function checkFieldNameAndType(string $Name, array $Types): void
    {
        if (!isset($this->Fields[$Name])) {
            throw new InvalidArgumentException("Invalid field name \""
                    .$Name."\".");
        }
        if (!in_array($this->Fields[$Name]["Type"], $Types)) {
            $ReceivedTypes = [];
            foreach ($Types as $Type) {
                $ReceivedTypes[] = StdLib::getConstantName(
                    __CLASS__,
                    $Type,
                    "TYPE_"
                );
            }
            $Received = join("|", $ReceivedTypes);
            $Expected = StdLib::getConstantName(
                __CLASS__,
                $this->Fields[$Name]["Type"],
                "TYPE_"
            ) ?? "(unknown)";
            throw new InvalidArgumentException("Attempt to access field \""
                    .$Name."\" of type ".$Expected
                    ." by calling method for type ".$Received);
        }
    }

    /**
     * Check validity of value for specified field.
     * @param array $FieldInfo Settings for field.
     * @param string $FieldName Name of field.
     * @param mixed $Value Value to check.
     */
    protected static function checkValue(
        array $FieldInfo,
        string $FieldName,
        $Value
    ): void {
        if (isset($FieldInfo["ValidateFunction"])) {
            self::checkValueUsingValidationFunction(
                $FieldInfo["ValidateFunction"],
                $FieldName,
                $Value
            );
        } else {
            self::checkValueUsingTypeConstraints(
                $FieldInfo,
                $FieldName,
                $Value
            );
        }
    }

    /**
     * Check validity of value using validation function.
     * @param callable $Function Validation function.
     * @param string $FieldName Name of field.
     * @param mixed $Value Value to check.
     * @throws InvalidArgumentException If value is found to be invalid.
     */
    protected static function checkValueUsingValidationFunction(
        callable $Function,
        string $FieldName,
        $Value
    ): void {
        $ErrMsg = call_user_func($Function, $FieldName, $Value);
        if ($ErrMsg === false) {
            throw new Exception("Calling validation function for"
                    ." field \"".$FieldName."\" failed.");
        }
        if ($ErrMsg !== null) {
            throw new InvalidArgumentException("Invalid value (\""
                    .$Value."\") for field \"".$FieldName."\": ".$ErrMsg);
        }
    }

    /**
     * Check validity of value based on field type constraints.
     * @param array $FieldInfo Settings for field.
     * @param string $FieldName Name of field.
     * @param mixed $Value Value to check.
     * @throws InvalidArgumentException If value is found to be invalid.
     */
    protected static function checkValueUsingTypeConstraints(
        array $FieldInfo,
        string $FieldName,
        $Value
    ): void {
        $Filters = [
            self::TYPE_EMAIL => FILTER_VALIDATE_EMAIL,
            self::TYPE_FLOAT => FILTER_VALIDATE_FLOAT,
            self::TYPE_INT => FILTER_VALIDATE_INT,
            self::TYPE_IPADDRESS => FILTER_VALIDATE_IP,
            self::TYPE_URL => FILTER_VALIDATE_URL,
        ];
        if (isset($Filters[$FieldInfo["Type"]])) {
            $Result = filter_var($Value, $Filters[$FieldInfo["Type"]]);
            if ($Result === false) {
                throw new InvalidArgumentException("Invalid value (\""
                        .$Value."\") for field \"".$FieldName."\".");
            }
        }

        if (isset($FieldInfo["ValidValues"])
                && !in_array($Value, $FieldInfo["ValidValues"])) {
            throw new InvalidArgumentException("Value (\"".$Value
                    ."\") for field \"".$FieldName."\" is not in list"
                    ." of valid values.");
        }

        switch ($FieldInfo["Type"]) {
            case self::TYPE_DATETIME:
                if (!is_numeric($Value) && (strtotime($Value) === false)) {
                    throw new InvalidArgumentException("Value (\"".$Value
                            ."\") for field \"".$FieldName."\" was not in"
                            ." a recognized date/time format.");
                }
                break;

            case self::TYPE_FLOAT:
            case self::TYPE_INT:
                if (isset($FieldInfo["MinVal"]) && ($Value < $FieldInfo["MinVal"])) {
                    throw new InvalidArgumentException("Value (\"".$Value
                            ."\") for field \"".$FieldName."\" is below"
                            ." minimum value (\"".$FieldInfo["MinVal"]."\").");
                }
                if (isset($FieldInfo["MaxVal"]) && ($Value > $FieldInfo["MaxVal"])) {
                    throw new InvalidArgumentException("Value (\"".$Value
                            ."\") for field \"".$FieldName."\" is above"
                            ." maximum value (\"".$FieldInfo["MaxVal"]."\").");
                }
                break;
        }
    }

    /**
     * Report whether specified field type is numeric.
     * @param string $Type Type to check.
     * @return bool TRUE if type is numeric, otherwise FALSE.
     */
    protected static function isNumericFieldType(string $Type): bool
    {
        $NumericFieldTypes = [
            self::TYPE_INT,
            self::TYPE_FLOAT,
        ];
        return in_array($Type, $NumericFieldTypes);
    }

    /**
     * Convert supplied value to form to be stored in database, based
     * on specified type for supplied value.
     * @param mixed $Value Value to convert.
     * @param string $Type Type of value being converted.
     * @return string Converted value.
     */
    protected static function convertValueForStorage($Value, string $Type): string
    {
        switch ($Type) {
            case self::TYPE_BOOL:
                return $Value ? "1" : "0";

            case self::TYPE_ARRAY:
                return serialize($Value);
        }
        return $Value;
    }

    /**
     * Convert form stored in database to usable value, based on
     * specified type for usable value.
     * @param string $RawValue Value in stored form.
     * @param string $Type Type of value being converted.
     * @return mixed Converted value.
     */
    protected static function convertValueFromStorage(string $RawValue, string $Type)
    {
        switch ($Type) {
            case self::TYPE_BOOL:
                return $RawValue ? true : false;

            case self::TYPE_ARRAY:
                return unserialize($RawValue);
        }
        return $RawValue;
    }

    /**
     * Update field value in database.
     * @param string $FieldName Name of field.
     * @param mixed $Value Value to save to database, or NULL if value
     *      in database should be unset.
     */
    protected function updateValueInDatabase(
        string $FieldName,
        $Value
    ): void {
        $ColumnName = Database::normalizeToColumnName($FieldName);
        if ($Value === null) {
            $this->RawValues[$ColumnName] = null;
            $QueryValue = "NULL";
        } else {
            $StorableValue = self::convertValueForStorage(
                $Value,
                $this->getFieldType($FieldName)
            );
            $this->RawValues[$ColumnName] = $StorableValue;
            $QueryValue = "'".$this->DB->escapeString($StorableValue)."'";
        }
        $this->DB->query("UPDATE `".$this->DbTableName
                ."` SET `".$ColumnName."` = ".$QueryValue.$this->SelectorClause);
    }

    /**
     * Add new row to database.
     */
    protected function addNewRowToDatabase(): void
    {
        # use selector column if available, otherwise use first defined column
        if (isset($this->SelectorColumn)) {
            $Column = $this->SelectorColumn;
            $Value = $this->SelectorValue;
        } else {
            reset($this->Fields);
            $FieldName = key($this->Fields);
            $Column = Database::normalizeToColumnName($FieldName);
            $Value = $this->getDefaultValue($FieldName);
        }

        # add row to database
        $this->DB->query("INSERT INTO ".$this->DbTableName." SET `"
                .$this->DB->escapeString($Column)."` = '"
                .$this->DB->escapeString($Value)."'");

        # set default values for new row if appropriate
        foreach ($this->Fields as $FieldName => $FieldInfo) {
            if (!$this->isLazyDefaultForField($FieldName)) {
                $this->updateValueInDatabase(
                    $FieldName,
                    $this->getDefaultValue($FieldName)
                );
            }
        }
    }

    /**
     * Check whether retrieval of default value for specified field.
     * should be "lazy" (i.e. not done until actually needed)
     */
    protected function isLazyDefaultForField(string $FieldName): bool
    {
        return !isset($this->Fields[$FieldName]["Default"])
                && isset($this->Fields[$FieldName]["DefaultFunction"]);
    }
}
