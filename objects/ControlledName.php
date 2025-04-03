<?PHP
#
#   FILE:  ControlledName.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2001-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Item;
use ScoutLib\StdLib;

/**
 * Metadata type representing non-hierarchical controlled vocabulary values.
 * Hierarchical controlled vocabularies should use Classification.
 */
class ControlledName extends Item
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Create a new empty ControlledName if it's not already present.
     * Caller should set other parameters after it's created.
     * If a controlledname with given name and field ID already
     *       exists, this will just return that controlledname.
     * @param string $Term New controlled vocabulary term.
     * @param int $FieldId ID of MetadataField for new term.
     * @return ControlledName A new ControlledName just created or the one in
     *       the database, if the controlledname already exists.
     */
    public static function create(string $Term, int $FieldId): ControlledName
    {
        $DB = new Database();

        # check if this controlledname is already present
        $DB->query("SELECT * FROM ControlledNames".
                " WHERE ControlledName = '".addslashes($Term).
                "' AND FieldId = ".intval($FieldId));
        if ($Row = $DB->fetchRow()) {
            $NameId = $Row["ControlledNameId"];
        } else {
            # add new controlled name
            $DB->query("INSERT INTO ControlledNames ".
                    "(FieldId, ControlledName) VALUES (".
                    intval($FieldId).", '".addslashes($Term)."')");

            $NameId = $DB->getLastInsertId();
        }
        # instantiate new controlledname and return
        $NewCN = new ControlledName(intval($NameId));
        return $NewCN;
    }

    /**
     * Check if there exists a controlledname with a ControlledName
     *       and FieldId same as given. This method is different
     *       from ItemExists(), which does check base on Id.
     * @param string $Term ControlledName of the controlledname.
     * @param int $FieldId ID of the MetadataField.
     * @return bool TRUE if there exists such controlled name,
     *       otherwise FALSE.
     */
    public static function controlledNameExists(string $Term, int $FieldId): bool
    {
        $DB = new Database();

        $DB->query("SELECT * FROM ControlledNames".
                " WHERE ControlledName = '".addslashes($Term).
                "' AND FieldId = ".intval($FieldId));
        return $DB->numRowsSelected() ? true : false;
    }

    /**
     * Get, set, or clear any variant terms for this controlled name .
     * @param string|bool $NewValue New value for variant terms. (OPTIONAL)
     *       Pass no argument to just retrieve current variant name.
     *       Pass FALSE to unset any variant name attached.
     * @return string|bool Return the current variant name, or FALSE
     *       if this ControlledName is not attached to any variant name.
     */
    public function variantName($NewValue = null)
    {
        # unset any variant name attached if asked
        if ($NewValue === false) {
            $this->DB->query("DELETE FROM VariantNames WHERE "
                    ."ControlledNameId = ".$this->Id);
            $this->VNCache = false;
        # else set new variant name if supplied
        } elseif ($NewValue !== null
                  && is_string($NewValue) && strlen($NewValue) > 0) {
            $this->VNCache = $NewValue;

            # try to load variant name and update cache
            $this->DB->query("SELECT VariantName FROM VariantNames WHERE "
                    ."ControlledNameId = ".$this->Id);

            # variant name exists so do an update
            if ($this->DB->NumRowsSelected() > 0) {
                $this->DB->query("UPDATE VariantNames SET VariantName = '"
                        .addslashes($NewValue)."' WHERE ControlledNameId = "
                        .$this->Id);
            # else no variant name so do an insert
            } else {
                $this->DB->query("INSERT INTO VariantNames ".
                        "(VariantName, ControlledNameId) VALUES ".
                        "('".addslashes($NewValue)."', ".$this->Id.")");
            }
        # else load variant name if none cached
        } elseif (!isset($this->VNCache)) {
            $this->VNCache = $this->DB->query("SELECT VariantName FROM VariantNames".
                   " WHERE ControlledNameId = ".$this->Id, "VariantName");
        }

        return $this->VNCache;
    }

    /**
     * Get or set the MetadataField associated with this term.
     * @param int $NewValue ID of new MetadataField.  (OPTIONAL)
     * @return int ID of associated MetadataField.
     */
    public function fieldId(?int $NewValue = null): int
    {
        return $this->DB->UpdateIntValue("FieldId", $NewValue);
    }

    /**
     * Get or set the Qualifier associated with this term via ID.
     * @param int|false $NewValue ID of new Qualifier or FALSE to clear
     *      any existing qualifier association.  (OPTIONAL)
     * @return int|false ID of currently associated Qualifier, or FALSE if no
     *       qualifier is currently associated.
     */
    public function qualifierId($NewValue = null)
    {
        return $this->DB->UpdateIntValue("QualifierId", $NewValue);
    }

    /**
     * Get or set the Qualifier associated with this term via object.
     * @param Qualifier|false $NewValue New Qualifier, or FALSE to clear any
     *       existing qualifier association.  (OPTIONAL)
     * @throws InvalidArgumentException When the specified new qualifier is
     *      neither a Qualifier nor FALSE.
     * @return Qualifier|false Currently associated Qualifier, or FALSE if no
     *       qualifier is associated.
     */
    public function qualifier($NewValue = null)
    {
        # if new qualifier supplied
        if ($NewValue !== null) {
            # set new qualifier ID
            if ($NewValue instanceof Qualifier) {
                $this->qualifierId($NewValue->id());
            } elseif ($NewValue === false) {
                $this->qualifierId(false);
            } else {
                throw new InvalidArgumentException("Invalid qualifier value");
            }

            # use new qualifier for return value
            $Qualifier = $NewValue;
        } else {
            # if qualifier is available
            if (($this->qualifierId() !== false)
                    && Qualifier::itemExists($this->qualifierId())) {
                # create qualifier object using stored ID
                $Qualifier = new Qualifier($this->qualifierId());

                # if ID was zero and no name available for qualifieR
                # (needed because some controlled name records in DB
                #       have 0 instead of NULL when no controlled name assigned)
                # (NOTE:  this is problematic if there is a qualifier with an
                #       ID value of 0!!!)
                if ($this->qualifierId() == 0 && !strlen($Qualifier->name())) {
                    # return FALSE to indicate no qualifier
                    $Qualifier = false;
                }
            } else {
                # return FALSE to indicate no qualifier
                $Qualifier = false;
            }
        }

        # return qualifier to caller
        return $Qualifier;
    }

    /**
     * Get count of resources associated with this ControlledName
     * @param bool $IncludeTempItems Whether to include temporary items
     *   in returned set.  (OPTIONAL, defaults to FALSE)
     * @return int count of associated resources
     */
    public function getAssociatedResourceCount($IncludeTempItems = false): int
    {
        return $this->DB->queryValue(
            "SELECT COUNT(*) AS Count FROM ".
            "RecordNameInts WHERE ControlledNameId = ".$this->Id
            .(!$IncludeTempItems ? " AND RecordId >= 0" : ""),
            "Count"
        );
    }

    /**
     * See if ControlledName is currently associated with any Resources.
     * @return bool TRUE if associated with at least one Resource, otherwise FALSE.
     */
    public function inUse(): bool
    {
        return $this->getAssociatedResourceCount(true) > 0 ? true : false;
    }

    /**
     * Get resourceIds associated with this ControlledName.
     * @param bool $IncludeTempItems Whether to include temporary items
     *   in returned set.  (OPTIONAL, defaults to FALSE)
     * @return array of ResourceIds.
     */
    public function getAssociatedResources(bool $IncludeTempItems = false): array
    {
        $this->DB->query(
            "SELECT RecordId FROM RecordNameInts "
            ."WHERE ControlledNameId = ".$this->Id
            .(!$IncludeTempItems ? " AND RecordId >= 0" : "")
        );

        return $this->DB->fetchColumn("RecordId");
    }

    /**
     * Change all currently associated Resources to be instead associated with
     * another ControlledName.
     * @param int $NewNameId ID of ControlledName to remap resources to.
     * @return void
     */
    public function remapTo(int $NewNameId): void
    {
        # Get a list of resources associated with the new name
        $this->DB->query("SELECT RecordId FROM RecordNameInts "
                         ."WHERE ControlledNameId = ".intval($NewNameId));
        $NewNameResources = [];
        while ($Row = $this->DB->fetchRow()) {
            $NewNameResources[$Row["RecordId"]] = 1;
        }

        # Get a list of resources associated with the old name
        $this->DB->query("SELECT RecordId FROM RecordNameInts "
                         ."WHERE ControlledNameId = ".intval($this->Id));
        $OldNameResources = [];
        while ($Row = $this->DB->fetchRow()) {
            $OldNameResources[] = $Row["RecordId"];
        }

        # Foreach of the old name resources, check to see if it's already
        # associated with the new name.  If not, associate it.
        foreach ($OldNameResources as $ResourceId) {
            if (!isset($NewNameResources[$ResourceId])) {
                $this->DB->query("INSERT INTO RecordNameInts "
                                 ."(RecordId, ControlledNameId) VALUES "
                                 ."(".intval($ResourceId).",".intval($NewNameId).")");
            }
        }

        # Clear out all the associations to the old name
        $this->DB->query("DELETE FROM RecordNameInts WHERE ControlledNameId = "
                .intval($this->Id));
    }

    /**
     * Update the LastAssigned timestamp for this classification.
     * @return void
     */
    public function updateLastAssigned(): void
    {
        $this->DB->query("UPDATE ControlledNames SET LastAssigned=NOW() "
                         ."WHERE ControlledNameId=".intval($this->Id));
    }

    /**
     * Remove ControlledName (and any accompanying associations from database.
     * This must be the last use of this object.
     * @param bool $DeleteIfHasResources Remove ControlledName even if Resources are
     *       currently associated with it.  (OPTIONAL, defaults to FALSE)
     * @return void
     */
    public function destroy(bool $DeleteIfHasResources = false): void
    {
        $DB = &$this->DB;

        if ($DeleteIfHasResources || !$this->inUse()) {
            # delete this controlled name
            parent::destroy();

            # delete associated variant name
            $DB->query("DELETE FROM VariantNames WHERE ControlledNameId=".
                $this->Id);

            if ($DeleteIfHasResources) {
                $DB->query("DELETE FROM RecordNameInts WHERE ".
                   "ControlledNameId=".$this->Id);
            }
        }
    }

    /**
     * Set the database access values (table name, ID column name, name column
     * name) for specified class.  This may be overridden in a child class, if
     * different values are needed.
     * @param string $ClassName Class to set values for.
     * @return void
     */
    protected static function setDatabaseAccessValues(string $ClassName): void
    {
        parent::setDatabaseAccessValues($ClassName);
        self::$ItemNameColumnNames[$ClassName] = "ControlledName";
    }

    private $VNCache;
}
