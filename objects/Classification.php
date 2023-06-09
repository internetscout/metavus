<?PHP
#
#   FILE:  Classification.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use InvalidArgumentException;
use Metavus\RecordFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Item;
use ScoutLib\StdLib;

/**
 * Metadata type representing hierarchical ("Tree") controlled vocabulary values.
 */
class Classification extends Item
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** Parent value for classifications with no parent. */
    const NOPARENT = -1;

    /**
     * Add new classification to the hierarchy.
     * @param string $Name Full name or segment name for new Classification.
     *       Segment name can be used if a parent ID is also supplied, otherwise
     *       full name is assumed.
     * @param int $FieldId MetadataField ID for new Classification.
     * @param int $ParentId ID of parent in hierachy of for new Classification.
     *       Use Classification::NOPARENT for new Classification with no parent
     *       (i.e. at top level of hierarchy).  (OPTIONAL - only required if
     *       full classification name is not supplied)
     */
    public static function create(string $Name, int $FieldId, int $ParentId = null)
    {
        # initialize state for creation
        self::$SegmentsCreated = 0;

        # if parent class supplied
        $DB = new Database();
        if ($ParentId !== null) {
            # error out if parent ID is invalid
            if ($ParentId != self::NOPARENT) {
                if ($DB->query(
                    "SELECT COUNT(*) AS NumberFound"
                        ." FROM Classifications"
                        ." WHERE ClassificationId = ".intval($ParentId),
                    "NumberFound"
                ) < 1) {
                    throw new InvalidArgumentException("Invalid parent ID"
                    ." specified (".$ParentId.").");
                }
            }

            # error out if name already exists
            $Name = trim($Name);
            $Count = $DB->queryValue(
                "SELECT COUNT(*) AS NumberFound"
                    ." FROM Classifications"
                    ." WHERE ParentId = ".intval($ParentId)
                    ." AND FieldId = ".intval($FieldId)
                    ." AND LOWER(SegmentName) = '"
                    .addslashes(strtolower($Name))."'",
                "NumberFound"
            );
            if ($Count > 0) {
                throw new Exception("Duplicate name specified for"
                ." new classification (".$Name.").");
            }

            # determine full name and depth for new classification
            if ($ParentId == self::NOPARENT) {
                $NewName = $Name;
                $NewDepth = 0;
            } else {
                $DB->query(
                    "SELECT ClassificationName, Depth"
                    ." FROM Classifications"
                    ." WHERE ClassificationId = ".intval($ParentId)
                );
                $ParentInfo = $DB->fetchRow();
                if ($ParentInfo === false) {
                    throw new Exception("Unable to fetch parent classification.");
                }
                $NewName = $ParentInfo["ClassificationName"]." -- ".$Name;
                $NewDepth = $ParentInfo["Depth"] + 1;
            }

            # add classification to database
            $InitialValues = [
                "FieldId" => $FieldId,
                "ParentId" => $ParentId,
                "SegmentName" => $Name,
                "ResourceCount" => 0,
                "Depth" => $NewDepth,
                "ClassificationName" => $NewName
            ];
            $NewItem = self::createWithValues($InitialValues);

            # we have created one segment
            self::$SegmentsCreated++;
        } else {
            # parse classification name into separate segments
            $Segments = preg_split("/--/", $Name);
            if ($Segments === false) {
                throw new Exception("Error splitting classification name.");
            }

            # start out with top as parent
            $ParentId = self::NOPARENT;

            # start off assuming we won't create anything
            $NewItem = null;

            # for each segment
            $CurrentDepth = -1;
            $CurrentFullName = "";
            foreach ($Segments as $Segment) {
                # track segment depth and full classification name for use
                #       in adding new entries
                $Segment = trim($Segment);
                $CurrentDepth++;
                $CurrentFullName .= (($CurrentFullName == "") ? "" : " -- ").$Segment;

                # if we have added classifications
                $Segment = addslashes($Segment);
                if (self::$SegmentsCreated > 0) {
                    # we know that current segment will not be found
                    $ClassId = null;
                } else {
                    # look up classification with current parent and segment name
                    if (!isset(self::$IdCache[$FieldId][$ParentId][$Segment])) {
                        $Query = "SELECT ClassificationId FROM Classifications "
                                ."WHERE ParentId = ".intval($ParentId)
                                ." AND SegmentName = '".addslashes($Segment)."'";
                        # (only need to include field ID in query if no parent
                        #       because field is implied by parent)
                        if ($ParentId == self::NOPARENT) {
                            $Query .= " AND FieldId = ".intval($FieldId);
                        }
                        self::$IdCache[$FieldId][$ParentId][$Segment] =
                                $DB->queryValue($Query, "ClassificationId");
                    }
                    $ClassId = self::$IdCache[$FieldId][$ParentId][$Segment];
                }

                # if classification not found
                if ($ClassId === null) {
                    # add new classification
                    $InitialValues = [
                        "FieldId" => $FieldId,
                        "ParentId" => $ParentId,
                        "SegmentName" => $Segment,
                        "ResourceCount" => 0,
                        "Depth" => $CurrentDepth,
                        "ClassificationName" => $CurrentFullName
                    ];
                    $NewItem = self::createWithValues($InitialValues);
                    $ClassId = $NewItem->id();
                    self::$IdCache[$FieldId][$ParentId][$Segment] = $ClassId;

                    # track total number of new classification segments created
                    self::$SegmentsCreated++;
                }

                # set parent to created or found class
                $ParentId = $ClassId;
            }

            # if it wasn't actually necessary to create anything
            if ($NewItem === null) {
                throw new Exception(
                    "Duplicate name specified for"
                    ." new classification (".$Name.")."
                );
            }
        }

        # return new classification to caller
        return new self($NewItem->id());
    }

    /**
     * Get full classification name (all segments).
     * @return string Classification name.
     */
    public function fullName()
    {
        return $this->DB->updateValue("ClassificationName");
    }

    /**
     * Get full classification name (all segments).
     * @param string $NewValue Argument for compatibility with parent class.
     *       (DO NOT USE)
     * @return string Segment name.
     */
    public function name(string $NewValue = null): string
    {
        if ($NewValue !== null) {
            throw new InvalidArgumentException("Illegal argument supplied.");
        }
        return $this->fullName();
    }

    /**
     * Get variant name of classification, if any.
     * @return string|false Variant name, or FALSE if no variant name.
     */
    public function variantName()
    {
        return false;
    }

    /**
     * Get depth of classification in hierarchy.  Top level is depth of 0.
     * @return int Depth in hierarchy.
     */
    public function depth()
    {
        return $this->DB->updateIntValue("Depth");
    }

    /**
     * Get number of released resources having this classification assigned to
     * them. This is only updated by RecalcResourceCount() and Delete().
     * @return int Count of released resources having this classification.
     */
    public function resourceCount()
    {
        return $this->DB->updateIntValue("ResourceCount");
    }

    /**
     * Get number of all resources (minus temporary ones) having this
     * classification assigned to them. This is only updated by
     * RecalcResourceCount() and Delete().
     * @return int Count of all resources having this classification.
     */
    public function fullResourceCount()
    {
        return $this->DB->updateIntValue("FullResourceCount");
    }

    /**
     * Get number of new segments (Classifications) generated when creating
     * a new Classification with a full name.
     * @return int Number of new segments generated.
     */
    public static function segmentsCreated()
    {
        return self::$SegmentsCreated;
    }

    /**
     * Get ID of parent Classification.  Returns Classification::NOPARENT
     *       if no parent (i.e. Classification is at top level of hierarchy).
     * @return int ID of parent classification.
     */
    public function parentId()
    {
        return $this->DB->updateIntValue("ParentId");
    }

    /**
     * Get or set the segment name.
     * @param string $NewValue New segment name.  (OPTIONAL)
     * @return string Segment name.
     */
    public function segmentName(string $NewValue = null)
    {
        return $this->DB->updateValue("SegmentName", $NewValue);
    }

    /**
     * Get or set the stored link string for the Classification.  (This value is
     * not used, updated, or manipulated in any way by Classification, and is only
     * being stored as a UI optimization.)
     * @param string $NewValue New link string.
     * @return string Current link string.
     */
    public function linkString(string $NewValue = null)
    {
        return $this->DB->updateValue("LinkString", $NewValue);
    }

    /**
     * Get or set the Qualifier associated with the Classification by ID.
     * @param int|false $NewValue ID of new Qualifier or FALSE to clear qualifier.
     * @return int|false ID of current Qualifier or FALSE if no qualifier set.
     * @see Classifiaction::Qualifier()
     */
    public function qualifierId($NewValue = null)
    {
        return $this->DB->updateIntValue("QualifierId", $NewValue);
    }

    /**
     * Get or set the ID of the MetadataField for the Classification.
     * @param int $NewValue ID of new MetadataField.
     * @return int ID of currently associated MetadataField.
     */
    public function fieldId($NewValue = null)
    {
        return $this->DB->updateIntValue("FieldId", $NewValue);
    }

    /**
     * Get or set the Qualifier associated with the Classification.
     * @param Qualifier $NewValue New Qualifier.
     * @return Qualifier|null Associated Qualifier (object) or NULL if no qualifier.
     * @see Classification::QualifierId()
     */
    public function qualifier($NewValue = null)
    {
        # if new qualifier supplied
        if ($NewValue !== null) {
            # set new qualifier ID
            $this->qualifierId($NewValue->id());

            # use new qualifier for return value
            $Qualifier = $NewValue;
        } else {
            # if qualifier is available
            if (($this->qualifierId() !== false)
                    && Qualifier::itemExists($this->qualifierId())) {
                # create qualifier object using stored ID
                $Qualifier = new Qualifier($this->qualifierId());
            } else {
                # return NULL to indicate no qualifier
                $Qualifier = null;
            }
        }

        # return qualifier to caller
        return $Qualifier;
    }

    /**
     * Rebuild classification full name and recalculate depth in hierarchy.
     * This is a DB-intensive and recursive function, and so should not be
     * called without some forethought.
     */
    public function recalcDepthAndFullName()
    {
        # start with full classification name set to our segment name
        $FullClassName = $this->segmentName();

        # assume to begin with that we're at the top of the hierarchy
        $Depth = 0;

        # while parent available
        $ParentId = $this->parentId();
        while ($ParentId != self::NOPARENT) {
            # retrieve classification information
            $this->DB->query("SELECT SegmentName, ParentId "
                    ."FROM Classifications "
                    ."WHERE ClassificationId=".$ParentId);
            $Record = $this->DB->fetchRow();

            # prepend segment name to full classification name
            $FullClassName = $Record["SegmentName"]." -- ".$FullClassName;

            # increment depth value
            $Depth++;

            # move to parent of current classification
            $ParentId = $Record["ParentId"];
        }

        # for each child
        $this->DB->query("SELECT ClassificationId FROM Classifications"
                ." WHERE ParentId = ".intval($this->Id));
        while ($Record = $this->DB->fetchRow()) {
            # perform depth and name recalc
            $Child = new self($Record["ClassificationId"]);
            $Child->recalcDepthAndFullName();
        }

        # save new depth and full classification name
        $this->DB->updateIntValue("Depth", $Depth);
        $this->DB->updateValue("ClassificationName", $FullClassName);
    }

    /**
     * Update the LastAssigned timestamp for this classification.
     */
    public function updateLastAssigned()
    {
        $this->DB->query("UPDATE Classifications SET LastAssigned=NOW() "
                         ."WHERE ClassificationId=".intval($this->Id));
    }

    /**
     * Recalculate number of non-temporary public and unreleased resources
     * assigned to class and all parent classes. This is a DB-intensive and
     * recursive function, and so should not be called without some
     * forethought. Assumes that value for full classification name (in
     * ClassificationName) is accurate.
     * @return Array of IDs of the Classifications that were updated.
     */
    public function recalcResourceCount()
    {
        # get all non-temp records associated with this class or any children
        $this->DB->query(
            "SELECT DISTINCT RecordId FROM RecordClassInts"
            ." WHERE RecordId > 0 AND ("
            ." ClassificationId=".$this->Id
            ." OR ClassificationId IN ("
            ." SELECT ClassificationId FROM Classifications WHERE "
            ." FieldId=".$this->fieldId()
            ." AND ClassificationName LIKE '".addslashes($this->fullName())." -- %'"
            ."))"
        );
        $RecordIds = $this->DB->fetchColumn("RecordId");

        # count all resources associated with this classification or any children
        $FullResourceCount = count($RecordIds);
        $this->DB->updateIntValue("FullResourceCount", $FullResourceCount);

        if (!isset(self::$FieldSchemaIds[$this->fieldId()])) {
            $Field = new MetadataField($this->fieldId());
            self::$FieldSchemaIds[$this->fieldId()] = $Field->schemaId();
        }
        $SchemaId = self::$FieldSchemaIds[$this->fieldId()];

        if (!isset(self::$RFactories[$SchemaId])) {
            self::$RFactories[$SchemaId] = new RecordFactory($SchemaId);
        }

        # count publicly-viewable records associated with this classification
        # and its children
        $RecordIds = self::$RFactories[$SchemaId]->filterOutUnviewableRecords(
            $RecordIds,
            User::getAnonymousUser()
        );
        $ResourceCount = count($RecordIds);
        $this->DB->updateIntValue("ResourceCount", $ResourceCount);

        # add our ID to list of IDs that have been recalculated
        $IdsUpdated = [ $this->Id ];

        # update resource count for our parent (if any)
        if ($this->parentId() != self::NOPARENT) {
            $Parent = new self($this->parentId());
            $IdsUpdated = array_merge($IdsUpdated, $Parent->recalcResourceCount());
        }

        # return list of IDs of updated classifications to caller
        return $IdsUpdated;
    }

    /**
     * Get number of classifications that have this Classification as their direct parent.
     * @return int Count of child Classifications.
     */
    public function childCount(): int
    {
        # return count of classifications that have this one as parent
        return $this->DB->query(
            "SELECT COUNT(*) AS ClassCount "
                    ."FROM Classifications "
                    ."WHERE ParentId=".intval($this->Id),
            "ClassCount"
        );
    }

    /**
     * Get list of IDs of Classifications that have this class as an "ancestor"
     * (parent, grandparent, great-grandparent, etc).
     * @return Array of child/grandchild/etc Classification IDs.
     */
    public function childList()
    {
        $ChildList = [];

        $this->DB->query("SELECT ClassificationId  "
                    ."FROM Classifications "
                    ."WHERE ParentId=".intval($this->Id));

        while ($Entry = $this->DB->fetchRow()) {
            $ChildList[] = $Entry["ClassificationId"];
            $Child = new self($Entry["ClassificationId"]);
            if ($Child->childCount() > 0) {
                $GrandChildList = $Child->childList();
                $ChildList = array_merge($GrandChildList, $ChildList);
            }
        }
         return array_unique($ChildList);
    }

    /**
     * Remove Classification (and accompanying associations) from database.
     * @param bool $DeleteParents Flag indicating whether to also delete
     *      Classification entries above this one in the hierarchy.  (OPTIONAL
     *      - defaults to FALSE)
     * @param bool $DeleteIfHasResources Flag indicating whether to delete the
     *      Classification if it still has Resources associated with it.
     *      (OPTIONAL - defaults to FALSE)
     * @param bool $DeleteIfHasChildren Flag indicating whether to delete the
     *      Classification if others have it as a parent.  (OPTIONAL - defaults
     *      to FALSE)
     * @return int Number of classifications deleted.
     */
    public function destroy(
        bool $DeleteParents = false,
        bool $DeleteIfHasResources = false,
        bool $DeleteIfHasChildren = false
    ): int {
        $DeleteCount = 0;

        # bail if we should not be deleted because we still have resources
        if (!$DeleteIfHasResources && $this->fullResourceCount() > 0) {
            return $DeleteCount;
        }

        # bail if we should not be deleted because we still have children
        if (!$DeleteIfHasChildren && $this->childCount() > 0) {
            return $DeleteCount;
        }

        # disassociate with any resources, even temp ones that will not
        # appear in fullResourceCount()
        $this->DB->query(
            "DELETE FROM RecordClassInts"
            ." WHERE ClassificationId = ".intval($this->Id)
        );

        # if we had any associated perm resources, recalculate counts
        # to update our parent's counts
        if ($this->fullResourceCount() > 0 && !$DeleteParents) {
            $this->recalcResourceCount();
        }

        # clear internal caches in case they include this item
        self::clearCaches();

        # delete this classification
        $ParentId = $this->parentId();
        parent::destroy();
        $DeleteCount++;

        # delete parent classification (if requested)
        if (($DeleteParents) && ($ParentId != self::NOPARENT)) {
            $Parent = new self($ParentId);
            $DeleteCount += $Parent->destroy(
                true,
                $DeleteIfHasResources,
                $DeleteIfHasChildren
            );
        }

        # return total number of classifications deleted to caller
        return $DeleteCount;
    }

    /**
     * Clear any and all internal class caches.
     */
    public static function clearCaches()
    {
        self::$IdCache = [];
        self::$RFactories = [];
        self::$FieldSchemaIds = [];
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private static $IdCache = [];
    private static $RFactories = [];
    private static $FieldSchemaIds = [];
    private static $SegmentsCreated = 0;
}
