<?PHP
#
#   FILE:  Edit.php (BatchEdit plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\ChangeSetEditingUI;
use Metavus\MetadataSchema;
use Metavus\Plugins\BatchEdit;
use Metavus\Plugins\Folders\Folder;
use Metavus\Record;
use Metavus\User;

$H_Plugin = BatchEdit::getInstance();

# retrieve user currently logged in
$H_User = User::getCurrentUser();

# make sure the user is allowed to do batch editing
if (!$H_Plugin->getConfigSetting("RequiredPrivs")->MeetsRequirements($H_User)) {
    User::handleUnauthorizedAccess();
    return;
}

$H_Folder = new Folder(intval($_GET["FI"]));

# iterate over the items in this folder, determining which schemas
# they belong to
$H_Schemas = [];
foreach ($H_Folder->GetItemIds() as $ResourceId) {
    $Resource = new Record($ResourceId);
    $SchemaId = $Resource->getSchemaId();

    if (!isset($H_Schemas[$SchemaId])) {
        $H_Schemas[$SchemaId] = new MetadataSchema($SchemaId);
    }
}
ksort($H_Schemas);

# iterate over our schemas, constructing an editing interface for each
#  of them
$H_Editors = [];
foreach ($H_Schemas as $SchemaId => $Schema) {
    $H_Editors[$SchemaId] = new ChangeSetEditingUI(
        "FEUI".$SchemaId,
        $SchemaId
    );
    $H_Editors[$SchemaId]->AddFieldButton(
        "Add Field",
        $H_Plugin->getConfigSetting("AllowedFields")
    );
}

$H_ChangedResources = [];
if (isset($_POST["Submit"]) && $_POST["Submit"] == "Apply All Changes") {
    # iterate through all our editing forms, pulling out the change
    # data for each
    $ChangeData = [];
    $ErrorCount = 0;
    foreach ($H_Schemas as $SchemaId => $Schema) {
        $ErrorCount += $H_Editors[$SchemaId]->validateFieldInput();
        $ChangeData[$SchemaId] =
                $H_Editors[$SchemaId]->GetValuesFromFormData();
    }

    # there is a field with an incorrect value for any schema, bail here and
    # do not apply changes to any records
    if ($ErrorCount > 0) {
        return;
    }

    # iterate through all the items in this folder
    foreach ($H_Folder->GetItemIds() as $ResourceId) {
        $Resource = new Record($ResourceId);

        # if we have any changes for resources in this schema
        if (count($ChangeData[$Resource->getSchemaId()])) {
            # apply them to this resource
            $ResourceWasChanged = $Resource->applyListOfChanges(
                $ChangeData[$Resource->getSchemaId()],
                $H_User
            );

            # and if anything was modified by the changes,
            # note that this resource was tweaked
            if ($ResourceWasChanged) {
                $H_ChangedResources[] = $Resource;
            }
        }
    }
}
