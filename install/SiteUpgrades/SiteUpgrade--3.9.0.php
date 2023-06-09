<?PHP
#
#   FILE:  SiteUpgrade--3.9.0.php
#
#   Part of the Collection Workflow Integration System (CWIS)
#   Copyright 2019 Edward Almasy and Internet Scout
#   http://scout.wisc.edu/cwis
#

use Metavus\MetadataSchema;
use Metavus\SavedSearch;
use ScoutLib\Database;
use ScoutLib\SearchParameterSet;

$GLOBALS["G_ErrMsgs"] = SiteUpgrade390_PerformUpgrade();

/**
* Perform all of the site upgrades for 3.9.0.
* @return string|null Returns NULL on success and an error message if an error occurs.
*/
function SiteUpgrade390_PerformUpgrade()
{
    try {
        $GLOBALS["G_MsgFunc"](1, "Adding Has No Password field to User Schema...");
        SiteUpgrade390_UpdateUserSchema();

        $GLOBALS["G_MsgFunc"](1, "Migrating User fields to new storage format...");
        SiteUpgrade390_MultipleUserValues();

        $GLOBALS["G_MsgFunc"](1, "Migrating SavedSearches to new storage format...");
        SiteUpgrade390_MigrateSavedSearches();

        $GLOBALS["G_MsgFunc"](1, "Migrating per-user search field selections to new format...");
        SiteUpgrade390_MigrateSearchSelections();

        $GLOBALS["G_MsgFunc"](1, "Making sure all metadata field names are compliant...");
        SiteUpgrade390_CheckMetadataFieldNames();

        $GLOBALS["G_MsgFunc"](1, "Making sure fields don't have values from other schemas...");
        SiteUpgrade390_FixDBFieldDefaults();

        $GLOBALS["G_MsgFunc"](1, "Enabling Backward Compatibility plugin...");
        $Plugin = $GLOBALS["G_PluginManager"]->getPlugin(
            "BackwardCompatibility",
            true
        );

        if ($Plugin !== null) {
            $Plugin->IsEnabled(true);
        }
    } catch (Exception $Exception) {
        return array($Exception->getMessage(),
                "Exception Trace:<br/><pre>"
                        .$Exception->getTraceAsString()."</pre>");
    }
}

/**
* Add the 'Has No Password' database column.
*/
function SiteUpgrade390_UpdateUserSchema()
{
    $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);

    if (!$Schema->fieldExists("Has No Password")) {
        $Schema->AddFieldsFromXmlFile(
            "install/MetadataSchema--User.xml"
        );
    }
}

/**
* Modify database schema to support multiple values in user fields.
*/
function SiteUpgrade390_MultipleUserValues()
{
    $DB = new Database();
    if ($DB->tableExists("RecordUserInts")) {
        return;
    }

    # attempt to create the RecordUserInts intersection table
    $DB->query(
        "CREATE TABLE RecordUserInts (".
        "RecordId INT NOT NULL,".
        "FieldId INT NOT NULL,".
        "UserId INT NOT NULL,".
        "INDEX Index_U (UserId),".
        "UNIQUE UIndex_RU (RecordId, FieldId, UserId) )"
    );

    # iterate over every schema
    foreach (MetadataSchema::getAllSchemas() as $SchemaId => $Schema) {
        foreach ($Schema->getFields(MetadataSchema::MDFTYPE_USER) as $FieldId => $Field) {
            # get the name of the db column
            $DBFieldName = $Field->dBFieldName();

            # migrate the existing data into our intersection table
            $DB->query(
                "INSERT INTO RecordUserInts ".
                "SELECT RecordId,".$FieldId.",".$DBFieldName." FROM Records ".
                "WHERE ".$DBFieldName." IS NOT NULL"
            );

            # nuke the existing column
            $DB->query("ALTER TABLE Records DROP COLUMN `".$DBFieldName."`");
        }
    }
}

/**
* Move SavedSearches to new storage format.
*/
function SiteUpgrade390_MigrateSavedSearches()
{
    $DB = new Database();

    # check if the SearchData column exists
    $DB->query("LOCK TABLES SavedSearches WRITE");
    if ($DB->fieldExists("SavedSearches", "SearchData")) {
        # if so, the migration is already complete and we should exit
        $DB->query("UNLOCK TABLES");
        return;
    }

    # if not, it needs to be added
    $DB->query(
        "ALTER TABLE SavedSearches ADD COLUMN SearchData TEXT DEFAULT NULL"
    );
    $DB->query("UNLOCK TABLES");

    # otherwise, we need to conver the old search data
    $Schema = new MetadataSchema();

    $DB->query("SELECT SearchId FROM SavedSearches");
    $SearchIds = $DB->fetchColumn("SearchId");
    foreach ($SearchIds as $SearchId) {
        # create a new parameter set
        $SearchParams = new SearchParameterSet();

        # for each text search parameter
        $DB->query(
            "SELECT * FROM SavedSearchTextParameters"
            ." WHERE SearchId = ".$SearchId
        );
        while ($Record = $DB->FetchRow()) {
            # add parameter to search criteria
            if ($Record["FieldId"] == -101) {
                $SearchParams->addParameter($Record["SearchText"]);
            } else {
                $SearchParams->addParameter(
                    $Record["SearchText"],
                    $Record["FieldId"]
                );
            }
        }

        # extract the per-field search value IDs from the database
        $Subgroups = array();
        $DB->query(
            "SELECT * FROM SavedSearchIdParameters"
            ." WHERE SearchId = ".$SearchId
        );
        while ($Record = $DB->FetchRow()) {
            $Subgroups[$Record["FieldId"]][]= $Record["SearchValueId"];
        }

        # iterate over each subgroup
        foreach ($Subgroups as $FieldId => $SearchValues) {
            # translate the ValueIds back to search strings
            $SearchStrings = SearchParameterSet::translateLegacySearchValues(
                $FieldId,
                $SearchValues
            );

            # create the corresponding SearchParameterSet
            $SubParams = new SearchParameterSet();
            $SubParams->logic("OR");
            $SubParams->addParameter($SearchStrings, $FieldId);

            # attempt to add it to our parent SearchParameterSet
            try {
                $SearchParams->addSet($SubParams);
            } catch (Exception $e) {
                ; # continue if something fails
            }
        }

        # and set the SPS for this SavedSearch
        $SavedSearch = new SavedSearch($SearchId);
        $SavedSearch->searchParameters($SearchParams);
    }

    # drop the now unused SavedSearch(Text|Id)Parameters tables
    $DB->query("DROP TABLE SavedSearchTextParameters");
    $DB->query("DROP TABLE SavedSearchIdParameters");
}

function SiteUpgrade390_MigrateSearchSelections()
{
    $DB = new Database();

    $DB->query(
        "SELECT UserId, SearchSelections "
        ."FROM APUsers WHERE SearchSelections IS NOT NULL"
    );

    $SearchData = $DB->fetchColumn("SearchSelections", "UserId");

    foreach ($SearchData as $UserId => $OldSetting) {
        $SearchSelections = unserialize($OldSetting);

        if (!is_array($SearchSelections)) {
            continue;
        }

        foreach ($SearchSelections as &$Item) {
            if ($Item == "Keyword") {
                $Item = "KEYWORD";
            }
        }

        $NewSetting = serialize($SearchSelections);
        if ($NewSetting != $OldSetting) {
            $DB->query(
                "UPDATE APUsers SET "
                ."SearchSelections='".addslashes($NewSetting)."' "
                ."WHERE UserId=".intval($UserId)
            );
        }
    }
}

/*
* Check to make sure no metadata field name can be interpreted as a number.
*/
function SiteUpgrade390_CheckMetadataFieldNames()
{
    $Schemas = MetadataSchema::GetAllSchemas();
    foreach ($Schemas as $Schema) {
        $Fields = $Schema->getFields(null, null, true);
        foreach ($Fields as $Field) {
            if (is_numeric($Field->name())) {
                $Field->name("X".$Field->name());
            }
        }
    }
}

/**
* Make sure that database fields default to NULL so that we don't get
* spurious values assigned in fields that belong to a different
* schema.
*/
function SiteUpgrade390_FixDBFieldDefaults()
{
    $DB = new Database();

    # fields where the SQL allows a default
    $TypesWithDefaults = MetadataSchema::MDFTYPE_NUMBER |
                  MetadataSchema::MDFTYPE_FLAG |
                  MetadataSchema::MDFTYPE_DATE |
                  MetadataSchema::MDFTYPE_TIMESTAMP;

    # fields where we want to clear spurious values, but mysql doesn't
    # allow defaults
    $TypesWithoutDefaults = MetadataSchema::MDFTYPE_TEXT |
                  MetadataSchema::MDFTYPE_PARAGRAPH |
                  MetadataSchema::MDFTYPE_URL ;

    # iterate over all schemas
    $Schemas = MetadataSchema::getAllSchemas();
    foreach ($Schemas as $SchemaId => $Schema) {
        # for the fields that can have a default
        foreach ($Schema->getFields($TypesWithDefaults, null, true) as $FieldId => $Field) {
            # if this is a date field, handle the 'Begin' and 'End' cols and clear out
            # bogus values in them
            if ($Field->type() == MetadataSchema::MDFTYPE_DATE) {
                foreach (array("Begin", "End") as $Suffix) {
                    $TgtField = $Field->DBFieldName().$Suffix;
                    if ($DB->FieldExists("Records", $TgtField)) {
                        $DB->query(
                            "ALTER TABLE Records ALTER COLUMN "
                            ."`".$TgtField."` SET DEFAULT NULL"
                        );
                        $DB->query(
                            "UPDATE Records SET `".$TgtField."` = NULL "
                            ."WHERE SchemaId != ".$SchemaId
                        );
                    }
                }
            } else {
                # otherwise, just clear the default off the column itself
                if ($DB->FieldExists("Records", $Field->DBFieldName())) {
                    if ($Field->Type() == MetadataSchema::MDFTYPE_TIMESTAMP) {
                        # for timestamps, just drop the default as they cannot default
                        # to null
                        $DB->query(
                            "ALTER TABLE Records ALTER COLUMN "
                            ."`".$Field->DBFieldName()."` DROP DEFAULT"
                        );
                    } else {
                        # for everything else, default to null
                        $DB->query(
                            "ALTER TABLE Records ALTER COLUMN "
                            ."`".$Field->DBFieldName()."` SET DEFAULT NULL"
                        );
                    }

                    $DB->query(
                        "UPDATE Records SET `".$Field->DBFieldName()."` = NULL "
                        ."WHERE SchemaId != ".$SchemaId
                    );
                }
            }
        }

        # next, iterate over fields that aren't allowed to have a default
        # and null out spurious values
        foreach ($Schema->getFields($TypesWithoutDefaults, null, true) as $FieldId => $Field) {
            if ($DB->fieldExists("Records", $Field->dBFieldName())) {
                $DB->query(
                    "UPDATE Records SET "
                    ."`".$Field->dBFieldName()."` = NULL "
                    ."WHERE SchemaId != ".$SchemaId
                );
            }
        }
    }
}
