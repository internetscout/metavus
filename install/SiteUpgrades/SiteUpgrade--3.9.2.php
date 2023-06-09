<?PHP
#
#   FILE:  SiteUpgrade--3.9.2.php
#
#   Part of the Collection Workflow Integration System (CWIS)
#   Copyright 2019 Edward Almasy and Internet Scout
#   http://scout.wisc.edu/cwis
#

use Metavus\MetadataSchema;
use ScoutLib\Database;

$GLOBALS["G_ErrMsgs"] = SiteUpgrade392_PerformUpgrade();

/**
* Perform all of the site upgrades for 3.9.2.
* @return Returns NULL on success and an eror message if an error occurs.
*/
function SiteUpgrade392_PerformUpgrade()
{
    try {
        $GLOBALS["G_MsgFunc"](1, "Setting standard metadata field mappings...");
        SiteUpgrade392_SetStandardFieldMappings();

        $GLOBALS["G_MsgFunc"](1, "Removing obsolete 'Checking Resource' privset entry...");
        SiteUpgrade392_RemoveCheckingResource();

        $GLOBALS["G_MsgFunc"](1, "Adding 'Creation Date' field to User schema...");
        SiteUpgrade392_UpdateUserSchema();
    } catch (Exception $Exception) {
        return array($Exception->getMessage(),
                "Exception Trace:<br/><pre>"
                        .$Exception->getTraceAsString()."</pre>");
    }
}

/**
* Set standard metadata field mappings for existing schemas.
*/
function SiteUpgrade392_SetStandardFieldMappings()
{
    $DB = new Database();
    $StandardFieldNameSets = array(
            MetadataSchema::SCHEMAID_DEFAULT => array(
                    "Title" => "Title",
                    "Description" => "Description",
                    "Url" => "Url",
                    "Screenshot" => "Screenshot",
                    "File" => false
                    ),
            "Blog" => array(
                    "Title" => "Title",
                    ),
            "Events" => array(
                    "Title" => "Title",
                    "Description" => "Description",
                    "Url" => "Url",
                    ),
            "Pages" => array(
                    "Title" => "Title",
                    "Description" => "Summary",
                    "File" => "Files",
                    ),
            );
    foreach ($StandardFieldNameSets as $SchemaId => $StandardFieldNames) {
        if (!is_numeric($SchemaId)) {
            $SchemaId = MetadataSchema::getSchemaIdForName($SchemaId);
        }

        if (!is_null($SchemaId)) {
            $Schema = new MetadataSchema($SchemaId);
            foreach ($StandardFieldNames as $StandardName => $FieldName) {
                $FieldId = -1;
                if ($SchemaId == MetadataSchema::SCHEMAID_DEFAULT) {
                    $SysConfigField = $StandardName."Field";
                    if ($DB->fieldExists("SystemConfiguration", $SysConfigField)) {
                        $FieldId = $DB->query("SELECT ".$SysConfigField
                                ." FROM SystemConfiguration", $SysConfigField);
                        $DB->query("ALTER TABLE SystemConfiguration"
                                ." DROP COLUMN ".$SysConfigField);
                    }
                }
                if ($FieldId < 0) {
                    $FieldId = $Schema->getFieldIdByName($FieldName);
                }
                if ($FieldId !== false) {
                    $Schema->stdNameToFieldMapping($StandardName, $FieldId);
                }
            }
        }
    }
}

/**
* Remove 'Checking Resource' condition from privsets.
*/
function SiteUpgrade392_RemoveCheckingResource()
{
    # list of priv types we'll be checking
    $PrivTypes = array(
        "AuthoringPrivileges",
        "EditingPrivileges",
        "ViewingPrivileges");

    # iterate over each schema
    foreach (MetadataSchema::getAllSchemas() as $Schema) {
        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);

        # iterate over priv types
        foreach ($PrivTypes as $PrivType) {
            $NewPrivs = $Schema->$PrivType();
            foreach (array(true, false) as $Val) {
                $NewPrivs->removeCondition(
                    -1,
                    $Val,
                    "==",
                    true
                );
            }
            $Schema->$PrivType($NewPrivs);
        }


        # iterate over all field privs in this schema
        foreach ($Schema->getFields() as $Field) {
            # iterate over priv types
            foreach ($PrivTypes as $PrivType) {
                $NewPrivs = $Field->$PrivType();
                foreach (array(true, false) as $Val) {
                    $NewPrivs->removeCondition(
                        -1,
                        $Val,
                        "==",
                        true
                    );
                }
                $Field->$PrivType($NewPrivs);
            }
        }
    }
}

/**
* Add and populate the 'Creation Date' user schema field.
*/
function SiteUpgrade392_UpdateUserSchema()
{
    $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_USER);

    # if the User schema lacks a CreationDate field
    if ($Schema->getField("CreationDate") === null) {
        # Re-run the XML to create it
        $Schema->addFieldsFromXmlFile(
            "install/MetadataSchema--User.xml"
        );
    }

    # propagate any existing data into the CreationDate field
    # (this is done outside the if() because a previous
    # AddFieldsFromXmlFile() may have added the field earlier in the
    # upgrade process)

    # pull out the UserId and CreationDate fields
    $UidField = $Schema->getField("UserId");
    $CreationDateField = $Schema->getField("CreationDate");

    # extract CreationDate data from the APUsers table
    $DB = new Database();
    $DB->query(
        "SELECT R.RecordId AS RecordId, "
        ."    U.CreationDate AS CreationDate "
        ."FROM APUsers U, Records R, RecordUserInts RU "
        ."WHERE RU.RecordId = R.RecordId "
        ."  AND RU.UserId = U.UserId "
        ."  AND RU.FieldId = ".intval($UidField->Id())
    );
    $Rows = $DB->FetchRows();

    # populate CreationDate in the Records table
    foreach ($Rows as $Row) {
        $DB->query(
            "UPDATE Records "
            ."SET ".$CreationDateField->DBFieldName()." = "
            ."'".addslashes($Row["CreationDate"])."' "
            ."WHERE RecordId=".intval($Row["RecordId"])
        );
    }
}
