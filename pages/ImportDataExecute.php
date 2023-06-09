<?PHP
#
#   FILE:  ImportDataExecute.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\Date;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Output debugging information.
 */
function PrintDebugInfo(): void
{
    global $DebugInfo;

    print $DebugInfo;
}

/**
 * Create a new controlled name for a specified field.
 * @param MetadataField $Field Field to which the name should belong.
 * @param string $Value Value that should be created.
 * @return int ControlledNameId for newly created name
 */
function AddControlledName($Field, $Value) : int
{
    global $ControlledNameCount;

    $Value = trim($Value);
    if (empty($Value)) {
        throw new Exception(
            "Attempt to add empty value to controlled name."
        );
    }

    if (!ControlledName::ControlledNameExists($Value, $Field->id())) {
        $ControlledNameCount++;
    }
    $ControlledName = ControlledName::create($Value, $Field->id());
    return $ControlledName->id();
}


/**
 * Look up or create a classification for a given field.
 * @param MetadataField $Field Field to which the class should belong.
 * @param string $Value Value to look for or create
 * @return int ClassificationId corresponding to the given value.
 */
function AddClassification($Field, $Value) : int
{
    static $CFactories;
    global $ClassificationCount;

    $Value = trim($Value);
    if (empty($Value)) {
        throw new Exception(
            "Attempt to add empty value to classification"
        );
    }

    # if we don't have a factory for this field, create one
    if (!isset($CFactories[$Field->id()])) {
        $CFactories[$Field->id()] = new ClassificationFactory(
            $Field->id()
        );
    }

    # attempt to look up that value in our field
    $ClassId = $CFactories[$Field->id()]->GetItemIdByName($Value);

    # if the value didn't exist, create it
    if ($ClassId === false) {
        $Classification = Classification::create($Value, $Field->id());
        $ClassId = $Classification->id();
        $ClassificationCount += Classification::SegmentsCreated();
        $CFactories[$Field->id()]->ClearCaches();
    }

    return $ClassId;
}

/**
 * Do initial setup of the import.
 * @return bool TRUE on success, FALSE if an error was encountered
 */
function FirstTimeThrough(): bool
{
    global $TotalLineCount, $FSeek;
    global $fp, $NameArray;
    global $NumberOfFields, $Debug, $DebugInfo;
    global $ReferenceArray;

    $Schema = new MetadataSchema();
    $ReferenceArray = array();

    # read in line from import file
    $fline = fgets($fp, 4096);

    if ($fline === false) {
        throw new Exception(
            "Error reading from input file."
        );
    }

    $FSeek += strlen($fline);

    if ($Debug) {
        $DebugInfo .= "fline=$fline<br>";
    }

    # parse line from import file
    $Vars = str_getcsv($fline, "\t");

    $NumberOfFields = 0;
    $InvalidFields = [];
    foreach ($Vars as $Var) {
        $Var = trim($Var);

        # old style import files
        if ($Var != "ControlledName" &&
            $Var != "ControlledNameTypeName" &&
            $Var != "ClassificationName" &&
            $Var != "ClassificationTypeId") {
            if (!$Schema->fieldExists($Var)) {
                $InvalidFields[] = $Var;
            }
            # save field object and field name
            $NameArray[] = $Var;
        } else {
            # save names for ControlledName or Classification
            $NameArray[] = $Var;
        }
        $NumberOfFields++;
    }

    # count the first header line
    $TotalLineCount = 1;

    if (count($InvalidFields) > 0) {
        $_SESSION["ErrorMessage"] =
            "Unknown metadata fields encountered: "
            .implode(", ", $InvalidFields)."<br/>";
        UnsetSessionVars();
        $GLOBALS["AF"]->SetJumpToPage("ImportData");
        return false;
    }

    return true;
}


/**
* Process lines in the import file, creating and modifying resources
* as necessary.
* @return bool TRUE on success, FALSE if an error was encountered.
*/
function DoWhileLoop(): bool
{
    global $fp, $FSeek, $ImportComplete;
    global $ResourceCount;
    global $TotalLineCount, $UniqueField;
    global $NumberOfFields, $Debug, $DebugInfo, $NameArray;
    global $ReferenceArray;

    $Schema = new MetadataSchema();
    $RFactory = new RecordFactory();
    $SearchEngine = new SearchEngine();
    $Recommender = new Recommender();

    $UserMap = array();

    $LineCount = 0;
    $Resource = null;
    $LastSqlCondition = null;

    # determines whether we're importing records or new ControlledName/Option/Tree vocabulary
    $ImportingRecords = $NumberOfFields != 1;

    # user current user for AddedById, LastModifiedById
    # current date for DateOfRecordCreation
    $CurrentUserId = User::getCurrentUser()->get("UserId");
    $TodaysDate = date("Y-m-d H:i:s");

    while (!feof($fp) && $LineCount < 250 && $ImportComplete == 0) {
        # read in line from import file
        $fline = fgets($fp, 2000000);

        # check for errors
        if ($fline === false) {
            # if we've just hit EOF, then that's fine and we can bail
            if (feof($fp)) {
                $ImportComplete = 1;
                break;
            }

            # otherwise notify the user that something went wrong
            throw new Exception(
                "Error reading from input file."
            );
        }

        # update variables
        $LineCount++;
        $TotalLineCount++;

        if ($Debug) {
            $DebugInfo .= "Line $TotalLineCount: fline=$fline<br>";
        }

        $FSeek += strlen($fline);
        $_SESSION["FSeek"] = $FSeek;

        $FieldArray = [];
        $ValueArray = [];

        # look-up table for handling "old format" import files
        $SpecialArray = [];

        # parse line from import file
        $Vars = str_getcsv($fline, "\t");

        # make sure number of vars on line match number of fields in header
        $NumberOfVars = count($Vars);
        if ($NumberOfVars != $NumberOfFields) {
            $_SESSION["ImportComplete"] = $ImportComplete;
            $ErrorMessage =
                "Error: incorrect field count on line $TotalLineCount.<br>".
                "Expected $NumberOfFields, encountered $NumberOfVars<br>".
                "Correct the problem and try importing again.<br>";
            foreach ($NameArray as $Index => $Name) {
                if ($Index < count($Vars)) {
                    $ErrorMessage .= "[".sprintf("%02d", $Index)."] "
                        .htmlspecialchars($Name)." = <i>"
                        .htmlspecialchars($Vars[$Index])."</i><br>\n";
                } else {
                    $ErrorMessage .= "[".sprintf("%02d", $Index)."] "
                        .htmlspecialchars($Name)." is missing\n";
                }
            }
            $_SESSION["ErrorMessage"] = $ErrorMessage;
            UnsetSessionVars();
            $GLOBALS["AF"]->SetJumpToPage("ImportData");
            return false;
        }

        # process each var and cache its value
        foreach ($Vars as $Index => $Var) {
            # translate backslashed tabs and newlines
            $Var = str_replace(
                array('\t', '\n'),
                array("\t", "\n"),
                (string)$Var
            );

            # skip values that were empty
            if (empty($Var)) {
                continue;
            }

            if ($Schema->fieldExists($NameArray[$Index])) {
                $Field = $Schema->getField($NameArray[$Index]);

                if ($Field->type() == MetadataSchema::MDFTYPE_CONTROLLEDNAME ||
                    $Field->type() == MetadataSchema::MDFTYPE_OPTION) {
                    # create new controlled name if needed, replace alpha value
                    $Var = AddControlledName($Field, $Var);
                } elseif ($Field->type() == MetadataSchema::MDFTYPE_TREE) {
                    # create new classification if needed, replace alpha value
                    $Var = AddClassification($Field, $Var);
                }
                $FieldArray[$Index] = $Field;
                $ValueArray[$Field->id()] = $Var;
            } else {
                $SpecialArray[$Index] = trim($Var);
            }
        }

        # old format with ControlledName/ControlleNameTypeName pairs
        $Key = array_search("ControlledName", $NameArray);
        if ($Key !== false && !is_null($Key)) {
            $Value = $SpecialArray[$Key];
            $Key = array_search("ControlledNameTypeName", $NameArray);
            $Field = $Schema->getField($SpecialArray[$Key]);
            if (is_object($Field)) {
                $Value = AddControlledName($Field, $Value);
                $ValueArray[$Field->id()] = $Value;
            }
        }

        # old format with ClassificationName/ClassificationTypeId pairs
        $Key = array_search("ClassificationName", $NameArray);
        if ($Key !== false && !is_null($Key)) {
            $Value = $SpecialArray[$Key];
            $Key = array_search("ClassificationTypeId", $NameArray);

            # compensate for bad data (missing type id)
            if (!is_numeric($SpecialArray[$Key])) {
                $IntConfig = InterfaceConfiguration::getInstance();
                $SpecialArray[$Key] = $IntConfig->getInt("BrowsingFieldId");
            }

            $Field = $Schema->getField($SpecialArray[$Key]);
            if (is_object($Field)) {
                $Value = AddClassification($Field, $Value);
                $ValueArray[$Field->id()] = $Value;
            }
        }

        # skip the rest if we're not importing records
        if (!$ImportingRecords) {
            continue;
        }

        # grab the UniqueField and Description values from the array
        if ($UniqueField == -1) {
            $TitleFieldKey = array_search("Title", $NameArray);
            if ($TitleFieldKey === false) {
                throw new Exception(
                    "Unable to locate Title field."
                );
            }
            if (!isset($FieldArray[$TitleFieldKey])) {
                $_SESSION["ImportComplete"] = $ImportComplete;
                $ErrorMessage =
                    "Error: No value for Title field on line $TotalLineCount.<br>".
                    "Correct the problem and try importing again.<br>";
                $_SESSION["ErrorMessage"] = $ErrorMessage;
                UnsetSessionVars();
                $GLOBALS["AF"]->SetJumpToPage("ImportData");
                return false;
            }

            $Title = addslashes(
                (string)$ValueArray[$FieldArray[$TitleFieldKey]->id()]
            );

            $DescriptionKey = array_search("Description", $NameArray);
            if ($DescriptionKey === false) {
                throw new Exception(
                    "Unable to locate Description field."
                );
            }
            if (!isset($FieldArray[$DescriptionKey])) {
                $_SESSION["ImportComplete"] = $ImportComplete;
                $ErrorMessage =
                    "Error: No value for Description field on line $TotalLineCount.<br>".
                    "Correct the problem and try importing again.<br>";
                $_SESSION["ErrorMessage"] = $ErrorMessage;
                UnsetSessionVars();
                $GLOBALS["AF"]->SetJumpToPage("ImportData");
                return false;
            }
            $Description = addslashes(
                (string)$ValueArray[$FieldArray[$DescriptionKey]->id()]
            );

            $SqlCondition = "Title=\"".$Title."\" "
                ."AND Description=\"".$Description."\"";
        } else {
            $UniqueFieldKey = array_search($UniqueField, $NameArray);
            if ($UniqueFieldKey === false) {
                throw new Exception(
                    "Unable to locate user-specified unique field (".$UniqueField.")"
                );
            }

            $Field = $FieldArray[$UniqueFieldKey];
            $UniqueFieldValue = addslashes(
                (string)$ValueArray[$Field->id()]
            );
            $UniqueFieldDBName = $Field->DBFieldName();

            $SqlCondition = $UniqueFieldDBName."=\"".$UniqueFieldValue."\"";
        }

        if ($Debug) {
            $DebugInfo .= "SqlCondition = ".$SqlCondition."<br/>";
        }

        if ($SqlCondition != $LastSqlCondition) {
            $Resources = $RFactory->getItemIds($SqlCondition);

            if (count($Resources) == 0) {
                # create new temporary record
                $Resource = Record::create(MetadataSchema::SCHEMAID_DEFAULT);

                # handle special fields
                if (array_search("Added By Id", $NameArray) === false) {
                    $Resource->set("Added By Id", $CurrentUserId);
                }

                if (array_search("Last Modified By Id", $NameArray) === false) {
                    $Resource->set("Last Modified By Id", $CurrentUserId);
                }

                $Key = array_search("Date Of Record Creation", $NameArray);
                if ($Key === false) {
                    $Resource->set("Date Of Record Creation", $TodaysDate);
                } else {
                    $Field = $FieldArray[$Key];
                    $DORC = explode(" ", (string)$ValueArray[$Field->id()]);
                    $Date = new Date($DORC[0]);
                    $DateBegin = $Date->BeginDate();
                    $Resource->set("Date Of Record Creation", $DateBegin);
                }

                $Key = array_search("Date Last Modified", $NameArray);
                if ($Key == false) {
                    $Resource->set("Date Last Modified", $TodaysDate);
                } else {
                    $Field = $FieldArray[$Key];
                    $DORC = explode(" ", (string)$ValueArray[$Field->id()]);
                    $Date = new Date($DORC[0]);
                    $DateBegin = $Date->BeginDate();
                    $Resource->set("Date Last Modified", $DateBegin);
                }

                # convert to real resource
                $Resource->IsTempRecord(false);
                $ResourceId = $Resource->id();

                # make sure search and recommender databases are updated
                $SearchEngine->QueueUpdateForItem($ResourceId);
                $Recommender->QueueUpdateForItem($ResourceId);

                if ($Debug) {
                    $DebugInfo .= "ResourceId = $ResourceId<br>";
                }

                # keep track of number of resources added
                $ResourceCount++;
                $_SESSION["ResourceCount"] = $ResourceCount;

                # cache the last title
                $LastSqlCondition = $SqlCondition;
            } elseif (count($Resources) == 1) {
                # this resource already exists
                # should only be one matching Resources record
                $ResourceId = $Resources[0];
                $Resource = new Record($ResourceId);
            } else {
                # otherwise duplicate resources exist!
                $_SESSION["ImportComplete"] = $ImportComplete;
                $ErrorMessage =
                    "Error: Multiple Resources matching \"".$SqlCondition
                    ."\" encountered.<br>"
                    ."Please select Back and correct the problem on line "
                    .$TotalLineCount.".";
                $_SESSION["ErrorMessage"] = $ErrorMessage;
                UnsetSessionVars();
                $GLOBALS["AF"]->SetJumpToPage("ImportData");
                return false;
            }
        }

        # now set each Resource field
        foreach ($ValueArray as $FieldId => $Value) {
            if (!empty($Value)) {
                if (is_object($Resource)) {
                    if ($Debug) {
                        $DebugInfo .= "ResourceId=".$Resource->id().
                            ": Setting FieldId $FieldId to $Value<br>";
                    }

                    $Field = $Schema->GetField($FieldId);
                    if (is_object($Field) &&
                        $Field->Type() == MetadataSchema::MDFTYPE_REFERENCE) {
                        $ReferenceArray[$Field->id()][$Resource->id()][] =
                            $Value;
                    } elseif (is_object($Field) &&
                            $Field->Type() == MetadataSchema::MDFTYPE_USER) {
                        # if we don't have a User object for this one
                        # user, create one by attempting to look them up
                        if (!isset($UserMap[$Value])) {
                            if (!isset($UFactory)) {
                                $UFactory = new UserFactory();
                            }
                            $UserMap[$Value] = (($Value >= 0)
                                            && $UFactory->userExists((int)$Value))
                                    ? new User($Value) : false;
                        }

                        # if a valid user was found for this value,
                        # add them to the field
                        if ($UserMap[$Value] !== false) {
                            $Resource->set($FieldId, $UserMap[$Value]);
                        }
                    } else {
                        $Resource->set($FieldId, $Value);
                    }
                }
            }
        }
    }

    return true;
}

/**
 * Used to unset session vars when reporting an error or in the post-processing call
 */
function UnsetSessionVars(): void
{
    foreach (array("FSeek", "ImportComplete", "ResourceCount", "ControlledNameCount",
        "ClassificationCount", "TotalLineCount", "NameArray", "TempFile",
        "NumberOfFields", "UniqueField",
        "Debug","ReferenceArray"
    ) as $Var) {
            unset($_SESSION[$Var]);
    }
}

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
global $ClassificationCount;
global $ControlledNameCount;
global $Debug;
global $DebugInfo;
global $FSeek;
global $ImportComplete;
global $NameArray;
global $NumberOfFields;
global $ResourceCount;
global $TotalLineCount;
global $UniqueField;
global $fp;
global $ReferenceArray;

# check if current user is authorized
if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

# be sure we're able to gracefully deal with screwy line endings
@ini_set("auto_detect_line_endings", "1");

# load up passed thru vars
foreach (array("FSeek", "ImportComplete", "ResourceCount", "ControlledNameCount",
    "ClassificationCount", "TotalLineCount", "NameArray", "TempFile",
    "NumberOfFields", "UniqueField", "ReferenceArray"
) as $Var) {
    if (isset($_SESSION[$Var])) {
        $$Var = $_SESSION[$Var];
    }
}

$UniqueField = $_SESSION["UniqueField"];
$Debug = $_SESSION["Debug"];
$TempFile = $_SESSION["Path"];
$fp = fopen($TempFile, 'r');

if ($fp === false) {
    throw new Exception(
        "Unable to open import file"
    );
}

## Initialize import variables (they'll be null the first time through):
foreach (array("ImportComplete", "FSeek","ResourceCount",
    "ControlledNameCount","ClassificationCount"
) as $Var) {
    if (is_null($$Var)) {
        $$Var = 0;
    }
}

# first time through
if ($FSeek == 0) {
    $Result = FirstTimeThrough();

    # stop if we hit an error
    if ($Result === false) {
        return;
    }
}

# seek to the next line
if ($FSeek > 0) {
    fseek($fp, $FSeek);
}

# the main work happens here
$Result = DoWhileLoop();

# stop if we hit an error
if ($Result === false) {
    return;
}

# end of file reached?
if (feof($fp)) {
    $ImportComplete = 1;
}

# register some key variables for other html code
foreach (array("ImportComplete","ResourceCount","ControlledNameCount",
    "ClassificationCount","TotalLineCount","NameArray","FSeek",
    "TempFile","NumberOfFields","ReferenceArray","UniqueField",
    "Debug"
) as $Var) {
    # the $$ syntax gets the value of a variable named by a variable
    $_SESSION[$Var] = $$Var;
}

# time to auto-refresh?
if ($ImportComplete == 0) {
    $GLOBALS["AF"]->SetJumpToPage("index.php?P=ImportDataExecute", 1);
} else {
    global $ReferenceMessages;

    $ReferenceMessages = array();

    # if we're done with our import, then try to resolve any
    # references it contained

    # first, pull out factories for each schema we wish to consider
    $RFactories = array();
    $Schemas = MetadataSchema::GetAllSchemas();
    ksort($Schemas);

    foreach ($Schemas as $SchemaId => $Schema) {
        if ($SchemaId != MetadataSchema::SCHEMAID_USER) {
            $RFactories[$SchemaId] = new RecordFactory($SchemaId);
        }
    }
    # for each reference field
    foreach ($ReferenceArray as $FieldId => $ResourceReferences) {
        # Foreach resource having a value for that field:
        foreach ($ResourceReferences as $ResourceId => $RefTargets) {
            $ThisResource = new Record($ResourceId);

            # for each value that it has
            foreach ($RefTargets as $RefTarget) {
                # iterate through the schemas, looking for resources that
                # match the Title or Url of our resource and stopping
                # after the first match is found

                $Candidates = [];
                foreach ($RFactories as $SchemaId => $RFactory) {
                    # construct an array of values that we're potentially looking for
                    $ValuesToMatch = array();
                    foreach (array("Title", "Url") as $TgtFieldName) {
                        $TgtField = $Schemas[$SchemaId]->GetFieldByMappedName(
                            $TgtFieldName
                        );
                        if ($TgtField !== null) {
                            $ValuesToMatch[$TgtField->id()] = $RefTarget;
                        }
                    }

                    # if this schema had at least one field we can
                    # search, look for matches
                    if (count($ValuesToMatch)) {
                        $Candidates = $RFactory->getIdsOfMatchingRecords(
                            $ValuesToMatch,
                            false
                        );
                    }
                    # if we found any matches, then we're done
                    if (count($Candidates)) {
                        break;
                    }
                }

                # complain if there was no match at all
                if (count($Candidates) == 0) {
                    $ReferenceMessages[] =
                        "Unable to resolve reference from '".
                        $ThisResource->get("Title")."' to '".$RefTarget."'";
                } elseif (count($Candidates) == 1) {
                    # if there was just one match, add the reference to the field
                    $Value = $ThisResource->get($FieldId);
                    $Value[] = $Candidates[0];
                    $ThisResource->set($FieldId, $Value);
                } else {
                    # if somehow there were a pile of matches,
                    # complain about that too
                    $ReferenceMesages[] =
                        "Reference from '".$ThisResource->get("Title")."' ".
                        "to '".$RefTarget."' is not unique";
                }
            }
        }
    }
    # remove file from database
    $ToDelete = new File($_SESSION["FileId"]);
    $ToDelete->Destroy();
}

PageTitle("Import Data");

# register post-processing function with the application framework
$GLOBALS["AF"]->AddPostProcessingCall(
    __NAMESPACE__."\\PostProcessingFn",
    $TempFile,
    $fp,
    $ImportComplete
);

# post-processing call
/**
 * Post-processing tasks: if import is complete, delete import file and
 * clean import status vars out of $_SESSION.
 * @param string $TempFile Path to file of imported data.
 * @param resource $fp File descriptor for import file.
 * @param int $ImportComplete One if the import has completed, zero
 *     otherwise.
 */
function PostProcessingFn($TempFile, $fp, $ImportComplete): void
{
    if ($ImportComplete == 1) {
        # close file
        fclose($fp);
        UnsetSessionVars();
    }
}
