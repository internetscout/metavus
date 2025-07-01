<?PHP
#
#   FILE:  MetadataSchema.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\HtmlOptionList;
use ScoutLib\ItemFactory;
use ScoutLib\StdLib;
use SimpleXMLElement;
use XMLWriter;

/**
 * Metadata schema (in effect a Factory class for MetadataField).
 */
class MetadataSchema extends ItemFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    # metadata field base types
    # (must parallel MetadataFields.FieldType declaration in install/CreateTables.sql
    #        and MetadataField::$FieldTypeDBEnums declaration below)
    const MDFTYPE_TEXT =            1;
    const MDFTYPE_PARAGRAPH =       2;
    const MDFTYPE_NUMBER =          4;
    const MDFTYPE_DATE =            8;
    const MDFTYPE_TIMESTAMP =       16;
    const MDFTYPE_FLAG =            32;
    const MDFTYPE_TREE =            64;
    const MDFTYPE_CONTROLLEDNAME =  128;
    const MDFTYPE_OPTION =          256;
    const MDFTYPE_USER =            512;
    const MDFTYPE_IMAGE =           1024;
    const MDFTYPE_FILE =            2048;
    const MDFTYPE_URL =             4096;
    const MDFTYPE_POINT =           8192;
    const MDFTYPE_REFERENCE =       16384;
    const MDFTYPE_EMAIL =           32768;
    const MDFTYPE_SEARCHPARAMETERSET = 65536;

    # types of field ordering
    const MDFORDER_DISPLAY =  1;
    const MDFORDER_EDITING =  2;
    const MDFORDER_ALPHABETICAL =  3;

    # error status codes
    const MDFSTAT_OK =                 1;
    const MDFSTAT_ERROR =              2;
    const MDFSTAT_DUPLICATENAME =      4;
    const MDFSTAT_DUPLICATEDBCOLUMN =  8;
    const MDFSTAT_FIELDDOESNOTEXIST =  16;
    const MDFSTAT_ILLEGALNAME =        32;
    const MDFSTAT_DUPLICATELABEL =     64;
    const MDFSTAT_ILLEGALLABEL =       128;

    # special schema IDs
    const SCHEMAID_DEFAULT = 0;
    const SCHEMAID_RESOURCES = 0;
    const SCHEMAID_USER = 1;
    const SCHEMAID_USERS = 1;

    # resource names
    const RESOURCENAME_DEFAULT = "Resource";
    const RESOURCENAME_USER = "User";

    # item class name
    const ITEMCLASSNAME_DEFAULT = "Metavus\\Record";

    # names used for display and edit orders
    const ORDER_DISPLAY_NAME = "Display";
    const ORDER_EDIT_NAME = "Edit";

    # maximum option list size for GetFieldsAsOptionList
    const MAX_OPT_LIST_SIZE = 20;

    /**
     * Object constructor, used to load an existing schema.(Use
     * MetadataSchema::create() to create a new schema.)
     * @param int $SchemaId ID of schema.Schema IDs are numerical, except for
     *       two special values SCHEMAID_DEFAULT and SCHEMAID_USER.(OPTIONAL,
     *       defaults to SCHEMAID_DEFAULT)
     * @see MetadataSchema::create()
     * @throws InvalidArgumentException If specified schema ID is invalid.
     * @throws Exception If a standard field mapping is found that does not have
     *       a valid schema/field ID combination.
     */
    public function __construct(int $SchemaId = self::SCHEMAID_DEFAULT)
    {
        # set up item factory base class
        parent::__construct(
            "Metavus\\MetadataField",
            "MetadataFields",
            "FieldId",
            "FieldName",
            false,
            "SchemaId = ".intval($SchemaId),
            "Metavus\\MetadataField::getField"
        );

        # make sure specified schema ID is valid
        self::loadSchemaInfoCache();
        if (!isset(self::$SchemaInfoCache[$SchemaId])) {
            throw new InvalidArgumentException("Attempt to load metadata schema"
                    ." with invalid ID (".$SchemaId.") at "
                    .StdLib::getMyCaller().".");
        }

        # load schema info from cache
        self::loadFieldMappingsCache();
        $Info = self::$SchemaInfoCache[$SchemaId];
        $this->Id = $SchemaId;
        $this->AuthoringPrivileges = new PrivilegeSet($Info["AuthoringPrivileges"]);
        $this->EditingPrivileges = new PrivilegeSet($Info["EditingPrivileges"]);
        $this->ViewingPrivileges = new PrivilegeSet($Info["ViewingPrivileges"]);
        $this->ViewPage = $Info["ViewPage"];
        if (!isset(self::$FieldMappings[$this->Id])) {
            self::$FieldMappings[$this->Id] = [];
        }

        # set up database convenience method parameters
        $this->DB->setValueUpdateParameters(
            "MetadataSchemas",
            "SchemaId = ".intval($SchemaId),
            self::$SchemaInfoCache[$SchemaId]
        );
    }

    /**
     * Get name (string) for constant.If there are multiple constants
     * defined with the same value, the first constant found with a name that
     * matches the prefix (if supplied) is returned.
     * @param int $Value Constant value.
     * @param string $Prefix Prefix to look for at beginning of name.Needed
     *       when there may be multiple constants with the same value.(OPTIONAL)
     * @return string|null Constant name or NULL if no matching value found.
     */
    public static function getConstantName($Value, ?string $Prefix = null)
    {
        # retrieve all constants for class
        $Reflect = new ReflectionClass(get_called_class());
        $Constants = $Reflect->getConstants();

        # for each constant
        foreach ($Constants as $CName => $CValue) {
            # if value matches and prefix (if supplied) matches
            if (($CValue == $Value)
                    && (($Prefix === null) || (strpos($CName, $Prefix) === 0))) {
                # return name to caller
                return $CName;
            }
        }

        # report to caller that no matching constant was found
        return null;
    }

    /**
     * Create new metadata schema.
     * @param string $Name Schema name.
     * @param PrivilegeSet $AuthorPrivs PrivilegeSet required for authoring.
     *       (OPTIONAL, defaults to all users)
     * @param PrivilegeSet $EditPrivs PrivilegeSet required for editing.(OPTIONAL,
     *       defaults to all users)
     * @param PrivilegeSet $ViewPrivs PrivilegeSet required for viewing.(OPTIONAL,
     *       defaults to all users)
     * @param string $ViewPage The page used to view the full record for a
     *       resource.If "$ID" shows up in the parameter, it will be replaced by
     *       the resource ID when viewing the resource.(OPTIONAL)
     * @param string $ResourceName User-readable name for resources for which
     *       the schema will be used.(OPTIONAL, defaults to singular version
     *       of schema name)
     * @return MetadataSchema New schema object.
     */
    public static function create(
        string $Name,
        ?PrivilegeSet $AuthorPrivs = null,
        ?PrivilegeSet $EditPrivs = null,
        ?PrivilegeSet $ViewPrivs = null,
        string $ViewPage = "",
        ?string $ResourceName = null
    ): MetadataSchema {

        # supply privilege settings if none provided
        if ($AuthorPrivs === null) {
            $AuthorPrivs = new PrivilegeSet();
        }
        if ($EditPrivs === null) {
            $EditPrivs = new PrivilegeSet();
        }
        if ($ViewPrivs === null) {
            $ViewPrivs = new PrivilegeSet();
        }

        # add schema to database
        $DB = new Database();
        if (strtoupper($Name) == "RESOURCES") {
            $Id = self::SCHEMAID_DEFAULT;
        } elseif (strtoupper($Name) == "USER") {
            $Id = self::SCHEMAID_USER;
        } else {
            $Id = (int)$DB->queryValue("SELECT SchemaId FROM MetadataSchemas"
                ." ORDER BY SchemaId DESC LIMIT 1", "SchemaId") + 1;
        }
        $DB->query("INSERT INTO MetadataSchemas"
                ." (SchemaId, Name, ViewPage,"
                ." AuthoringPrivileges, EditingPrivileges, ViewingPrivileges)"
                ." VALUES (".intval($Id).","
                ."'".addslashes($Name)."',"
                ."'".$DB->escapeString($ViewPage)."',"
                ."'".$DB->escapeString($AuthorPrivs->data())."',"
                ."'".$DB->escapeString($EditPrivs->data())."',"
                ."'".$DB->escapeString($ViewPrivs->data())."')");

        # clear data caches so they will be reloaded
        self::clearStaticCaches();

        # construct the new schema
        $Schema = new MetadataSchema($Id);

        # set schema name if none supplied
        if (!strlen($Name)) {
            $Schema->name("Metadata Schema ".$Id);
        }

        # set the resource name if one is supplied
        if ($ResourceName === null) {
            $ResourceName = StdLib::singularize($Name);
        }

        $Schema->resourceName($ResourceName);

        # create display and edit orders
        MetadataFieldOrder::createWithOrder($Schema, self::ORDER_DISPLAY_NAME, []);
        MetadataFieldOrder::createWithOrder($Schema, self::ORDER_EDIT_NAME, []);

        # return the new schema
        return $Schema;
    }

    /**
     * Destroy metadata schema.Schema may no longer be used after this
     * method is called.
     * @return void
     */
    public function delete(): void
    {
        # delete resources associated with schema
        $RFactory = new RecordFactory($this->Id);
        $ResourceIds = $RFactory->getItemIds();

        foreach ($ResourceIds as $ResourceId) {
            $Resource = new Record($ResourceId);
            $Resource->destroy();
        }

        # unmap all the mapped fields
        self::loadFieldMappingsCache();
        $MappedNames = array_keys(self::$FieldMappings[$this->Id]);

        foreach ($MappedNames as $MappedName) {
            $this->stdNameToFieldMapping((string)$MappedName, false);
        }

        # delete fields associated with schema
        $Fields = $this->getFields(null, null, true, true);

        foreach (array_keys($Fields) as $FieldId) {
            $this->dropField($FieldId);
        }

        # delete metadata field orders associated with schema
        foreach (MetadataFieldOrder::getOrdersForSchema($this) as $Order) {
            $Order->delete();
        }

        # remove schema info from database
        $this->DB->query("DELETE FROM MetadataSchemas WHERE SchemaId = ".intval($this->Id));

        # clear data caches so they will be reloaded
        self::clearStaticCaches();
    }

    /**
     * Check with schema exists with specified ID.
     * @param int $SchemaId ID to check.
     * @return bool TRUE if schema exists with specified ID, otherwise FALSE.
     */
    public static function schemaExistsWithId(int $SchemaId): bool
    {
        $DB = new Database();
        $DB->query("SELECT * FROM MetadataSchemas"
                ." WHERE SchemaId = ".intval($SchemaId));
        return ($DB->numRowsSelected() > 0) ? true : false;
    }

    /**
     * Get schema ID.Schema IDs are numerical, with two special
     * values SCHEMAID_DEFAULT and SCHEMAID_USER.
     * @return int Current schema ID.
     */
    public function id(): int
    {
        # return value to caller
        return intval($this->Id);
    }

    /**
     * Get/set name of schema.
     * @param string $NewValue New name for schema.(OPTIONAL)
     * @return string Current schema name.
     */
    public function name(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            self::clearStaticCaches();
        }
        return $this->DB->updateValue("Name", $NewValue);
    }

    /**
     * Get/set abbreviated name of schema.
     * The abbreviated name is one letter long, usually used
     * by tag names.
     * @param string $NewValue New abbreviated name for schema.(OPTIONAL)
     * @return string Current schema abbreviated name.
     */
    public function abbreviatedName(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            self::clearStaticCaches();
        }
        $AName = $this->DB->updateValue("AbbreviatedName", $NewValue);
        if (!strlen($AName)) {
            $AName = strtoupper(substr($this->name(), 0, 1));
        }
        return $AName;
    }

    /**
     * Get/set name of resources using this schema.
     * @param string $NewValue New resource name for schema.(OPTIONAL)
     * @return string Returns the current resource name.
     */
    public function resourceName(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            self::clearStaticCaches();
        }
        $RName = $this->DB->updateValue("ResourceName", $NewValue);
        if (!strlen($RName)) {
            $RName = self::RESOURCENAME_DEFAULT;
        }
        return $RName;
    }

    /**
     * Get fully-qualified name of class for items that use this schema.
     * @return string Class name, with any namespace qualifier.
     */
    public function getItemClassName(): string
    {
        $FQClassName = $this->DB->updateValue("ItemClassName");
        if (!strlen($FQClassName)) {
            $FQClassName = self::ITEMCLASSNAME_DEFAULT;
            $this->setItemClassName($FQClassName);
        }
        return $FQClassName;
    }

    /**
     * Set fully-qualified name of class for items that use this schema.
     * @param string $NewValue Class name, with any namespace qualifier.
     */
    public function setItemClassName(string $NewValue): void
    {
        self::clearStaticCaches();
        $this->DB->updateValue("ItemClassName", $NewValue);
    }

    /**
     * Gets or sets the default sort field for this schema.
     * Used to determine the default field to use when sorting search results.
     * @param int|false $NewValue The new default sort field id.
     *     False can be passed to clear the default sort field. (OPTIONAL)
     * @return int|false Returns the id of the field to sort with if available.
     *     Otherwise, returns false.
     */
    public function defaultSortField($NewValue = null)
    {
        if ($NewValue !== null) {
            # get valid possible values for the default sorting field
            $PossibleSortFields = $this->getSortFields();

            # check if the provided field exists and is a valid sorting field
            if ($NewValue !== false && (!$this->fieldExists($NewValue) ||
                !array_key_exists($NewValue, $PossibleSortFields))) {
                throw new Exception("Invalid field provided. Field id: " . $NewValue);
            }

            self::clearStaticCaches();
        }

        return $this->DB->updateIntValue("DefaultSortField", $NewValue);
    }

    /**
    * Get the URL of the page for viewing resources that belong to this schema.
    * @return string The URL of the view page.
    */
    public function getViewPage(): string
    {
        return $this->DB->updateValue("ViewPage");
    }

    /**
     * Set the URL of the page for viewing resources that belong to this schema.
     * @param string $NewValue The new URL to set for the view page.
     */
    public function setViewPage(string $NewValue): void
    {
        self::clearStaticCaches();
        $this->DB->updateValue("ViewPage", $NewValue);
    }

    /**
    * Get the URL of the page for editing resources that belong to this schema.
    * @return string The URL of the edit page.
    */
    public function getEditPage(): string
    {
        return $this->DB->updateValue("EditPage");
    }

    /**
     * Set the URL of the page for editing resources that belong to this schema.
     * @param string $NewValue The new URL to set for the edit page.
     */
    public function setEditPage(string $NewValue): void
    {
        self::clearStaticCaches();
        $this->DB->updateValue("EditPage", $NewValue);
    }

    /**
     * Get/set whether or not users can post comments to this schema
     * @param bool $NewValue New CommentsEnabled status for schema.(OPTIONAL)
     * @return bool Current schema CommentsEnabled value
     */
    public function commentsEnabled($NewValue = null): bool
    {
        return $this->DB->updateValue("CommentsEnabled", $NewValue);
    }

    /**
     * Get/set privileges that allowing authoring resources with this schema.
     * @param PrivilegeSet $NewValue New PrivilegeSet value.(OPTIONAL)
     * @return PrivilegeSet PrivilegeSet that allows authoring.
     */
    public function authoringPrivileges(?PrivilegeSet $NewValue = null): PrivilegeSet
    {
        # if new privileges supplied
        if ($NewValue !== null) {
            # store new privileges in database
            self::clearStaticCaches();
            $this->DB->updateValue("AuthoringPrivileges", $NewValue->data());
            $this->AuthoringPrivileges = $NewValue;
        }

        # return current value to caller
        return $this->AuthoringPrivileges;
    }

    /**
     * Get/set privileges that allowing editing resources with this schema.
     * @param PrivilegeSet $NewValue New PrivilegeSet value.(OPTIONAL)
     * @return PrivilegeSet PrivilegeSet that allows editing.
     */
    public function editingPrivileges(?PrivilegeSet $NewValue = null): PrivilegeSet
    {
        # if new privileges supplied
        if ($NewValue !== null) {
            # store new privileges in database
            self::clearStaticCaches();
            $this->DB->updateValue("EditingPrivileges", $NewValue->data());
            $this->EditingPrivileges = $NewValue;
        }

        # return current value to caller
        return $this->EditingPrivileges;
    }

    /**
     * Get/set privileges that allowing viewing resources with this schema.
     * @param PrivilegeSet $NewValue New PrivilegeSet value.(OPTIONAL)
     * @return PrivilegeSet Privilege set object that allows viewing.
     */
    public function viewingPrivileges(?PrivilegeSet $NewValue = null): PrivilegeSet
    {
        # if new privileges supplied
        if ($NewValue !== null) {
            # store new privileges in database
            self::clearStaticCaches();
            $this->DB->updateValue("ViewingPrivileges", $NewValue->data());
            $this->ViewingPrivileges = $NewValue;
        }

        # return current value to caller
        return $this->ViewingPrivileges;
    }

    /**
     * Determine if the given user can author resources using this schema.
     * The result of this method can be modified via the
     * EVENT_RESOURCE_AUTHOR_PERMISSION_CHECK event.
     * @param User $User User to check.
     * @return bool TRUE if the user can author resources and FALSE otherwise
     */
    public function userCanAuthor(User $User): bool
    {
        # get authoring privilege set for schema
        $AuthorPrivs = $this->authoringPrivileges();

        # user can author if privileges are greater than resource set
        $CanAuthor = $AuthorPrivs->meetsRequirements($User);

        # allow plugins to modify result of permission check
        $SignalResult = (ApplicationFramework::getInstance())->signalEvent(
            "EVENT_RESOURCE_AUTHOR_PERMISSION_CHECK",
            [
                "Resource" => null,
                "User" => $User,
                "CanAuthor" => $CanAuthor,
                "Schema" => $this,
            ]
        );
        $CanAuthor = $SignalResult["CanAuthor"];

        # report back to caller whether user can author field
        return $CanAuthor;
    }

    /**
     * Determine if the given user can edit resources using this schema.
     * The result of this method can be modified via the
     * EVENT_RESOURCE_EDIT_PERMISSION_CHECK event.
     * @param User $User User to check.
     * @return bool TRUE if the user can edit resources and FALSE otherwise
     */
    public function userCanEdit(User $User): bool
    {
        # get editing privilege set for schema
        $EditPrivs = $this->editingPrivileges();

        # user can edit if privileges are greater than resource set
        $CanEdit = $EditPrivs->meetsRequirements($User);

        # allow plugins to modify result of permission check
        $SignalResult = (ApplicationFramework::getInstance())->signalEvent(
            "EVENT_RESOURCE_EDIT_PERMISSION_CHECK",
            [
                "Resource" => null,
                "User" => $User,
                "CanEdit" => $CanEdit,
                "Schema" => $this,
            ]
        );
        $CanEdit = $SignalResult["CanEdit"];

        # report back to caller whether user can edit field
        return $CanEdit;
    }

    /**
     * Determine if the given user can view resources using this schema.
     * The result of this method can be modified via the
     * EVENT_RESOURCE_VIEW_PERMISSION_CHECK event.
     * @param User $User User to check.
     * @return bool TRUE if the user can view resources and FALSE otherwise
     */
    public function userCanView(User $User): bool
    {
        # get viewing privilege set for schema
        $ViewPrivs = $this->viewingPrivileges();

        # user can view if privileges are greater than resource set
        $CanView = $ViewPrivs->meetsRequirements($User);

        # allow plugins to modify result of permission check
        $SignalResult = (ApplicationFramework::getInstance())->signalEvent(
            "EVENT_RESOURCE_VIEW_PERMISSION_CHECK",
            [
                "Resource" => null,
                "User" => $User,
                "CanView" => $CanView,
                "Schema" => $this,
            ]
        );
        $CanView = $SignalResult["CanView"];

        # report back to caller whether user can view field
        return $CanView;
    }

    /**
     * Compute a user class (opaque string) based on the privileges of the
     * specified user and which privilege flags are required by the schema for
     * viewing records.
     * @param User $User User to compute a user class for
     * @return string user class
     */
    public function computeUserClass(User $User): string
    {
        # put the anonymous user into their own user class, otherwise
        # use the UserId for a key into the ClassCache
        $UserId = $User->isAnonymous() ? "XX-ANON-XX" : $User->id();

        $CacheKey = $this->Id.".".$UserId;

        # check if we have a cached UserClass for this User
        if (!isset(self::$UserClassCache[$CacheKey])) {
            # assemble a list of the privilege flags (PRIV_SYSADMIN,
            # etc) that are checked when evaluating the UserCanView for
            # all fields in this schema
            $RelevantPerms = [];

            foreach ($this->getFields() as $Field) {
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
     * Get the resource ID GET parameter for the view page for the schema.
     * @return string|null Returns the resource ID GET parameter for the view page
     *       for the schema or NULL if GET parameter could not be parsed.
     */
    public function getViewPageIdParameter()
    {
        # get the query/GET parameters for the view page
        $Query = parse_url($this->getViewPage(), PHP_URL_QUERY);

        # the URL couldn't be parsed
        if (!is_string($Query)) {
            return null;
        }

        # parse the GET parameters out of the query string
        parse_str($Query, $GetVars);

        # search for the ID parameter
        $Result = array_search("\$ID", $GetVars);

        return $Result !== false ? (string)$Result : null;
    }

    /**
     * Determine if a path matches the view page path for the schema.For the two
     * to match, the path GET parameters must contain at least the GET parameters
     * in the view page's GET parameters, and all of the required GET parameters
     * must match the ones in the view page, unless the parameter is a variable
     * in the view page path.The path's GET parameters may contain more
     * parameters.
     * @param string $Path Path to match against, e.g.,
     *     index.php?P=FullRecord&ID=123.
     * @return bool Returns TRUE if the path matches the view page path for the
     *     schema.
     */
    public function pathMatchesViewPage(string $Path): bool
    {
        # get the query/GET parameters for the view page
        $Query = parse_url($this->getViewPage(), PHP_URL_QUERY);

        # can't perform matching if the URL couldn't be parsed
        if (!is_string($Query)) {
            return false;
        }

        # parse the GET parameters out of the query string
        parse_str($Query, $GetVars);

        # now, get the query/GET parameters from the path given
        $PathQuery = parse_url($Path, PHP_URL_QUERY);

        # can't perform matching if the URL couldn't be parsed
        if (!is_string($PathQuery)) {
            return false;
        }

        # parse the GET parameters out of the path's query string
        parse_str($PathQuery, $PathGetVars);

        # make sure the given path GET parameters contain at least the GET
        # parameters from the view page and that all non-variable parameters are
        # equal.the path GET parameters may contain more, which is okay
        foreach ($GetVars as $GetVarName => $GetVarValue) {
            # there's a required parameter that is not included in the path GET
            # parameters
            if (!array_key_exists($GetVarName, $PathGetVars)) {
                return false;
            }

            # require the path's value to be equal to the view page's value if
            # the view page's value is not a variable,
            if ($PathGetVars[$GetVarName] != $GetVarValue &&
                (!is_string($GetVarValue) || !strlen($GetVarValue) || $GetVarValue[0] != "$")) {
                return false;
            }
        }

        # the path matches the view page path
        return true;
    }

    /**
     * Add new metadata field.
     * @param string $FieldName Name of new field.
     * @param int $FieldType Type of new field.
     * @param bool $Optional Whether setting a value for new field is optional when
     *       creating new records that use the field.(OPTIONAL, defaults to TRUE)
     * @param mixed $DefaultValue Initial default value for field.(OPTIONAL)
     * @return MetadataField|null New field object or NULL if field addition failed.
     */
    public function addField(
        string $FieldName,
        int $FieldType,
        bool $Optional = true,
        $DefaultValue = null
    ) {
        $this->clearErrorMessages();

        # create new field
        try {
            $Field = MetadataField::create(
                $this->id(),
                $FieldType,
                $FieldName,
                $Optional,
                $DefaultValue
            );
        } catch (Exception $Exception) {
            $this->logErrorMessage($Exception->getMessage());
            $Field = null;
        }

        # clear internal caches to make sure new field is recognized going forward
        $this->clearCaches();
        self::clearStaticCaches();

        # return new field to caller
        return $Field;
    }

    /**
     * Add new metadata fields from XML file.NewFields() can be used to
     * determine how many (or whether) new fields were added, and getErrorMessages()
     * can be used to determine what errors were * encountered.
     * @param string $FileName Name of XML file.
     * @param string $Owner Owner to set for new fields.(OPTIONAL, supply
     *       NULL to not set an owner)
     * @param bool $TestRun If TRUE, any new fields created are removed before
     *       the method returns.(OPTIONAL, defaults to FALSE)
     * @return bool TRUE if no errors were encountered in loading or
     *       parsing the XML file or adding fields, otherwise FALSE.
     * @see MetadataSchema::newFields()
     * @see MetadataSchema::errorMessages()
     */
    public function addFieldsFromXmlFile(
        string $FileName,
        $Owner = null,
        bool $TestRun = false
    ): bool {
        # clear loading status
        $this->NewFields = [];
        $this->clearErrorMessages();

        # check that file exists and is readable
        if (!file_exists($FileName)) {
            $this->logErrorMessage("Could not find XML file '".$FileName."'.");
            return false;
        } elseif (!is_readable($FileName)) {
            $this->logErrorMessage("Could not read from XML file '".$FileName."'.");
            return false;
        }

        # load XML from file
        libxml_use_internal_errors(true);
        $XmlData = simplexml_load_file($FileName);
        $Errors = libxml_get_errors();
        libxml_use_internal_errors(false);

        # if XML load failed
        if ($XmlData === false) {
            # retrieve XML error messages
            foreach ($Errors as $Err) {
                $ErrType = ($Err->level == LIBXML_ERR_WARNING) ? "Warning"
                        : (($Err->level == LIBXML_ERR_ERROR) ? "Error"
                        : "Fatal Error");
                $this->logErrorMessage("XML ".$ErrType.": ".$Err->message
                        ." (".$Err->file.":".$Err->line.",".$Err->column.")");
            }
        # else if no metadata fields found record error message
        } elseif (!count($XmlData->MetadataField)) {
            $this->logErrorMessage("No metadata fields found.");
        # else process metadata fields
        } else {
            # for each metadata field entry found
            $FieldsAdded = 0;
            $FieldIndex = 0;
            foreach ($XmlData->MetadataField as $FieldXml) {
                $FieldIndex++;

                # pull out field type if present
                if (isset($FieldXml->Type)) {
                    $FieldType = "Metavus\\MetadataSchema::".$FieldXml->Type;
                    if (!defined($FieldType)) {
                        $FieldType = "Metavus\\MetadataSchema::MDFTYPE_"
                                .strtoupper(str_replace(
                                    " ",
                                    "",
                                    (string)$FieldXml->Type
                                ));
                    }
                }

                # if required values are missing
                if (!isset($FieldXml->Name) || !isset($FieldXml->Type) ||
                    !isset($FieldType) || !defined($FieldType)) {
                    # add error message about required value missing
                    if (!isset($FieldXml->Name)) {
                        $this->logErrorMessage("Field name not found (MetadataField #"
                                .$FieldIndex.").");
                    }
                    if (!isset($FieldXml->Type) || !isset($FieldType) || !defined($FieldType)) {
                        $this->logErrorMessage("Valid type not found for field '"
                                .$FieldXml->Name."' (MetadataField #"
                                .$FieldIndex.").");
                    }
                # else if there is not already a field with this name
                } elseif (!$this->nameIsInUse(trim($FieldXml->Name))) {
                    # create new field
                    $Field = $this->addField($FieldXml->Name, constant($FieldType));

                    # if field creation failed
                    if ($Field === null) {
                        # add any error message to our error list
                        $ErrorMsgs = $this->getErrorMessages("addField");
                        foreach ($ErrorMsgs as $Msg) {
                            $this->logErrorMessage($Msg." (addField)");
                        }
                    } else {
                        # add field to list of created fields
                        $this->NewFields[$Field->id()] = $Field;

                        # assume no vocabulary to load
                        $VocabToLoad = null;

                        # for other field attributes
                        if (is_iterable($FieldXml) === false) {
                            throw new Exception("Field XML data is not iterable.");
                        }
                        foreach ($FieldXml as $MethodName => $Value) {
                            # if tags look valid and have not already been set
                            if (method_exists($Field, $MethodName) && ($MethodName != "Name") &&
                                ($MethodName != "Type")) {
                                # if tag indicates privilege set
                                if (preg_match("/^[a-z]+Privileges\$/i", $MethodName)) {
                                    # save element for later processing
                                    $PrivilegesToSet[$Field->id()][$MethodName] = $Value;
                                } else {
                                    # condense down any extraneous whitespace
                                    $Value = preg_replace("/\s+/", " ", trim($Value));

                                    // @phpstan-ignore-next-line
                                    $ArgType = StdLib::getArgumentType([$Field, $MethodName], 0);

                                    # convert string "FALSE" to boolean
                                    if ($ArgType == "bool"
                                            && strtoupper($Value) === "FALSE") {
                                            $Value = false;
                                    }

                                    # convert to array if needed
                                    if ($ArgType == "array") {
                                        $Value = [ $Value ];
                                    }

                                    # set value for field
                                    $Field->$MethodName($Value);
                                }
                            } elseif ($MethodName == "VocabularyFile") {
                                $VocabToLoad = (string)$Value;
                            }
                        }

                        # save the temp ID so that any privileges to set
                        #   can be mapped to the actual ID when the field is
                        #   made permanent
                        $TempId = $Field->id();

                        # make new field permanent
                        $Field->isTempItem(false);

                        # load any vocabularies
                        if ($VocabToLoad !== null) {
                            $Field->loadVocabulary($VocabToLoad);
                        }

                        # map privileges to set to the permanent field ID
                        if (isset($PrivilegesToSet) && isset($PrivilegesToSet[$TempId])) {
                            # copy the privileges over
                            $PrivilegesToSet[$Field->id()] =
                                $PrivilegesToSet[$TempId];

                            # remove the values for the temp ID
                            unset($PrivilegesToSet[$TempId]);
                        }
                    }
                }
            }

            # if we have schema-level privileges to set
            if (count($XmlData->SchemaPrivileges)) {
                foreach ($XmlData->SchemaPrivileges->children() as $PrivName => $PrivXml) {
                    # if our current value for this privset is empty,
                    # take the one from the file
                    if ($this->$PrivName()->comparisonCount() == 0) {
                        # extract the values to set from the XML
                        $Value = $this->convertXmlToPrivilegeSet($PrivXml);
                        # set the privilege
                        $this->$PrivName($Value);
                    }
                }
            }

            # if we have privileges to set
            if (isset($PrivilegesToSet)) {
                # for each field with privileges
                foreach ($PrivilegesToSet as $FieldId => $Privileges) {
                    # load the field for which to set the privileges
                    $Field = MetadataField::getField($FieldId);

                    # for each set of privileges for field
                    foreach ($Privileges as $MethodName => $Value) {
                        # convert privilege value
                        $Value = $this->convertXmlToPrivilegeSet($Value);

                        # if conversion failed
                        if ($Value === null) {
                            # add resulting error messages to our list
                            $ErrorMsgs = $this->getErrorMessages(
                                "convertXmlToPrivilegeSet"
                            );
                            foreach ($ErrorMsgs as $Msg) {
                                $this->logErrorMessage($Msg
                                        ." (convertXmlToPrivilegeSet)");
                            }
                        } else {
                            # set value for field
                            $Field->$MethodName($Value);
                        }
                    }
                }
            }

            # if errors were found during creation
            if ($this->thereAreErrorMessages() || $TestRun) {
                # remove any fields that were created
                foreach ($this->NewFields as $Field) {
                    $Field->drop();
                }
                $this->NewFields = [];
            } else {
                # set owner for new fields (if supplied)
                if ($Owner !== null) {
                    foreach ($this->NewFields as $Field) {
                        $Field->owner($Owner);
                    }
                }

                # if there were standard field mappings included
                if (isset($XmlData->StandardFieldMapping)) {
                    # for each standard field mapping found
                    foreach ($XmlData->StandardFieldMapping as $MappingXml) {
                        # if required values are supplied
                        if (isset($MappingXml->Name) && isset($MappingXml->StandardName)) {
                            # get ID for specified field
                            $FieldName = (string)$MappingXml->Name;
                            $StandardName = (string)$MappingXml->StandardName;
                            $FieldId = $this->getFieldIdByName($FieldName);

                            # if field ID was found
                            if ($FieldId !== false) {
                                # set standard field mapping
                                $this->stdNameToFieldMapping(
                                    $StandardName,
                                    $FieldId
                                );
                            } else {
                                # log error about field not found
                                $this->logErrorMessage("Field not found with name '"
                                        .$FieldName."' to map to standard field name '"
                                        .$StandardName."'.");
                            }
                        } else {
                            # log error about missing value
                            if (!isset($MappingXml->Name)) {
                                $this->logErrorMessage("Field name missing for standard"
                                        ." field mapping.");
                            }
                            if (!isset($MappingXml->StandardName)) {
                                $this->logErrorMessage("Standard field name missing for"
                                        ." standard field mapping.");
                            }
                        }
                    }
                }
            }
        }

        # report success (TRUE) or failure (FALSE) based on whether errors were recorded
        return $this->thereAreErrorMessages() ? false : true;
    }

    /**
     * Get new fields recently added (if any) via XML file.
     * @return array Array of fields recently added (MetadataField objects).
     * @see MetadataSchema::addFieldsFromXmlFile()
     */
    public function newFields(): array
    {
        return $this->NewFields;
    }

    /**
     * Add new metadata field based on supplied XML.The XML elements are method
     * names from the MetadataField object, with the values being passed in as the
     * parameter to that method.The <i>FieldName</i> and <i>FieldType</i>
     * elements are required.Values for elements/methods that would normally be
     * called with constants in PHP can be constant names.
     * @param string $Xml Block of XML containing field description.
     * @return MetadataField|null New MetadataField object or NULL if addition failed.
     */
    public function addFieldFromXml(string $Xml)
    {
        # assume field addition will fail
        $Field = null;

        # add XML prefixes if needed
        $Xml = trim($Xml);
        if (!preg_match("/^<\?xml/i", $Xml)) {
            if (!preg_match("/^<document>/i", $Xml)) {
                $Xml = "<document>".$Xml."</document>";
            }
            $Xml = "<?xml version='1.0'?".">".$Xml;
        }

        # parse XML
        $XmlData = simplexml_load_string($Xml);

         # if required values are present
        if (($XmlData instanceof SimpleXMLElement) && isset($XmlData->Name) &&
            isset($XmlData->Type) && constant("Metavus\MetadataSchema::".$XmlData->Type)) {
            # create the metadata field
            $Field = $this->addField(
                $XmlData->Name,
                constant("Metavus\MetadataSchema::".$XmlData->Type)
            );

            # if field creation succeeded
            if ($Field != null) {
                # for other field attributes
                foreach ($XmlData as $MethodName => $Value) {
                    # if they look valid and have not already been set
                    if (method_exists($Field, $MethodName) && ($MethodName != "Name") &&
                        ($MethodName != "Type")) {
                        # if tag indicates privilege set
                        if (preg_match("/^[a-z]+Privileges\$/i", $MethodName)) {
                            # save element for later processing
                            $PrivilegesToSet[$MethodName] = $Value;
                        } else {
                            # condense down any extraneous whitespace
                            $Value = preg_replace("/\s+/", " ", trim($Value));

                            # set value for field
                            $Field->$MethodName($Value);
                        }
                    }
                }

                # make new field permanent
                $Field->isTempItem(false);

                # if we have privileges to set
                if (isset($PrivilegesToSet)) {
                    # for each set of privileges for field
                    foreach ($PrivilegesToSet as $MethodName => $Value) {
                        # convert privilege value
                        $Value = $this->convertXmlToPrivilegeSet($Value);

                        # if conversion failed
                        if ($Value === null) {
                            # add resulting error messages to our list
                            $ErrorMsgs = $this->getErrorMessages(
                                "convertXmlToPrivilegeSet"
                            );
                            foreach ($ErrorMsgs as $Msg) {
                                $this->logErrorMessage($Msg
                                        ." (convertXmlToPrivilegeSet)");
                            }
                        } else {
                            # set value for field
                            $Field->$MethodName($Value);
                        }
                    }
                }
            }
        }

        # return new field (if any) to caller
        return $Field;
    }

    /**
     * Delete metadata field and all associated data.
     * @param int|string $FieldId ID or name of field to be deleted.
     * @return bool TRUE if delete succeeded, otherwise FALSE.
     * @throws Exception If field is mapped as a standard field.
     */
    public function dropField($FieldId): bool
    {
        # check that field exists
        if (!$this->fieldExists($FieldId)) {
            return false;
        }
        $Field = $this->getField($FieldId);

        # verify that this field is not mapped prior to dropping it
        self::loadFieldMappingsCache();
        if (isset(self::$FieldMappings[$this->Id])) {
            foreach (self::$FieldMappings[$this->Id] as $Name => $FieldId) {
                if ($Field->id() == $FieldId) {
                    throw new Exception("Attempt to delete ".$Field->name()
                            .", which is mapped as the standard ".$Name
                            ." in the ".$this->name()." Schema.");
                }
            }
        }

        (ApplicationFramework::getInstance())->signalEvent(
            "EVENT_PRE_FIELD_DELETE",
            ["FieldId" => $Field->id()]
        );

        $Field->drop();

        $this->clearCaches();
        self::clearStaticCaches();

        return true;
    }

    /**
     * Retrieve metadata field.
     * @param mixed $FieldId ID or name of field.
     * @return MetadataField MetadataField object with the specified ID or name.
     * @throws InvalidArgumentException If the provided field name is invalid.
     * @throws InvalidArgumentException If the field comes from a different schema.
     */
    public function getField($FieldId): MetadataField
    {
        $FieldId = static::getCanonicalFieldIdentifier($FieldId, $this->Id);

        # if field is not already loaded
        if (!isset(self::$FieldCache[$FieldId])) {
            self::$FieldCache[$FieldId] = MetadataField::getField($FieldId);
        }

        # error out if field was from a different schema
        $Field = self::$FieldCache[$FieldId];
        if ($Field->schemaId() != $this->Id) {
            throw new InvalidArgumentException(
                "Attempt to retrieve a field from a different schema"
                ." (expected: ".$this->Id.", found: ".$Field->schemaId().")."
            );
        }

        return $Field;
    }

    /**
     * Retrieve metadata field ID by name.
     * @param string $FieldName Field name.
     * @param bool $IgnoreCase If TRUE, case is ignore when matching field names.
     * @return int|false ID of requested MetadataField or FALSE if no field
     *      found with specified name.
     */
    public function getFieldIdByName(string $FieldName, bool $IgnoreCase = false)
    {
        return $this->getItemIdByName($FieldName, $IgnoreCase);
    }

    /**
     * Check whether field with specified name or ID exists.
     * @param string|int $Field Name or ID of field.
     * @return boolean TRUE if field with specified name exists, otherwise FALSE.
     */
    public function fieldExists($Field): bool
    {
        return is_numeric($Field)
                ? $this->itemExists((int)$Field)
                : $this->nameIsInUse($Field);
    }

    /**
     * Check whether a field exists that has a name that would be normalized to
     * the same database column name as the name specified in the parameter.
     * @param string $FieldName Field name to check that when normalized for use
     *         as a database column does not collide with the name of any other
     *         field normalized to a database column.
     * @param bool $IgnoreCase Inherited from parent class ItemFactory, must
     *         be TRUE or an Exception will be thrown. $IgnoreCase must be TRUE,
     *         so case-insensitive string comparison is used, to prevent a
     *         field from being created whose name differs only by
     *         capitalization from that of an existing field.
     * @return bool TRUE if there is a field whose database column name is
     *         the same as the column name that would be assigned to the new
     *         field, otherwise return FALSE.
     * @throws InvalidArgumentException If IgnoreCase parameter is false.
     */
    public function nameIsInUse($FieldName, $IgnoreCase = true): bool
    {
        if ($IgnoreCase === false) {
            throw new InvalidArgumentException("IgnoreCase must be TRUE.");
        }

        # get the database column name for the named field
        $NormalizedFieldName = MetadataField::normalizeFieldNameForDB(
            $FieldName,
            $this->Id
        );

        # the ID of the field with the same column name will be NULL
        # if none is found
        if (is_null($this->getFieldIdByDbName($NormalizedFieldName))) {
            return false;
        }

        return true;
    }

    /**
     * Get the ID of the field in this schema, if any, whose database column
     * name case-insensitively matches the database column name specified
     * in the parameter.
     * @param string $DbFieldName database column name with the database column
     *         names for each field in the schema.
     * @return int|NULL ID of field in this schema with a database column name
     *         that case-insensitively matches the database column name that
     *         is given in the parameter. Return NULL if no such field exists.
     */
    public function getFieldIdByDbName(string $DbFieldName): ?int
    {
        # true is passed to getFieldNames so that disabled fields are not
        # ignored
        $Fields = $this->getFields(null, null, true);

        # column name comparison is case-insensitive
        $DbFieldName = strtolower($DbFieldName);

        # check for potential column name collisions
        foreach ($Fields as $Field) {
            $FieldName = strtolower($Field->dBFieldName());

            if ($DbFieldName == $FieldName) {
                return $Field->id();
            }
        }
        return null;
    }

    /**
     * Check whether some field of the specified type exists and is viewable
     * by the current user.
     * @param int $Type Field type (MDFTYPE_ constant).
     * @return bool TRUE if exists and is viewable, otherwise FALSE.
     */
    public function aFieldIsViewableOfType(int $Type): bool
    {
        $User = User::getCurrentUser();
        foreach ($this->getFields($Type) as $Field) {
            if (!$Field->enabled()) {
                continue;
            }
            if (!$Field->viewingPrivileges()->meetsRequirements($User)) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Retrieve array of fields.
     * @param int|array $FieldTypes MetadataField types (MDFTYPE_ values) to
     *      retrieve, either as an array or a bitmask, or NULL to return
     *      all types of fields.(OPTIONAL, defaults to NULL)
     * @param int $OrderType Order in which to return fields (MDFORDER_ value).
     *      (OPTIONAL, defaults to NULL which indicates no particular order)
     * @param bool $IncludeDisabledFields TRUE to include disabled fields.
     *      (OPTIONAL, defaults to FALSE)
     * @param bool $IncludeTempFields TRUE to include temporary fields (in
     *      the process of being created/edited).(OPTIONAL, defaults to FALSE)
     * @return array Array of MetadataField objects, with IDs for array index.
     */
    public function getFields(
        $FieldTypes = null,
        ?int $OrderType = null,
        bool $IncludeDisabledFields = false,
        bool $IncludeTempFields = false
    ): array {
        # normalize field type parameter if necessary
        if (is_array($FieldTypes)) {
            $NewFieldTypes = 0;
            foreach ($FieldTypes as $FieldType) {
                $NewFieldTypes |= $FieldType;
            }
            $FieldTypes = $NewFieldTypes;
        }

        # if we have a cached value, use that
        $CacheKey = $this->Id.":"
                .(is_null($FieldTypes) ? "_" : $FieldTypes).":"
                .(is_null($OrderType) ? "_" : $OrderType).":"
                .($IncludeDisabledFields ? "T" : "F").":"
                .($IncludeTempFields ? "T" : "F");
        if (isset(self::$GetFieldsCache[$CacheKey])) {
            return self::$GetFieldsCache[$CacheKey];
        }

        # create empty array to pass back
        $Fields = [];

        # make sure field info is loaded
        self::loadFieldInfoCaches();

        if (!isset(self::$FieldsBySchema[$this->Id])) {
            return $Fields;
        }

        # iterate over all the fields
        foreach (self::$FieldsBySchema[$this->Id] as $FieldId) {
            $FieldInfo = self::$FieldInfoCache[$FieldId];

            # skip field if requested
            if ((!$FieldInfo["Enabled"] && !$IncludeDisabledFields) ||
                ($FieldId < 0 && !$IncludeTempFields)) {
                continue;
            }

            #1 if no types specified or this field is a specified type, include it
            if (($FieldTypes === null) || ($FieldInfo["Type"] & $FieldTypes)) {
                $Fields[$FieldId] = $this->getField($FieldId);
            }
        }

        # if field sorting requested
        if ($OrderType !== null) {
            # update field comparison ordering if not set yet
            if (!$this->fieldCompareOrdersSet()) {
                $this->updateFieldCompareOrders();
            }

            $this->FieldCompareType = $OrderType;

            # sort field array by requested order type
            uasort($Fields, [$this, "CompareFieldOrder"]);
        }

        # cache value for reuse
        self::$GetFieldsCache[$CacheKey] = $Fields;

        # return array of field objects to caller
        return $Fields;
    }

    /**
     * Retrieve field names.
     * @param int $FieldTypes MetadataField types (MDFTYPE_ values) to retrieve, ORed
     *       together, or NULL to return all types of fields.(OPTIONAL, defaults
     *       to NULL)
     * @param int $OrderType Order in which to return fields (MDFORDER_ value).
     *       (OPTIONAL, defaults to NULL which indicates no particular order)
     * @param bool $IncludeDisabledFields TRUE to include disabled fields.(OPTIONAL,
     *       defaults to FALSE)
     * @param bool $IncludeTempFields TRUE to include temporary fields (in the process
     *       of being created/edited).(OPTIONAL, defaults to FALSE)
     * @return Array of field names, with field IDs for array index.
     *
     */
    public function getFieldNames(
        ?int $FieldTypes = null,
        ?int $OrderType = null,
        bool $IncludeDisabledFields = false,
        bool $IncludeTempFields = false
    ): array {

        $Fields = $this->getFields(
            $FieldTypes,
            $OrderType,
            $IncludeDisabledFields,
            $IncludeTempFields
        );

        $FieldNames = [];
        foreach ($Fields as $Field) {
            $FieldNames[$Field->id()] = $Field->name();
        }

        return $FieldNames;
    }

    /**
     * Retrieve fields of specified type as HTML option list with field names
     * as labels and field IDs as value attributes.The first element on the list
     * will have a label of "--" and an ID of -1 to indicate no field selected.
     * @param string $OptionListName Value of option list "name" and "id" attributes.
     * @param int $FieldTypes Types of fields to return.(OPTIONAL - use NULL
     *       for all types)
     * @param int|array $SelectedFieldId ID or array of IDs of the currently-selected
     *       field(s).(OPTIONAL)
     * @param bool $IncludeNullOption Whether to include "no selection" (-1) option.
     *       (OPTIONAL - defaults to TRUE)
     * @param array $AddEntries An array of additional entries to include at the end of
     *       the option list, with option list values for the indexes and option list
     *       labels for the values.(OPTIONAL)
     * @param bool $AllowMultiple TRUE to allow multiple field selections
     * @param bool $Disabled If TRUE, field will not be editable.
     * @return string HTML for option list.
     */
    public function getFieldsAsOptionList(
        string $OptionListName,
        ?int $FieldTypes = null,
        $SelectedFieldId = null,
        bool $IncludeNullOption = true,
        ?array $AddEntries = null,
        bool $AllowMultiple = false,
        bool $Disabled = false
    ): string {

        # construct option list
        $OptList = $this->getFieldsAsOptionListObject(
            $OptionListName,
            $FieldTypes,
            $SelectedFieldId,
            $IncludeNullOption,
            $AddEntries,
            $AllowMultiple,
            $Disabled
        );

        # return option list HTML to caller
        return $OptList->getHtml();
    }

    /**
     * Retrieve fields as configured HtmlOptionList object.
     * @param string $OptionListName Value of option list "name" and "id" attributes.
     * @param int $FieldTypes Types of fields to return.(OPTIONAL - use NULL
     *       for all types)
     * @param int|array $SelectedFieldId ID or array of IDs of the currently-selected
     *       field(s).(OPTIONAL)
     * @param bool $IncludeNullOption Whether to include "no selection" (-1) option.
     *       (OPTIONAL - defaults to TRUE)
     * @param array $AddEntries An array of additional entries to include at the end of
     *       the option list, with option list values for the indexes and option list
     *       labels for the values.(OPTIONAL)
     * @param bool $AllowMultiple TRUE to allow multiple field selections
     * @param bool $Disabled If TRUE, field will not be editable.
     * @return HtmlOptionList object.
     */
    public function getFieldsAsOptionListObject(
        string $OptionListName,
        ?int $FieldTypes = null,
        $SelectedFieldId = null,
        bool $IncludeNullOption = true,
        ?array $AddEntries = null,
        bool $AllowMultiple = false,
        bool $Disabled = false
    ): HtmlOptionList {

        # retrieve requested fields
        $FieldNames = $this->getFieldNames($FieldTypes);

        # transform field names to labels
        foreach ($FieldNames as $FieldId => $FieldName) {
            $FieldNames[$FieldId] = $this->getField($FieldId)->getDisplayName();
        }

        # add in null entry if requested
        if ($IncludeNullOption) {
            $FieldNames = ["" => "--"] + $FieldNames;
        }

        # add additional entries if supplied
        if ($AddEntries) {
            $FieldNames = $FieldNames + $AddEntries;
        }

        $OptList = new HtmlOptionList($OptionListName, $FieldNames, $SelectedFieldId);
        $OptList->multipleAllowed($AllowMultiple);
        if ($AllowMultiple) {
            $OptList->size(min(self::MAX_OPT_LIST_SIZE, count($FieldNames)));
        }
        $OptList->disabled($Disabled);

        return $OptList;
    }

    /**
     * Retrieve a list of all the possible sorting fields for this schema.
     * @return array Associative array of field ids and names.
     */
    public function getSortFields(): array
    {
        # set the field types to get
        $FieldTypes = MetadataSchema::MDFTYPE_TEXT | MetadataSchema::MDFTYPE_NUMBER
                    | MetadataSchema::MDFTYPE_DATE | MetadataSchema::MDFTYPE_TIMESTAMP
                    | MetadataSchema::MDFTYPE_URL;

        # load up possible values for DefaultSortField setting
        $Fields = $this->getFields($FieldTypes, MetadataSchema::MDFORDER_DISPLAY);
        $PossibleSortFields = [];
        foreach ($Fields as $FieldId => $Field) {
            if ($Field->IncludeInSortOptions() && $Field->userCanView(User::getCurrentUser())) {
                $PossibleSortFields[$FieldId] = $Field->Name();
            }
        }

        return $PossibleSortFields;
    }

    /**
     * Retrieve array of field types.
     * @return array Array with enumerated types for the indexes and field names
     *       (strings) for the values.
     */
    public function getFieldTypes(): array
    {
        return MetadataField::$FieldTypeDBEnums;
    }

    /**
     * Retrieve array of field types that user can create.
     * @return array Array with enumerated types for the indexes and field names
     *       (strings) for the values.
     */
    public function getAllowedFieldTypes(): array
    {
        return MetadataField::$FieldTypeDBAllowedEnums;
    }

    /**
     * Remove all metadata field associations for a given qualifier.
     * @param int|Qualifier $QualifierIdOrObject Qualifier object or ID.
     * @return void
     */
    public function removeQualifierAssociations($QualifierIdOrObject): void
    {
        # sanitize qualifier ID or grab it from object
        $QualifierIdOrObject = is_object($QualifierIdOrObject)
                ? $QualifierIdOrObject->id() : intval($QualifierIdOrObject);

        # delete intersection records from database
        $this->DB->query("DELETE FROM FieldQualifierInts"
                ." WHERE QualifierId = ".$QualifierIdOrObject);

        # reset default qualifier for any fields that were using this one
        $this->DB->query(
            "UPDATE MetadataFields SET DefaultQualifier = NULL"
            ."WHERE DefaultQualifier = ".$QualifierIdOrObject
        );
    }

    /**
     * Check whether qualifier is in use by any metadata field (in any schema).
     * @param int|Qualifier $QualifierIdOrObject Qualifier ID or Qualifier object.
     * @return bool TRUE if qualifier is in use, otherwise FALSE.
     */
    public function qualifierIsInUse($QualifierIdOrObject): bool
    {
        # sanitize qualifier ID or grab it from object
        $QualifierIdOrObject = is_object($QualifierIdOrObject)
                ? $QualifierIdOrObject->id() : intval($QualifierIdOrObject);

        # determine whether any fields use qualifier as default
        $DefaultCount = $this->DB->queryValue(
            "SELECT COUNT(*) AS RecordCount"
                ." FROM MetadataFields"
                ." WHERE DefaultQualifier = ".$QualifierIdOrObject,
            "RecordCount"
        );
        if ($DefaultCount === null) {
            throw new Exception("Unexpected NULL returned by query.");
        }

        # determine whether any fields are associated with qualifier
        $AssociationCount = $this->DB->queryValue(
            "SELECT COUNT(*) AS RecordCount"
                ." FROM FieldQualifierInts"
                ." WHERE QualifierId = ".$QualifierIdOrObject,
            "RecordCount"
        );
        if ($AssociationCount === null) {
            throw new Exception("Unexpected NULL returned by query.");
        }

        # report whether qualifier is in use based on defaults and associations
        return (((int)$DefaultCount + (int)$AssociationCount) > 0) ? true : false;
    }

    /**
     * Get highest field ID currently in use.
     * @return int MetadataField ID value.
     */
    public function getHighestFieldId(): int
    {
        return $this->getHighestItemId();
    }

    /**
     * Get/set mapping of standard field name to specific field.
     * @param string $MappedName Standard field name.
     * @param int|false $FieldId ID of field to map to, or FALSE to clear
     *       mapping.(OPTIONAL)
     * @return int|null ID of field to which standard field name is mapped or NULL if
     *       specified standard field name is not currently mapped.
     * @throws InvalidArgumentException If field ID is invalid for this schema.
     */
    public function stdNameToFieldMapping(string $MappedName, $FieldId = null)
    {
        self::loadFieldMappingsCache();
        if ($FieldId !== null) {
            # if name is unmapped or is mapped differently
            if (!isset(self::$FieldMappings[$this->Id][$MappedName]) ||
                (self::$FieldMappings[$this->Id][$MappedName] != $FieldId)) {
                # check to make sure target field exists
                if (($FieldId !== false) && !$this->fieldExists($FieldId)) {
                    throw new InvalidArgumentException("Attempt to set"
                            ." standard field mapping to invalid field ID"
                            ." (".$FieldId.") at ".StdLib::getMyCaller().".");
                }

                # clear any existing mapping
                if (isset(self::$FieldMappings[$this->Id][$MappedName])) {
                    $this->DB->query("DELETE FROM StandardMetadataFieldMappings"
                            ." WHERE SchemaId = '".addslashes($this->Id)
                            ."' AND Name = '".addslashes($MappedName)."'");
                    unset(self::$FieldMappings[$this->Id][$MappedName]);
                }

                # add new mapping
                if ($FieldId !== false) {
                    $this->DB->query("INSERT INTO StandardMetadataFieldMappings"
                            ." (SchemaId, Name, FieldId) VALUES ('"
                            .addslashes($this->Id)."', '".addslashes($MappedName)
                            ."', '".addslashes((string)$FieldId)."')");
                    self::$FieldMappings[$this->Id][$MappedName] = $FieldId;
                }

                # clear our caches
                $this->clearCaches();
                self::clearStaticCaches();
            }
        }
        return isset(self::$FieldMappings[$this->Id][$MappedName])
                ? self::$FieldMappings[$this->Id][$MappedName] : null;
    }

    /**
     * Get mapping of field ID to standard field name.
     * @param int $FieldId Field ID.
     * @return string|null Standard field name to which specified field is
     *      mapped, or NULL if field is not currently mapped.
     */
    public function fieldToStdNameMapping(int $FieldId)
    {
        self::loadFieldMappingsCache();
        $MappedName = array_search($FieldId, self::$FieldMappings[$this->Id]);
        return ($MappedName === false) ? null : $MappedName;
    }

    /**
     * Get field by standard field name.
     * @param string $MappedName Standard field name.
     * @return MetadataField|null to which standard field name is mapped or NULL
     *       if specified standard field name is not currently mapped or mapped
     *       field does not exist.
     */
    public function getFieldByMappedName(string $MappedName)
    {
        return ($this->stdNameToFieldMapping($MappedName) == null) ? null
                : $this->getField($this->stdNameToFieldMapping($MappedName));
    }

    /**
     * Get field ID by standard field name.
     * @param string $MappedName Standard field name.
     * @return int|null ID for MetadataField to which standard field name is
     *       mapped or NULL if specified standard field name is not currently
     *       mapped or mapped field does not exist.
     */
    public function getFieldIdByMappedName(string $MappedName)
    {
        return $this->stdNameToFieldMapping($MappedName);
    }

    /**
     * Get fields that have an owner associated with them.
     * @return array Array of fields that have an owner associated with them.
     */
    public function getOwnedFields(): array
    {
        self::loadFieldInfoCaches();

        $Fields = [];
        foreach (self::$FieldsBySchema[$this->Id] as $FieldId) {
            $FieldInfo = self::$FieldInfoCache[$FieldId];

            if (strlen($FieldInfo["Owner"]) > 0) {
                $Fields[$FieldId] = $this->getField($FieldId);
            }
        }

        return $Fields;
    }

    /**
     * Determine if a Field exists in any schema.
     * @param int|string $Field Field name or FieldId to check
     * @return bool TRUE for fields that exist.
     */
    public static function fieldExistsInAnySchema($Field): bool
    {
        # if we were given a field id, check to see if it exists
        self::loadFieldInfoCaches();
        if (is_numeric($Field) && array_key_exists($Field, self::$FieldInfoCache)) {
            return true;
        }

        # otherwise, try to look up this field
        try {
            $FieldId = self::getCanonicalFieldIdentifier($Field);
            return array_key_exists($FieldId, self::$FieldInfoCache) ?
                true : false;
        } catch (Exception $e) {
            # if we can't find the field, then it doesn't exist
            return false;
        }
    }

    /**
     * Retrieve canonical identifier for field.Names passed in are compared
     * against field names, not field labels.This method should only be used
     * in situations where there are no concerns about field information
     * changing during invocation.
     * @param mixed $Field Field object, ID, or name.
     * @param int $SchemaId ID of schema to limit fields to.(OPTIONAL)
     * @return int Canonical field identifier.
     * @throws InvalidArgumentException If illegal schema ID argument
     *       was supplied.
     * @throws InvalidArgumentException If invalid numerical schema ID
     *       argument was supplied.
     * @throws Exception If schema ID and numerical field ID were supplied,
     *       and field ID was not within the specified schema.
     * @throws Exception If a field name is supplied that does not match
     *       any existing metadata field.
     * @throws InvalidArgumentException If field argument supplied could
     *       not be interpreted.
     */
    public static function getCanonicalFieldIdentifier($Field, ?int $SchemaId = null): int
    {
        # check to make sure any specified schema is valid
        self::loadFieldInfoCaches();
        if ($SchemaId !== null) {
            if (!isset(self::$SchemaNamesCache[$SchemaId])) {
                throw new InvalidArgumentException(
                    "Invalid schema ID supplied (".$SchemaId.")."
                );
            }
        }

        # if field object was passed in
        if ($Field instanceof MetadataField) {
            # check to make sure field ID is within any specified schema
            if (($SchemaId !== null) && ($Field->schemaId() != $SchemaId)) {
                throw new Exception("Supplied field (".$Field->name()
                        .") is not within specified "
                        .self::$SchemaNamesCache[$SchemaId]
                        ." schema (".$SchemaId.")");
            }

            # return identifier from field to caller
            return $Field->id();
        # else if field ID was passed in
        } elseif (is_numeric($Field)) {
            # check to make sure field ID is valid
            $FieldId = $Field;
            if (!isset(self::$FieldInfoCache[$FieldId])) {
                throw new InvalidArgumentException(
                    "Invalid field ID supplied (".$FieldId.")."
                );
            }

            # check to make sure field ID is within any specified schema
            if (($SchemaId !== null) &&
                (self::$FieldInfoCache[$FieldId]["SchemaId"] != $SchemaId)) {
                throw new Exception("Supplied field ID (".$FieldId
                        .") is not within specified "
                        .self::$SchemaNamesCache[$SchemaId]
                        ." schema (".$SchemaId.")");
            }

            # return supplied field ID to caller
            return (int)$FieldId;
        # else if field name was passed in
        } elseif (is_string($Field)) {
            # look for field with specified name
            $FieldName = trim($Field);

            $SchemaKey = ($SchemaId === null) ? self::NO_SCHEMA : $SchemaId;
            if (!isset(self::$FieldNameCache[$SchemaKey][$FieldName])) {
                throw new Exception(
                    "No field found with the name \"".$FieldName
                            ."\" (schema ID: ".$SchemaKey.")."
                );
            }

            return self::$FieldNameCache[$SchemaKey][$FieldName];

        # else error out because we were given an illegal field argument
        } else {
            throw new InvalidArgumentException(
                "Illegal field argument supplied."
            );
        }
    }

    /**
     * Retrieve label for field.If no label is available for the field,
     * the field name is returned instead.Handling of the $Field argument
     * is the same as GetCanonicalFieldIdentifier().This method should only
     * be used in situations where a static method is needed and there are no
     * concerns about field information changing during invocation.
     * @param mixed $Field Field object, ID, or name.
     * @return string Human-readable field name.
     * @throws InvalidArgumentException If field argument supplied could
     *       not be interpreted.
     * @throws Exception If a field name is supplied that does not match
     *       any existing metadata field.
     * @see MetadataSchema::getCanonicalFieldIdentifier()
     */
    public static function getPrintableFieldName($Field): string
    {
        if (MetadataSchema::fieldExistsInAnySchema($Field)) {
            # retrieve field ID
            $Id = self::getCanonicalFieldIdentifier($Field);

            # if we have a label for this field, return it
            self::loadFieldInfoCaches();
            if (isset(self::$FieldInfoCache[$Id])) {
                $DisplayName = strlen(self::$FieldInfoCache[$Id]["FieldLabel"]) ?
                    self::$FieldInfoCache[$Id]["FieldLabel"] :
                    self::$FieldInfoCache[$Id]["FieldName"] ;
                return self::$FieldInfoCache[$Id]["SchemaPrefix"].$DisplayName;
            }
        }

        # otherwise return a blank string
        return "";
    }

    /**
     * Retrieve printable representation for field value. Handling of the
     * $Field argument is the same as GetCanonicalFieldIdentifier(). This
     * method should only be used in situations where a static method is
     * needed and there are no concerns about field information changing
     * during invocation.
     * @param mixed $Field Field object, ID, or name.
     * @param mixed $Value Value to retrieve representation for.
     * @return string Human-readable field name.
     * @throws InvalidArgumentException If field argument supplied could
     *       not be interpreted.
     * @throws Exception If a field name is supplied that does not match
     *       any existing metadata field.
     * @see MetadataSchema::getCanonicalFieldIdentifier()
     */
    public static function getPrintableFieldValue($Field, $Value): string
    {
        if (MetadataSchema::fieldExistsInAnySchema($Field)) {
            $FieldId = self::getCanonicalFieldIdentifier($Field);
            self::loadFieldInfoCaches();
            $FieldType = self::$FieldInfoCache[$FieldId]["Type"];
            switch ($FieldType) {
                case self::MDFTYPE_FLAG:
                    $Field = MetadataField::getField($FieldId);
                    $PrintableValue = $Value
                            ? $Field->flagOnLabel()
                            : $Field->flagOffLabel();
                    break;

                case self::MDFTYPE_CONTROLLEDNAME:
                case self::MDFTYPE_OPTION:
                    # if value does not appear to be an ID
                    if (strval(intval($Value)) !== $Value) {
                        # use literal value
                        $PrintableValue = $Value;
                    # else if ID appears to be for valid term
                    } elseif (ControlledName::itemExists((int)$Value)) {
                        # use term corresponding to ID
                        $PrintableValue = (new ControlledName($Value))->name();
                    # else use message indicating unknown term
                    } else {
                        $PrintableValue = "(unknown controlled name ID: ".$Value.")";
                    }
                    break;

                case self::MDFTYPE_TREE:
                    # if value does not appear to be an ID
                    if (strval(intval($Value)) !== $Value) {
                        # use literal value
                        $PrintableValue = $Value;
                    # else if ID appears to be for valid term
                    } elseif (Classification::itemExists((int)$Value)) {
                        # use term corresponding to ID
                        $PrintableValue = (new Classification($Value))->name();
                    # else use message indicating unknown term
                    } else {
                        $PrintableValue = "(unknown classification ID: ".$Value.")";
                    }
                    break;

                case self::MDFTYPE_USER:
                    if (strval(intval($Value)) !== $Value) {
                        # use literal value
                        $PrintableValue = $Value;
                    # else if ID appears to be for valid user
                    } elseif (User::itemExists((int)$Value)) {
                        # use user corresponding to ID
                        $PrintableValue = (new User($Value))->name();
                    # else use message indicating unknown user
                    } else {
                        $PrintableValue = "(unknown user ID: ".$Value.")";
                    }
                    break;

                default:
                    $PrintableValue = $Value;
                    break;
            }

            return $PrintableValue;
        } else {
            return "";
        }
    }

    /**
     * Retrieve a list of all available standard fields names.
     * @return array of field names.
     */
    public static function getStandardFieldNames(): array
    {
        $DB = new Database();
        $DB->query("SELECT DISTINCT Name FROM StandardMetadataFieldMappings");
        return $DB->fetchColumn("Name");
    }

    /**
     * Translate search values from a legacy URL string to
     *  their modern equivalents.
     * @param int $FieldId FieldId to use for translation
     * @param mixed $Values Values to translate
     * @return array of translated values
     */
    public static function translateLegacySearchValues(
        int $FieldId,
        $Values
    ): array {

        # start out assuming we won't find any values to translate
        $ReturnValues = [];

        # try to grab the specified field
        try {
            $Field = MetadataField::getField($FieldId);
        } catch (Exception $e) {
            # field no longer exists, so there are no values to translate
            return $ReturnValues;
        }

        # if incoming value is not an array
        if (!is_array($Values)) {
            # convert incoming value to an array
            $Values = [$Values];
        }

        # for each incoming value
        foreach ($Values as $Value) {
            # look up value for index
            if ($Field->type() == self::MDFTYPE_FLAG) {
                # (for flag fields the value index (0 or 1) is used in Database)
                if ($Value >= 0) {
                    $ReturnValues[] = "=".$Value;
                }
            } elseif ($Field->type() == self::MDFTYPE_NUMBER) {
                # (for flag fields the value index (0 or 1) is used in Database)
                if ($Value >= 0) {
                    $ReturnValues[] = ">=".$Value;
                }
            } elseif ($Field->type() == self::MDFTYPE_USER) {
                $User = new User(intval($Value));
                $ReturnValues[] = "=".$User->get("UserName");
            } elseif ($Field->type() == self::MDFTYPE_OPTION) {
                if (!isset($PossibleFieldValues)) {
                    $PossibleFieldValues = $Field->getPossibleValues();
                }

                if (isset($PossibleFieldValues[$Value])) {
                    $ReturnValues[] = "=".$PossibleFieldValues[$Value];
                }
            } elseif (is_numeric($Value)) {
                $Factory = $Field->getFactory();
                if (!is_null($Factory) && $Factory->itemExists($Value)) {
                    # only get value if item exists for given ID/field
                    $ReturnValues[] = "=".$Field->getValueForId((int)$Value);
                }
            }
        }

        # return array of translated values to caller
        return $ReturnValues;
    }

    /**
     * Get IDs for all existing metadata schemas.
     * @return array Returns an array of schema IDs.
     */
    public static function getAllSchemaIds(): array
    {
        return array_keys(self::getAllSchemaNames());
    }

    /**
     * Get names for all existing metadata schemas.
     * @return array Returns an array of names, indexed by schema ID.
     */
    public static function getAllSchemaNames(): array
    {
        $DB = new Database();
        $DB->query("SELECT SchemaId, Name FROM MetadataSchemas");
        return $DB->fetchColumn("Name", "SchemaId");
    }

    /**
     * Get all existing metadata schemas.
     * @return array Returns an array of MetadataSchema objects with the schema
     *       IDs for the index.
     */
    public static function getAllSchemas(): array
    {
        # fetch IDs of all metadata schemas
        $SchemaIds = self::getAllSchemaIds();

        # construct objects from the IDs
        $Schemas = [];
        foreach ($SchemaIds as $SchemaId) {
            $Schemas[$SchemaId] = new MetadataSchema($SchemaId);
        }

        # return schemas to caller
        return $Schemas;
    }

    /**
     * Determine if a specified field is used in either schema or field
     * permissions.
     * @param int $FieldId FieldId to check.
     * @return bool TRUE if field is used.
     */
    public static function fieldUsedInPrivileges(int $FieldId): bool
    {
        # list of priv types we'll be checking
        $PrivTypes = [
            "AuthoringPrivileges",
            "EditingPrivileges",
            "ViewingPrivileges"
        ];

        # iterate over each schema
        foreach (self::getAllSchemas() as $Schema) {
            # see if the provided field is checked in any of the
            # schema-level privs, returning TRUE if so
            foreach ($PrivTypes as $PrivType) {
                if ($Schema->$PrivType()->checksField($FieldId)) {
                    return true;
                }
            }

            # otherwise, iterate over all the field-level privs, returning true
            # if any of those check the provided field
            foreach ($Schema->getFields() as $Field) {
                foreach ($PrivTypes as $PrivType) {
                    if ($Field->$PrivType()->checksField($FieldId)) {
                        return true;
                    }
                }
            }
        }

        # nothing checks this field, return FALSE
        return false;
    }

    /**
     * Get schema ID for specified name.
     * @param string $Name Schema name.
     * @return integer|null Schema ID or NULL if no schema found with specified name.
     */
    public static function getSchemaIdForName(string $Name)
    {
        $DB = new Database();
        $Id = $DB->queryValue("SELECT SchemaId FROM MetadataSchemas"
                ." WHERE Name = '".addslashes($Name)."'", "SchemaId");
        return ($Id === null) ? null : (int)$Id;
    }

    /**
     * Allow external dependencies, i.e., the current list of owners that are
     * available, to be injected.
     * @param callable $Callback Retrieval callback.
     * @return void
     */
    public static function setOwnerListRetrievalFunction(callable $Callback): void
    {
        self::$OwnerListRetrievalFunction = $Callback;
    }

    /**
     * Disable owned fields that have an owner that is unavailable and
     * re-enable fields if an owner has returned and the field was flagged to
     * be re-enabled.
     * @return void
     */
    public static function normalizeOwnedFields(): void
    {
        # if an owner list retrieval function and default schema exists
        if (self::$OwnerListRetrievalFunction &&
            self::schemaExistsWithId(self::SCHEMAID_DEFAULT)) {
            # retrieve the list of owners that currently exist
            $OwnerList = call_user_func(self::$OwnerListRetrievalFunction);

            # an array is expected
            if (is_array($OwnerList)) {
                $Schema = new MetadataSchema(self::SCHEMAID_DEFAULT);

                # get each metadata field that is owned by a plugin
                $OwnedFields = $Schema->getOwnedFields();

                # loop through each owned field
                foreach ($OwnedFields as $OwnedField) {
                    # the owner of the current field
                    $Owner = $OwnedField->owner();

                    # if the owner of the field is in the list of owners that
                    # currently exist, i.e., available plugins
                    if (in_array($Owner, $OwnerList)) {
                        # enable the field and reset its "enable on owner return"
                        # flag if the "enable on owner return" flag is currently
                        # set to true.in other words, re-enable the field since
                        # the owner has returned to the list of existing owners
                        if ($OwnedField->enableOnOwnerReturn()) {
                            $OwnedField->enabled(true);
                            $OwnedField->enableOnOwnerReturn(false);
                        }
                    # if the owner of the field is *not* in the list of owners
                    # that currently exist, i.e., available plugins
                    } else {
                        # first, see if the field is currently enabled since it
                        # will determine whether the field is re-enabled when
                        # the owner becomes available again
                        $Enabled = $OwnedField->enabled();

                        # if the field is enabled, set its "enable on owner
                        # return" flag to true and disable the field.nothing
                        # needs to be done if the field is already disabled
                        if ($Enabled) {
                            $OwnedField->enableOnOwnerReturn($Enabled);
                            $OwnedField->enabled(false);
                        }
                    }
                }
            }
        }
    }

    /**
     * Update the field comparison ordering cache that is used for sorting
     * fields.
     * @return void
     */
    protected function updateFieldCompareOrders(): void
    {
        $Index = 0;

        foreach ($this->getDisplayOrder()->getFields() as $Field) {
            $this->FieldCompareDisplayOrder[$Field->id()] = $Index++;
        }

        $Index = 0;

        foreach ($this->getEditOrder()->getFields() as $Field) {
            $this->FieldCompareEditOrder[$Field->id()] = $Index++;
        }
    }

    /**
     * Get the display order for the schema.
     * @return MetadataFieldOrder Returns a MetadataFieldOrder object.
     */
    public function getDisplayOrder(): MetadataFieldOrder
    {
        return MetadataFieldOrder::getOrderForSchema($this, self::ORDER_DISPLAY_NAME);
    }

    /**
     * Get the editing order for the schema.
     * @return MetadataFieldOrder Returns a MetadataFieldOrder object.
     */
    public function getEditOrder(): MetadataFieldOrder
    {
        return MetadataFieldOrder::getOrderForSchema($this, self::ORDER_EDIT_NAME);
    }

    /**
     * Determine whether the field comparison ordering caches are set.
     * @return bool TRUE if the caches are set or FALSE otherwise
     */
    protected function fieldCompareOrdersSet(): bool
    {
        return $this->FieldCompareDisplayOrder && $this->FieldCompareEditOrder;
    }

    /**
     * Field sorting callback.
     * @param MetadataField $FieldA First comparision field.
     * @param MetadataField $FieldB Second comparison field.
     * @return int -1, 0, or 1, depending on the order desired
     * @see usort()
     */
    protected function compareFieldOrder(
        MetadataField $FieldA,
        MetadataField $FieldB
    ): int {
        if ($this->FieldCompareType == self::MDFORDER_ALPHABETICAL) {
            return ($FieldA->getDisplayName() < $FieldB->getDisplayName()) ? -1 : 1;
        }

        if ($this->FieldCompareType == self::MDFORDER_EDITING) {
            $Order = $this->FieldCompareEditOrder;
        } else {
            $Order = $this->FieldCompareDisplayOrder;
        }

        $PositionA = StdLib::getArrayValue($Order, $FieldA->id(), 0);
        $PositionB = StdLib::getArrayValue($Order, $FieldB->id(), 0);

        return $PositionA < $PositionB ? -1 : 1;
    }

    /**
     * Clear internal caches.
     * @return void
     */
    public static function clearStaticCaches(): void
    {
        self::$FieldCache = null;
        self::$FieldInfoCache = null;
        self::$FieldMappings = null;
        self::$FieldNameCache = [];
        self::$FieldsBySchema = [];
        self::$GetFieldsCache = [];
        self::$SchemaInfoCache = null;
        self::$SchemaNamesCache = null;
        self::$UserClassCache = [];
    }

    /**
     * Export schema to XML file.
     * @param string $FileName Full name of file to which to write XML.
     * @return void
     */
    public function exportToXmlFile(string $FileName): void
    {
        # set up XML writer
        $XOut = new XMLWriter();
        $XOut->openMemory();
        $XOut->setIndent(true);
        $XOut->setIndentString("    ");

        # begin XML document
        $XOut->startDocument("1.0", "UTF-8");
        $XOut->startElement("MetadataSchema");

        # get XML for schema and add to document
        $XOut->writeRaw($this->getAsXml());

        # end XML document
        $XOut->endDocument();

        $XmlData = $XOut->flush();
        if (is_int($XmlData)) {
            throw new Exception("XOut->flush() returned int (should be impossible).");
        }

        # format XML nicely
        $NiceXml = StdLib::formatXmlDocumentNicely($XmlData);
        if ($NiceXml === false) {
            throw new Exception("Unable to format generated XML.");
        }

        # write XML document to file
        $Result = file_put_contents($FileName, $NiceXml);
        if ($Result === false) {
            throw new Exception("Unable to write XML to file \"".$FileName."\".");
        }
    }

    /*
     * Get schema as XML.  The returned XML will not include document begin
     * or end tags and may not be ideally formatted.
     * @return string XML data.
     */
    public function getAsXml(): string
    {
        # set up XML writer
        $XOut = new XMLWriter();
        $XOut->openMemory();
        $XOut->setIndent(true);
        $XOut->setIndentString("    ");

        # for each standard field that is mapped to a metadata field
        foreach (self::$FieldMappings[$this->Id] as $StdFieldName => $MappedFieldId) {
            # if metadata field appears valid
            if ($this->fieldExists($MappedFieldId)) {
                # add mapping to document
                $MappedField = $this->getField($MappedFieldId);
                $XOut->startElement("StandardFieldMapping");
                $XOut->writeElement("StandardName", $StdFieldName);
                $XOut->writeElement("Name", $MappedField->name());
                $XOut->endElement();
            }
        }

        # write out schema privileges
        $PrivSetNames = [
            "AuthoringPrivileges",
            "EditingPrivileges",
            "ViewingPrivileges",
        ];
        foreach ($PrivSetNames as $PrivSetName) {
            $PrivSetCallback = [$this, $PrivSetName];
            $Privs = ($PrivSetCallback)();
            $XOut->startElement($PrivSetName);
            $XOut->writeRaw("\n".$Privs->getAsXml());
            $XOut->endElement();
        }

        # for each metadata field
        foreach ($this->getFields() as $FieldId => $Field) {
            # generate XML for field and add it to document
            $XOut->startElement("MetadataField");
            $XOut->writeRaw("\n".$Field->getAsXml());
            $XOut->endElement();
        }

        # return generated XML to caller
        $XmlData = $XOut->flush();
        if (is_int($XmlData)) {
            throw new Exception("XOut->flush() returned int (should be impossible).");
        }

        return $XmlData;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    # key to use in the *Cache arrays for entries that don't refer to a specific schema
    const NO_SCHEMA = "NoSchema";

    private $AuthoringPrivileges;
    private $EditingPrivileges;
    private $FieldCompareType;
    private $Id;
    private $NewFields = [];
    private $ViewingPrivileges;
    private $ViewPage;

    private static $FieldCache = null;
    private static $FieldInfoCache = null;
    private static $FieldMappings = null;
    private static $FieldNameCache = [];
    private static $FieldsBySchema = [];
    private static $GetFieldsCache = [];
    private static $SchemaInfoCache = null;
    private static $SchemaNamesCache = null;
    private static $UserClassCache = [];

    protected static $OwnerListRetrievalFunction;

    /**
     * The cache for metadata field display ordering.
     */
    protected $FieldCompareDisplayOrder = [];

    /**
     * The cache for metadata field edit ordering.
     */
    protected $FieldCompareEditOrder = [];

    /**
     * Ensure cache of schema information is loaded.
     * @return void
     */
    private static function loadSchemaInfoCache(): void
    {
        if (self::$SchemaInfoCache === null) {
            $DB = new Database();
            $DB->query("SELECT * FROM MetadataSchemas");
            self::$SchemaInfoCache = [];
            foreach ($DB->fetchRows() as $Row) {
                self::$SchemaInfoCache[$Row["SchemaId"]] = $Row;
            }
        }
    }

    /**
     * Ensure caches used for looking up field information are loaded.This
     * will populate FieldInfoCache with detailed information about each
     * field, FieldNameCache with a mapping of field names to field id
     * numbers, and FieldsBySchema with a mapping of SchemaId to FieldIds for
     * that schema.
     * @return void
     */
    private static function loadFieldInfoCaches(): void
    {
        if (self::$FieldInfoCache === null) {
            self::$FieldNameCache = [];
            self::$FieldsBySchema = [];

            self::$SchemaNamesCache = self::getAllSchemaNames();
            $DB = new Database();
            $DB->query(
                "SELECT SchemaId, FieldId, FieldName, Label, FieldType, Enabled, Owner"
                ." FROM MetadataFields ORDER BY SchemaId DESC"
            );
            # ordered by SchemaId so that the lowest SchemaId for a given name will
            # be preferred in the population of FieldNameCache
            while ($Row = $DB->fetchRow()) {
                $SchemaId = $Row["SchemaId"];
                $FieldId = $Row["FieldId"];
                $FieldName = trim($Row["FieldName"]);
                $Label = !is_null($Row["Label"]) ? trim($Row["Label"]) : "";
                $FieldType = MetadataField::$FieldTypePHPEnums[
                    $Row["FieldType"]];
                $Enabled = boolval($Row["Enabled"]);
                $Owner = !is_null($Row["Owner"]) ? trim($Row["Owner"]) : "";

                $SchemaPrefix = ($SchemaId == self::SCHEMAID_DEFAULT)
                    ? "" : self::$SchemaNamesCache[$SchemaId].": ";

                $QualifiedFieldName = $SchemaPrefix.$FieldName;

                # populate field info
                self::$FieldInfoCache[$FieldId] = [
                    "SchemaId" => $SchemaId,
                    "SchemaPrefix" => $SchemaPrefix,
                    "FieldName" => $FieldName,
                    "QualifiedFieldName" => $QualifiedFieldName,
                    "FieldLabel" => $Label,
                    "Type" => $FieldType,
                    "Enabled" => $Enabled,
                    "Owner" => $Owner,
                ];

                # populate field name lookup table
                self::$FieldNameCache[self::NO_SCHEMA][$FieldName] = $FieldId;
                self::$FieldNameCache[self::NO_SCHEMA][$QualifiedFieldName] = $FieldId;
                self::$FieldNameCache[$SchemaId][$FieldName] = $FieldId;
                self::$FieldNameCache[$SchemaId][$QualifiedFieldName] = $FieldId;

                # populate the list of fields by schema
                self::$FieldsBySchema[$SchemaId][] = $FieldId;
            }
        }
    }

    /**
     * Ensure cache of standard field mapping information is loaded.
     * @return void
     */
    private static function loadFieldMappingsCache(): void
    {
        # if standard field mappings have not yet been loaded
        if (self::$FieldMappings === null) {
            self::loadFieldInfoCaches();
            $DB = new Database();

            # for each standard field mapping
            $DB->query("SELECT * FROM StandardMetadataFieldMappings");
            foreach ($DB->fetchRows() as $Row) {
                if (!isset(self::$FieldInfoCache[$Row["FieldId"]])) {
                    throw new Exception(
                        "Standard mapping of FieldId ".$Row["FieldId"]
                        ." for \"".$Row["Name"]."\" in Schema ".$Row["SchemaId"]
                        ." refers to a non-existent field."
                    );
                }

                $FieldInfo = self::$FieldInfoCache[$Row["FieldId"]];
                if ($FieldInfo["SchemaId"] != $Row["SchemaId"]) {
                    throw new Exception(
                        "Standard mapping of FieldId ".$Row["FieldId"]
                        ." for \"".$Row["Name"]."\" in Schema ".$Row["SchemaId"]
                        ." refers to a field from a different schema."
                    );
                }

                # save mapping
                self::$FieldMappings[$Row["SchemaId"]][$Row["Name"]] =
                    $Row["FieldId"];
            }
        }
    }

    /**
     * Convert SimpleXmlElement to PrivilegeSet.Any error messages resulting
     * from failed conversion can be retrieved with ErrorMessages().
     * @param iterable $Xml Element containing privilege XML.
     * @return PrivilegeSet|null Resulting PrivilegeSet or NULL if conversion failed.
     * @see MetadataSchema::errorMessages()
     */
    private function convertXmlToPrivilegeSet($Xml)
    {
        $this->clearErrorMessages();
        try {
            return PrivilegeSet::createFromXml($Xml, $this);
        } catch (Exception $e) {
            $this->logErrorMessage($e->getMessage());
            return null;
        }
    }
}
