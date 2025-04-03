<?PHP
#
#   FILE:  Constraint.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

use Exception;
use Metavus\MetadataField;
use ScoutLib\Database;

/**
 * Encapsulate a constraint tuple, which consists of a key, a relation, and a
 * value.
 */
class Constraint
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor. Encapsulate the key, value, and relation of the
     *     constraint.
     * @param string|MetadataField $Key Key to provide context for the constraint.
     * @param int|string $Value Value used to determine if the constraint is met.
     * @param string $Relation Relation between the key and value.
     */
    public function __construct($Key, $Value, string $Relation)
    {
        $ValidRelations = ["=", "!=", "<", ">", "<=", ">="];
        if (!in_array($Relation, $ValidRelations)) {
            throw new Exception(
                "Invalid relation provided for URL Constraint: "
                .$Relation
            );
        }

        if (is_null(self::$DB)) {
            self::$DB = new Database();
        }

        if (!($Key instanceof MetadataField)) {
            if (!is_string($Key)) {
                throw new Exception(
                    "Constraint keys must be either strings or MetadataField objects."
                );
            }

            if ($Key != "SchemaId" &&
                !self::$DB->columnExists("UrlChecker_UrlHistory", $Key)) {
                throw new Exception(
                    "Invalid constrain key: ".$Key.". "
                    ."Must be either 'SchemaId' or a column from UrlChecker_UrlHistory."
                );
            }
        }

        $this->Key = $Key;
        $this->Value = $Value;
        $this->Relation = $Relation;
    }

    /**
     * Get SQL representing a constraint to include in a
     *     query. Assumes that the Records table was aliased as R and
     *     the UrlChecker_UrlHistory table was aliased as URH.
     * @return string SQL fragment.

     */
    public function toSql() : string
    {
        # field constraint
        if ($this->Key instanceof MetadataField) {
            $ColName = "R.".$this->Key->dBFieldName();
        } else {
            $ColName = $this->Key == "SchemaId" ?
                "R.".$this->Key :
                "URH.".$this->Key;
        }

        return $ColName." ".$this->Relation." '".addslashes($this->Value)."'";
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------
    private static $DB = null;

    private $Key;
    private $Value;
    private $Relation;
}
