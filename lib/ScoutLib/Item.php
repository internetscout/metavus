<?PHP
#
#   FILE:  Item.php
#
#   Part of the ScoutLib application support library
#   Copyright 2016-2021 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#

namespace ScoutLib;

use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

/**
 * Common base class for persistent items stored in database.
 */
abstract class Item
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** ID value used to indicate no item. */
    const NO_ITEM = -2123456789;

    /**
     * Constructor, used to load existing items.  To create new items, child
     * classes should implement a static Create() method.
     * @param mixed $Id ID of item to load, in a form resolvable by
     *       GetCanonicalId().
     * @throws InvalidArgumentException If ID is invalid.
     * @see GetCanonicalId()
     * @see Create()
     */
    public function __construct($Id)
    {
        # set up database access values
        $ClassName = get_class($this);
        static::setDatabaseAccessValues($ClassName);
        $this->ItemIdColumnName = self::$ItemIdColumnNames[$ClassName];
        $this->ItemNameColumnName = self::$ItemNameColumnNames[$ClassName];
        $this->ItemTableName = self::$ItemTableNames[$ClassName];

        # normalize item ID
        $this->Id = static::getCanonicalId($Id);

        # load item info from database
        $this->DB = new Database();
        $Condition = "`" . $this->ItemIdColumnName . "` = " . intval($this->Id);
        $this->DB->query("SELECT * FROM `" . $this->ItemTableName . "`"
            . " WHERE " . $Condition);
        $ItemValues = $this->DB->fetchRow();

        # error out if item not found in database
        if ($ItemValues === false) {
            throw new InvalidArgumentException("Attempt to load " . $ClassName
                . " with unknown ID (" . $Id . ").");
        }

        # set up convenience access for getting/setting item values
        $this->DB->setValueUpdateParameters(
            $this->ItemTableName,
            $Condition,
            $ItemValues
        );
    }

    /**
     * Destroy item.  Item object should no longer be used after this call.
     */
    public function destroy()
    {
        # delete item from database
        $this->DB->Query("DELETE FROM `" . $this->ItemTableName . "`"
            . " WHERE `" . $this->ItemIdColumnName . "` = " . intval($this->Id));
    }

    /**
     * Get item ID.
     * @return int Canonical item ID.
     */
    public function id(): int
    {
        return $this->Id;
    }

    /**
     * Normalize item ID to canonical form.
     * @param mixed $Id ID to normalize.
     * @return int Canonical ID.
     */
    public static function getCanonicalId($Id): int
    {
        return $Id;
    }

    /**
     * Get/set name of item.  (This method assumes that either a item name
     * column was configured or there is a "Name" column in the database of
     * type TEXT.)
     * @param string $NewValue New name.  (OPTIONAL)
     * @return string Current name.
     */
    public function name(string $NewValue = null): string
    {
        $NameColumn = strlen($this->ItemNameColumnName)
            ? $this->ItemNameColumnName
            : "Name";
        return $this->DB->updateValue($NameColumn, $NewValue);
    }

    /**
     * Get/set when item was created.  (This method assumes there is a
     * "DateCreated" column in the database of type DATETIME.)
     * @param string $NewValue New creation date.
     * @return string|false Creation date in the format "YYYY-MM-DD HH:MM:SS",
     *       or FALSE if date is unknown..
     */
    public function dateCreated(string $NewValue = null)
    {
        return $this->DB->updateDateValue("DateCreated", $NewValue);
    }

    /**
     * Get/set ID of user who created the item.  (This method assumes
     * there is a "CreatedBy" column in the database of type INT.)
     * @param int $NewValue New user ID.
     * @return int|false ID of user who created item, or FALSE if unknown.
     */
    public function createdBy(int $NewValue = null)
    {
        return $this->DB->updateIntValue("CreatedBy", $NewValue);
    }

    /**
     * Get/set when item was last modified.  (This method assumes there
     * is a "DateLastModified" column in the database of type DATETIME.)
     * @param string $NewValue New modification date.
     * @return string|false Modification date in the format "YYYY-MM-DD HH:MM:SS",
     *       or FALSE if date is unknown..
     */
    public function dateLastModified(string $NewValue = null)
    {
        return $this->DB->updateDateValue("DateLastModified", $NewValue);
    }

    /**
     * Get/set ID of user who last modified the item.  (This method assumes
     * there is a "LastModifiedBy" column in the database of type INT.)
     * @param int $NewValue New user ID.
     * @return int|false ID of user who last modified item, or FALSE if unknown.
     */
    public function lastModifiedBy(int $NewValue = null)
    {
        return $this->DB->updateIntValue("LastModifiedBy", $NewValue);
    }

    /**
     * Check whether an item exists with the specified ID.  This only checks
     * whether there is an entry for an item with the specified ID in the
     * database -- it does not check anything else (e.g. the type of the item).
     * @param int|null|Item $Id ID to check.
     * @return bool TRUE if item exists with ID, otherwise FALSE.
     */
    public static function itemExists($Id): bool
    {
        # check for NULL ID (usually used to indicate no value set)
        if ($Id === null) {
            return false;
        }

        # if an object was passed in
        if (is_object($Id)) {
            # make sure that the object passed in matches our called
            # class or something that descends from our called class
            $CalledClassName = get_called_class();
            $ObjClassName = get_class($Id);
            if (!is_a($Id, $CalledClassName)) {
                throw new Exception(
                    "Called " . $CalledClassName . "::ItemExists "
                    . "on an object of type " . $ObjClassName
                    . ", which is unrelated to " . $CalledClassName
                );
            }

            # call the object's ItemExists method
            # (we want to do this rather than just setting $ClassName
            # and continuing so that we'll properly handle subclasses
            # that override ItemExists)
            return $ObjClassName::itemExists($Id->id());
        }

        # if non-numeric ID was passed in then it cannot exist
        # (needed because intval("123 some garbage") returns 123,
        # which could cause us to incorrectly report that an item
        # with id "123 some garbage" exists if 123 is a valid id)
        if (!is_numeric($Id)) {
            return false;
        }

        # set up database access values
        $ClassName = get_called_class();
        static::setDatabaseAccessValues($ClassName);

        # build database query to check for item
        $Query = "SELECT COUNT(*) AS ItemCount"
            . " FROM " . self::$ItemTableNames[$ClassName]
            . " WHERE " . self::$ItemIdColumnNames[$ClassName] . " = " . intval($Id);

        # check for item and return result to caller
        $DB = new Database();
        $ItemCount = $DB->query($Query, "ItemCount");
        return ($ItemCount > 0) ? true : false;
    }

    /**
     * Instantiate item and call specified method with supplied parameters.
     * @param int $Id ID of item to instantiate.
     * @param string $MethodName Name of method to call.
     * @param array $MethodArgs Arguments to pass to specified method.
     */
    public static function callMethod($Id, $MethodName, ...$MethodArgs)
    {
        $ClassName = get_called_class();
        if (![$ClassName, "itemExists"]($Id)) {
            throw new InvalidArgumentException("Attempt to call method "
                    .$ClassName."::".$MethodName." for nonexistent ID (".$Id.").");
        }
        $Item = new $ClassName($Id);
        call_user_func_array([$Item, $MethodName], $MethodArgs);
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $DB;
    protected $Id;
    protected $ItemIdColumnName;
    protected $ItemNameColumnName;
    protected $ItemTableName;

    protected static $ItemIdColumnNames;
    protected static $ItemNameColumnNames;
    protected static $ItemTableNames;

    /**
     * Create a new item, using specified initial database values.
     * @param array $Values Values to set database columns to for
     *       new item, with column names for the index.
     * @return static Newly-created item.
     */
    protected static function createWithValues(array $Values)
    {
        # set up database access values
        $ClassName = get_called_class();
        static::setDatabaseAccessValues($ClassName);

        # set up query to add item to database
        $Query = "INSERT INTO `" . self::$ItemTableNames[$ClassName] . "`";

        # add initial values to query if supplied
        if (count($Values)) {
            $Query .= " SET ";
            $Assignments = [];
            foreach ($Values as $Column => $Value) {
                # convert FALSE and TRUE to ints
                if ($Value === false) {
                    $Value = 0;
                } elseif ($Value === true) {
                    $Value = 1;
                }

                $Assignments[] = "`" . $Column . "` = '" . addslashes($Value) . "'";
            }
            $Query .= implode(", ", $Assignments);
        }

        # add item to database
        $DB = new Database();
        $DB->query($Query);

        # retrieve ID for newly-created item
        $NewItemId = $DB->getLastInsertId();

        # create item object
        $NewItem = new $ClassName($NewItemId);

        # return new item object to caller
        return $NewItem;
    }

    /**
     * Set the database access values (table name, ID column name, name column
     * name) for specified class.  This may be overridden in a child class, if
     * different values are needed.
     * @param string $ClassName Class to set values for.
     */
    protected static function setDatabaseAccessValues(string $ClassName)
    {
        if (!isset(self::$ItemIdColumnNames[$ClassName])) {
            $BaseClassName = basename(str_replace("\\", "/", $ClassName));
            self::$ItemIdColumnNames[$ClassName] = $BaseClassName . "Id";
            self::$ItemNameColumnNames[$ClassName] = $BaseClassName . "Name";
            self::$ItemTableNames[$ClassName] = StdLib::pluralize($BaseClassName);
        }
    }
}
