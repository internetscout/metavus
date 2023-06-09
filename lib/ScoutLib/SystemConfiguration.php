<?PHP
#
#   FILE:  SystemConfiguration.php
#
#   Part of the ScoutLib application support library
#   Copyright 2019-2021 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

use InvalidArgumentException;

/**
 * Configuration settings storage manager.  The primary thing this class adds
 * over Datastore is the ability to set temporary override values for a given
 * field, that will be returned in place of the saved value.
 */
class SystemConfiguration extends Datastore
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get universal instance of class.
     * @return self Class instance.
     */
    public static function getInstance()
    {
        if (!isset(static::$Instance)) {
            static::$Instance = new static();
        }
        return static::$Instance;
    }

    /**
     * Get/set information about available configuration settings.
     * @param array $NewValue Configuration setting list, with setting names
     *      for the index, and an associative array with "Type", "Default",
     *      and "Description" entries for each setting.  (OPTIONAL)
     * @return array Current configuration settings list.
     */
    public static function settings(array $NewValue = null): array
    {
        if ($NewValue !== null) {
            static::checkFieldsList($NewValue);
            static::$OurSettings = $NewValue;
            if (isset(static::$Instance)) {
                static::$Instance = new static();
            }
        }
        return static::$OurSettings;
    }

    /**
     * Get/set name of database table used to store configuration
     * setting values.  (Defaults to "SystemConfiguration".)
     * @param string $NewValue Name of database table in which to save
     *      settings.  (OPTIONAL)
     * @return string Current name of table.
     */
    public static function dbTableName(string $NewValue = null): string
    {
        if ($NewValue !== null) {
            static::$OurDbTableName = $NewValue;
            if (isset(static::$Instance)) {
                static::$Instance = new static();
            }
        }
        return static::$OurDbTableName;
    }

    /**
     * Set override value for array setting.  The override value will
     * returned by getArray() in place of any existing value, for the
     * duration of the current page load.
     * @param string $SettingName Name of setting.
     * @param array $Value New value for setting.
     */
    public function overrideArray(string $SettingName, array $Value)
    {
        $this->checkFieldNameAndType($SettingName, [ self::TYPE_ARRAY ]);
        self::checkValue($this->Fields[$SettingName], $SettingName, $Value);
        $this->OverrideValues[$SettingName] = $Value;
    }

    /**
     * Set override value for boolean setting.  The override value will
     * returned by getBool() in place of any existing value, for the
     * duration of the current page load.
     * @param string $SettingName Name of setting.
     * @param bool $Value New value for setting.
     */
    public function overrideBool(string $SettingName, bool $Value)
    {
        $this->checkFieldNameAndType($SettingName, [ self::TYPE_BOOL ]);
        self::checkValue($this->Fields[$SettingName], $SettingName, $Value);
        $this->OverrideValues[$SettingName] = $Value;
    }

    /**
     * Set override value for date/time setting.  The override value will
     * returned by getDatetime() in place of any existing value, for the
     * duration of the current page load.
     * @param string $SettingName Name of setting.
     * @param string $Value New value for setting.
     */
    public function overrideDatetime(string $SettingName, string $Value)
    {
        $this->checkFieldNameAndType($SettingName, [ self::TYPE_DATETIME ]);
        self::checkValue($this->Fields[$SettingName], $SettingName, $Value);
        $this->OverrideValues[$SettingName] = $Value;
    }

    /**
     * Set override value for float setting.  The override value will
     * returned by getFloat() in place of any existing value, for the
     * duration of the current page load.
     * @param string $SettingName Name of setting.
     * @param float $Value New value for setting.
     */
    public function overrideFloat(string $SettingName, float $Value)
    {
        $this->checkFieldNameAndType($SettingName, [ self::TYPE_FLOAT ]);
        self::checkValue($this->Fields[$SettingName], $SettingName, $Value);
        $this->OverrideValues[$SettingName] = $Value;
    }

    /**
     * Set override value for integer setting.  The override value will
     * returned by getInt() in place of any existing value, for the
     * duration of the current page load.
     * @param string $SettingName Name of setting.
     * @param int $Value New value for setting.
     */
    public function overrideInt(string $SettingName, int $Value)
    {
        $this->checkFieldNameAndType($SettingName, [ self::TYPE_INT ]);
        self::checkValue($this->Fields[$SettingName], $SettingName, $Value);
        $this->OverrideValues[$SettingName] = $Value;
    }

    /**
     * Set override value for string setting.  The override value will
     * returned by getString() in place of any existing value, for the
     * duration of the current page load.
     * @param string $SettingName Name of setting.
     * @param string $Value New value for setting.
     */
    public function overrideString(string $SettingName, string $Value)
    {
        $this->checkFieldNameAndType($SettingName, [ self::TYPE_STRING ]);
        self::checkValue($this->Fields[$SettingName], $SettingName, $Value);
        $this->OverrideValues[$SettingName] = $Value;
    }

    /**
     * Check whether a setting currently has an override value set.
     */
    public function isOverridden(string $SettingName): bool
    {
        if (!isset($this->Fields[$SettingName])) {
            throw new InvalidArgumentException("Invalid setting name \""
                    .$SettingName."\".");
        }
        return isset($this->OverrideValues[$SettingName]) ? true : false;
    }

    /**
     * Clear any existing override value for a setting.
     * @param string $SettingName Name of setting.
     */
    public function clearOverride(string $SettingName)
    {
        if (!isset($this->Fields[$SettingName])) {
            throw new InvalidArgumentException("Invalid setting name \""
                    .$SettingName."\".");
        }
        if (isset($this->OverrideValues[$SettingName])) {
            unset($this->OverrideValues[$SettingName]);
        }
    }

    /**
     * Get array setting value, with support for overridden values.
     * @param string $SettingName Name of setting.
     * @return array Current value for setting, or overrid value if set.
     */
    public function getArray(string $SettingName): array
    {
        if (isset($this->OverrideValues[$SettingName])) {
            $this->checkFieldNameAndType($SettingName, [ self::TYPE_ARRAY ]);
            return $this->OverrideValues[$SettingName];
        }
        return parent::getArray($SettingName);
    }

    /**
     * Get boolean setting value, with support for overridden values.
     * @param string $SettingName Name of setting.
     * @return bool Current value for setting, or overrid value if set.
     */
    public function getBool(string $SettingName): bool
    {
        if (isset($this->OverrideValues[$SettingName])) {
            $this->checkFieldNameAndType($SettingName, [ self::TYPE_BOOL ]);
            return $this->OverrideValues[$SettingName];
        }
        return parent::getBool($SettingName);
    }

    /**
     * Get date/time setting value, with support for overridden values.
     * @param string $SettingName Name of setting.
     * @return int Current value for setting, or overrid value if set,
     *      in both cases as a Unix timestamp.
     */
    public function getDatetime(string $SettingName): int
    {
        if (isset($this->OverrideValues[$SettingName])) {
            $this->checkFieldNameAndType($SettingName, [ self::TYPE_DATETIME ]);
            return strtotime($this->OverrideValues[$SettingName]);
        }
        return parent::getDatetime($SettingName);
    }

    /**
     * Get float setting value, with support for overridden values.
     * @param string $SettingName Name of setting.
     * @return float Current value for setting, or overrid value if set.
     */
    public function getFloat(string $SettingName): float
    {
        if (isset($this->OverrideValues[$SettingName])) {
            $this->checkFieldNameAndType($SettingName, [ self::TYPE_FLOAT ]);
            return $this->OverrideValues[$SettingName];
        }
        return parent::getFloat($SettingName);
    }

    /**
     * Get integer setting value, with support for overridden values.
     * @param string $SettingName Name of setting.
     * @return int Current value for setting, or overrid value if set.
     */
    public function getInt(string $SettingName): int
    {
        if (isset($this->OverrideValues[$SettingName])) {
            $this->checkFieldNameAndType($SettingName, [ self::TYPE_INT ]);
            return $this->OverrideValues[$SettingName];
        }
        return parent::getInt($SettingName);
    }

    /**
     * Get string setting value, with support for overridden values.
     * @param string $SettingName Name of setting.
     * @return string Current value for setting, or overrid value if set.
     */
    public function getString(string $SettingName): string
    {
        if (isset($this->OverrideValues[$SettingName])) {
            $this->checkFieldNameAndType($SettingName, self::$StringBasedTypes);
            return $this->OverrideValues[$SettingName];
        }
        return parent::getString($SettingName);
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected static $Instance;
    protected static $OurSettings = [];
    protected static $OurDbTableName = "SystemConfiguration";

    protected $OverrideValues = [];

    /**
     * Object constructor.
     */
    protected function __construct()
    {
        parent::__construct(static::$OurSettings, static::$OurDbTableName);
    }
}
