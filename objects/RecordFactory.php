<?PHP
#
#   FILE:  RecordFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\ItemFactory;
use ScoutLib\StdLib;

/**
 * Factory for Record objects.
 */
class RecordFactory extends ItemFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    const ITEM_CLASS = "Metavus\\Record";

    /**
     * Class constructor.
     * @param int $SchemaId ID of schema to load resources for.(OPTIONAL,
     *       defaults to SCHEMAID_DEFAULT)
     */
    public function __construct(int $SchemaId = MetadataSchema::SCHEMAID_DEFAULT)
    {
        # save schema
        $this->SchemaId = $SchemaId;
        $this->Schema = new MetadataSchema($this->SchemaId);

        # set up item factory base class
        parent::__construct(
            static::ITEM_CLASS,
            "Records",
            "RecordId",
            null,
            false,
            "SchemaId = ".intval($this->SchemaId)
        );
    }

    /**
     * Get metadata schema associated with this resource factory.
     * @return MetadataSchema MetadataSchema.
     */
    public function schema(): MetadataSchema
    {
        return $this->Schema;
    }

    /**
     * Import resource records from XML file.The file should contain
     * a top-level "ResourceCollection" tag, inside of which should be
     * one or more <Resource> tags.Within the <Resource> tag are tags
     * giving metadata field values, with the tag names constructed
     * from the alphanumeric part of the field names (e.g.Title in a
     * <Title> tag, Date Record Checked in a <DateRecordChecked> tag,
     * etc).See install/SampleResource.xml for an example.
     * @param string $FileName Name of XML file.
     * @return array IDs of any new resource records.
     * @throws Exception When input file cannot be opened.
     */
    public function importRecordsFromXmlFile(string $FileName): array
    {
        $this->clearErrorMessages();

        # open file
        libxml_use_internal_errors(true);
        $XmlData = simplexml_load_file($FileName);
        $Errors = libxml_get_errors();
        libxml_use_internal_errors(false);

        # if XML load failed
        if ($XmlData === false) {
            # retrieve XML error messages
            foreach ($Errors as $Error) {
                $ErrType = ($Error->level == LIBXML_ERR_WARNING) ? "Warning"
                        : (($Error->level == LIBXML_ERR_ERROR) ? "Error"
                        : "Fatal Error");
                $this->logErrorMessage("XML ".$ErrType.": ".$Error->message
                        ." (".$Error->file.":".$Error->line.",".$Error->column.")");
            }
            return [];
        }

        # load possible tag names
        $PossibleTags = [];

        $Fields = $this->Schema->getFields();
        foreach ($Fields as $Field) {
            $NormalizedName = preg_replace(
                "/[^A-Za-z0-9]/",
                "",
                $Field->Name()
            );
            if (is_string($NormalizedName)) {
                $PossibleTags[$NormalizedName] = $Field;
            }
        }

        # arrays to hold ControlledName and Classification factories
        $CNFacts = [];
        $CFacts = [];

        # parse XML
        $NewResourceIds = [];
        $ResourceIndex = 0;
        foreach ($XmlData->Resource as $ResourceXml) {
            $ResourceIndex++;
            # new resource for every <Resource> tag
            $Resource = Record::create($this->SchemaId);

            # retrieve fields for resource
            foreach ($ResourceXml->children() as $FieldXml) {
                # check if tag is valid
                $TagName = $FieldXml->getName();

                if (!array_key_exists($TagName, $PossibleTags)) {
                    $this->logErrorMessage(
                        "Invalid metadata field tag \"".$TagName
                        ."\" found in record #".$ResourceIndex."."
                    );
                    continue;
                }

                $Value = $FieldXml->count() ? $FieldXml->children() : (string) $FieldXml;
                $Field = $PossibleTags[$TagName];

                # set value in resource based on field type
                switch ($Field->Type()) {
                    case MetadataSchema::MDFTYPE_TEXT:
                    case MetadataSchema::MDFTYPE_PARAGRAPH:
                    case MetadataSchema::MDFTYPE_NUMBER:
                    case MetadataSchema::MDFTYPE_TIMESTAMP:
                    case MetadataSchema::MDFTYPE_URL:
                    case MetadataSchema::MDFTYPE_EMAIL:
                    case MetadataSchema::MDFTYPE_DATE:
                        $Resource->set($Field, $Value);
                        break;

                    case MetadataSchema::MDFTYPE_FLAG:
                        $Resource->set(
                            $Field,
                            (strtoupper($Value) == "TRUE") ? true : false
                        );
                        break;

                    case MetadataSchema::MDFTYPE_OPTION:
                    case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                        if (!isset($CNFacts[$Field->Id()])) {
                            $CNFacts[$Field->id()] = $Field->getFactory();
                        }

                        $CName = $CNFacts[$Field->Id()]->GetItemByName($Value);
                        if ($CName === null) {
                            $CNFacts[$Field->Id()]->ClearCaches();
                            $CName = ControlledName::create($Value, $Field->Id());
                        }
                        $Resource->set($Field, $CName);
                        break;

                    case MetadataSchema::MDFTYPE_TREE:
                        if (!isset($CFacts[$Field->id()])) {
                            $CFacts[$Field->id()] = $Field->getFactory();
                        }

                        $Class = $CFacts[$Field->Id()]->getItemByName($Value);
                        if ($Class === null) {
                            $CFacts[$Field->id()]->clearCaches();
                            $Class = Classification::create($Value, $Field->Id());
                        }
                        $Resource->set($Field, $Class);
                        break;

                    case MetadataSchema::MDFTYPE_POINT:
                        list($Point["X"], $Point["Y"]) = explode(",", $Value);
                        $Resource->set($Field, $Point);
                        break;

                    case MetadataSchema::MDFTYPE_USER:
                        if (preg_match("/^[0-9]+\$/", $Value)) {
                            $Value = intval($Value);
                        }
                        $Resource->set($Field, $Value);
                        break;

                    case MetadataSchema::MDFTYPE_IMAGE:
                        $this->importImagesFromXml(
                            dirname($FileName),
                            $Resource,
                            $ResourceIndex,
                            $Field,
                            $Value
                        );
                        break;

                    case MetadataSchema::MDFTYPE_FILE:
                        $this->importFilesFromXml(
                            dirname($FileName),
                            $Resource,
                            $ResourceIndex,
                            $Field,
                            $Value
                        );
                        break;

                    case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                        $NewSet = SearchParameterSet::createFromXml(
                            $FieldXml->children()
                        );
                        $Resource->set($Field, $NewSet);
                        break;

                    case MetadataSchema::MDFTYPE_REFERENCE:
                        break;

                    default:
                        break;
                }
            }

            # make resource non-temporary
            $Resource->isTempRecord(false);
            $Resource->queueSearchAndRecommenderUpdate();
            $NewResourceIds[] = $Resource->id();
        }

        # report to caller what resources were added
        return $NewResourceIds;
    }

    /**
     * Clear or change specific qualifier for all resources.
     * @param int|Qualifier $ObjectOrId Qualifier ID or object to clear or change.
     * @param int|Qualifier $NewObjectOrId New Qualifier ID or object.(OPTIONAL,
     *      defaults to NULL, which will clear old qualifier)
     */
    public function clearQualifier($ObjectOrId, $NewObjectOrId = null)
    {
        # sanitize qualifier ID or retrieve from object
        $QualifierId = ($ObjectOrId instanceof Qualifier)
            ?  $ObjectOrId->id() : intval($ObjectOrId);

        # if new qualifier passed in
        if ($NewObjectOrId !== null) {
            # sanitize qualifier ID to change to or retrieve it from object
            $NewQualifierIdVal = ($NewObjectOrId instanceof Qualifier)
                ?  $NewObjectOrId->id() : intval($NewObjectOrId);
        } else {
            # qualifier should be cleared
            $NewQualifierIdVal = "NULL";
        }

        # for each metadata field
        $Fields = $this->Schema->getFields();
        foreach ($Fields as $Field) {
            # if field uses qualifiers and uses item-level qualifiers
            $QualColName = $Field->DBFieldName()."Qualifier";
            if ($Field->UsesQualifiers() && $Field->HasItemLevelQualifiers() &&
                $this->DB->FieldExists("Records", $QualColName)) {
                # set all occurrences to new qualifier value
                $this->DB->Query("UPDATE Records"
                       ." SET ".$QualColName." = ".$NewQualifierIdVal.""
                       ." WHERE ".$QualColName." = '".$QualifierId."'"
                       ." AND SchemaId = ".intval($this->SchemaId));
            }
        }

        # clear or change qualifier association with controlled names
        # (NOTE: this should probably be done in a controlled name factory object)
        $this->DB->Query("UPDATE ControlledNames"
               ." SET QualifierId = ".$NewQualifierIdVal
               ." WHERE QualifierId = '".$QualifierId."'");

        # clear or change qualifier association with classifications
        # (NOTE: this should probably be done in a classification factory object)
        $this->DB->Query("UPDATE Classifications"
               ." SET QualifierId = ".$NewQualifierIdVal
               ." WHERE QualifierId = '".$QualifierId."'");
    }

    /**
     * Return number of resources that have ratings.
     * @return int Resource count.
     */
    public function getRatedRecordCount(): int
    {
        return $this->DB->Query(
            "SELECT COUNT(DISTINCT RecordId) AS ResourceCount"
                    ." FROM RecordRatings",
            "ResourceCount"
        );
    }

    /**
     * Return number of users who have rated resources.
     * @return int User count.
     */
    public function getRatedRecordUserCount(): int
    {
        return $this->DB->Query(
            "SELECT COUNT(DISTINCT UserId) AS UserCount"
                    ." FROM RecordRatings",
            "UserCount"
        );
    }

    /**
     * Get resources sorted by descending Date of Record Release, with Date of
     * Record Creation as the secondary sort criteria..
     * @param int $Count Maximum number of resources to return.
     * @param int $Offset Starting offset of segment to return (0=beginning).
     * @param int $MaxDaysToGoBack Maximum number of days to go back for
     *       resources, according to Date of Record Release.
     * @return array Array of Resource objects.
     */
    public function getRecentlyReleasedRecords(
        int $Count = 10,
        int $Offset = 0,
        int $MaxDaysToGoBack = 90
    ): array {
        # assume that no resources will be found
        $Resources = [];

        # calculate cutoff date for resources
        $CutoffDate = date(
            "Y-m-d H:i:s",
            (int)strtotime($MaxDaysToGoBack." days ago")
        );

        # query for resource IDs
        $this->DB->Query("SELECT `".$this->ItemIdColumnName."`"
            ." FROM `".$this->ItemTableName."` WHERE"
            ." DateOfRecordRelease > '".$CutoffDate."'"
            ." AND `".$this->ItemIdColumnName."` >= 0"
            ." AND SchemaId = ".intval($this->SchemaId)
            ." ORDER BY DateOfRecordRelease DESC, DateOfRecordCreation DESC");
        $AllResourceIds = $this->DB->fetchColumn($this->ItemIdColumnName);

        # filter out resources that aren't viewable to current user
        # in chunks of 10 * $Count records
        $ResourceIds = [];
        foreach (array_chunk($AllResourceIds, 10 * $Count) as $ChunkIds) {
            $ResourceIds = array_merge(
                $ResourceIds,
                $this->filterOutUnviewableRecords(
                    $ChunkIds,
                    User::getCurrentUser()
                )
            );

            if (count($ResourceIds) > $Count) {
                break;
            }
        }

        # subset the results as requested
        $ResourceIds = array_slice(
            $ResourceIds,
            $Offset,
            $Count
        );

        # for each resource ID found
        foreach ($ResourceIds as $ResourceId) {
            # load resource and add to list of found resources
            $Resources[$ResourceId] = new Record($ResourceId);
        }

        # return found resources to caller
        return $Resources;
    }

    /**
     * Return array of item IDs.If sorting by a MetadataField is necessary,
     * use getRecordIdsSortedBy() instead.
     * @param string $Condition For compatibility with
     *   ItemFactory::getItemIds.Should always be null.
     * @param bool $IncludeTempItems Whether to include temporary items
     *   in returned set.(OPTIONAL, defaults to FALSE)
     * @param string $SortField For compatibility with
     *   ItemFactory::getItemIds.Should always be null.
     * @param bool $SortAscending If TRUE, sort items in ascending order,
     *   otherwise sort items in descending order.(OPTIONAL, and
     *   only meaningful if a sort field is specified.)
     * @return array Item IDs.
     */
    public function getItemIds(
        string $Condition = null,
        bool $IncludeTempItems = false,
        string $SortField = null,
        bool $SortAscending = true
    ): array {
        if (!is_null($Condition)) {
            $GLOBALS["AF"]->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "Non-null Condition passed to RecordFactory::getItemIds() at "
                .StdLib::getMyCaller()
                ." You probably meant to call getIdsOfMatchingRecords()."
                ." This will throw an exception in a future version."
            );
        }

        if (!is_null($SortField)) {
            $GLOBALS["AF"]->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "Non-null SortField passed to RecordFactory::getItemIds() at "
                .StdLib::getMyCaller()
                ." You probably meant to call getRecordIdsSortedBy()."
                ." This will throw an exception in a future version."
            );
        }

        return parent::getItemIds(
            $Condition,
            $IncludeTempItems,
            $SortField,
            $SortAscending
        );
    }

    /**
     * Get resource IDs sorted by specified field.Only IDs for resources
     * with non-empty non-null values for the specified field are returned.
     * @param MetadataField|int|string $Field MetadataField object, field ID,
     *      or name of field.
     * @param bool $Ascending If TRUE, sort is ascending, otherwise sort is descending.
     * @param int $Limit Number of IDs to retrieve.(OPTIONAL)
     * @return array Resource IDs.
     * @throws InvalidArgumentException If the provided field name is invalid.
     * @throws InvalidArgumentException If the field comes from a different schema.
     * @throws InvalidArgumentException If provided field is of a type not supported.
     * @throws InvalidArgumentException If User field is provided and field allows multiple values.
     */
    public function getRecordIdsSortedBy(
        $Field,
        bool $Ascending = true,
        int $Limit = null
    ): array {
        $Field = $this->schema()->getField($Field);
        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_EMAIL:
            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                $Condition = $Field->dBFieldName()." IS NOT NULL"
                ." AND LENGTH(TRIM(".$Field->dBFieldName()."))>0" ;
                $RecordIds = parent::getItemIds(
                    $Condition,
                    false,
                    $Field->dBFieldName(),
                    $Ascending
                );
                break;

            case MetadataSchema::MDFTYPE_FLAG:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
                $RecordIds = parent::getItemIds(
                    $Field->dBFieldName()." IS NOT NULL",
                    false,
                    $Field->dBFieldName(),
                    $Ascending
                );
                break;

            case MetadataSchema::MDFTYPE_DATE:
                $RecordIds = parent::getItemIds(
                    $Field->dBFieldName()."Begin IS NOT NULL",
                    false,
                    $Field->dBFieldName()."Begin",
                    $Ascending
                );
                break;

            case MetadataSchema::MDFTYPE_USER:
                if ($Field->allowMultiple()) {
                    throw new Exception(
                        "Cannot sort based on User fields that permit "
                        ."multiple values"
                    );
                }

                $DB = new Database();
                $DB->query(
                    "SELECT R.".$this->ItemIdColumnName." AS Ids FROM"
                    ." ".$this->ItemTableName." R, APUsers U, RecordUserInts RU "
                    ." WHERE R.SchemaId = ".intval($this->SchemaId)
                    ." AND RU.FieldId = ".$Field->id()
                    ." AND R.".$this->ItemIdColumnName." = RU.RecordId"
                    ." AND RU.UserId = U.UserId"
                    ." ORDER BY U.UserName ".($Ascending ? "ASC" : "DESC")
                );
                $RecordIds = $DB->fetchColumn("Ids");
                break;

            default:
                throw new Exception(
                    "getRecordIdsSortedBy() is not supported for "
                    .$Field->typeAsName()." Fields"
                );
        }

        if (!is_null($Limit)) {
            $RecordIds = array_slice($RecordIds, 0, $Limit);
        }

        # return resource IDs to caller
        return $RecordIds;
    }

    /**
     * Get resource IDs where a given field is empty.
     * @param MetadataField|int|string $Field Field to examine
     * @return array Resource IDs.
     * @throws InvalidArgumentException If the provided field name is invalid.
     * @throws InvalidArgumentException If the field comes from a different schema.
     */
    public function getRecordIdsWhereFieldIsEmpty(
        $Field
    ): array {
        $Field = $this->schema()->getField($Field);

        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_EMAIL:
                $Condition = "(".$Field->dBFieldName()." IS NULL"
                    ." OR LENGTH(TRIM(".$Field->dBFieldName()."))=0)" ;
                break;

            case MetadataSchema::MDFTYPE_FLAG:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                $Condition = $Field->dBFieldName()." IS NULL";
                break;

            case MetadataSchema::MDFTYPE_DATE:
                $Condition = $Field->dBFieldName()."Begin IS NULL";
                break;

            case MetadataSchema::MDFTYPE_POINT:
                $Condition = $Field->dBFieldName()."X IS NULL"
                    ." AND ".$Field->dBFieldName()."Y IS NULL";
                break;

            case MetadataSchema::MDFTYPE_TREE:
                $QueryForValues =
                    "SELECT R.".$this->ItemIdColumnName." AS Ids FROM "
                    .$this->ItemTableName." R, Classifications C, RecordClassInts RC"
                    ." WHERE R.SchemaId = ".intval($this->SchemaId)
                    ." AND C.FieldId = ".$Field->id()
                    ." AND R.".$this->ItemIdColumnName." = RC.RecordId"
                    ." AND RC.ClassificationId = C.ClassificationId" ;
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                $QueryForValues =
                    "SELECT R.".$this->ItemIdColumnName." AS Ids FROM "
                    .$this->ItemTableName." R, ControlledNames N, RecordNameInts RN"
                    ." WHERE R.SchemaId = ".intval($this->SchemaId)
                    ." AND N.FieldId = ".$Field->id()
                    ." AND R.".$this->ItemIdColumnName." = RN.RecordId"
                    ." AND RN.ControlledNameId = N.ControlledNameId";
                break;

            case MetadataSchema::MDFTYPE_USER:
                $QueryForValues =
                    "SELECT R.".$this->ItemIdColumnName." AS Ids FROM "
                    .$this->ItemTableName." R, APUsers U, RecordUserInts RU"
                    ." WHERE R.SchemaId = ".intval($this->SchemaId)
                    ." AND RU.FieldId = ".$Field->id()
                    ." AND R.".$this->ItemIdColumnName." = RU.RecordId"
                    ." AND RU.UserId = U.UserId";
                break;

            case MetadataSchema::MDFTYPE_IMAGE:
                $QueryForValues =
                    "SELECT R.".$this->ItemIdColumnName." AS Ids FROM "
                    .$this->ItemTableName." R, Images I, RecordImageInts RI"
                    ." WHERE R.SchemaId = ".intval($this->SchemaId)
                    ." AND RI.FieldId = ".$Field->id()
                    ." AND R.".$this->ItemIdColumnName." = RI.RecordId"
                    ." AND RI.ImageId = I.ImageIdId" ;
                break;

            case MetadataSchema::MDFTYPE_FILE:
                $QueryForValues =
                    "SELECT R.".$this->ItemIdColumnName." AS Ids FROM "
                    .$this->ItemTableName." R, Files F"
                    ." WHERE R.SchemaId = ".intval($this->SchemaId)
                    ." AND F.FieldId = ".$Field->id()
                    ." AND R.".$this->ItemIdColumnName." = F.RecordId";
                break;

            case MetadataSchema::MDFTYPE_REFERENCE:
                $QueryForValues =
                    "SELECT R.".$this->ItemIdColumnName." AS Ids FROM "
                    .$this->ItemTableName." R, ReferenceInts RI"
                    ." WHERE R.SchemaId = ".intval($this->SchemaId)
                    ." AND RI.FieldId = ".$Field->id()
                    ." AND R.".$this->ItemIdColumnName." = RI.SrcRecordId";
                break;
        }

        if (isset($Condition)) {
            return parent::getItemIds($Condition);
        } elseif (isset($QueryForValues)) {
            $DB = new Database();
            # get a list of all the RecordIds where we have one or more values
            # for the given field
            $DB->query($QueryForValues);
            $RecordIdsWithValue = $DB->fetchColumn("Ids");

            # figure out which RecordIds have no value by starting with
            # a list of all all RecordIds and removing those that have a value
            return array_diff(parent::getItemIds(), $RecordIdsWithValue);
        } else {
            throw new Exception(
                "Neither Condition nor QueryForValues is set in "
                ."getRecordIdsWhereFieldIsEmpty(), which should be impossible."
            );
        }
    }

    /**
     * Filter a list of records from our schema down to only those viewable
     * by the specified user.
     * @param array $RecordIds List of record IDs to filter.
     * @param User $User User to use for filtering.
     * @return array List of record IDs after filtering.
     */
    public function filterOutUnviewableRecords(array $RecordIds, User $User): array
    {
        # compute this user's class
        $UserClass = $this->computeUserClass($User);

        # load our permissions cache (self::$UserClassPermissionsCache)
        $this->loadUserPermsCache($UserClass, $RecordIds);

        # extract CanView results for the records requested from our global
        #   cache for all records
        $Cache = array_intersect_key(
            self::$UserClassPermissionsCache[$UserClass],
            array_flip($RecordIds)
        );

        # generate an array where the keys are record IDs affected by
        #   user comparisons for the current user
        $UserComparisonsRIDs = array_flip(
            $this->recordsWhereUserComparisonsMatterForViewing($User)
        );

        # generate a per-user cache key
        $PerUserKey = $this->SchemaId.".UID_".$User->id();

        # figure out which records we didn't have cached values for
        #   and iterate over those, adding them to our cache when possible
        $CanViewValuesToStore = [];
        $MissingIds = array_diff($RecordIds, array_keys($Cache));
        foreach ($MissingIds as $Id) {
            # if we've already computed per-user permissions for this user in
            #   this page load, use that
            if (isset(self::$PerUserPermissionsCache[$PerUserKey])) {
                $CanView = self::$PerUserPermissionsCache[$PerUserKey];
            } else {
                # otherwise, evaluate permissions for this record
                if (Record::itemExists($Id)) {
                    $Record = Record::getRecord($Id);
                    $CanView = $Record->userCanView($User, false);
                    $ExpirationDate = $Record->getViewCacheExpirationDate();
                } else {
                    $CanView = false;
                    $ExpirationDate = null;
                }

                # if this is a result we can cache persistently
                #   (i.e. not affected by user comparisons), update our internal
                #   caches and queue this value for saving in the database
                if (!isset($UserComparisonsRIDs[$Id])) {
                    self::$UserClassPermissionsCache[$UserClass][$Id] = $CanView;
                    $CanViewValuesToStore[] = [$Id, $UserClass, $CanView, $ExpirationDate];
                } else {
                    # this isn't a result we should cache persistently
                    #   in the database, but we still want to cache it
                    #   within this page load
                    self::$PerUserPermissionsCache[$PerUserKey] = $CanView;
                }
            }
            $Cache[$Id] = $CanView;
        }

        # save CanView values that can be persistently stored
        $this->saveUserPermsCacheValues($CanViewValuesToStore);

        # if record view permission check has any handlers that may
        #   modify our cached values
        if ($GLOBALS["AF"]->isHookedEvent("EVENT_RESOURCE_VIEW_PERMISSION_CHECK")) {
            # apply hooked functions to each value
            foreach (array_keys($Cache) as $Id) {
                $SignalResult = $GLOBALS["AF"]->signalEvent(
                    "EVENT_RESOURCE_VIEW_PERMISSION_CHECK",
                    [
                        "Resource" => $Id,
                        "User" => $User,
                        "CanView" => $Cache[$Id],
                        "Schema" => $this->Schema,
                    ]
                );
                $Cache[$Id] = $SignalResult["CanView"];
            }
        }

        # filter out the non-viewable records, preserving the supplied order
        return array_intersect(
            $RecordIds,
            array_keys(array_filter($Cache))
        );
    }

    /**
     * Filter a list of records from our schema leaving only those viewable by
     *   a specified user.
     * @param array $ResourceIds ResourceIds to check
     * @param User $User User to use for check
     * @return array of ResourceIds (subset of $ResourceIds) that $User can view
     * @deprecated
     */
    public function filterNonViewableRecords(array $ResourceIds, User $User): array
    {
        (ApplicationFramework::getInstance())->logMessage(
            ApplicationFramework::LOGLVL_WARNING,
            "filterNonViewableRecords() called at ".StdLib::getMyCaller()
                    .", rather than filterOutUnviewableRecords()."
        );
        return $this->filterOutUnviewableRecords($ResourceIds, $User);
    }

    /**
     * Given a supplied list of records, return the first N entries that
     * are viewable by the specified user.  This is preferable to just filtering
     * the whole list (with filterOutUnviewableRecords()) because it minimizes
     * the amount of time spent checking permissions.
     * @param array $RecordIds List of record IDs.
     * @param User $User User to use for viewability check.
     * @param int $NumberOfRecords Number of records to return.
     * @return array List of IDs for viewable records (may be less than
     *      the requested number, if not enough viewable records found).
     */
    public function getFirstNViewableRecords(
        array $RecordIds,
        User $User,
        int $NumberOfRecords
    ): array {
        $ViewableRecordIds = [];
        foreach (array_chunk($RecordIds, $NumberOfRecords) as $RecordIdChunk) {
            $ViewableRecordIdChunk = $this->filterOutUnviewableRecords(
                $RecordIdChunk,
                $User
            );
            $ViewableRecordIds = array_merge($ViewableRecordIds, $ViewableRecordIdChunk);
            if (count($ViewableRecordIds) >= $NumberOfRecords) {
                if (count($ViewableRecordIds) > $NumberOfRecords) {
                    $ViewableRecordIds = array_slice(
                        $ViewableRecordIds,
                        0,
                        $NumberOfRecords
                    );
                }
                break;
            }
        }
        return $ViewableRecordIds;
    }

    /**
     * Determine at what date/time the results of a filterOutUnviewableRecords()
     * call will no longer be valid.
     * @param array $RecordIds Records to check.
     * @param User $User User for whom the check is being performed.
     * @return bool|string FALSE when the fNVR() result does not expire, a date in
     *   SQL format giving the expiration time otherwise.
     */
    public function getViewCacheExpirationDate(array $RecordIds, User $User)
    {
        # nothing to check if no records provided
        if (count($RecordIds) == 0) {
            return false;
        }

        $Timestamp = false;

        $UserClass = $this->computeUserClass($User);
        $QueryBase = "SELECT MIN(ExpirationDate) AS Date FROM UserPermsCache WHERE "
            ." ExpirationDate IS NOT NULL AND "
            ." UserClass='".$UserClass."'"
            ." AND RecordId IN ";
        $ChunkSize = Database::getIntegerDataChunkSize(
            $RecordIds,
            strlen($QueryBase) + 2
        );
        foreach (array_chunk($RecordIds, $ChunkSize) as $ChunkIds) {
            $ChunkResult = $this->DB->queryValue(
                $QueryBase."(".implode(",", $ChunkIds).")",
                "Date"
            );

            if (!is_null($ChunkResult)) {
                $ChunkTimestamp = strtotime($ChunkResult);
                if ($Timestamp === false || $ChunkTimestamp < $Timestamp) {
                    $Timestamp = $ChunkTimestamp;
                }
            }
        }

        if ($Timestamp === false) {
            return false;
        }

        return date(StdLib::SQL_DATE_FORMAT, $Timestamp);
    }

    /**
     * Find Ids of records with values that match those specified.(Only
     * works for Text, Paragraph, Number, Timestamp, Date, Flag, Url,
     * Point, and User fields.)
     * @param array $ValuesToMatch Array with metadata field IDs (or other values
     *       that can be resolved by MetadataSchema::GetCanonicalFieldIdentifier())
     *       for the index.Values to search for can be:
     *           strings (all field types)
     *           arrays of strings (all but Point fields)
     *           a User object, a UserId, or an array of either
     *       When an array is provided to search for multiple values only the == and != operators
     *           are supported.
     * @param bool $AllRequired TRUE to AND conditions together, FALSE to OR them
     *        (OPTIONAL, default TRUE)
     * @param string $Operator Operator for comparison, (OPTIONAL, default ==)
     * @return array RecordIds
     */
    public function getIdsOfMatchingRecords(
        array $ValuesToMatch,
        bool $AllRequired = true,
        string $Operator = "=="
    ): array {
        # get the SQL condition to match these resources
        $Condition = $this->getSqlConditionToMatchRecords(
            $ValuesToMatch,
            $AllRequired,
            $Operator
        );

        # if there were no valid conditions, return an empty array
        if (strlen($Condition) == 0) {
            return [];
        }

        # build query statement
        $Query = "SELECT `".$this->ItemIdColumnName."`"
            ." FROM `".$this->ItemTableName."` WHERE (".$Condition
            .") AND SchemaId = ".intval($this->SchemaId);

        # execute query to retrieve matching resource IDs
        $this->DB->Query($Query);
        $RecordIds = $this->DB->FetchColumn($this->ItemIdColumnName);

        # return any RecordIds found to caller
        return $RecordIds;
    }

    /**
     * Count resources with values that match those specified.(Only
     * works for Text, Paragraph, Number, Timestamp, Date, Flag, Url,
     * Point, and User fields.)
     * @param array $ValuesToMatch Array with metadata field IDs (or other values
     *       that can be resolved by MetadataSchema::GetCanonicalFieldIdentifier())
     *       for the index.Values to search for can be:
     *           strings (all field types)
     *           arrays of strings (all but Point fields)
     *           a User object, a UserId, or an array of either
     *       When an array is provided to search for multiple values only the == and != operators
     *           are supported.
     * @param bool $AllRequired TRUE to AND conditions together, FALSE to OR them
     *        (OPTIONAL, default TRUE)
     * @param string $Operator Operator for comparison, (OPTIONAL, default ==)
     * @return int number of matching resources.
     */
    public function getCountOfMatchingRecords(
        array $ValuesToMatch,
        bool $AllRequired = true,
        string $Operator = "=="
    ): int {
        # start off assuming nothing will match
        $Count = 0;

        # get the SQL condition to match these resources
        $Condition = $this->getSqlConditionToMatchRecords(
            $ValuesToMatch,
            $AllRequired,
            $Operator
        );

        # if there were valid conditions
        if (strlen($Condition)) {
            $Count = $this->DB->queryValue(
                "SELECT COUNT(*) AS Count "
                ." FROM `".$this->ItemTableName."` WHERE (".$Condition
                .") AND SchemaId = ".intval($this->SchemaId),
                "Count"
            );
        }

        return $Count;
    }

    /**
     * Return the number of resources in this schema that are visible
     *   to a specified user and that have a given ControlledName value
     *   set.
     * @param int $ValueId Field valueid to look for
     * @param User $User User to check
     * @param bool $ForegroundUpdate TRUE to wait for value rather than
     *   a background update (OPTIONAL, default FALSE)
     * @return int the number of associated resources or -1 when no count
     *      is available
     */
    public function associatedVisibleRecordCount(
        int $ValueId,
        User $User,
        bool $ForegroundUpdate = false
    ): int {
        # if the specified user is matched by any UserIs or UserIsNot
        # privset conditions for any resources, then put them in a class
        # by themselves
        $PermissionsAreUnique = ($User->isAnonymous()
            || count($this->recordsWhereUserComparisonsMatterForViewing($User)) == 0) ?
            false : true;
        $UserClass = $PermissionsAreUnique ?
            "UID_".$User->id() : $this->computeUserClass($User);

        $CacheKey = $this->SchemaId.".".$UserClass;

        # set up the cache for this cache key
        if (!isset(self::$VisibleResourceCountCache[$CacheKey])) {
            self::$VisibleResourceCountCache[$CacheKey] = [];
        }

        $CName = new ControlledName($ValueId);

        # if we don't have a cached result for this valueid and have not yet
        # loaded this field
        if (!isset(self::$VisibleResourceCountCache[$CacheKey][$ValueId]) &&
            !isset(self::$VisibleResourceCountFieldsLoaded[$CName->fieldId()])) {
            # load all the values we have for terms in this vocabulary
            $this->DB->Query(
                "SELECT"
                ." VR.ResourceCount AS ResourceCount,"
                ." VR.ValueId AS ValueId"
                ." FROM"
                ." VisibleRecordCounts VR,"
                ." ControlledNames C"
                ." WHERE "
                ." VR.SchemaId = ".intval($this->SchemaId)
                ." AND VR.UserClass = '".addslashes($UserClass)."'"
                ." AND VR.ValueId = C.ControlledNameId"
                ." AND C.FieldId = ".$CName->fieldId()
            );

            $NewData = $this->DB->FetchColumn(
                "ResourceCount",
                "ValueId"
            );

            if (count($NewData)) {
                self::$VisibleResourceCountCache[$CacheKey] += $NewData;
            }
            self::$VisibleResourceCountFieldsLoaded[$CName->fieldId()] = true;
        }

        # if we still don't have a result for this valueid
        if (!isset(self::$VisibleResourceCountCache[$CacheKey][$ValueId])) {
            $UserId = !$User->isAnonymous() ? $User->id() : null;
            # if we're doing a foreground update
            if ($ForegroundUpdate) {
                # run the update callback
                $this->updateAssociatedVisibleRecordCount(
                    $ValueId,
                    $UserId
                );

                # and call ourselves again
                return $this->associatedVisibleRecordCount(
                    $ValueId,
                    $User
                );
            } else {
                # otherwise (for background update), queue the update
                # callback and return -1
                $GLOBALS["AF"]->QueueUniqueTask(
                    [$this, "updateAssociatedVisibleRecordCount"],
                    [$ValueId, $UserId]
                );
                return -1;
            }
        }

        # owtherwise, return the cached data
        return self::$VisibleResourceCountCache[$CacheKey][$ValueId];
    }


    /**
     * Update the count of resources associated with a
     * ControlledName that are visible to a specified user.
     * @param int $ValueId ControlledNameId to update.
     * @param int|null $UserId UserId to update or NULL for the anonymous
     *   user.
     */
    public function updateAssociatedVisibleRecordCount(
        int $ValueId,
        $UserId
    ) {
        $User = ($UserId === null) ?
            User::getAnonymousUser() : new User($UserId);

        # if the specified user is matched by any UserIs or UserIsNot
        # privset conditions for any resources, then put them in a class
        # by themselves
        $PermissionsAreUnique = ($User->isAnonymous()
            || count($this->recordsWhereUserComparisonsMatterForViewing($User)) == 0) ?
            false : true;
        $UserClass = $PermissionsAreUnique ?
            "UID_".$User->id() : $this->computeUserClass($User);

        $this->DB->Query(
            "SELECT RecordId FROM RecordNameInts "
            ."WHERE ControlledNameId=".intval($ValueId)
        );
        $ResourceIds = $this->DB->fetchColumn("RecordId");

        $ResourceIds = $this->filterOutUnviewableRecords(
            $ResourceIds,
            $User
        );

        $ResourceCount = count($ResourceIds);

        $this->DB->Query(
            "INSERT INTO VisibleRecordCounts "
            ."(SchemaId, UserClass, ValueId, ResourceCount) "
            ."VALUES ("
            .intval($this->SchemaId).","
            ."'".addslashes($UserClass)."',"
            .intval($ValueId).","
            .$ResourceCount.")"
        );

        $CacheKey = $this->SchemaId.".".$UserClass;
        self::$VisibleResourceCountCache[$CacheKey][$ValueId] = $ResourceCount;
    }

    /**
     * Get the total number of resources visible to a specified user.
     * @param User $User User to check.
     * @return int Number of visible resources.
     */
    public function getVisibleRecordCount(User $User): int
    {
        $ResourceIds = $this->DB->Query(
            "SELECT `".$this->ItemIdColumnName."`"
            ." FROM `".$this->ItemTableName."`"
            ." WHERE `".$this->ItemIdColumnName."` > 0"
            ." AND SchemaId = ".intval($this->SchemaId)
        );
        $ResourceIds = $this->DB->FetchColumn("RecordId");

        $ResourceIds = $this->filterOutUnviewableRecords(
            $ResourceIds,
            $User
        );

        return count($ResourceIds);
    }

    /**
     * Clear cache of visible resources associated with a ControlledName.
     * @param array $ValueIds CNIds to clear the cache for.
     */
    public function clearVisibleRecordCountForValues(array $ValueIds)
    {
        if (count($ValueIds) == 0) {
            throw new Exception("No values provided");
        }

        # and clear our visible resource count cache
        $this->DB->Query(
            "DELETE FROM VisibleRecordCounts WHERE "
            ."SchemaId=".intval($this->SchemaId)." AND "
            ."ValueId IN (".implode(",", $ValueIds).")"
        );
    }

    /**
     * Clear database visibility caches for all the CNames referenced
     * by a specified resource.
     * @param Record $Resource Resource to snag values from.
     */
    public function clearVisibleRecordCount($Resource)
    {
        # get all the CName and Option fields
        $Fields = $this->schema()->getFields(
            MetadataSchema::MDFTYPE_OPTION |
            MetadataSchema::MDFTYPE_CONTROLLEDNAME
        );

        # pull out the Values associated with those
        $Values = [];
        foreach ($Fields as $Field) {
            $Values += $Resource->get($Field);
        }

        # if any values were found
        if (count($Values)) {
            # clear our visible resource count cache for those values
            $this->clearVisibleRecordCountForValues(
                array_keys($Values)
            );
        }
    }

    /**
     * Get the total number of released resources in the collection
     * @return int The total number of released resources.
     */
    public function getReleasedRecordTotal(): int
    {
        return $this->getVisibleRecordCount(
            User::getAnonymousUser()
        );
    }

    /**
     * Clear internal caches. This is primarily intended for situations where
     * memory may have run low.
     */
    public function clearCaches()
    {
        self::clearStaticCaches();
    }

    # ---- PUBLIC STATIC INTERFACE -------------------------------------------

    /**
     * Clear internal static caches.
     */
    public static function clearStaticCaches()
    {
        self::$VisibleResourceCountCache = [];
        self::$VisibleResourceCountFieldsLoaded = [];
        self::$UserClassPermissionsCache = [];
        self::$PerUserPermissionsCache = [];
        self::$UserClassCache = [];
        self::$UserComparisonResourceCache = [];
        self::$UserComparisonFieldCache = [];
        self::$RecordSchemaCache = null;
    }

    /**
     * Check whether resource with specified ID exists, in any schema.
     * @param int $Id Resource ID to check.
     * @return bool TRUE if resource exists, otherwise FALSE.
     */
    public static function recordExistsInAnySchema(int $Id): bool
    {
        return (new self())->itemExists($Id, true);
    }

    /**
     * Take an array keyed by SchemaId with elements giving arrays of
     * ResourceIds and merge it to a flattened list of ResourceIds.
     * @param array $ResourcesPerSchema Array of per-schema ResourceIds.
     * @return array Flattened array of ResourceIds.
     * @see RecordFactory::bulidMultiSchemaResourceList()
     */
    public static function flattenMultiSchemaRecordList(array $ResourcesPerSchema): array
    {
        $Result = [];
        foreach ($ResourcesPerSchema as $SchemaId => $ResourceIds) {
            $Result = array_merge($Result, $ResourceIds);
        }

        return $Result;
    }

    /**
     * Take an array of ResourceIds and split it into an array keyed by
     * SchemaId where the elements are arrays of ResourceIds for that
     * schema.
     * @param array $ResourceIds Array of ResourceIds.
     * @return array Two-dimensional array of ResourceIds.
     * @see RecordFactory::flattenMultiSchemaResourceList()
     */
    public static function buildMultiSchemaRecordList(array $ResourceIds): array
    {
        # bulk load the schema cache
        if (is_null(self::$RecordSchemaCache)) {
            $DB = new Database();
            $DB->query("SELECT RecordId, SchemaId FROM Records");
            self::$RecordSchemaCache = $DB->fetchColumn("SchemaId", "RecordId");
        }

        $Result = [];
        foreach ($ResourceIds as $Id) {
            # if no entry in cache (e.g., record created after cache was loaded)
            if (!isset(self::$RecordSchemaCache[$Id])) {
                self::$RecordSchemaCache[$Id] = Record::getSchemaForRecord($Id);
            }
            $SchemaId = self::$RecordSchemaCache[$Id];
            $Result[$SchemaId][] = $Id;
        }

        return $Result;
    }

    /**
     * Filter a list of Record IDs from a mixture of schemas leaving only
     *   those viewable by a specified user.
     * @param array $RecordIds Record IDs to check
     * @param User $User User to use for check
     * @return array of ResourceIds (subset of $ResourceIds) that $User can view
     */
    public static function multiSchemaFilterNonViewableRecords(array $RecordIds, User $User)
    {
        $ViewableRecords = [];

        $MultiSchemaList = self::buildMultiSchemaRecordList($RecordIds);
        foreach ($MultiSchemaList as $SchemaId => $SchemaRecordIds) {
            $RFactory = new self($SchemaId);
            $ViewableRecords[$SchemaId] = $RFactory->filterOutUnviewableRecords(
                $SchemaRecordIds,
                $User
            );
        }
        $ViewableRecords = self::flattenMultiSchemaRecordList($ViewableRecords);

        return array_intersect($RecordIds, $ViewableRecords);
    }

    /**
     * Iterate over a list of Records to determine if any of them have a
     *   screenshot available.
     * @param array $Records Records to check.
     * @return bool TRUE if one or more records has a screenshot, FALSE
     *  otherwise.
     */
    public static function recordListHasAnyScreenshots(
        array $Records
    ): bool {
        foreach ($Records as $Record) {
            $Screenshot = $Record->getMapped("Screenshot");
            if (is_array($Screenshot) && count($Screenshot) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Iterate over a list of Record Ids to determine if any of them have a
     *   screenshot available.
     * @param array $RecordIds Record Ids to check.
     * @return bool TRUE if one or more records has a screenshot, FALSE
     *  otherwise.
     */
    public static function recordIdListHasAnyScreenshots(
        array $RecordIds
    ): bool {
        foreach ($RecordIds as $RecordId) {
            $Record = new Record($RecordId);
            $Screenshot = $Record->getMapped("Screenshot");
            if (is_array($Screenshot) && count($Screenshot) > 0) {
                return true;
            }
        }
        return false;
    }


    /**
     * Clear the cache of viewable resources.
     */
    public static function clearViewingPermsCache()
    {
        $DB = new Database();
        $DB->query("DELETE FROM UserPermsCache");

        self::$UserClassPermissionsCache = [];
        self::$PerUserPermissionsCache = [];
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $Schema;
    protected $SchemaId;

    # internal caches
    private static $VisibleResourceCountCache;
    private static $VisibleResourceCountFieldsLoaded;
    private static $UserClassPermissionsCache;
    private static $PerUserPermissionsCache;
    private static $UserClassCache;
    private static $UserComparisonResourceCache;
    private static $UserComparisonFieldCache;
    private static $RecordSchemaCache = null;

    /**
     * Generate SQL to find resources with values that match those specified.(Only
     * works for Text, Paragraph, Number, Timestamp, Date, Flag, Url,
     * Point, and User fields.)
     * @param array $ValuesToMatch Array with metadata field IDs (or other values
     *       that can be resolved by MetadataSchema::GetCanonicalFieldIdentifier())
     *       for the index.Values to search for can be:
     *           strings (all field types)
     *           arrays of strings (all but Point fields)
     *           a User object, a UserId, or an array of either
     *       When an array is provided to search for multiple values only the == and != operators
     *           are supported.
     * @param bool $AllRequired TRUE to AND conditions together, FALSE to OR them
     *        (OPTIONAL, default TRUE)
     * @param string $Operator Operator for comparison, (OPTIONAL, default ==)
     * @return string SQL to be inserted in a WHERE clause
     */
    private function getSqlConditionToMatchRecords(
        array $ValuesToMatch,
        bool $AllRequired = true,
        string $Operator = "=="
    ): string {
        # fix up equality operator
        if ($Operator == "==") {
            $Operator = "=";
        }

        # fix up pattern matching operator
        if ($Operator == "=~") {
            $Operator = "REGEXP";
        }

        $LinkingTerm = "";
        $Condition = "";

        # for each value
        $Fields = $this->Schema->getFields();
        foreach ($ValuesToMatch as $FieldId => $Value) {
            # only equality supported for NULL
            if ($Operator != "=" && $Value == "NULL") {
                throw new InvalidArgumentException(
                    "Invalid operator, ".$Operator." is not supported for NULL"
                );
            }

            # convert supplied FieldId to canonical identifier
            $FieldId = MetadataSchema::getCanonicalFieldIdentifier(
                $FieldId,
                $this->SchemaId
            );

            # check that provided operator is sane
            switch ($Fields[$FieldId]->Type()) {
                case MetadataSchema::MDFTYPE_TEXT:
                case MetadataSchema::MDFTYPE_PARAGRAPH:
                case MetadataSchema::MDFTYPE_URL:
                case MetadataSchema::MDFTYPE_EMAIL:
                    $ValidOps = ["=", "REGEXP"];
                    break;
                case MetadataSchema::MDFTYPE_POINT:
                case MetadataSchema::MDFTYPE_USER:
                    $ValidOps = ["="];
                    break;

                case MetadataSchema::MDFTYPE_FLAG:
                    $ValidOps = ["=", "!="];
                    break;

                case MetadataSchema::MDFTYPE_NUMBER:
                case MetadataSchema::MDFTYPE_DATE:
                case MetadataSchema::MDFTYPE_TIMESTAMP:
                    $ValidOps = [
                        "=",
                        "!=",
                        "<",
                        "<=",
                        ">",
                        ">="
                    ];
                    break;

                default:
                    $ValidOps = [];
            }

            if (count($ValidOps) && !in_array($Operator, $ValidOps)) {
                throw new InvalidArgumentException(
                    "Operator ".$Operator." is not supported for "
                    .$Fields[$FieldId]->TypeAsName()." fields"
                );
            }

            # if multiple values were specified, be sure that they are
            # supported for the given operator
            if (is_array($Value) && !in_array($Operator, ["=", "!="])) {
                throw new InvalidArgumentException(
                    "Operator ".$Operator." is not supported for "
                    ."fields with multiple values"
                );
            }

            # for the REGEXP operator, check if the provided value (pattern)
            # is a non-empty string
            if ($Operator == "REGEXP" && (!is_string($Value) || strlen($Value) == 0)) {
                throw new InvalidArgumentException(
                    "Value for REGEX comparisons must be a non-empty string"
                );
            }

            # add SQL fragments to Condition as needed
            switch ($Fields[$FieldId]->Type()) {
                case MetadataSchema::MDFTYPE_TEXT:
                case MetadataSchema::MDFTYPE_PARAGRAPH:
                case MetadataSchema::MDFTYPE_NUMBER:
                case MetadataSchema::MDFTYPE_DATE:
                case MetadataSchema::MDFTYPE_TIMESTAMP:
                case MetadataSchema::MDFTYPE_FLAG:
                case MetadataSchema::MDFTYPE_URL:
                    $DBFname = $Fields[$FieldId]->DBFieldName();
                    # add comparison to condition
                    if ($Value == "NULL") {
                        $Condition .= $LinkingTerm."("
                            .$DBFname." IS NULL OR ".$DBFname." = '')";
                    } else {
                        # if multiple values specified
                        if (is_array($Value)) {
                            # escape all the values given and enclose them in quotes
                            $EscapedValues = array_map(
                                function ($x) {
                                    return "'".addslashes($x)."'";
                                },
                                $Value
                            );
                            $Condition .= $LinkingTerm.$DBFname." "
                                .($Operator == "!=" ? "NOT " : "")
                                ."IN (".implode(",", $EscapedValues).")";
                        } else {
                            if ($Fields[$FieldId]->type() == MetadataSchema::MDFTYPE_TIMESTAMP) {
                                $Value = date(StdLib::SQL_DATE_FORMAT, strtotime($Value));
                            }

                            $Condition .= $LinkingTerm.$DBFname." "
                                .$Operator." '".addslashes($Value)."'";
                        }
                    }
                    break;

                case MetadataSchema::MDFTYPE_POINT:
                    $DBFname = $Fields[$FieldId]->DBFieldName();

                    if ($Value == "NULL") {
                        $Condition .= $LinkingTerm."("
                               .$DBFname."X IS NULL AND "
                               .$DBFname."Y IS NULL)";
                    } else {
                        $Vx = addslashes($Value["X"]);
                        $Vy = addslashes($Value["Y"]);

                        $Condition .= $LinkingTerm."("
                               .$DBFname."X = '".$Vx."' AND "
                               .$DBFname."Y = '".$Vy."')";
                    }
                    break;

                case MetadataSchema::MDFTYPE_USER:
                    # convert to array if needed
                    if (!is_array($Value)) {
                        $Value = [ $Value ];
                    }

                    # build the list of UserIds to search for
                    $TgtValues = [];

                    # ensure each provided user is valid
                    foreach ($Value as $UserOrId) {
                        if ($UserOrId instanceof User) {
                            $TgtValues[] = $UserOrId->id();
                        } elseif (is_numeric($UserOrId) && User::itemExists((int)$UserOrId)) {
                            $TgtValues[] = $UserOrId;
                        } else {
                            throw new InvalidArgumentException(
                                "Invalid value provided for User field"
                            );
                        }
                    }

                    # if no users were specified
                    if (!count($TgtValues)) {
                        # then nothing matches
                        $Condition .= $LinkingTerm."(FALSE)";
                    } else {
                        # add conditional to match specified users
                        $Condition .= $LinkingTerm."("
                            ."`".$this->ItemIdColumnName."` IN (SELECT RecordId FROM "
                            ."RecordUserInts WHERE FieldId=".intval($FieldId)
                            ." AND UserId IN ("
                            .implode(",", $TgtValues).")) )";
                    }
                    break;

                default:
                    throw new InvalidArgumentException(
                        "Unsupported field type: ".$Fields[$FieldId]->TypeAsName()
                    );
            }

            $LinkingTerm = $AllRequired ? " AND " : " OR ";
        }

        return $Condition;
    }

    /**
     * Populate the UserClassPermissionsCache for a specified user class.
     * @param string $UserClass User class to use.
     * @param array $RecordIds RecordIds to load.
     */
    private function loadUserPermsCache(
        string $UserClass,
        array $RecordIds
    ) {
        # once per page-load, clear out expired entries in the database cache
        static $StaleEntriesHaveBeenExpired = false;
        if (!$StaleEntriesHaveBeenExpired) {
            # clear expired entries
            $this->DB->query(
                "DELETE FROM UserPermsCache WHERE "
                ."ExpirationDate IS NOT NULL AND ExpirationDate < NOW()"
            );
            $StaleEntriesHaveBeenExpired = true;
        }

        # (Note: We can use the user class without a schema prefix to key
        #  $UserClassPermissionsCache rather than a $CacheKey with an explicit
        #  schema prefix (as in similar places like getUserComparisonFields(),
        #  associatedVisibleRecordCount(), and others) even though User Classes
        #  are schema specific because the values we're caching are Record IDs.
        #  Since the Record IDs already imply a schema, there's no ambiguity
        #  regarding which schema was involved when the stored user class
        #  was computed.)

        # ensure our cache has an entry for this user class
        if (!isset(self::$UserClassPermissionsCache[$UserClass])) {
            self::$UserClassPermissionsCache[$UserClass] = [];
        }

        # figure out which records we are missing
        $MissingIds = array_diff(
            $RecordIds,
            array_keys(self::$UserClassPermissionsCache[$UserClass])
        );

        # if nothing was missing, nothing to do
        if (count($MissingIds) == 0) {
            return;
        }

        # populate the cache for the missing records
        $QueryBase = "SELECT RecordId, CanView FROM UserPermsCache WHERE"
            ." UserClass='".$UserClass."'"
            ." AND RecordId IN ";

        $ChunkSize = Database::getIntegerDataChunkSize(
            $MissingIds,
            strlen($QueryBase) + 2
        );

        foreach (array_chunk($MissingIds, $ChunkSize) as $ChunkIds) {
            $this->DB->query(
                $QueryBase."(".implode(",", $ChunkIds).")"
            );
            self::$UserClassPermissionsCache[$UserClass] += $this->DB->FetchColumn(
                "CanView",
                "RecordId"
            );
        }
    }

    /**
     * Persistently store values in the UserPermsCache
     * @param array $Values Values to store where each row is an array in the
     *   form [Id, UserClass, CanView, ExpirationDate]
     */
    private function saveUserPermsCacheValues($Values)
    {
        $QueryBase = "INSERT INTO UserPermsCache "
            ."(RecordId, UserClass, CanView, ExpirationDate) VALUES ";
        $QuerySuffix = " ON DUPLICATE KEY UPDATE "
            ."CanView = VALUES(CanView), ExpirationDate = VALUES(ExpirationDate)";
        $MaxQueryValueLength = Database::getMaxQueryLength() - strlen($QueryBase)
            - strlen($QuerySuffix);

        $QueryValues = [];
        $QueryValueLength = 0;

        # iterate over provided values
        foreach ($Values as $Value) {
            list($Id, $UserClass, $CanView, $ExpirationDate) = $Value;

            # construct the sql values for this item
            $ItemValues =
                "("
                .$Id.","
                ."'".$UserClass."',"
                .($CanView ? "1" : "0").","
                .($ExpirationDate !== false ? "'".$ExpirationDate."'" : "NULL")
                .")";
            $ItemValueLength = strlen($ItemValues) + 1; # add 1 for the comma

            # if adding this item to our query would make the query too long
            if ($QueryValueLength + $ItemValueLength >= $MaxQueryValueLength) {
                # run the query and clear our queue of values
                $this->DB->query(
                    $QueryBase.implode(",", $QueryValues).$QuerySuffix
                );
                $QueryValues = [];
                $QueryValueLength = 0;
            }

            # and add this value into our queue
            $QueryValues[] = $ItemValues;
            $QueryValueLength += $ItemValueLength;
        }

        # if we have values left to insert, do so
        if (count($QueryValues)) {
            $this->DB->query(
                $QueryBase.implode(",", $QueryValues).$QuerySuffix
            );
        }
    }

    /**
     * Compute a UserClass based on the privilege flags
     * (i.e.PRIV_SYSADMIN, etc) used in the current schema (i.e.,
     * appearing in conditions within ViewingPrivileges).
     * @param User $User User to compute a user class for
     * @return string user class
     */
    private function computeUserClass(User $User): string
    {
        # put the anonymous user into their own user class, otherwise
        # use the UserId for a key into the ClassCache
        $UserId = $User->isAnonymous() ? "XX-ANON-XX" : $User->id();

        $CacheKey = $this->SchemaId.".".$UserId;

        # check if we have a cached UserClass for this User
        if (!isset(self::$UserClassCache[$CacheKey])) {
            # assemble a list of the privilege flags (PRIV_SYSADMIN,
            # etc) that are checked when evaluating the UserCanView for
            # all fields in this schema
            $RelevantPerms = [];

            foreach ($this->Schema->getFields() as $Field) {
                $RelevantPerms = array_merge(
                    $RelevantPerms,
                    $Field->ViewingPrivileges()->PrivilegeFlagsChecked()
                );
            }
            $RelevantPerms = array_unique($RelevantPerms);

            # whittle the list of all privs checked down to just the
            # list of privs that users in this class have
            $PermsInvolved = [];
            foreach ($RelevantPerms as $Perm) {
                if ($User->hasPriv($Perm)) {
                    $PermsInvolved[] = $Perm;
                }
            }

            # generate a string by concatenating all the involved
            # permissions then hashing the result (hashing gives
            # a fixed-size string for storing in the database)
            self::$UserClassCache[$CacheKey] = md5(implode("-", $PermsInvolved));
        }

        return self::$UserClassCache[$CacheKey];
    }

    /**
     * List resources where a UserIs or UserIsNot condition changes
     *  viewability from the default viewability for their user class
     *  (for example, the list of resources a users with their privilege flags
     *  would not normally be able to see, but this specific user can
     *  because they are the value of AddedById)
     * @param User $User User object
     * @return Array of ResourceIds
     */
    private function recordsWhereUserComparisonsMatterForViewing(User $User): array
    {
        $ResourceIds = [];

        # if we're checking the anonymous user, presume that
        #  nothing will match
        if ($User->isAnonymous()) {
            return $ResourceIds;
        }

        $CacheKey = $this->SchemaId.".".$User->id();
        if (!isset(self::$UserComparisonResourceCache[$CacheKey])) {
            $Schema = new MetadataSchema($this->SchemaId);

            # for each comparison type
            foreach (["==", "!="] as $ComparisonType) {
                $UserComparisonFields = $this->getUserComparisonFields(
                    $ComparisonType
                );

                # if we have any fields to check
                if (count($UserComparisonFields) > 0) {
                    # query the database for resources where one or more of the
                    # user comparisons will be satisfied
                    $SqlOp = ($ComparisonType == "==") ? "= " : "!= ";
                    $DB = new Database();
                    $DB->query(
                        "SELECT R.`".$this->ItemIdColumnName."` as RecordId FROM ".
                        "`".$this->ItemTableName."` R, RecordUserInts RU WHERE ".
                        "R.SchemaId = ".$this->SchemaId." AND ".
                        "R.RecordId = RU.RecordId AND ".
                        "RU.UserId ".$SqlOp.$User->id()." AND ".
                        "RU.FieldId IN (".implode(",", $UserComparisonFields).")"
                    );
                    $Result = $DB->fetchColumn("RecordId");

                    # merge those resources into our results
                    $ResourceIds = array_merge(
                        $ResourceIds,
                        $Result
                    );
                }
            }

            self::$UserComparisonResourceCache[$CacheKey] = array_unique($ResourceIds);
        }

        return self::$UserComparisonResourceCache[$CacheKey];
    }

    /**
     * Fetch the list of fields implicated in user comparisons.
     * @param string $ComparisonType Type of comparison (i.e.'==' or '!=')
     * @return array of FieldIds
     */
    private function getUserComparisonFields(string $ComparisonType): array
    {
        $CacheKey = $this->SchemaId.".".$ComparisonType;
        if (!isset(self::$UserComparisonFieldCache[$CacheKey])) {
            # iterate through all the fields in the schema,
            #  constructing a list of the User fields implicated
            #  in comparisons of the desired type
            $UserComparisonFields = [];
            foreach ($this->Schema->getFields() as $Field) {
                $UserComparisonFields = array_merge(
                    $UserComparisonFields,
                    $Field->ViewingPrivileges()->FieldsWithUserComparisons(
                        $ComparisonType
                    )
                );
            }
            self::$UserComparisonFieldCache[$CacheKey] =
            array_unique($UserComparisonFields);
        }

        return self::$UserComparisonFieldCache[$CacheKey];
    }

    /**
     * Log a warning when a 'Resource' function is called instead of a 'Record' function
     * @param string $CalledFn Name of the function called
     * @param string $CallerInfo Information about the call site
     * @codeCoverageIgnore
     */
    private function logResourceFunctionWarning(string $CalledFn, string $CallerInfo)
    {
        $CorrectFn = str_replace(
            ["Resource", "resource"],
            ["Record", "record"],
            $CalledFn
        );

        $GLOBALS["AF"]->logMessage(
            ApplicationFramework::LOGLVL_WARNING,
            "Use of RecordFactory::".$CalledFn
            ." (should be ".$CorrectFn.") at "
            .$CallerInfo
        );
    }

    /**
     * Load data for an image field from XML.
     * @param string $BaseDir Base directory for locating images.
     * @param Record $Resource Record to modify.
     * @param int $ResourceIndex Record number for error messages.
     * @param MetadataField $Field Field ti populate.
     * @param mixed $XmlData XML data produced by the simplexml library.
     */
    private function importImagesFromXml(
        string $BaseDir,
        Record $Resource,
        int $ResourceIndex,
        MetadataField $Field,
        $XmlData
    ) {
        if (!property_exists($XmlData, "image")) {
            $this->logErrorMessage("<image> tag not found (in the "
                                   .$Field->name()." field in record #"
                                   .$ResourceIndex.")");
            return;
        }

        # iterate through each <image> node
        foreach ($XmlData->image as $ImageXml) {
            # iterate through nodes within <image>
            $SourceImageName = null;
            $AltText = null;
            foreach ($ImageXml->children() as $Child) {
                switch ($Child->getName()) {
                    case "file":
                        $SourceImageName = $BaseDir."/".$Child;
                        break;

                    case "alttext":
                        $AltText = (string) $Child;
                        break;

                    default:
                        $this->logErrorMessage(
                            "Unknown tag found: <".$Child->getName().">"
                                ." (in the ".$Field->name()." field in record #"
                                .$ResourceIndex.")"
                        );
                }
            }

            # check that a source image was found
            if (is_null($SourceImageName)) {
                $this->logErrorMessage(
                    "<file> tag not found for <image> (in the "
                        .$Field->name()." field in record #"
                        .$ResourceIndex.")"
                );
                continue;
            }

            # check file exist
            if (!file_exists($SourceImageName)) {
                $this->logErrorMessage(
                    "File ".$SourceImageName
                        ." does not exist (in the ".$Field->name()
                        ." field in record #".$ResourceIndex.")"
                );
                continue;
            }

            # check file is readable
            if (!is_readable($SourceImageName)) {
                $this->logErrorMessage(
                    "File ".$SourceImageName
                        ." is not readable (in the ".$Field->name()
                        ." field in record #".$ResourceIndex.")"
                );
                continue;
            }

            # attempt to save to resource
            try {
                $Image = Image::create($SourceImageName);
                if (!is_null($AltText)) {
                    $Image->altText($AltText);
                }
                $Resource->set($Field, $Image);
            } catch (Exception $e) {
                $this->logErrorMessage(
                    "Exception while"
                    ." creating Image object and"
                    ." saving to Record:\n".$e->getMessage()
                    ."\n(in the ".$Field->name()
                    ." field in record #".$ResourceIndex.")"
                );
                continue;
            }
        }
    }

    /**
     * Load data for a file field from XML.
     * @param string $BaseDir Base directory for locating files.
     * @param Record $Resource Record to modify.
     * @param int $ResourceIndex Record number for error messages.
     * @param MetadataField $Field Field ti populate.
     * @param mixed $XmlData XML data produced by the simplexml library.
     */
    private function importFilesFromXml(
        string $BaseDir,
        Record $Resource,
        int $ResourceIndex,
        MetadataField $Field,
        $XmlData
    ) {
        if (!property_exists($XmlData, "file")) {
            $this->logErrorMessage(
                "<file> tag not found (in the "
                    .$Field->name()." field in record #"
                    .$ResourceIndex.")"
            );
            return;
        }

        # iterate through each <file> node
        foreach ($XmlData->file as $FileXml) {
            $SourceFileName = $BaseDir."/".$FileXml;

            # check file exists and is readable
            if (!file_exists($SourceFileName)) {
                $this->logErrorMessage(
                    "File ".$SourceFileName
                    ." does not exist (in the ".$Field->name()
                    ." field in record #".$ResourceIndex.")"
                );
                continue;
            }

            if (!is_readable($SourceFileName)) {
                $this->logErrorMessage(
                    "File ".$SourceFileName
                        ." is not readable (in the ".$Field->name()
                        ." field in record #".$ResourceIndex.")"
                );
                continue;
            }

            # attempt to save to resource
            try {
                $File = File::create($SourceFileName, null, false);
                $Resource->set($Field, $File);
            } catch (Exception $e) {
                $this->logErrorMessage(
                    "Exception while"
                    ." creating File object and saving"
                    ." to Record:\n".$e->getMessage()
                    ."\n(in the ".$Field->name()
                    ." field in record #".$ResourceIndex.")"
                );
                continue;
            }
        }
    }
}
