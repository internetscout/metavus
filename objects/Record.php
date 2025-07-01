<?PHP
#
#   FILE:  Record.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Date;
use ScoutLib\Item;
use ScoutLib\ObserverSupportTrait;
use ScoutLib\StdLib;

/**
 * Represents a set of metadata describing a resource.
 */
class Record extends Item
{
    use ObserverSupportTrait;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    # types of changes to fields that can be performed by Record::applyListOfChanges()
    const CHANGE_NOP = 0;
    const CHANGE_SET = 1; /* for setting a single, specified value */
    const CHANGE_CLEAR = 2; /* for clearing a single, specified value */
    const CHANGE_CLEARALL = 3; /* for clearing all values */
    const CHANGE_APPEND = 4;
    const CHANGE_PREPEND = 5;
    /* const CHANGE_REPLACE = 6; (legacy value; no longer used) */
    const CHANGE_FIND_REPLACE = 7;

    const IMAGE_CACHE_PATH = "local/data/caches/ImageLinks";

    # events that can be monitored via registerObserver()
    # (TO DO: push down into ObserverSupportTrait once our minimum
    #   supported PHP version allows constants in traits (PHP 8.2))
    const EVENT_SET = 1;
    const EVENT_CLEAR = 2;
    const EVENT_ADD = 4;
    const EVENT_REMOVE = 8;

    /**
     * Object constructor for loading an existing record.(To create a new
     * record, use Record::create().)
     * @param int $RecordId ID of resource to load.
     * @see Record::create()
     * @throws InvalidArgumentException If ID is invalid.
     */
    public function __construct(int $RecordId)
    {
        # call parent contstructor to load info from DB
        parent::__construct($RecordId);

        # load local attributes from database value cache
        $this->CumulativeRating = $this->DB->updateValue("CumulativeRating");

        # load our local metadata schema
        $this->SchemaId = $this->DB->updateValue("SchemaId");
        if (!isset(self::$Schemas[$this->SchemaId])) {
            self::$Schemas[$this->SchemaId] =
                    new MetadataSchema($this->SchemaId);
        }
    }

    /**
     * Create a new resource.
     * @param int $SchemaId ID of metadata schema for new resource.
     * @return Record New Record object.
     * @throws Exception If record creation failed.
     */
    public static function create(int $SchemaId)
    {
        # be sure the DB access values are set
        $Class = get_called_class();
        static::setDatabaseAccessValues($Class);

        # clean out any temp resource records more than three days old
        $RFactory = new RecordFactory();
        $RFactory->cleanOutStaleTempItems(60 * 24 * 3);

        # lock DB tables to prevent next ID from being grabbed
        $DB = new Database();
        $DB->query("LOCK TABLES Records WRITE");

        # find next temp resource ID
        $Id = $RFactory->getNextTempItemId();

        # write out new resource record with temp resource ID
        #  Set DateLastModified = NOW() to avoid being pruned as a
        #  stale temp resource.
        $DB->query(
            "INSERT INTO Records
            SET `".self::$ItemIdColumnNames[$Class]."` = '".intval($Id)."',
            `SchemaId` = '".intval($SchemaId)."',
            `DateLastModified` = NOW() "
        );

        # release DB tables
        $DB->query("UNLOCK TABLES");

        # instantiate newly-added record as object
        $Record = new Record($Id);

        # for each field that can have a default value
        $Schema = new MetadataSchema($SchemaId);
        $Fields = $Schema->getFields(MetadataSchema::MDFTYPE_OPTION
                | MetadataSchema::MDFTYPE_TEXT
                | MetadataSchema::MDFTYPE_FLAG
                | MetadataSchema::MDFTYPE_NUMBER
                | MetadataSchema::MDFTYPE_POINT);
        foreach ($Fields as $Field) {
            # if there is a default value available
            $DefaultValue = $Field->DefaultValue();
            if (($DefaultValue !== false) || ($Field->type() == MetadataSchema::MDFTYPE_FLAG)) {
                # if the default value is an array
                if (is_array($DefaultValue)) {
                    # if there are values in the array
                    if (!empty($DefaultValue)) {
                        # set default value
                        $Record->set($Field, $DefaultValue);
                    }
                } else {
                    # set default value
                    $Record->set($Field, $DefaultValue);
                }
            }
        }

        $Record->updateAutoupdateFields(
            MetadataField::UPDATEMETHOD_ONRECORDCREATE,
            User::getCurrentUser()
        );

        # signal record creation
        (ApplicationFramework::getInstance())->signalEvent(
            "EVENT_RESOURCE_CREATE",
            ["Resource" => $Record]
        );
        $Record->notifyObservers(self::EVENT_ADD);

        # return new Resource object to caller
        return $Record;
    }

    /**
     * Duplicate the specified resource and return to caller.
     * @param int $ResourceId ID of resource to duplicate.
     * @param bool $MarkAsDuplicate TRUE to add a [DUPLICATE] tag to the
     *     record. If a mapped Title field exists, it is added
     *     there. Otherwise, mapped Description is used. If neither is mapped,
     *     nothing is added. (OPTIONAL, default TRUE)
     * @return Record New Resource object.
     */
    public static function duplicate(
        int $ResourceId,
        bool $MarkAsDuplicate = true
    ) {
        # check that resource to be duplicated exists
        if (!Record::itemExists($ResourceId)) {
            throw new InvalidArgumentException(
                "No resource found with specified ID (".$ResourceId.")."
            );
        }

        # load up resource to duplicate
        $SrcResource = new Record($ResourceId);
        $Schema = $SrcResource->getSchema();

        # create new target resource
        $DstResource = Record::create($Schema->id());

        # for each metadata field
        $Fields = $Schema->getFields();
        foreach ($Fields as $Field) {
            if ($Field->copyOnResourceDuplication()) {
                $NewValue = $SrcResource->get($Field, true);

                # clear default value from destination resource that is
                # set when creating a new resource
                $DstResource->clear($Field);

                if ($NewValue !== null) {
                    # copy value from source resource to destination resource
                    if (is_array($NewValue) && count($NewValue) > 0 &&
                        current($NewValue) instanceof Image) {
                        # handle image duplication
                        $DuplicateImgs = [];
                        foreach ($NewValue as $Element) {
                            $DuplicateImg = $Element->duplicate();
                            $DuplicateImgs[$DuplicateImg->id()] = $DuplicateImg;
                        }
                        $NewValue = $DuplicateImgs;
                    }
                    $DstResource->set($Field, $NewValue);
                }
            }
        }

        # modify likely field to indicate resource is duplicate
        if ($MarkAsDuplicate) {
            foreach (["Title", "Description"] as $FieldName) {
                $MappedField = $Schema->getFieldByMappedName($FieldName);
                if ($MappedField instanceof MetadataField) {
                    $DstResource->set(
                        $MappedField,
                        $DstResource->get($MappedField)." [DUPLICATE]"
                    );
                    break;
                }
            }
        }

        # update auto-update fields
        $UpdateTypes = [
            MetadataField::UPDATEMETHOD_ONRECORDCREATE,
            MetadataField::UPDATEMETHOD_ONRECORDCHANGE,
        ];

        if ($DstResource->userCanView(User::getAnonymousUser())) {
            $UpdateTypes[] = MetadataField::UPDATEMETHOD_ONRECORDRELEASE;
        }

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        foreach ($UpdateTypes as $UpdateType) {
            $DstResource->updateAutoupdateFields(
                $UpdateType,
                $User
            );
        }

        # return new resource to caller
        return $DstResource;
    }

    /**
     * Remove resource (and accompanying associations) from database and
     * delete any associated files.
     * @return void|int|array May return number of records destroyed or list of
     *      IDs of records that were destroyed.
     */
    public function destroy()
    {
        # signal that resource deletion is about to occur
        (ApplicationFramework::getInstance())->signalEvent(
            "EVENT_RESOURCE_DELETE",
            ["Resource" => $this]
        );
        $this->notifyObservers(self::EVENT_REMOVE);

        # grab list of classifications
        $Classifications = $this->classifications();

        # delete resource/classification intersections
        $DB = $this->DB;
        $DB->query(
            "DELETE FROM RecordClassInts WHERE "
            ."`".$this->ItemIdColumnName."` = ".$this->id()
        );

        # for each classification type
        foreach ($Classifications as $ClassType => $ClassesOfType) {
            # for each classification of that type
            foreach ($ClassesOfType as $ClassId => $ClassName) {
                # recalculate resource count for classification
                $Class = new Classification($ClassId);
                $Class->recalcResourceCount();
            }
        }

        # delete resource references
        $DB->query("DELETE FROM ReferenceInts WHERE SrcRecordId = "
                .$this->id()." OR DstRecordId = ".$this->id());

        # delete resource/name intersections
        $DB->query("DELETE FROM RecordNameInts WHERE RecordId = ".$this->id());

        # delete resource/user intersections
        $DB->query("DELETE FROM RecordUserInts WHERE RecordId = ".$this->id());

        # delete all the images associated with this resource
        $ImageFactory = new ImageFactory();

        $ImageFields = $this->getSchema()->getFields(
            MetadataSchema::MDFTYPE_IMAGE
        );
        foreach ($ImageFields as $Field) {
            $ImageIds = $ImageFactory->getImageIdsForRecord(
                $this->id(),
                $Field->id()
            );

            # delete the associated images
            foreach ($ImageIds as $ImageId) {
                (new Image($ImageId))->destroy();
            }
        }

        # delete image symlinks
        $this->clearAllImageSymlinks();

        # delete any associated files
        $Factory = new FileFactory(null);
        $Files = $Factory->getFilesForResource($this->id());
        foreach ($Files as $File) {
            $File->destroy();
        }

        # delete resource ratings
        $DB->query("DELETE FROM RecordRatings WHERE RecordId = ".$this->id());

        # delete resource record from database
        $DB->query(
            "DELETE FROM Records WHERE "
            ."`".$this->ItemIdColumnName."` = ".$this->id()
        );

        # drop item from search engine and recommender system
        $SysConfig = SystemConfiguration::getInstance();
        if ($SysConfig->getBool("SearchDBEnabled")) {
            $SearchEngine = new SearchEngine();
            $SearchEngine->dropItem($this->id());
        }
        if ($SysConfig->getBool("RecommenderDBEnabled")) {
            $Recommender = new Recommender();
            $Recommender->dropItem($this->id());
        }

        # get the folders containing the resource
        $FolderFactory = new FolderFactory();
        $Folders = $FolderFactory->getFoldersContainingItem(
            $this->Id,
            "Resource"
        );

        # drop the resource from each folder it belongs to
        foreach ($Folders as $Folder) {
            # mixed item type folder
            if ($Folder->ContainsItem($this->Id, "Resource")) {
                $Folder->RemoveItem($this->Id, "Resource");
            # single item type folder
            } else {
                $Folder->RemoveItem($this->Id);
            }
        }

        # delete any resource comments
        $DB->query("DELETE FROM Messages WHERE ParentId = ".$this->Id);

        # delete permissions cache entries
        $DB->query("DELETE FROM UserPermsCache WHERE RecordId=".$this->Id);

        # delete any SchemaIdCache entries
        if (isset(self::$SchemaIdCache[$this->Id])) {
            unset(self::$SchemaIdCache[$this->Id]);
        }
    }

    /**
     * Get instance of record with appropriate class.
     * @param int $RecordId ID of record to load.
     * @return mixed Instance of appropriate class.
     */
    public static function getRecord(int $RecordId)
    {
        $FQClassName = (new self($RecordId))->getSchema()->getItemClassName();
        return new $FQClassName($RecordId);
    }

    /**
     * Update the auto-updated fields as necessary.
     * @param string $UpdateType Type of update being performed, one of
     *     the MetadataField::UPDATEMETHOD_ constants.
     * @param User $User User responsible for the update.
     * @return void
     */
    public function updateAutoupdateFields(string $UpdateType, User $User)
    {
        # update timestamp fields
        $TimestampFields = $this->getSchema()->getFields(
            MetadataSchema::MDFTYPE_TIMESTAMP
        );
        foreach ($TimestampFields as $Field) {
            if ($Field->UpdateMethod() == $UpdateType) {
                $this->set($Field, "now");
            }
        }

        # if no user logged in, nothing to do
        if ($User->isAnonymous()) {
            return;
        }

        # update user fields
        $UserFields = $this->getSchema()->getFields(
            MetadataSchema::MDFTYPE_USER
        );
        foreach ($UserFields as $Field) {
            if ($Field->UpdateMethod() == $UpdateType) {
                $this->set($Field, $User);
            }
        }
    }

    /**
     * Retrieve ID of schema for resource.
     * @return int Schema ID.
     */
    public function getSchemaId(): int
    {
        return $this->SchemaId;
    }

    /**
     * Retrieve ID of schema for resource.
     * @return int Schema ID.
     * @deprecated
     * @see Record::getSchemaId()
     */
    public function schemaId(): int
    {
        # log a warning message to alert the usage of a deprecated function
        (ApplicationFramework::getInstance())->logMessage(
            ApplicationFramework::LOGLVL_WARNING,
            "Call to deprecated function ".__FUNCTION__. " at ".StdLib::getMyCaller()
        );

        return $this->getSchemaId();
    }

    /**
     * Get MetadataSchema for resource.
     * @return MetadataSchema Our schema.
     */
    public function getSchema(): MetadataSchema
    {
        return self::$Schemas[$this->SchemaId];
    }

    /**
     * Get MetadataSchema for resource.
     * @return MetadataSchema Our schema.
     * @deprecated
     * @see Record::getSchema()
     */
    public function schema(): MetadataSchema
    {
        # log a warning message to alert the usage of a deprecated function
        (ApplicationFramework::getInstance())->logMessage(
            ApplicationFramework::LOGLVL_WARNING,
            "Call to deprecated function ".__FUNCTION__. " at ".StdLib::getMyCaller()
        );

        return $this->getSchema();
    }

    /**
     * Get/set whether resource is a temporary record. Once permanent, records
     *     cannot be made temporary again.
     * @param bool|null $NewSetting FALSE to toggle a record to permanent
     *     status. (OPTIONAL)
     * @return bool TRUE if resource is temporary record, or FALSE otherwise.
     */
    public function isTempRecord(?bool $NewSetting = null): bool
    {
        $OldSetting = ($this->id() < 0);

        # if setting has not changed, report current one back to caller
        if (is_null($NewSetting) || $NewSetting === $OldSetting) {
            return $OldSetting;
        }

        # disallow perm -> temp changes
        if ($OldSetting === false && $NewSetting === true) {
            throw new Exception(
                "Permanent records cannot be made temporary again."
            );
        }

        $DB = $this->DB;
        $Factory = new RecordFactory($this->SchemaId);

        # if no calls to set() have yet been made such that we don't know if this record
        # was initially visible, compute visibility now
        if (!isset(self::$WasPublic[$this->id()])) {
            self::$WasPublic[$this->id()] = $this->userCanView(User::getAnonymousUser());
        }

        # lock DB tables to prevent next ID from being grabbed
        $DB->query("LOCK TABLES `".$this->ItemTableName."` WRITE");

        # get next resource ID as appropriate
        $OldRecordId = $this->Id;
        $this->Id = $Factory->getNextItemId();

        # change resource ID
        $DB->query(
            "UPDATE `".$this->ItemTableName."` SET "
                ."`".$this->ItemIdColumnName."` = ".$this->Id
                ." WHERE `".$this->ItemIdColumnName."` = ".$OldRecordId
        );

        # update parameters for value update methods
        $this->DB->SetValueUpdateParameters(
            $this->ItemTableName,
            "`".$this->ItemIdColumnName."` = ".$this->Id
        );

        # release DB tables
        $DB->query("UNLOCK TABLES");

        # clear internal caches
        unset($this->ClassificationCache);
        unset($this->ControlledNameCache);
        unset($this->ControlledNameVariantCache);

        # change associations
        $DB->query("UPDATE RecordClassInts SET RecordId = ".
                   $this->Id." WHERE RecordId = ".$OldRecordId);
        $DB->query("UPDATE RecordNameInts SET RecordId = ".
                   $this->Id." WHERE RecordId = ".$OldRecordId);
        $DB->query("UPDATE Files SET RecordId = ".
                   $this->Id." WHERE RecordId = ".$OldRecordId);
        $DB->query("UPDATE ReferenceInts SET SrcRecordId = ".
                   $this->Id." WHERE SrcRecordId = ".$OldRecordId);
        $DB->query("UPDATE Images SET ItemId = ".
                   $this->Id." WHERE ItemId = ".$OldRecordId);
        $DB->query("UPDATE RecordUserInts SET RecordId = ".
                   $this->Id." WHERE RecordId = ".$OldRecordId);
        $DB->query("UPDATE RecordRatings SET RecordId = ".
                   $this->Id." WHERE RecordId = ".$OldRecordId);

        # update stored visibility info
        self::$WasPublic[$this->id()] = self::$WasPublic[$OldRecordId];
        unset(self::$WasPublic[$OldRecordId]);

        # and run housekeeping
        $User = User::getCurrentUser();
        $this->doHousekeepingAfterChangeToRecord(
            $User->id(),
            self::$WasPublic[$this->id()],
            true,
            true
        );

        (ApplicationFramework::getInstance())->signalEvent(
            "EVENT_RESOURCE_ADD",
            ["Resource" => $this]
        );
        $this->notifyObservers(self::EVENT_ADD);

        return $NewSetting;
    }

    # --- Generic Attribute Retrieval Methods -------------------------------

    /**
     * Retrieve view page URL for this resource.
     * @return string view page url
     */
    public function getViewPageUrl(): string
    {
        # put our Id into the ViewPage from our schema
        $Url = str_replace(
            "\$ID",
            (string)$this->id(),
            $this->getSchema()->getViewPage()
        );

        # return clean url, if one is available
        return (ApplicationFramework::getInstance())->getCleanRelativeUrlForPath($Url);
    }

    /**
     * Retrieve edit page URL for this resource.
     * @return string edit page url
     */
    public function getEditPageUrl(): string
    {
        # put our Id into the EditPage from our schema
        $Url = str_replace(
            "\$ID",
            (string)$this->id(),
            $this->getSchema()->getEditPage()
        );

        # return clean url, if one is available
        return (ApplicationFramework::getInstance())->getCleanRelativeUrlForPath($Url);
    }

    /**
     * Retrieve value using field name, ID, or field object.
     * @param int|string|MetadataField $Field Field ID or full name of field
     *      or MetadataField object.
     * @param bool $ReturnObject For field types that can return multiple values, if
     *      TRUE, returns array of objects, else returns array of values.
     *      Defaults to FALSE.
     * @param bool $IncludeVariants If TRUE, includes variants in return value.
     *      Only applicable for ControlledName fields.
     * @return mixed Requested object(s) or value(s). Returns empty array
     *      (for field types that allow multiple values) or NULL (for field
     *      types that do not allow multiple values) if no values found. Returns
     *      NULL if field does not exist or was otherwise invalid.
     */
    public function get($Field, bool $ReturnObject = false, bool $IncludeVariants = false)
    {
        $Field = $this->normalizeFieldArgument($Field);

        if ($Field->schemaId() != $this->getSchemaId()) {
            throw new Exception("Attempt to get a value for a field"
                    ." from a different schema."
                    ." (Field: ".$Field->name()." [".$Field->id()
                    ."], Field Schema: ".$Field->schemaId()
                    .", Resource Schema: ".$this->getSchemaId()
                    .")");
        }

        # grab database field name
        $DBFieldName = $Field->dBFieldName();

        # format return value based on field type
        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_EMAIL:
                $ReturnValue = $this->DB->updateValue($DBFieldName);
                break;

            case MetadataSchema::MDFTYPE_TIMESTAMP:
                $ReturnValue = $this->DB->updateValue($DBFieldName);

                if ($ReturnValue == "0000-00-00 00:00:00") {
                    $ReturnValue = null;
                }
                break;

            case MetadataSchema::MDFTYPE_NUMBER:
                $ReturnValue = $this->DB->updateIntValue($DBFieldName);
                break;

            case MetadataSchema::MDFTYPE_FLAG:
                $ReturnValue = $this->DB->updateBoolValue($DBFieldName);
                break;

            case MetadataSchema::MDFTYPE_POINT:
                $XValue = $this->DB->updateFloatValue($DBFieldName."X");
                $YValue = $this->DB->updateFloatValue($DBFieldName."Y");
                if (($XValue === false) || ($YValue === false)) {
                    $ReturnValue = ["X" => null, "Y" => null];
                } else {
                    $ReturnValue = [ "X" => $XValue, "Y" => $YValue ];
                }
                break;

            case MetadataSchema::MDFTYPE_DATE:
                $Begin = $this->DB->updateValue($DBFieldName."Begin");
                $End = $this->DB->updateValue($DBFieldName."End");
                $Precision = $this->DB->updateValue($DBFieldName."Precision");

                if (strlen($Begin)) {
                    if (Date::isValidDate($Begin, $End) == false) {
                        (ApplicationFramework::getInstance())->logMessage(
                            ApplicationFramework::LOGLVL_WARNING,
                            "Invalid date (".$Begin." - ".$End.")"
                            ." in Field ".$Field->name()." (".$Field->id().")"
                            ." for Record ID ".$this->id()
                        );
                        return null;
                    }

                    $ReturnValue = new Date($Begin, $End, $Precision);
                    if (!$ReturnObject) {
                        $ReturnValue = $ReturnValue->formatted();
                    }
                } else {
                    $ReturnValue = null;
                }
                break;

            case MetadataSchema::MDFTYPE_TREE:
                # start with empty array
                $ReturnValue = [];

                # if classification cache has not been loaded
                if (!isset($this->ClassificationCache)) {
                    # load all classifications associated with this resource into cache
                    $this->ClassificationCache = [];
                    $this->DB->query(
                        "SELECT Classifications.ClassificationId,"
                                    ." Classifications.FieldId,ClassificationName"
                            ." FROM RecordClassInts, Classifications"
                            ." WHERE RecordClassInts.RecordId = ".$this->Id
                            ." AND RecordClassInts.ClassificationId"
                        ." = Classifications.ClassificationId"
                    );
                    while ($Record = $this->DB->fetchRow()) {
                        $ClassId = $Record["ClassificationId"];
                        $this->ClassificationCache[$ClassId]["Name"]
                                = $Record["ClassificationName"];
                        $this->ClassificationCache[$ClassId]["FieldId"]
                                = $Record["FieldId"];
                    }
                }
                # for each entry in classification cache
                foreach ($this->ClassificationCache as $ClassificationId => $ClassificationInfo) {
                    # if classification ID matches field we are looking for
                    if ($ClassificationInfo["FieldId"] == $Field->id()) {
                        # add field to result
                        if ($ReturnObject) {
                            $ReturnValue[$ClassificationId] =
                                    new Classification($ClassificationId);
                        } else {
                            $ReturnValue[$ClassificationId] = $ClassificationInfo["Name"];
                        }
                    }
                }
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                # start with empty array
                $ReturnValue = [];

                # if controlled name cache has not been loaded
                if (!isset($this->ControlledNameCache)) {
                    # load all controlled names associated with this resource into cache
                    $this->ControlledNameCache = [];
                    $this->DB->query(
                        "SELECT ControlledNames.ControlledNameId,"
                                    ." ControlledNames.FieldId,ControlledName"
                            ." FROM RecordNameInts, ControlledNames"
                            ." WHERE RecordNameInts.RecordId = ".$this->Id
                            ." AND RecordNameInts.ControlledNameId"
                                    ." = ControlledNames.ControlledNameId"
                        ." ORDER BY ControlledNames.ControlledName ASC"
                    );
                    while ($Record = $this->DB->fetchRow()) {
                        $CNameId = $Record["ControlledNameId"];
                        $this->ControlledNameCache[$CNameId]["Name"]
                                = $Record["ControlledName"];
                        $this->ControlledNameCache[$CNameId]["FieldId"]
                                = $Record["FieldId"];
                    }
                }

                # if variant names requested and variant name cache has not been loaded
                if ($IncludeVariants && !isset($this->ControlledNameVariantCache)) {
                    # load all controlled names associated with this resource into cache
                    $this->ControlledNameVariantCache = [];
                    $this->DB->query("SELECT ControlledNames.ControlledNameId,"
                                    ." ControlledNames.FieldId,"
                                    ." ControlledName, VariantName"
                            ." FROM RecordNameInts, ControlledNames, VariantNames"
                            ." WHERE RecordNameInts.RecordId = ".$this->Id
                            ." AND RecordNameInts.ControlledNameId"
                                    ." = ControlledNames.ControlledNameId"
                            ." AND VariantNames.ControlledNameId"
                                    ." = ControlledNames.ControlledNameId");
                    while ($Record = $this->DB->fetchRow()) {
                        $this->ControlledNameVariantCache[$Record["ControlledNameId"]][]
                                = $Record["VariantName"];
                    }
                }

                # for each entry in controlled name cache
                foreach ($this->ControlledNameCache as $CNameId => $ControlledNameInfo) {
                    # if controlled name type matches field we are looking for
                    if ($ControlledNameInfo["FieldId"] == $Field->id()) {
                        # if objects requested
                        if ($ReturnObject) {
                            $ReturnValue[$CNameId] =
                                    new ControlledName($CNameId);
                        } else {
                            # if variant names requested
                            if ($IncludeVariants) {
                                # add field to result
                                $ReturnValue[] = $ControlledNameInfo["Name"];

                                # add any variant names to result
                                if (isset($this->ControlledNameVariantCache[$CNameId])) {
                                    $ReturnValue = array_merge(
                                        $ReturnValue,
                                        $this->ControlledNameVariantCache[$CNameId]
                                    );
                                }
                            } else {
                                # add field with index to result
                                $ReturnValue[$CNameId] =
                                        $ControlledNameInfo["Name"];
                            }
                        }
                    }
                }
                break;

            case MetadataSchema::MDFTYPE_USER:
                # start out assuming no associated users
                $ReturnValue = [];

                # query the database to get the associated userids
                $this->DB->query(
                    "SELECT UserId FROM RecordUserInts WHERE ".
                    "RecordId=".intval($this->Id).
                    " AND FieldId=".intval($Field->id())
                    ." AND UserId IN (SELECT UserId FROM APUsers)"
                );
                $UserIds = $this->DB->fetchColumn("UserId");

                # convert each userid to either a name or a User object
                foreach ($UserIds as $UserId) {
                    $User = new User(intval($UserId));
                    if ($ReturnObject) {
                        $ReturnValue[$UserId] = $User;
                    } else {
                        $ReturnValue[$UserId] = $User->get("UserName");
                    }
                }
                break;

            case MetadataSchema::MDFTYPE_IMAGE:
                $Factory = new ImageFactory();
                $ImageIds = $Factory->getImageIdsForRecord(
                    $this->id(),
                    $Field->id()
                );

                # start off assuming no images
                $ReturnValue = [];

                foreach ($ImageIds as $ImageId) {
                    if ($ReturnObject) {
                        $ReturnValue[$ImageId] = new Image($ImageId);
                    } else {
                        $ReturnValue[] = $ImageId;
                    }
                }
                break;

            case MetadataSchema::MDFTYPE_FILE:
                # retrieve files using factory
                $Factory = new FileFactory($Field->id());
                $ReturnValue = $Factory->getFilesForResource(
                    $this->Id,
                    $ReturnObject
                );
                break;

            case MetadataSchema::MDFTYPE_REFERENCE:
                # query for resource references
                $this->DB->query("
                    SELECT * FROM ReferenceInts
                    WHERE FieldId = ".$Field->id()."
                    AND SrcRecordId = ".$this->id());

                $ReturnValue = [];

                # return each reference as a Resource object
                if ($ReturnObject) {
                    $FoundErrors = false;

                    while (false !== ($Record = $this->DB->fetchRow())) {
                        $ReferenceId = $Record["DstRecordId"];
                        $Reference = new Record($ReferenceId);
                        $ReturnValue[$ReferenceId] = $Reference;
                    }
                # return each reference as a resource ID
                } else {
                    while (false !== ($Record = $this->DB->fetchRow())) {
                        $ReferenceId = $Record["DstRecordId"];
                        $ReturnValue[$ReferenceId] = $ReferenceId;
                    }
                }
                break;

            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                $SPSData = $this->DB->updateValue($DBFieldName);
                $ReturnValue = new SearchParameterSet((strlen($SPSData) ? $SPSData : null));
                break;

            default:
                # ERROR OUT
                throw new Exception("Attempt to retrieve "
                        ."unknown field type (".$Field->type().")");
        }

        # (temporarily compensate for this method returning NULL for no value
        #       instead of FALSE)
        if (($ReturnValue === false) && ($Field->type() != MetadataSchema::MDFTYPE_FLAG)) {
            $ReturnValue = null;
        }

        # return formatted value to caller
        return $ReturnValue;
    }

    /**
     * Retrieve value using field name or field object, signaling
     * EVENT_FIELD_DISPLAY_FILTER to allow other code to possibly modify the
     * value before it's returned.Note that the default for the $ReturnObject
     * parameter is TRUE, which is the opposite of the default for the same
     * parameter to all other Resource::Get*() methods.
     * @param string|int|MetadataField $Field Metadata field name, ID, or field object.
     * @param bool $ReturnObject For field types that can return multiple values, if
     *      TRUE, returns array of objects, else returns array of values.
     *      (OPTIONAL, defaults to TRUE)
     * @param bool $IncludeVariants If TRUE, includes variants in return value.
     *      Only applicable for ControlledName fields.(OPTIONAL, defaults
     *      to FALSE)
     * @return mixed Requested object(s) or value(s).Returns empty array
     *      (for field types that allow multiple values) or NULL (for field types
     *      that do not allow multiple values) if no values found.Returns NULL
     *      if field does not exist or was otherwise invalid.
     * @see Record::get()
     */
    public function getForDisplay(
        $Field,
        bool $ReturnObject = true,
        bool $IncludeVariants = false
    ) {
        $Field = $this->normalizeFieldArgument($Field);

        # retrieve value
        $Value = $this->get($Field, $ReturnObject, $IncludeVariants);

        # signal event to allowed hooked code to modify value
        $SignalResult = (ApplicationFramework::getInstance())->signalEvent(
            "EVENT_FIELD_DISPLAY_FILTER",
            [
                "Field" => $Field,
                "Resource" => $this,
                "Value" => $Value
            ]
        );

        # return possibly modified value to caller
        return $SignalResult["Value"];
    }

    /**
     * Retrieve all resource values as an array.
     * @param bool $IncludeDisabledFields Include values for disabled fields.
     *       (OPTIONAL, defaults to FALSE)
     * @param bool $ReturnObjects If TRUE, an object is returned for field types
     *       where appropriate, in the same fashion as Resource::Get()
     *       (OPTIONAL, defaults to TRUE)
     * @return array Array of values with field names for array indices.
     *       Qualifiers (where available) are returned with an index of the
     *       field name with " Qualifier" appended.
     * @see Record::get()
     */
    public function getAsArray(
        bool $IncludeDisabledFields = false,
        bool $ReturnObjects = true
    ): array {
        # retrieve field info
        $Fields = $this->getSchema()->getFields();

        # for each field
        foreach ($Fields as $Field) {
            # if field is enabled or caller requested disabled fields
            if ($Field->Enabled() || $IncludeDisabledFields) {
                # retrieve info and add it to the array
                $FieldStrings[$Field->Name()] = $this->get($Field, $ReturnObjects);

                # if field uses qualifiers
                if ($Field->UsesQualifiers()) {
                    # get qualifier attributes and add to the array
                    $FieldStrings[$Field->Name()." Qualifier"] =
                            $this->getQualifier($Field, $ReturnObjects);
                }
            }
        }

        # add in internal values
        $FieldStrings[$this->ItemIdColumnName] = $this->id();
        $FieldStrings["CumulativeRating"] = $this->cumulativeRating();

        # return array to caller
        return $FieldStrings;
    }

    /**
     * Retrieve value using standard (mapped) field name.
     * @param string $MappedName Standard field name.
     * @param bool $ReturnObject For field types that can return multiple values, if
     *       TRUE, returns array of objects, else returns array of values.
     *       Defaults to FALSE.
     * @param bool $IncludeVariants If TRUE, includes variants in return value.Only
     *       applicable for ControlledName fields.Defaults to FALSE.
     * @return mixed Requested object(s) or value(s), or NULL if no mapping
     *       found.Returns empty array (for field types that allow multiple values)
     *       or NULL (for field types that do not allow multiple values) if no
     *       values found.
     * @see Record::get()
     */
    public function getMapped(
        string $MappedName,
        bool $ReturnObject = false,
        bool $IncludeVariants = false
    ) {
        $FieldId = $this->getSchema()->stdNameToFieldMapping($MappedName);
        return $FieldId
                ? $this->get($FieldId, $ReturnObject, $IncludeVariants)
                : null;
    }

    /**
     * Retrieve qualifier by metadata field.
     * @param string|int|MetadataField $Field Metadata field name, ID, or field object.
     * @param bool $ReturnObject If TRUE, return Qualifier objects, else return
     *       qualifier IDs.  (OPTIONAL, defaults to TRUE)
     * @return array|null Array of qualifiers if field supports qualifiers,
     *       or NULL if field does not support qualifiers or field is invalid.
     */
    public function getQualifier($Field, bool $ReturnObject = true)
    {
        $Field = $this->normalizeFieldArgument($Field);

        # assume no qualifiers if not otherwise determined
        $ReturnValue = null;

        # if field uses qualifiers
        if ($Field->usesQualifiers()) {
            # retrieve qualifiers based on field type
            switch ($Field->type()) {
                case MetadataSchema::MDFTYPE_TREE:
                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                case MetadataSchema::MDFTYPE_OPTION:
                    # retrieve list of items
                    $Items = $this->get($Field);

                    # if field uses item-level qualifiers
                    if ($Field->hasItemLevelQualifiers()) {
                        # determine general item name in DB
                        $TableName = ($Field->type() == MetadataSchema::MDFTYPE_TREE)
                                ? "Classification" : "ControlledName";

                        # for each item
                        foreach ($Items as $ItemId => $ItemName) {
                            # look up qualifier for item
                            $QualId = $this->DB->queryValue(
                                "SELECT * FROM ".$TableName."s"
                                    ." WHERE ".$TableName."Id = ".$ItemId,
                                "QualifierId"
                            );


                            if ($QualId > 0) {
                                # if object was requested by caller
                                if ($ReturnObject) {
                                    # load qualifier and add to return value array
                                    $ReturnValue[$ItemId] = new Qualifier($QualId);
                                } else {
                                    # add qualifier ID to return value array
                                    $ReturnValue[$ItemId] = $QualId;
                                }
                            } else {
                                # add NULL to return value array for this item
                                $ReturnValue[$ItemId] = null;
                            }
                        }
                    } else {
                        # for each item
                        foreach ($Items as $ItemId => $ItemName) {
                            # if object was requested by caller
                            if ($ReturnObject && $Field->defaultQualifier() !== false) {
                                # load default qualifier and add to return value array
                                $ReturnValue[$ItemId] = new Qualifier(
                                    $Field->defaultQualifier()
                                );
                            } else {
                                # add default qualifier ID to return value array
                                $ReturnValue[$ItemId] = $Field->defaultQualifier();
                            }
                        }
                    }
                    break;

                default:
                    # if field uses item-level qualifiers
                    if ($Field->hasItemLevelQualifiers()) {
                        # if qualifier available
                        $QFieldValue = $this->DB->updateIntValue(
                            $Field->dBFieldName()."Qualifier"
                        );
                        if ($QFieldValue !== false) {
                            # if object was requested by caller
                            if ($ReturnObject) {
                                # return qualifier for field
                                $ReturnValue = new Qualifier($QFieldValue);
                            } else {
                                # return qualifier ID for field
                                $ReturnValue = $QFieldValue;
                            }
                        }
                    } else {
                        # if default qualifier available
                        if ($Field->defaultQualifier() > 0) {
                            # if object was requested by caller
                            if ($ReturnObject) {
                                # return default qualifier
                                $ReturnValue = new Qualifier($Field->defaultQualifier());
                            } else {
                                # return default qualifier ID
                                $ReturnValue = $Field->defaultQualifier();
                            }
                        }
                    }
                    break;
            }
        }

        # return qualifier object or ID (or array of same) to caller
        return $ReturnValue;
    }

    /**
     * Determine if the value for a field is set.
     * @param string|int|MetadataField $Field Metadata field name, ID, or field object.
     * @param bool $IgnorePadding Optional flag for ignoring whitespace padding
     *      for text, paragraph, number, and URL fields.
     * @return bool Returns TRUE if the value is set or FALSE otherwise.
     */
    public function fieldIsSet($Field, bool $IgnorePadding = false): bool
    {
        $Field = $this->normalizeFieldArgument($Field);

        # get the value
        $Value = $this->get($Field);

        # checks depend on the field type
        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_EMAIL:
                return !is_null($Value)
                    && strlen($Value)
                    && (!$IgnorePadding || strlen(trim($Value)));

            case MetadataSchema::MDFTYPE_FLAG:
                $DBFieldName = $Field->dBFieldName();
                return ($this->DB->updateValue($DBFieldName) === false)
                        ? false : true;

            case MetadataSchema::MDFTYPE_POINT:
                return !is_null($Value["X"])
                    && !is_null($Value["Y"])
                    && strlen(trim($Value["X"]))
                    && strlen(trim($Value["Y"]));

            case MetadataSchema::MDFTYPE_DATE:
                return !is_null($Value)
                    && strlen(trim($Value))
                    && $Value != "0000-00-00";

            case MetadataSchema::MDFTYPE_TIMESTAMP:
                return !is_null($Value)
                    && strlen(trim($Value))
                    && $Value != "0000-00-00 00:00:00";

            case MetadataSchema::MDFTYPE_TREE:
            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
            case MetadataSchema::MDFTYPE_FILE:
            case MetadataSchema::MDFTYPE_IMAGE:
            case MetadataSchema::MDFTYPE_REFERENCE:
            case MetadataSchema::MDFTYPE_USER:
                return count($Value) > 0 ? true : false;

            default:
                return false;
        }
    }

    /**
     * Get persistent URLs for images (i.e.URLs that reference the image by
     *   Record Id, Field Id, and Image Index instead of using an Image Id;
     *   these URLs do not break when a field is updated).
     * @param int|string|MetadataField $Field Field ID or full name of field
     *      or MetadataField object.
     * @param string $ImageSize Desired size from available size names defined
     *      for user interface (e.g., 'preview').
     * @return array Persistent image URLs, keyed by ImageId
     */
    public function getPersistentImageUrls($Field, string $ImageSize)
    {
        # get our target field and extract its values
        $Field = $this->normalizeFieldArgument($Field);
        $ImageIds = $this->get($Field);

        # iterate over our images getting URLs for each
        $Result = [];
        foreach ($ImageIds as $Index => $ImageId) {
            $Result[$ImageId] = $this->getPersistentUrlForImage(
                $Field,
                $Index,
                $ImageSize
            );
        }

        return $Result;
    }

    /**
     * Get legacy format persistent URLs for images (i.e.URLs that reference
     *   the image by Record Id, Field Id, and Image Index instead of using an
     *   Image Id; these URLs do not break when a field is updated).
     * @param int|string|MetadataField $Field Field ID or full name of field
     *      or MetadataField object.
     * @param int $ImageSize Desired size as an Metavus\Image::SIZE_ constant.
     * @return array Persistent image URLs, keyed by ImageId
     */
    public function getLegacyPersistentImageUrls($Field, int $ImageSize = Image::SIZE_FULL)
    {
        # get our target field and extract its values
        $Field = $this->normalizeFieldArgument($Field);
        $ImageIds = $this->get($Field);

        # iterate over our images getting URLs for each
        $Result = [];
        foreach ($ImageIds as $Index => $ImageId) {
            $Result[$ImageId] = $this->getLegacyPersistentUrlForImage(
                $Field,
                $Index,
                $ImageSize
            );
        }

        return $Result;
    }

    /**
     * Get MD5 checksum calculated from values of specified fields.
     * @param array $Fields Field IDs or names or MetadataField instances.
     * @return string Checksum value (32-character hexadecimal number).
     */
    public function getChecksumForFields(array $Fields): string
    {
        $FieldValueString = "";
        foreach ($Fields as $Field) {
            $Value = $this->get($Field);
            $FieldValueString .= serialize($Value);
        }
        return hash("md5", $FieldValueString);
    }

    # --- Generic Attribute Setting Methods ---------------------------------

    /**
     * Set value using field name, ID, or field object.
     * @param string|int|MetadataField $Field Metadata field name, ID, or field object.
     * @param mixed $NewValue New value for field.
     * @param bool $Reset When TRUE Controlled Names, Classifications,
     *       and Options will be set to contain *ONLY* the contents of
     *       NewValue, rather than appending $NewValue to the current value.
     * @return void
     * @throws Exception When attempting to set a value for a field that is
     *       part of a different schema than the resource.
     * @throws InvalidArgumentException When attempting to set a controlled
     *       name with an invalid ID.
     */
    public function set($Field, $NewValue, bool $Reset = false)
    {
        $Field = $this->normalizeFieldArgument($Field);

        if ($Field->schemaId() != $this->getSchemaId()) {
            throw new Exception("Attempt to set a value for a field "
                    ."from a different schema.");
        }

        # if this is the first call to set(), determine if record
        # was initially publicly visible
        # (done here rather than in __construct() because 1) we don't need the
        # check when we aren't setting anything and 2) getAnonymousUser()
        # calls Record::__construct(), resulting in infinite recursion)
        if (!isset(self::$WasPublic[$this->id()])) {
            self::$WasPublic[$this->id()] = $this->userCanView(User::getAnonymousUser());
        }

        $DBFieldName = $Field->dBFieldName();
        $ValueWasChanged = false;

        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_EMAIL:
                $CurrentValue = $this->DB->updateValue($DBFieldName);
                if ($NewValue !== $CurrentValue) {
                    $this->DB->updateValue($DBFieldName, $NewValue);
                    $ValueWasChanged = true;
                    $NotificationType = ($NewValue === false)
                            ? MetadataField::EVENT_CLEAR : MetadataField::EVENT_SET;
                    $Field->notifyObservers($NotificationType, $this->Id, $NewValue);
                }
                break;

            case MetadataSchema::MDFTYPE_NUMBER:
                $CurrentValue = $this->DB->updateIntValue($DBFieldName);
                $NewValue = is_numeric($NewValue) ? (int)$NewValue : $NewValue;
                if ($NewValue !== $CurrentValue) {
                    $this->DB->updateIntValue($DBFieldName, $NewValue);
                    $ValueWasChanged = true;
                    $NotificationType = ($NewValue === false)
                            ? MetadataField::EVENT_CLEAR : MetadataField::EVENT_SET;
                    $Field->notifyObservers($NotificationType, $this->Id, $NewValue);
                }
                break;

            case MetadataSchema::MDFTYPE_POINT:
                $ValueWasChanged = $this->setPointField($Field, $NewValue);
                break;

            case MetadataSchema::MDFTYPE_FLAG:
                $CurrentValue = $this->DB->updateBoolValue($DBFieldName);
                if ($NewValue !== $CurrentValue) {
                    $this->DB->updateBoolValue($DBFieldName, $NewValue);
                    $ValueWasChanged = true;
                    $Field->notifyObservers(
                        MetadataField::EVENT_SET,
                        $this->Id,
                        $NewValue
                    );
                }
                break;

            case MetadataSchema::MDFTYPE_USER:
                $ValueWasChanged = $this->setUserField($Field, $NewValue, $Reset);
                break;

            case MetadataSchema::MDFTYPE_DATE:
                $ValueWasChanged = $this->setDateField($Field, $NewValue);
                break;

            case MetadataSchema::MDFTYPE_TIMESTAMP:
                $CurrentValue = $this->DB->updateDateValue($DBFieldName);
                if ($NewValue !== false) {
                    $Timestamp = strtotime($NewValue);
                    if ($Timestamp === false) {
                        throw new InvalidArgumentException(
                            "Unable to parse incoming date (".$NewValue.")."
                        );
                    }
                    $NewValue = date(StdLib::SQL_DATE_FORMAT, $Timestamp);
                }
                if ($NewValue !== $CurrentValue) {
                    $this->DB->updateDateValue($DBFieldName, $NewValue);
                    $ValueWasChanged = true;
                    $NotificationType = ($NewValue === false)
                            ? MetadataField::EVENT_CLEAR : MetadataField::EVENT_SET;
                    $Field->notifyObservers($NotificationType, $this->Id, $NewValue);
                }
                break;

            case MetadataSchema::MDFTYPE_TREE:
                $ValueWasChanged = $this->setTreeField($Field, $NewValue, $Reset);
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
                $ValueWasChanged = $this->setControlledNameField($Field, $NewValue, $Reset);
                break;

            case MetadataSchema::MDFTYPE_IMAGE:
                $ValueWasChanged = $this->setImageField($Field, $NewValue, $Reset);
                break;

            case MetadataSchema::MDFTYPE_FILE:
                $ValueWasChanged = $this->setFileField($Field, $NewValue, $Reset);
                break;

            case MetadataSchema::MDFTYPE_REFERENCE:
                $ValueWasChanged = $this->setReferenceField($Field, $NewValue, $Reset);
                break;

            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                $CurrentValueData = $this->DB->updateValue($DBFieldName);
                if ($NewValue->data() !== $CurrentValueData) {
                    $this->DB->updateValue($DBFieldName, $NewValue->data());
                    $ValueWasChanged = true;
                    $Field->notifyObservers(
                        MetadataField::EVENT_SET,
                        $this->Id,
                        $NewValue
                    );
                }
                break;

            default:
                # ERROR OUT
                throw new Exception("Attempt to set unknown resource field type");
        }

        # if no changes, nothing else to do
        if (!$ValueWasChanged) {
            return;
        }

        # if temp record, nothing else to do
        if ($this->isTempRecord()) {
            return;
        }

        $this->doHousekeepingAfterChangeToValue($Field);
    }

    /**
     * Set qualifier using field object.
     * @param string|int|MetadataField $Field Metadata field name, ID, or field object.
     * @param mixed $NewValue Qualifier object or ID.
     * @return void
     */
    public function setQualifier($Field, $NewValue)
    {
        $Field = $this->normalizeFieldArgument($Field);

        # if field uses qualifiers and uses item-level qualifiers
        if ($Field->usesQualifiers() && $Field->hasItemLevelQualifiers()) {
            # if qualifier object passed in
            if ($NewValue instanceof Qualifier) {
                # grab qualifier ID from object
                $QualifierId = $NewValue->id();
            } else {
                # assume value passed in is qualifier ID
                $QualifierId = $NewValue;
            }

            # update qualifier value in database
            $QFieldName = $Field->dBFieldName()."Qualifier";
            $this->DB->updateIntValue($QFieldName, $QualifierId);
        }
    }

    /**
     * Clear field value.For Flag fields, this means setting them to
     * their default value.
     * @param string|int|MetadataField $Field Metadata field name, ID, or field object.
     * @param mixed $ValueToClear Specific value to clear (for fields that
     *       support multiple values).(OPTIONAL)
     * @return void
     */
    public function clear($Field, $ValueToClear = null)
    {
        $Field = $this->normalizeFieldArgument($Field);
        $UpdateModTime = false;

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # store value in DB based on field type
        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_EMAIL:
            case MetadataSchema::MDFTYPE_POINT:
            case MetadataSchema::MDFTYPE_DATE:
                $this->set($Field, false);
                break;

            case MetadataSchema::MDFTYPE_FLAG:
                $DBFieldName = $Field->dBFieldName();
                $this->DB->updateValue($DBFieldName, false);
                break;

            case MetadataSchema::MDFTYPE_TREE:
            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
            case MetadataSchema::MDFTYPE_USER:
                # if value to clear supplied
                if ($ValueToClear !== null) {
                    $Value = $this->get($Field);

                    if (!is_array($ValueToClear)) {
                        $ValueToClear = [$ValueToClear => "Dummy"];
                    }

                    # for each element of array
                    foreach ($ValueToClear as $Id => $Dummy) {
                        if (array_key_exists($Id, $Value)) {
                            unset($Value[$Id]);
                        }
                    }

                    $this->set($Field, $Value, true);
                } else {
                    $this->set($Field, [], true);
                }

                break;

            case MetadataSchema::MDFTYPE_FILE:
                # if value to clear supplied
                if ($ValueToClear !== null) {
                    # convert value to array if necessary
                    $Files = $ValueToClear;
                    if (!is_array($Files)) {
                        $Files = [$Files];
                    }

                    # convert values to objects if necessary
                    foreach ($Files as $Index => $File) {
                        if (!is_object($File)) {
                            $Files[$Index] = new File($File);
                        }
                    }
                } else {
                    # use all files associated with resource
                    $Files = $this->get($Field, true);
                }

                foreach ($Files as $File) {
                    # signal event to indicate file deletion
                    (ApplicationFramework::getInstance())->signalEvent(
                        "EVENT_RESOURCE_FILE_DELETE",
                        [
                            "Field" => $Field,
                            "Resource" => $this,
                            "File" => $File,
                        ]
                    );

                    # delete files
                    $File->destroy();
                }
                $Field->notifyObservers(MetadataField::EVENT_REMOVE, $this->Id, $Files);
                break;

            case MetadataSchema::MDFTYPE_IMAGE:
            case MetadataSchema::MDFTYPE_REFERENCE:
                if ($ValueToClear !== null) {
                    $OldValue = $this->get($Field);
                    $ValueToClear = $this->normalizeValueToItemIds(
                        $ValueToClear,
                        $Field
                    );
                    $NewValue = array_diff($OldValue, $ValueToClear);
                } else {
                    $NewValue = [];
                }

                $UpdateModTime = ($Field->type() == MetadataSchema::MDFTYPE_IMAGE) ?
                    $this->setImageField($Field, $NewValue, true) :
                    $this->setReferenceField($Field, $NewValue, true);
                break;

            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                $this->DB->updateValue($Field->dBFieldName(), false);
                break;

            default:
                throw new Exception(
                    "Attempt to clear unknown resource field type"
                );
        }

        if ($UpdateModTime && !$this->isTempRecord()) {
            # update modification timestamps
            $UserId = $User->isLoggedIn() ? $User->get("UserId") : -1;
            $this->DB->query("DELETE FROM RecordFieldTimestamps "
                       ."WHERE RecordId=".$this->Id." AND "
                       ."FieldId=".$Field->id());
            $this->DB->query("INSERT INTO RecordFieldTimestamps "
                       ."(RecordId,FieldId,ModifiedBy,Timestamp) VALUES ("
                       .$this->Id.",".$Field->id().","
                       .$UserId.",NOW())");
        }
    }

    /**
     * Apply a list of changes to a Record and perform automatic field updates
     *   if the record was modified.
     * @param array $ChangesToApply List of changes where each element is an
     *   array having keys FieldId, Op, and Val.
     *   FieldId is an integer field identifier,
     *   Op is a  CHANGE_xx constant describing the change to be done,
     *   and Val is a string giving a updated value.
     *   When Op is CHANGE_FIND_REPLACE, there will also be a Val2 key giving the
     *   replacement text.
     * @param User $User User for permissions checks (OPTIONAL)
     *   If specified, changes will only be made for fields that are 1) marked editable
     *     and 2) that the user has permission to edit.Other fields will be skipped.
     *   If left unspecified, all provided changes will be made.
     * @return bool TRUE when resource was changed, FALSE otherwise.
     */
    public function applyListOfChanges(array $ChangesToApply, ?User $User = null): bool
    {
        $RecordWasChanged = false;

        # iterate over the changes we were given
        foreach ($ChangesToApply as $Change) {
            $Field = MetadataField::getField($Change["FieldId"]);

            if ($User !== null) {
                # if this field is not editable, move to the next
                if (!$Field->editable()) {
                    continue;
                }
                # if we were given a user but they do not have permission to edit this field,
                # move to the next field
                if (!$this->userCanEditField($User, $Field)) {
                    continue;
                }
            }

            # handle legacy change constant
            if ($Change["Op"] == 6) {
                $Change["Op"] = self::CHANGE_SET;
            }

            # get the previous value
            $OldValue = $this->get($Field);

            # start off assuming we won't have a new value to set
            $NewValue = null;

            # determine what the new value should be or clear existing values
            # depending on our operation
            switch ($Change["Op"]) {
                case self::CHANGE_NOP:
                    break;

                case self::CHANGE_SET:
                    $NewValue = trim($Change["Val"]);
                    if (strlen($NewValue) == 0) {
                        throw new Exception(
                            "New value required for CHANGE_SET."
                        );
                    }
                    break;

                case self::CHANGE_FIND_REPLACE:
                    $NewValue = str_replace($Change["Val"], $Change["Val2"], $OldValue);
                    break;

                case self::CHANGE_APPEND:
                    $Sep = $Field->type() == MetadataSchema::MDFTYPE_PARAGRAPH ?
                        "\n" : " " ;
                    $NewValue = $OldValue.$Sep.$Change["Val"];
                    break;

                case self::CHANGE_PREPEND:
                    $Sep = $Field->type() == MetadataSchema::MDFTYPE_PARAGRAPH ?
                        "\n" : " " ;
                    $NewValue = $Change["Val"].$Sep.$OldValue;
                    break;

                case self::CHANGE_CLEAR:
                    $ValueToClear = trim($Change["Val"]);
                    if (strlen($ValueToClear) == 0) {
                        throw new Exception(
                            "A value to clear must be specified for CHANGE_CLEAR."
                        );
                    }

                    if (is_array($OldValue)) {
                        if (isset($OldValue[$ValueToClear]) &&
                            ($Field->optional() || count($OldValue) > 1)) {
                            $this->clear($Field, $ValueToClear);
                        }
                    } else {
                        if ($OldValue == $ValueToClear && $Field->optional()) {
                            $this->clear($Field);
                        }
                    }
                    break;

                case self::CHANGE_CLEARALL:
                    if ($Field->optional()) {
                        $this->clear($Field);
                    }
                    break;
            }

            # if this change involved setting a new value (rather than just
            # clearing values), update our record
            if ($NewValue !== null) {
                $this->modifyFieldValue(
                    $User,
                    $Field,
                    $NewValue
                );
            }

            # see if the updated value differed from the old one
            if ($OldValue != $this->get($Field)) {
                $RecordWasChanged = true;
            }
        }

        return $RecordWasChanged;
    }

    /**
     * Perform necessary actions after a record has been modified -- handles
     * clearing caches, automatic field updates, search/recommender updates,
     * and signaling EVENT_RESOURCE_MODIFY. (Method is public because it needs
     * to be run as a post-processing call or background task.)
     * @param int|null $UserId User that modified the record or null when no
     *    user was logged in.
     * @param bool $WasPublic TRUE when record was public when loaded on the
     *    page that set up this function call (passed as a parameter to work
     *    correctly when run in a background task)
     * @param bool $RunAutoUpdates TRUE to run updates triggered by record changes,
     *     FALSE to skip them.
     * @param bool $WasTemp TRUE if the record has just been toggled from temp
     *     to perm, FALSE otherwise (OPTIONAL, default FALSE)
     * @return void
     */
    public function doHousekeepingAfterChangeToRecord(
        ?int $UserId,
        bool $WasPublic,
        bool $RunAutoUpdates,
        bool $WasTemp = false
    ) : void {
        $AF = ApplicationFramework::getInstance();
        $User = is_null($UserId) ? User::getAnonymousUser() : new User($UserId);

        if ($RunAutoUpdates) {
            $this->updateAutoupdateFields(
                MetadataField::UPDATEMETHOD_ONRECORDCHANGE,
                $User
            );
        }

        # if this method is run in the background, the anonymous user will be
        # logged in
        # the ID of the user who performed the action that initated this call
        # should be the ID used to set last modified by ID
        $LoggedInUser = User::getCurrentUser();
        User::setCurrentUser($User);

        $AF->signalEvent(
            "EVENT_RESOURCE_MODIFY",
            ["Resource" => $this]
        );

        $this->notifyObservers(self::EVENT_SET);

        # restore the user who was logged in when the method was called
        User::setCurrentUser($LoggedInUser);

        # on resource modification, clear the UserPermsCache entry
        #   so that stale permissions checks are not cached
        $this->clearPermissionsCache();

        $IsPublic = $this->userCanView(User::getAnonymousUser());

        # recalculate resource counts for tree fields if necessary
        if ($WasTemp || $WasPublic != $IsPublic) {
            $this->recalculateCountsForClassifications();
        }

        if ($WasPublic == false && $IsPublic == true) {
            $this->updateAutoupdateFields(
                MetadataField::UPDATEMETHOD_ONRECORDRELEASE,
                $User
            );
        }

        $this->queueSearchAndRecommenderUpdate();
    }

    # --- Field-Specific or Type-Specific Attribute Retrieval Methods -------

    /**
     * Get 2D array of classifications associated with resource.
     * @return array Array where first index is classification (field) name,
     *       second index is classification ID.
     */
    public function classifications()
    {
        $DB = $this->DB;

        # start with empty array
        $Names = [];

        # for each controlled name
        $DB->query("SELECT ClassificationName, MetadataFields.FieldName, "
                ."RecordClassInts.ClassificationId FROM RecordClassInts, "
                ."Classifications, MetadataFields "
                ."WHERE RecordClassInts.RecordId = ".$this->Id." "
                ."AND RecordClassInts.ClassificationId = "
                        ."Classifications.ClassificationId "
                ."AND Classifications.FieldId = MetadataFields.FieldId ");
        while ($Record = $DB->fetchRow()) {
            # add name to array
            $Names[$Record["FieldName"]][$Record["ClassificationId"]] =
                    $Record["ClassificationName"];
        }

        # return array to caller
        return $Names;
    }


    # --- Ratings Methods ---------------------------------------------------

    /**
     * Get cumulative rating  (range is usually 0-100)
     * @return int Rating value.
     */
    public function cumulativeRating()
    {
        return $this->CumulativeRating;
    }

    /**
     * Return cumulative rating scaled to 1/10th.(Range is usually 0-10.)
     * @return int|null Scaled rating value or NULL if no cumulative rating available.
     */
    public function scaledCumulativeRating()
    {
        if ($this->CumulativeRating == null) {
            return null;
        } else {
            return intval(($this->CumulativeRating + 5) / 10);
        }
    }

    /**
     * Get current number of ratings for resource.
     * @return int Ratings count.
     */
    public function numberOfRatings(): int
    {
        # if number of ratings not already set
        if (!isset($this->NumberOfRatings)) {
            $this->fetchAndPossiblyUpdateCumulativeRating();
        }

        # return number of ratings to caller
        return $this->NumberOfRatings;
    }

    /**
     * Get/set rating by a specific user for resource.
     * @param int $NewRating New rating value.
     * @param int $UserId ID of user rating resource.
     * @return int|null Current rating value of resource by user or NULL
     *       if user has not rated resource.
     */
    public function rating(?int $NewRating = null, ?int $UserId = null)
    {
        $DB = $this->DB;

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # if user ID not supplied
        if ($UserId == null) {
            # if user is logged in
            if ($User->isLoggedIn()) {
                # use ID of current user
                $UserId = $User->get("UserId");
            } else {
                # return NULL to caller
                return null;
            }
        }

        # sanitize $NewRating
        if (!is_null($NewRating)) {
            $NewRating = intval($NewRating);
        }

        # if there is a rating for resource and user
        $DB->query("SELECT Rating FROM RecordRatings "
                ."WHERE UserId = ".intval($UserId)." AND RecordId = ".$this->Id);
        if ($Record = $DB->fetchRow()) {
            # if new rating was supplied
            if ($NewRating != null) {
                # update existing rating
                $DB->query("UPDATE RecordRatings "
                        ."SET Rating = ".$NewRating.", DateRated = NOW() "
                        ."WHERE UserId = ".intval($UserId)
                        ." AND RecordId = ".$this->Id);

                # update cumulative rating value
                $this->fetchAndPossiblyUpdateCumulativeRating();

                # return value is new rating
                $Rating = $NewRating;
            } else {
                # get rating value to return to caller
                $Rating = intval($Record["Rating"]);
            }
        } else {
            # if new rating was supplied
            if ($NewRating != null) {
                # add new rating
                $DB->query(
                    "INSERT INTO RecordRatings "
                    ."(RecordId, UserId, DateRated, Rating) "
                    ."VALUES ("
                    .$this->Id.", "
                    .intval($UserId).", "
                    ."NOW(), "
                    .$NewRating.")"
                );

                # update cumulative rating value
                $this->fetchAndPossiblyUpdateCumulativeRating();

                # return value is new rating
                $Rating = $NewRating;
            } else {
                # return value is NULL
                $Rating = null;
            }
        }

        # return rating value to caller
        return $Rating;
    }


    # --- Resource Comment Methods ------------------------------------------

    /**
     * Get comments for resource.
     * @return array Comments as Message objects.
     */
    public function comments(): array
    {
        # read in comments if not already loaded
        if (!isset($this->Comments)) {
            $this->Comments = [];
            $this->DB->query("SELECT MessageId FROM Messages "
                    ."WHERE ParentId = ".$this->Id
                    ." AND ParentType = 2 "
                    ."ORDER BY DatePosted DESC");
            while ($MessageId = $this->DB->fetchField("MessageId")) {
                $this->Comments[] = new Message($MessageId);
            }
        }

        # return array of comments to caller
        return $this->Comments;
    }

    /**
     * Get current number of comments for resource.
     * @return int Number of comments.
     */
    public function numberOfComments()
    {
        # obtain number of comments if not already set
        if (!isset($this->NumberOfComments)) {
            $this->NumberOfComments =
                    $this->DB->query(
                        "SELECT Count(*) AS NumberOfComments "
                            ."FROM Messages "
                            ."WHERE ParentId = ".$this->Id
                            ." AND ParentType = 2",
                        "NumberOfComments"
                    );
        }

        # return number of comments to caller
        return $this->NumberOfComments;
    }


    # --- Permission Methods -------------------------------------------------

    /**
     * Determine if the given user can view the resource, e.g., on the full
     * record page.The result of this method can be modified via the
     * EVENT_RESOURCE_VIEW_PERMISSION_CHECK event.
     * @param \ScoutLib\User $User User to check against.
     * @param bool $AllowHooksToModify TRUE if hook functions should be
     *     allowed to modify the return value (OPTIONAL default TRUE).
     * @return bool TRUE if the user can view the resource and FALSE otherwise
     * @see Record::getViewCacheExpirationDate()
     */
    public function userCanView(\ScoutLib\User $User, bool $AllowHooksToModify = true)
    {
        # anon users cannot view temp records
        if ($this->isTempRecord() && !$User->isLoggedIn()) {
            return false;
        }

        return $this->checkSchemaPermissions($User, "View", $AllowHooksToModify);
    }

    /**
     * Determine if the given user can edit the resource.The result of this
     * method can be modified via the EVENT_RESOURCE_EDIT_PERMISSION_CHECK event.
     * @param \ScoutLib\User $User User to check against.
     * @return bool TRUE if the user can edit the resource and FALSE otherwise
     */
    public function userCanEdit($User)
    {
        return $this->checkSchemaPermissions($User, "Edit");
    }

    /**
     * Determine if the given user can edit the resource.The result of this
     * method can be modified via the EVENT_RESOURCE_EDIT_PERMISSION_CHECK event.
     * @param \ScoutLib\User $User User to check against.
     * @return bool TRUE if the user can edit the resource and FALSE otherwise
     */
    public function userCanAuthor($User)
    {
        return $this->checkSchemaPermissions($User, "Author");
    }

    /**
     * Check if the user is allowed to modify (Edit for perm resources,
     * Author for temp) a specified resources.
     * @param User $User User to check.
     * @return bool TRUE if the user can modify the resource, FALSE otherwise
     */
    public function userCanModify($User)
    {
        $CheckFn = "userCan".($this->isTempRecord() ? "Author" : "Edit");
        return $this->$CheckFn($User);
    }

    /**
     * Check if the result of the most recent userCanView() call will only be
     * valid for a certain time because it contains a comparison against a
     * TIMESTAMP field, getting the expiration time of the check if so.
     * @return false|string FALSE when the userCanView() result does not expire, a date in
     *   SQL format giving the expiration time otherwise.
     * @see Record::userCanView()
     */
    public function getViewCacheExpirationDate()
    {
        return $this->ViewPrivExpirationDate;
    }

    /**
     * Check whether user is allowed to view specified metadata field.
     * @param User $User User to check.
     * @param mixed $FieldOrFieldName Field name or object.
     * @return bool TRUE if user can view field, otherwise FALSE.
     */
    public function userCanViewField($User, $FieldOrFieldName)
    {
        return $this->checkFieldPermissions($User, $FieldOrFieldName, "View");
    }

    /**
     * Check whether user can view specified standard (mapped) metadata field.
     * @param User $User User to check.
     * @param string $MappedName Name of standard (mapped) field.
     * @return bool TRUE if user can view field, otherwise FALSE.
     */
    public function userCanViewMappedField($User, $MappedName)
    {
        $FieldId = $this->getSchema()->stdNameToFieldMapping($MappedName);
        return ($FieldId === null) ? false
                : $this->checkFieldPermissions($User, $FieldId, "View");
    }

    /**
     * Check whether user is allowed to edit specified metadata field.
     * @param User $User User to check.
     * @param mixed $FieldOrFieldName Field name or object.
     * @return bool TRUE if user can edit field, otherwise FALSE.
     */
    public function userCanEditField($User, $FieldOrFieldName)
    {
        return $this->checkFieldPermissions($User, $FieldOrFieldName, "Edit");
    }

    /**
     * Check whether user is allowed to author specified metadata field.
     * @param User $User User to check.
     * @param mixed $FieldOrFieldName Field name or object.
     * @return bool TRUE if user can author field, otherwise FALSE.
     */
    public function userCanAuthorField($User, $FieldOrFieldName)
    {
        return $this->checkFieldPermissions($User, $FieldOrFieldName, "Author");
    }

    /**
     * Check whether user is allowed to modify (Edit for perm
     * resources, Author for temp) specified metadata field.
     * @param User $User User to check.
     * @param mixed $FieldOrFieldName Field name or object.
     * @return bool TRUE if user can modify field, otherwise FALSE.
     */
    public function userCanModifyField($User, $FieldOrFieldName)
    {
        $CheckFn = "userCan".(($this->isTempRecord()) ? "Author" : "Edit")."Field";

        return $this->$CheckFn($User, $FieldOrFieldName);
    }

    /**
     * Clear permissions caches referring to this record.
     * @return void
     */
    public function clearPermissionsCache(): void
    {
        $this->DB->query("DELETE FROM UserPermsCache WHERE RecordId=".$this->Id);
        PrivilegeSet::clearCaches();
        RecordFactory::clearStaticCaches();

        $this->PermissionCache = [];
    }

    # --- Utility Methods ----------------------------------------------------

    /**
     * Update search and recommender system DBs.
     * @return void
     */
    public function queueSearchAndRecommenderUpdate(): void
    {
        if ($this->isTempRecord()) {
            return;
        }

        $SysConfig = SystemConfiguration::getInstance();
        if ($SysConfig->getBool("SearchDBEnabled")) {
            $SearchEngine = new SearchEngine();
            $SearchEngine->queueUpdateForItem($this);
        }

        if ($SysConfig->getBool("RecommenderDBEnabled")) {
            $Recommender = new Recommender();
            $Recommender->queueUpdateForItem($this);
        }
    }

    /**
     * Get schema ID for specified record.
     * @param int $RecordId ID of record.
     * @return int Schema ID.
     * @throws InvalidArgumentException If the supplied record ID is not found.
     */
    public static function getSchemaForRecord(int $RecordId): int
    {
        # make sure database access has been set up
        $Class = get_called_class();
        if (!isset(self::$SchemaIdCache)) {
            static::setDatabaseAccessValues($Class);
            self::$SchemaIdCache = [];
        }

        # return schema ID for record if already loaded
        if (isset(self::$SchemaIdCache[$RecordId])) {
            return self::$SchemaIdCache[$RecordId];
        }

        # query database for schema ID
        $DB = new Database();
        $IdColumnName = self::$ItemIdColumnNames[$Class];
        $TableName = self::$ItemTableNames[$Class];
        $SchemaId = $DB->queryValue(
            "SELECT SchemaId FROM `".$TableName."`"
                    ." WHERE `".$IdColumnName."` = ".$RecordId,
            "SchemaId"
        );
        if ($SchemaId === null) {
            throw new InvalidArgumentException("Unknown record ID (".$RecordId.").");
        }

        # save schema ID to cache and return it to caller
        self::$SchemaIdCache[$RecordId] = (int)$SchemaId;
        return (int)$SchemaId;
    }

    /**
     * Get schema IDs for specified resources.
     * @param array $RecordIds Array of record IDs.
     * @return array Array of schema IDs indexed by record ID.
     * @throws InvalidArgumentException If any of the supplied record IDs
     *      are illegal (not strictly numeric) or not found.
     */
    public static function getSchemasForRecords(array $RecordIds): array
    {
        # make sure database access has been set up
        $Class = get_called_class();
        if (!isset(self::$SchemaIdCache)) {
            static::setDatabaseAccessValues($Class);
            self::$SchemaIdCache = [];
        }

        # load info if any data was missing
        $MissingIds = array_diff($RecordIds, array_keys(self::$SchemaIdCache));
        if (count($MissingIds)) {
            $DB = new Database();
            $IdColumnName = self::$ItemIdColumnNames[$Class];
            $TableName = self::$ItemTableNames[$Class];
            $QueryBase = "SELECT `".$IdColumnName."`, SchemaId FROM `".$TableName."`"
                ." WHERE `".$IdColumnName."` IN ";
            $MissingIds = array_map("intval", $MissingIds);
            $ChunkSize = Database::getIntegerDataChunkSize(
                $MissingIds,
                strlen($QueryBase) + 2
            );
            foreach (array_chunk($MissingIds, $ChunkSize) as $ChunkIds) {
                $DB->query(
                    $QueryBase."(".implode(",", $ChunkIds).")"
                );
                self::$SchemaIdCache += $DB->fetchColumn("SchemaId", $IdColumnName);
            }
        }

        # find schema IDs for specified resources
        $SchemaIds = array_intersect_key(
            self::$SchemaIdCache,
            array_flip($RecordIds)
        );

        # check that specified resource IDs were all valid
        if (count($SchemaIds) < count($RecordIds)) {
            $BadIds = array_diff($RecordIds, array_keys($SchemaIds));
            throw new InvalidArgumentException("Unknown record IDs ("
                    .implode(", ", $BadIds).").");
        }

        # return schema IDs to caller
        return $SchemaIds;
    }

    /**
     * Get the ItemIdColumnName currently in use for Records.
     * @return string Column name.
     */
    public static function getItemIdColumnName()
    {
        $Class = get_called_class();
        static::setDatabaseAccessValues($Class);

        return self::$ItemIdColumnNames[$Class];
    }

    /**
     * Notify registered observers about the specified event.
     * Observer functions should have the following signature:
     *      function myObserver(
     *          int $Event,
     *          Record $Record): void
     * Record addition and deletion produce ADD and REMOVE events, and
     * record modification produces a SET event.  Observers are notified
     * about the REMOVE event before the record is actually deleted.
     * @param int $Event Event to notify about (EVENT_ constant).
     */
    public function notifyObservers(int $Event): void
    {
        $Args = [ $Event, $this ];
        $this->notifyObserversWithArgs($Event, $Args, $this->Id);
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $ClassificationCache;
    private $Comments;
    private $ControlledNameCache;
    private $ControlledNameVariantCache;
    private $CumulativeRating;
    private $NumberOfComments;
    private $NumberOfRatings;
    private $RunAutoUpdates = false;
    private $SchemaId;
    private $ViewPrivExpirationDate = false;

    private static $SchemaIdCache;
    private static $Schemas;
    private static $WasPublic = [];

    protected $PermissionCache = [];

    # ---- Field Setting Methods ---------------------------------------------

    /**
     * Perform internal housekeeping necessary after changing a value -- sets
     * up a callback to run near the end of execution to handle record
     * modification, updates field timestamps, and clears caches.
     * @param MetadataField $Field Field that was changed.
     * @return void
     */
    private function doHousekeepingAfterChangeToValue(MetadataField $Field) : void
    {
        $this->updateModificationTimestampForField($Field);

        # if this field is not an option or a controlled name, but it is
        # checked for visibility, then we need to clear resource
        # visibility cache (for example, if this is AddedById and
        # our schema allows users to view resources where they are
        # the AddedById)
        if (!in_array(
            $Field->type(),
            [MetadataSchema::MDFTYPE_OPTION, MetadataSchema::MDFTYPE_CONTROLLEDNAME]
        ) && $this->getSchema()->viewingPrivileges()->checksField($Field->id())) {
            $RFactory = new RecordFactory($this->SchemaId);
            $RFactory->clearVisibleRecordCount($this);
        }

        if ($Field->triggersAutoUpdates()) {
            $this->RunAutoUpdates = true;
        }

        $AF = ApplicationFramework::getInstance();
        $User = User::getCurrentUser();

        if ($this->RunAutoUpdates) {
            # grab a lock on the task queue
            $AF->beginAtomicTaskOperation();

            # check if there's a no update version of this task queued, deleting
            # it if there was
            $Params = [
                $this->id(),
                "doHousekeepingAfterChangeToRecord",
                $User->id(),
                self::$WasPublic[$this->id()],
                false
            ];

            $TaskId = $AF->getTaskId("\\Metavus\\Record::callMethod", $Params);
            if ($TaskId !== false) {
                $AF->deleteTask($TaskId);
            }

            # release lock
            $AF->endAtomicTaskOperation();
        }

        $Params = [
            $this->id(),
            "doHousekeepingAfterChangeToRecord",
            $User->id(),
            self::$WasPublic[$this->id()],
            $this->RunAutoUpdates
        ];
        $AF->queueUniqueTask(
            "\\Metavus\\Record::callMethod",
            $Params,
            ApplicationFramework::PRIORITY_HIGH,
            "Update fields after change to record ID ".$this->id()
                ." by user ID ".$User->id().($this->RunAutoUpdates ? " (with AutoUpdates)" : "")
        );
    }

    /**
     * Update the value stored for a Point field
     * @param mixed $NewValue Array containing X and Y values.
     * @return bool TRUE if changes were made to the Record, false otherwise.
     */
    private function setPointField(MetadataField $Field, $NewValue): bool
    {
        if ($Field->type() != MetadataSchema::MDFTYPE_POINT) {
            throw new Exception("setPointField() can only be used for Point fields");
        }

        # assume no change
        $ValueChanged = false;

        $DBFieldName = $Field->dBFieldName();
        $XFieldName = $DBFieldName."X";
        $YFieldName = $DBFieldName."Y";

        $CurrentXValue = $this->DB->updateFloatValue($XFieldName);
        $CurrentYValue = $this->DB->updateFloatValue($YFieldName);

        if ($NewValue === false) {
            if (($CurrentXValue !== false) || ($CurrentYValue !== false)) {
                $this->DB->updateFloatValue($XFieldName, false);
                $this->DB->updateFloatValue($YFieldName, false);
                $ValueChanged = true;
                $Field->notifyObservers(
                    MetadataField::EVENT_CLEAR,
                    $this->Id,
                    $NewValue
                );
            }
            return $ValueChanged;
        }

        $Digits = $Field->pointDecimalDigits();
        $NewXValue = is_numeric($NewValue["X"])
            ? round((float)$NewValue["X"], $Digits)
            : $NewValue["X"];
        $NewYValue = is_numeric($NewValue["Y"])
            ? round((float)$NewValue["Y"], $Digits)
            : $NewValue["Y"];
        if (($NewXValue !== $CurrentXValue) || ($NewYValue !== $CurrentYValue)) {
            $this->DB->updateFloatValue($XFieldName, $NewXValue);
            $this->DB->updateFloatValue($YFieldName, $NewYValue);
            $ValueChanged = true;
            $Field->notifyObservers(
                MetadataField::EVENT_CLEAR,
                $this->Id,
                [ "X" => $NewXValue, "Y" => $NewYValue ]
            );
        }

        return $ValueChanged;
    }

    /**
     * Update the value stored for a User field.
     * @param MetadataField $Field Metadata field to set.
     * @param mixed $NewValue UserId, User object, array of UserIds, or
     *   array of User objects.
     * @param bool $Reset TRUE to remove Users not present in
     *   $NewValue.Otherwise, Users in NewValue will be appended to the
     *   current list of users.
     * @return bool TRUE if changes were made to the Record, false otherwise.
     */
    private function setUserField(MetadataField $Field, $NewValue, bool $Reset): bool
    {
        if ($Field->type() != MetadataSchema::MDFTYPE_USER) {
            throw new Exception("setUserField() can only be used for User fields");
        }

        # do not try to set User fields to the anonymous user
        if (($NewValue instanceof User) && ($NewValue->id() === null)) {
            (ApplicationFramework::getInstance())->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "Attempt to set metadata field \"".$Field->name()
                        ."\" (ID:".$Field->id().") to the anonymous user."
            );
            return false;
        }

        $OldValue = array_keys($this->get($Field));
        $NewValue = $this->normalizeValueToItemIds($NewValue, $Field);

        # if this is a unique field, only accept the first of the options given
        if ($Field->allowMultiple() == false && count($NewValue) > 1) {
            $NewValue = array_slice($NewValue, 0, 1, true);
        }

        if ($OldValue == $NewValue) {
            return false;
        }

        $ValueChanged = false;

        if ($Reset || $Field->allowMultiple() == false) {
            $ToRemove = array_diff($OldValue, $NewValue);
            $ValueChanged |= $this->removeAssociation(
                "RecordUserInts",
                "UserId",
                $ToRemove,
                $Field
            );
            if ($ValueChanged) {
                $Field->notifyObservers(
                    MetadataField::EVENT_REMOVE,
                    $this->Id,
                    $ToRemove
                );
            }
        }

        # associate with resource if not already associated
        $ValueChanged |= $this->addAssociation(
            "RecordUserInts",
            "UserId",
            $NewValue,
            $Field
        );
        if ($ValueChanged) {
            $Field->notifyObservers(
                MetadataField::EVENT_ADD,
                $this->Id,
                $NewValue
            );
        }

        return (bool)$ValueChanged;
    }

    /**
     * Update the value stored for a Date field.
     * @param MetadataField $Field Metadata field to set
     * @param mixed $NewValue Date object or a string that can be parsed by
     *   Date::__construct()
     * @return bool TRUE if changes were made to the Record, false otherwise
     */
    private function setDateField(MetadataField $Field, $NewValue): bool
    {
        if ($Field->type() != MetadataSchema::MDFTYPE_DATE) {
            throw new Exception("setDateField() can only be used for Date fields");
        }

        $ValueChanged = false;

        $DBFieldName = $Field->dBFieldName();

        $BFieldName = $DBFieldName."Begin";
        $EFieldName = $DBFieldName."End";
        $PFieldName = $DBFieldName."Precision";
        $CurrentBValue = $this->DB->updateValue($BFieldName);
        $CurrentEValue = $this->DB->updateValue($EFieldName);
        $CurrentPValue = $this->DB->updateValue($PFieldName);

        # convert the "0000-00-00" that mysql stores for empty timestamps
        # to FALSE
        if ($CurrentBValue == "0000-00-00") {
            $CurrentBValue = false;
        }
        if ($CurrentEValue == "0000-00-00") {
            $CurrentEValue = false;
        }
        if ($CurrentBValue === false && $CurrentEValue === false && $CurrentPValue == 0) {
            $CurrentPValue = false;
        }

        if ($NewValue === false) {
            if (($CurrentBValue !== false) || ($CurrentEValue !== false) ||
                ($CurrentPValue !== false)) {
                $this->DB->updateValue($BFieldName, false);
                $this->DB->updateValue($EFieldName, false);
                $this->DB->updateValue($PFieldName, false);
                $ValueChanged = true;
                $Field->notifyObservers(
                    MetadataField::EVENT_CLEAR,
                    $this->Id,
                    false
                );
            }
        } else {
            $NewDate = ($NewValue instanceof Date) ? $NewValue
                : new Date($NewValue);
            if (($CurrentBValue === false)
                    || ($CurrentEValue === false)
                    || ($CurrentPValue === false)) {
                $this->DB->updateValue($BFieldName, $NewDate->beginDate());
                $this->DB->updateValue($EFieldName, $NewDate->endDate());
                $this->DB->updateValue($PFieldName, $NewDate->precision());
                $ValueChanged = true;
            } else {
                $CurrentDate = new Date(
                    $CurrentBValue,
                    $CurrentEValue,
                    $CurrentPValue
                );
                if ($NewDate->beginDate() != $CurrentDate->beginDate()
                        || $NewDate->endDate() != $CurrentDate->endDate()
                        || $NewDate->precision() != $CurrentDate->precision()) {
                    $this->DB->updateValue($BFieldName, $NewDate->beginDate());
                    $this->DB->updateValue($EFieldName, $NewDate->endDate());
                    $this->DB->updateValue($PFieldName, $NewDate->precision());
                    $ValueChanged = true;
                }
            }
            if ($ValueChanged) {
                $Field->notifyObservers(
                    MetadataField::EVENT_SET,
                    $this->Id,
                    $NewDate
                );
            }
        }

        return $ValueChanged;
    }

    /**
     * Update the value stored for a Tree field.
     * @param MetadataField $Field Metadata field to set
     * @param mixed $NewValue ClassificationId, Classification object, array of CssificationIds, or
     *   array of Classification objects
     * @param bool $Reset TRUE to remove Classifications not present in
     *   $NewValue.Otherwise, Classifications in NewValue will be appended to the
     *   current list of classifications
     * @return bool TRUE if changes were made to the Record, false otherwise
     */
    private function setTreeField(MetadataField $Field, $NewValue, bool $Reset): bool
    {
        if ($Field->type() != MetadataSchema::MDFTYPE_TREE) {
            throw new Exception("setTreeField() can only be used for Tree fields");
        }

        # get normalized old and new values
        $OldValue = array_keys($this->get($Field));
        $NewValue = $this->normalizeValueToItemIds($NewValue, $Field);

        # if values were the same, nothing to do
        if ($OldValue == $NewValue) {
            return false;
        }

        # assume no change
        $ValueChanged = false;

        if ($Reset) {
            # remove values that were in the old value but not the new one
            $ToRemove = array_diff($OldValue, $NewValue);
            if (count($ToRemove)) {
                $ValueChanged = true;
                $this->removeAssociation(
                    "RecordClassInts",
                    "ClassificationId",
                    $ToRemove
                );
                foreach ($ToRemove as $ClassificationId) {
                    (new Classification($ClassificationId))->recalcResourceCount();
                }
            }
        }

        # determine what new values were provided
        $ToAdd = array_diff($NewValue, $OldValue);

        # if there are values to add
        if (count($ToAdd)) {
            # for each value to be added
            foreach ($ToAdd as $ClassificationId) {
                $Class = new Classification($ClassificationId);

                # check that the classification is valid for this field
                if ($Class->fieldId() != $Field->id()) {
                    throw new Exception(
                        "Attempting to store classification from "
                        ."Field ".$Class->fieldId()." into Field "
                        .$Field->id()
                    );
                }

                # associate with resource
                $WasAdded = $this->addAssociation(
                    "RecordClassInts",
                    "ClassificationId",
                    $ClassificationId
                );
                # update resource counts and last assigned if needed
                if ($WasAdded) {
                    $Class->updateLastAssigned();
                    $Class->recalcResourceCount();
                    $ValueChanged = true;
                }
            }
        }

        # if values were added or removed
        if ($ValueChanged) {
            # clear classification cache
            unset($this->ClassificationCache);

            # notify any observers of the changes
            if (isset($ToRemove)) {
                $Field->notifyObservers(
                    MetadataField::EVENT_REMOVE,
                    $this->Id,
                    $ToRemove
                );
            }
            $Field->notifyObservers(
                MetadataField::EVENT_ADD,
                $this->Id,
                $ToAdd
            );
        }

        return $ValueChanged;
    }

    /**
     * Update the value stored for a ControlledName field.
     * @param MetadataField $Field Metadata field to set
     * @param mixed $NewValue ControlledNameId, ControlledName object, array of CNIds, or
     *   array of ControlledName objects
     * @param bool $Reset TRUE to remove ControlledMaes not present in
     *   $NewValue.Otherwise, ControlledNames in NewValue will be appended to the
     *   current list of ControlledNames
     * @return bool TRUE if changes were made to the Record, false otherwise.
     */
    private function setControlledNameField(MetadataField $Field, $NewValue, bool $Reset): bool
    {
        $ValidTypes = [
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            MetadataSchema::MDFTYPE_OPTION,
        ];
        if (!in_array($Field->type(), $ValidTypes)) {
            throw new Exception(
                "setControlledNameField() can only be used for ControlledName and Option fields"
            );
        }

        $OldValue = array_keys($this->get($Field));
        $NewValue = $this->normalizeValueToItemIds($NewValue, $Field);

        # if this is a unique field, only accept the first of the options given
        #  NB: all ControlledNames implicitly AllowMultiple
        if ($Field->type() == MetadataSchema::MDFTYPE_OPTION &&
            $Field->allowMultiple() == false && count($NewValue) > 1) {
            $NewValue = array_slice($NewValue, 0, 1, true);
        }

        # if values were the same, nothing to do
        if ($OldValue == $NewValue) {
            return false;
        }

        # start off assuming no changes
        $ValueChanged = false;

        # if the value has changed
        if ($Reset ||
            ($Field->type() == MetadataSchema::MDFTYPE_OPTION &&
            $Field->allowMultiple() == false)) {
            $ToRemove = array_diff($OldValue, $NewValue);
            if (count($ToRemove)) {
                $ValueChanged = true;
                $this->removeAssociation(
                    "RecordNameInts",
                    "ControlledNameId",
                    $ToRemove
                );
            }
        }

        $ToAdd = array_diff($NewValue, $OldValue);
        if (count($ToAdd)) {
            $ValueChanged = true;
            $this->addAssociation(
                "RecordNameInts",
                "ControlledNameId",
                $ToAdd
            );

            # for each element of array
            foreach ($ToAdd as $ControlledNameId) {
                (new ControlledName($ControlledNameId))->updateLastAssigned();
            }
            $Field->notifyObservers(
                MetadataField::EVENT_ADD,
                $this->Id,
                $ToAdd
            );
        }

        if ($ValueChanged) {
            # clear our controlled name cache
            unset($this->ControlledNameCache);
            unset($this->ControlledNameVariantCache);

            # clear visible count cache for any affected CNames
            $RFactory = new RecordFactory($this->SchemaId);
            $RFactory->clearVisibleRecordCountForValues(
                array_unique(array_merge($OldValue, $NewValue))
            );

            # notify any observers of the changes
            if (isset($ToRemove)) {
                $Field->notifyObservers(
                    MetadataField::EVENT_REMOVE,
                    $this->Id,
                    $ToRemove
                );
            }
            $Field->notifyObservers(
                MetadataField::EVENT_ADD,
                $this->Id,
                $ToAdd
            );
        }

        return $ValueChanged;
    }

    /**
     * Update the value stored for an Image field.
     * @param MetadataField $Field Metadata field to set.
     * @param mixed $NewValue ImageId, Image object, array of ImageIds, or
     *   array of Image objects.
     * @param bool $Reset TRUE to remove Images not present in
     *   $NewValue.Otherwise, Images in NewValue will be appended to the
     *   current list of images.
     * @return bool TRUE if changes were made to the Record, false otherwise.
     */
    private function setImageField(MetadataField $Field, $NewValue, bool $Reset): bool
    {
        if ($Field->type() != MetadataSchema::MDFTYPE_IMAGE) {
            throw new Exception("setImageField() can only be used for Image fields");
        }

        # normalize and check incoming value
        $NewValue = $this->normalizeValueToItemIds($NewValue, $Field);
        $OldValue = $this->get($Field);

        # if old and new values are the same, nothing to do
        if ($OldValue == $NewValue) {
            return false;
        }

        # start off assuming no change
        $ValueChanged = false;

        # if necessary, remove values from the record
        if ($Reset) {
            $ToRemove = array_diff($OldValue, $NewValue) ;
            if (count($ToRemove)) {
                $ValueChanged = true;
                foreach ($ToRemove as $ImageId) {
                    (new Image($ImageId))->destroy();
                }
                $Field->notifyObservers(
                    MetadataField::EVENT_REMOVE,
                    $this->Id,
                    $ToRemove
                );
            }
        }

        # see if we have any values to add
        $ToAdd = array_diff($NewValue, $OldValue);

        # if not, nothing else to do
        if (count($ToAdd) == 0) {
            return $ValueChanged;
        }

        # iterate over values to add
        foreach ($ToAdd as $ImageId) {
            # get existing recordid
            $Image = new Image($ImageId);
            $RecordId = $Image->getIdOfAssociatedItem();

            # error out if some other record already owns this image
            if ($RecordId != Image::NO_ITEM  && $RecordId != $this->id()) {
                throw new Exception(
                    "Attempt to associate Image (Id=".$ImageId.") "
                    ."with a Record that already belongs to a different "
                    ."Record (Id=".$RecordId.")"
                );
            }

            # associate image with this record and field
            $Image->setItemId($this->id());
            $Image->setFieldId($Field->id());
        }

        # clear image symlinks for this record
        $this->clearImageSymlinksForField($Field->id());

        $Field->notifyObservers(MetadataField::EVENT_ADD, $this->Id, $ToAdd);

        return true;
    }

    /**
     * Update the value stored for a File field.
     * @param mixed $NewValue FileId, File object, array of FileIds, or array
     *   of File objects.
     * @param bool $Reset TRUE to remove Files not present in
     *   $NewValue.Otherwise, Files in NewValue will be appended to the
     *   current list of files.
     * @return bool TRUE if changes were made to the Record, false otherwise.
     */
    private function setFileField(MetadataField $Field, $NewValue, bool $Reset): bool
    {
        if ($Field->type() != MetadataSchema::MDFTYPE_FILE) {
            throw new Exception("setFileField() can only be used for File fields");
        }

        # normalize and check incoming value
        $NewValue = $this->normalizeValueToItemIds($NewValue, $Field);
        $OldValue = array_keys($this->get($Field));

        if ($OldValue == $NewValue) {
            return false;
        }

        # start off assuming no change
        $ValueChanged = false;

        # if necessary, remove values from the record
        if ($Reset) {
            $ToRemove = array_diff($OldValue, $NewValue);

            if (count($ToRemove)) {
                $ValueChanged = true;
                $this->clear($Field, $ToRemove);
            }
        }

        # if necessary, add values to the record
        $ToAdd = array_diff($NewValue, $OldValue);
        if (count($ToAdd)) {
            $ValueChanged = true;

            # for each new incoming file
            $AddedFileIds = [];
            foreach ($ToAdd as $FileId) {
                # get the file
                $File = new File($FileId);

                # make copy of file
                $NewFile = $File->duplicate();

                # associate copy with this record and field
                $NewFile->resourceId($this->Id);
                $NewFile->fieldId($Field->id());

                # signal event to indicate file addition
                (ApplicationFramework::getInstance())->signalEvent(
                    "EVENT_RESOURCE_FILE_ADD",
                    [
                        "Field" => $Field,
                        "Resource" => $this,
                        "File" => $NewFile,
                    ]
                );

                # note copy's file ID for observers
                $AddedFileIds[] = $NewFile->id();
            }
            $Field->notifyObservers(MetadataField::EVENT_ADD, $this->Id, $AddedFileIds);
        }

        # report to caller if we changed anything
        return $ValueChanged;
    }

    /**
     * Update the value stored for a Reference field.
     * @param mixed $NewValue RecordId, Record object, array of RecordIds, or array
     *   of Record objects.
     * @param bool $Reset TRUE to remove Records not present in
     *   $NewValue.Otherwise, Records in NewValue will be appended to the
     *   current value.
     * @return bool TRUE if changes were made to the Record, false otherwise.
     */
    private function setReferenceField(MetadataField $Field, $NewValue, bool $Reset): bool
    {
        if ($Field->type() != MetadataSchema::MDFTYPE_REFERENCE) {
            throw new Exception("setReferenceField() can only be used for Reference fields");
        }

        $OldValue = array_keys($this->get($Field));
        $NewValue = $this->normalizeValueToItemIds($NewValue, $Field);

        if ($OldValue == $NewValue) {
            return false;
        }

        # assume no changes
        $ValueChanged = false;

        if ($Reset) {
            $ToRemove = array_diff($OldValue, $NewValue);
            if (count($ToRemove)) {
                $ValueChanged = true;

                $this->DB->query(
                    "DELETE FROM ReferenceInts "
                    ."WHERE FieldId = ".$Field->id()
                    ." AND SrcRecordId = ".$this->id()
                    ." AND DstRecordId IN (".implode(",", $ToRemove).")"
                );
                $Field->notifyObservers(MetadataField::EVENT_REMOVE, $this->Id, $ToRemove);
            }
        }

        $ToAdd = array_diff($NewValue, $OldValue);
        foreach ($ToAdd as $ReferenceId) {
            # skip references to the current resource
            if ($ReferenceId == $this->id()) {
                continue;
            }

            # add the reference to the references table
            $this->DB->query(
                "INSERT INTO ReferenceInts ("
                ."FieldId, "
                ."SrcRecordId, "
                ."DstRecordId"
                .") VALUES ("
                .$Field->id().","
                .$this->id().","
                .addslashes($ReferenceId).")"
            );
            $ValueChanged = true;
        }
        $Field->notifyObservers(MetadataField::EVENT_ADD, $this->Id, $ToAdd);

        return $ValueChanged;
    }

    /**
     * Modify fields, signaling events and performing keyword
     *   substitutions as necessary.
     * @param User|null $User User performing the modification or NULL if unknown.
     * @param MetadataField $Field Field to modify
     * @param mixed $NewValue Value to set
     * @return void
     */
    private function modifyFieldValue(
        $User,
        MetadataField $Field,
        $NewValue
    ): void {
        $ShouldDoDateSubs = [
            MetadataSchema::MDFTYPE_TEXT,
            MetadataSchema::MDFTYPE_PARAGRAPH,
            MetadataSchema::MDFTYPE_DATE,
            MetadataSchema::MDFTYPE_TIMESTAMP,
        ];

        $ShouldDoUserSubs = [
            MetadataSchema::MDFTYPE_TEXT,
            MetadataSchema::MDFTYPE_PARAGRAPH,

        ];

        # process substitutions for fields where they apply
        if (in_array($Field->type(), $ShouldDoDateSubs)) {
            $Substitutions = [
                "X-DATE-X" => date("M j Y"),
                "X-TIME-X" => date("g:ia T")
            ];

            $NewValue = str_replace(
                array_keys($Substitutions),
                array_values($Substitutions),
                $NewValue
            );
        }

        if (in_array($Field->type(), $ShouldDoUserSubs)) {
            $Substitutions = [
                "X-USERNAME-X"  => ($User !== null) ? $User->get("UserName") : "",
                "X-USEREMAIL-X" => ($User !== null) ? $User->get("EMail") : "",
            ];

            $NewValue = str_replace(
                array_keys($Substitutions),
                array_values($Substitutions),
                $NewValue
            );
        }

        # process edit hooks for fields where they apply
        $ShouldCallHooks = [
            MetadataSchema::MDFTYPE_TEXT,
            MetadataSchema::MDFTYPE_NUMBER,
            MetadataSchema::MDFTYPE_DATE,
            MetadataSchema::MDFTYPE_TIMESTAMP,
            MetadataSchema::MDFTYPE_PARAGRAPH,
            MetadataSchema::MDFTYPE_FLAG,
            MetadataSchema::MDFTYPE_URL
        ];

        if (in_array($Field->type(), $ShouldCallHooks)) {
            $SignalResult = (ApplicationFramework::getInstance())->signalEvent(
                "EVENT_POST_FIELD_EDIT_FILTER",
                [
                    "Field" => $Field,
                    "Resource" => $this,
                    "Value" => $NewValue
                ]
            );
            $NewValue = $SignalResult["Value"];
        }


        # before updating field value, verify that the provided value is valid
        # (getFactory() returns a factory object that implements itemExists() for
        #  vocabulary fields or NULL for fields that allow any value)
        $Factory = $Field->getFactory();
        if (is_null($Factory) || $Factory->itemExists($NewValue) !== false) {
            $this->set($Field, $NewValue);
        }
    }


    # ---- Permission Checking Methods ---------------------------------------

    /**
     * Check schema permissions to see if user is allowed to
     *         View/Edit/Author this resource.
     * @param \ScoutLib\User $User User to check.
     * @param string $CheckType Type of check to perform (one of View,
     *       Author, or Edit).
     * @param bool $AllowHooksToModify TRUE if hook functions should be
     *       allowed to modify the return value (OPTIONAL default TRUE).
     * @return bool TRUE if user is allowed, FALSE otherwise
     */
    private function checkSchemaPermissions(
        $User,
        string $CheckType,
        bool $AllowHooksToModify = true
    ): bool {
        # construct a key to use for our permissions cache
        $CacheKey = "UserCan".$CheckType.$User->id();

        # if we don't have a cached value for this perm, compute one
        if (!isset($this->PermissionCache[$CacheKey])) {
            # get privileges for schema
            $PermsFn = $CheckType."ingPrivileges";
            $SchemaPrivs = $this->getSchema()->$PermsFn();

            # check passes if user privileges are greater than resource set
            $CheckResult = $SchemaPrivs->MeetsRequirements($User, $this);

            # save the result of this check in our cache
            $this->PermissionCache[$CacheKey] = $CheckResult;

            if ($CheckType == "View") {
                $this->ViewPrivExpirationDate = $SchemaPrivs->getResultExpirationDate();
            }
        }

        $Value = $this->PermissionCache[$CacheKey];

        if ($AllowHooksToModify) {
            $SignalResult = (ApplicationFramework::getInstance())->signalEvent(
                "EVENT_RESOURCE_".strtoupper($CheckType)."_PERMISSION_CHECK",
                [
                    "Resource" => $this,
                    "User" => $User,
                    "Can".$CheckType => $Value,
                    "Schema" => $this->getSchema(),
                ]
            );

            $Value =  $SignalResult["Can".$CheckType];
        }

        return $Value;
    }

    /**
     * Check field permissions to see if user is allowed to
     *         View/Author/Edit a specified field.
     * @param User $User User to check.
     * @param MetadataField|int|string $Field Metadata field or field ID or field name.
     * @param string $CheckType Type of check to perform (View, Author, or Edit).
     * @return bool TRUE if user is allowed, FALSE otherwise.
     */
    private function checkFieldPermissions(User $User, $Field, string $CheckType): bool
    {
        $Field = $this->normalizeFieldArgument($Field);

        # construct a key to use for our permissions cache
        $CacheKey = "UserCan".$CheckType."Field".$Field->id()."-".$User->id();

        # if we don't have a cached value, compute one
        if (!isset($this->PermissionCache[$CacheKey])) {
            # if field is enabled and editable, do permission check
            if ($Field->enabled() && ($CheckType == "View" || $Field->editable())) {
                # be sure schema privs allow View/Edit/Author for this resource
                $SchemaCheckFn = "UserCan".$CheckType;
                if ($this->$SchemaCheckFn($User)) {
                    # get appropriate privilege set for field
                    $PermsFn = $CheckType."ingPrivileges";
                    $FieldPrivs = $Field->$PermsFn();

                    # user can View/Edit/Author if privileges are greater than field set
                    $CheckResult = $FieldPrivs->meetsRequirements($User, $this);
                } else {
                    $CheckResult = false;
                }
            } else {
                $CheckResult = false;
            }

            # allow plugins to modify result of permission check
            $SignalResult = (ApplicationFramework::getInstance())->signalEvent(
                "EVENT_FIELD_".strtoupper($CheckType)."_PERMISSION_CHECK",
                [
                    "Field" => $Field,
                    "Resource" => $this,
                    "User" => $User,
                    "Can".$CheckType => $CheckResult
                ]
            );
            $CheckResult = $SignalResult["Can".$CheckType];

            # save the result of this check in our cache
            $this->PermissionCache[$CacheKey] = $CheckResult;
        }

        # return cached permission value
        return $this->PermissionCache[$CacheKey];
    }


    # ---- Methods for managing persistent image URLs ------------------------

    /**
     * Get a legacy format persistent URL for a given image within a specified
     * field.These URLs reference an image by record Id, Field Id, and index
     * within the field rather than using an image Id.This prevents the URL
     * from breaking when an image is updated.
     * @param MetadataField $Field Metadata field.
     * @param int $Index Index of the image within this field.
     * @param int $LegacySize Desired image size as an Image::SIZE_* constant.
     * @return string Persistent image URL.
     */
    private function getLegacyPersistentUrlForImage(
        MetadataField $Field,
        int $Index,
        int $LegacySize
    ): string {

        switch ($LegacySize) {
            case Image::SIZE_FULL:
                $Size = "mv-image-large";
                break;
            case Image::SIZE_PREVIEW:
                $Size = "mv-image-preview";
                break;
            case Image::SIZE_THUMBNAIL:
                $Size = "mv-image-thumbnail";
                break;
            default:
                throw new Exception("Unknown image size requested");
        }

        $ImageUrl = $this->getPersistentUrlForImage($Field, $Index, $Size);

        # if we have no CleanURL support, just pass the value up
        if (!(ApplicationFramework::getInstance())->cleanUrlSupportAvailable()) {
            return $ImageUrl;
        }

        # get the name of the symlink
        $LinkName = str_replace(
            ApplicationFramework::baseUrl()."viewimage/",
            "",
            $ImageUrl
        );

        # translate to legacy format
        $LegacyLinkName = str_replace(
            ["thumbnail", "preview", "large"],
            ["t", "p", "f" ],
            $LinkName
        );

        # create symlink if needed
        if (!file_exists(self::IMAGE_CACHE_PATH."/".$LegacyLinkName)) {
            $SrcPath = realpath(self::IMAGE_CACHE_PATH."/".$LinkName);

            if ($SrcPath !== false) {
                symlink(
                    $SrcPath,
                    self::IMAGE_CACHE_PATH."/".$LegacyLinkName
                );
            }
        }

        # return the legacy link
        return ApplicationFramework::baseUrl()."viewimage/".$LegacyLinkName;
    }

    /**
     * Get a persistent URL for a given image within a specified field.These
     *   URLs reference an image by record Id, Field Id, and index within the
     *   field rather than using an image Id.This prevents the URL from
     *   breaking when an image is updated.
     * @param MetadataField $Field Metadata field.
     * @param int $Index Index of the image within this field.
     * @param string $Size Desired image size from available size names
     *   defined for user interface (e.g., 'preview').
     * @return string Persistent image URL.
     */
    private function getPersistentUrlForImage(
        MetadataField $Field,
        int $Index,
        string $Size
    ): string {
        $ImageIds = $this->get($Field);

        if (!isset($ImageIds[$Index])) {
            throw new Exception(
                "Attempt to retrieve invalid image index."
            );
        }

        $Image = new Image($ImageIds[$Index]);
        $SrcFile = $Image->url($Size);

        # if we have no CleanURL support, just return the file name
        if (!(ApplicationFramework::getInstance())->cleanUrlSupportAvailable()) {
            return $SrcFile;
        }

        # make sure our ImageLinks dir exists
        if (!is_dir(self::IMAGE_CACHE_PATH)) {
            mkdir(self::IMAGE_CACHE_PATH);
        }

        # determine the desired location of our symlink
        $FileSuffix = strtolower(
            str_replace("mv-image-", "", $Size)
        );
        $LinkName =
            implode("_", [$this->id(), $Field->id(), $Index, $FileSuffix])
            .".".\ScoutLib\ImageFile::extensionForFormat($Image->format());

        # if our symlink doesn't exist, create it
        if (!file_exists(self::IMAGE_CACHE_PATH."/".$LinkName)) {
            symlink(
                dirname(__DIR__)."/".$SrcFile,
                self::IMAGE_CACHE_PATH."/".$LinkName
            );
        }

        return ApplicationFramework::baseUrl()."viewimage/".$LinkName;
    }

    /**
     * Remove symlinks used for to cache image mappings for a given field.
     * @param int $FieldId Source field.
     * @return void
     */
    private function clearImageSymlinksForField(int $FieldId): void
    {
        $this->clearImageSymlinksByGlob(
            self::IMAGE_CACHE_PATH."/".$this->id()."_".$FieldId."_*"
        );
    }

    /**
     * Remove all symlinks used for to cache image mappings for this record.
     * @return void
     */
    private function clearAllImageSymlinks(): void
    {
        $this->clearImageSymlinksByGlob(
            self::IMAGE_CACHE_PATH."/".$this->id()."_*"
        );
    }

    /**
     * Remove image symlinks that match a specified glob.
     * @param string $Pattern File name pattern to match in a format
     *   acceptable to PHP's glob().
     * @return void
     */
    private function clearImageSymlinksByGlob(string $Pattern): void
    {
        if (!is_dir(self::IMAGE_CACHE_PATH)) {
            return;
        }

        $Files = glob($Pattern);

        # glob() returns FALSE on errors
        if ($Files === false) {
            return;
        }

        foreach ($Files as $File) {
            if (file_exists($File) && is_link($File)) {
                unlink($File);
            }
        }
    }


    # ---- Utility Methods ---------------------------------------------------

    /**
     * Recalculate and save cumulative rating value for resource.
     * @return void
     */
    private function fetchAndPossiblyUpdateCumulativeRating(): void
    {
        # grab totals from DB
        $this->DB->query("SELECT COUNT(Rating) AS Count, "
                ."SUM(Rating) AS Total FROM RecordRatings "
                ."WHERE RecordId = ".$this->Id);
        $Record = $this->DB->fetchRow();

        $this->NumberOfRatings = $Record["Count"];
        $NewCumulativeRating = round($Record["Total"] / max(1, $Record["Count"]));

        # if saved cumulative rating is no longer accurate
        if ($this->CumulativeRating != $NewCumulativeRating) {
            # update local value and save new cumulative rating in DB
            $this->CumulativeRating = $NewCumulativeRating;
            $this->DB->updateIntValue("CumulativeRating", $NewCumulativeRating);
        }
    }

    /**
     * Associate specified value(s) with resource (by adding an entry into
     * the specified intersection table if necessary).If an object or array
     * of objects are passed in, they must support an Id() method to retrieve
     * the object ID.
     * @param string $TableName Name of database intersection table.
     * @param string $FieldName Name of column in database table.
     * @param mixed $Value ID or object or array of IDs or objects to associate.
     * @param MetadataField $Field Metadata field.(OPTIONAL)
     * @return bool TRUE if new value was associated, otherwise FALSE.
     */
    private function addAssociation(
        string $TableName,
        string $FieldName,
        $Value,
        ?MetadataField $Field = null
    ): bool {
        # we should ignore duplicate key errors when doing inserts
        $this->DB->SetQueryErrorsToIgnore([
            "/INSERT INTO ".$TableName."/" =>
                        "/Duplicate entry '-?[0-9]+-[0-9]+(-[0-9]+)?' for key/"
        ]);

        # start out assuming no association will be added
        $AssociationAdded = false;

        # convert new value to array if necessary
        $Values = is_array($Value) ? $Value : [$Value];

        # for each new value
        foreach ($Values as $Value) {
            # retrieve ID from value if necessary
            if (is_object($Value) && method_exists($Value, "id")) {
                $Value = $Value->id();
            }

            # try to insert a new entry for this association
            $this->DB->query("INSERT INTO ".$TableName." SET"
                        ." RecordId = ".intval($this->Id)
                        .", ".$FieldName." = ".intval($Value)
                        .($Field ? ", FieldId = ".intval($Field->id()) : ""));

            # if the insert ran without a duplicate key error,
            #  then we added an association
            if ($this->DB->IgnoredError() === false) {
                $AssociationAdded = true;
            }
        }

        # clear ignored errors
        $this->DB->SetQueryErrorsToIgnore(null);

        # report to caller whether association was added
        return $AssociationAdded;
    }

    /**
     * Disassociate specified value(s) with resource (by removing entries in
     * the specified intersection table as necessary).If an object or array
     * of objects are passed in, they must support an Id() method to retrieve
     * the object ID.
     * @param string $TableName Name of database intersection table.
     * @param string $FieldName Name of column in database table.
     * @param mixed $Value ID or Item or array of IDs or Items to disassociate.
     * @param MetadataField $Field Metadata field.(OPTIONAL)
     * @return bool TRUE if value was disassociated, otherwise FALSE.
     */
    private function removeAssociation(
        string $TableName,
        string $FieldName,
        $Value,
        ?MetadataField $Field = null
    ): bool {
        # start out assuming no association will be removed
        $AssociationRemoved = false;

        # convert value to array if necessary
        $Values = is_array($Value) ? $Value : [$Value];

        # for each value
        foreach ($Values as $Value) {
            # retrieve ID from value if necessary
            if (is_object($Value) && method_exists($Value, "id")) {
                $Value = $Value->id();
            }

            # remove any intersections with target ID from DB
            $this->DB->query("DELETE FROM ".$TableName
                    ." WHERE RecordId = ".intval($this->Id)
                    .($Field ? " AND FieldId = ".intval($Field->id()) : "")
                    ." AND ".$FieldName." = ".intval($Value));
            if ($this->DB->NumRowsAffected()) {
                $AssociationRemoved = true;
            }
        }

        # report to caller whether association was added
        return $AssociationRemoved;
    }

    /**
     * Recalculate counts (of associated records) for all classifications
     *     associated with this record.
     * @return void
     */
    private function recalculateCountsForClassifications() : void
    {
        $TreeFields = $this->getSchema()->getFields(
            MetadataSchema::MDFTYPE_TREE
        );
        foreach ($TreeFields as $Field) {
            $Classes = $this->get($Field, true);
            foreach ($Classes as $Class) {
                $Class->recalcResourceCount();
            }
        }
    }

    /**
     * Convert a value or array of values to an array of item IDs.
     * @param mixed $NewValue single item ID, single object, array of item IDs,
     *   array of objects, or array of strings keyed by item ID
     * @param MetadataField $Field Metadata field associated with values.
     * @return array Array of item IDs
     * @throws InvalidArgumentException when the provided value cannot be converted
     *  to an array of item IDs
     */
    private function normalizeValueToItemIds($NewValue, MetadataField $Field): array
    {
        # if we were just given a single value, normalize it and be done
        if (!is_array($NewValue)) {
            return [$this->normalizeSingleValueToItemId($NewValue, $Field)];
        }

        # empty arrays are valid without any further checking
        if (count($NewValue) == 0) {
            return [];
        }

        # see if any of the elements in our array are non-numeric strings,
        # indicating that item IDs are in the array keys
        $IsStringArray = false;
        foreach ($NewValue as $Value) {
            if (is_string($Value) && !is_numeric($Value)) {
                $IsStringArray = true;
                break;
            }
        }

        # if this is a single-element array where the first key is zero
        # and the first value is numeric, then interpret that first
        # value as an ItemId
        if ((count($NewValue) == 1)
                && (key($NewValue) == 0)
                && is_numeric(current($NewValue))) {
            $ValuesArePlaceholders = false;
        } else {
            # otherwise, see if all the values in the array are just "1",
            # indicating that item IDs are in the array keys
            $ValuesArePlaceholders = true;
            foreach ($NewValue as $Value) {
                if ($Value != "1") {
                    $ValuesArePlaceholders = false;
                    break;
                }
            }
        }

        # if necessary, get the item IDs out of array keys
        if ($IsStringArray || $ValuesArePlaceholders) {
            $NewValue = array_keys($NewValue);
        }

        # apply our single value normalizer to the given values
        return array_map(
            function ($Item) use ($Field) {
                return $this->normalizeSingleValueToItemId($Item, $Field);
            },
            $NewValue
        );
    }

    /**
     * Convert a single value to an item ID.
     * @param mixed $NewValue single item ID or single object
     * @param MetadataField $Field Metadata field associated with value.
     * @return int valid item ID
     * @throws InvalidArgumentException when the value passed cannot be
     *   converted to an item ID
     */
    private function normalizeSingleValueToItemId($NewValue, MetadataField $Field): int
    {
        # handle single numeric value
        $ObjectType = $Field->getClassForValues();
        if (is_numeric($NewValue) && is_string($ObjectType)) {
            if (!$ObjectType::itemExists($NewValue)) {
                throw new Exception("Invalid item ID provided ".$NewValue);
            }
            return (int) $NewValue;
        }

        # handle object value
        if (is_object($NewValue) && is_string($ObjectType)) {
            if (!$NewValue instanceof $ObjectType) {
                throw new InvalidArgumentException(
                    "Invalid object provided, must be an instance of \"".$ObjectType."\"."
                );
            }
            if (!method_exists($NewValue, "id")) {
                throw new InvalidArgumentException(
                    "Invalid object provided, does not have id() method."
                );
            }
            return $NewValue->id();
        }

        # handle string value if we have a lookup method for it
        if (is_string($NewValue)) {
            $Factory = $Field->getFactory();
            if (is_object($Factory) && method_exists($Factory, "getItemIdByName")) {
                $ItemId = $Factory->getItemIdByName($NewValue);
                if ($ItemId === false) {
                    throw new InvalidArgumentException(
                        "Invalid string value provided (\"".$NewValue."\")."
                    );
                }
                return $ItemId;
            }
        }

        # if it's anything else, it wasn't valid
        throw new InvalidArgumentException(
            "Value provided was not an item ID "
            ."or an instance of ".$ObjectType
        );
    }

    /**
     * Update modification timestamp for specified field.
     * @param MetadataField $Field Field to update timestamp for.
     * @return void
     */
    protected function updateModificationTimestampForField(MetadataField $Field): void
    {
        $User = User::getCurrentUser();
        $UserId = $User->isLoggedIn() ? $User->id() : -1;
        $this->DB->query(
            "DELETE FROM RecordFieldTimestamps "
            ."WHERE RecordId=".$this->Id." AND FieldId=".$Field->id()
        );
        $this->DB->query(
            "INSERT INTO RecordFieldTimestamps (RecordId,FieldId,ModifiedBy,Timestamp) "
            ."VALUES (".$this->Id.",".$Field->id().",".$UserId.",NOW())"
        );
    }

    /**
     * Normalize metadata field argument to MetadataField object.
     * @param int|string|MetadataField $Field Field argument to normalize.
     * @return MetadataField Normalized argument value.
     */
    protected function normalizeFieldArgument($Field): MetadataField
    {
        return ($Field instanceof MetadataField) ? $Field
                : $this->getSchema()->getField($Field);
    }

    /**
     * Set the database access values (table name, ID column name, name column
     * name) for specified class.This may be overridden in a child class, if
     * different values are needed.
     * @param string $ClassName Class to set values for.
     * @return void
     */
    protected static function setDatabaseAccessValues(string $ClassName): void
    {
        if (!isset(self::$ItemIdColumnNames[$ClassName])) {
            self::$ItemIdColumnNames[$ClassName] = "RecordId";
            self::$ItemNameColumnNames[$ClassName] = null;
            self::$ItemTableNames[$ClassName] = "Records";
        }
    }
}
