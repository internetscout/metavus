<?PHP
#
#   FILE:  PopulateField.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\VocabularyFactory;

PageTitle("Populate Metadata Field");

# ----- CONFIGURATION  -------------------------------------------------------

# location of vocabulary (.voc) files
$PathToVocabularyFiles = "data/Vocabularies/";

# ----- MAIN -----------------------------------------------------------------

# check that user has permission for this
if (!CheckAuthorization(PRIV_COLLECTIONADMIN)) {
    return;
}

# load metadata field
if (isset($_GET["ID"])) {
    $FieldId = intval($_GET["ID"]);
    if (MetadataSchema::FieldExistsInAnySchema($FieldId)) {
        $G_Field = new MetadataField($FieldId);
    }
}
if (!isset($G_Field) || ($G_Field->Status() != MetadataSchema::MDFSTAT_OK)) {
    $G_ErrorMessages[] = "Could not load metadata field.";
} elseif (!($G_Field->Type() & (MetadataSchema::MDFTYPE_CONTROLLEDNAME |
                              MetadataSchema::MDFTYPE_OPTION |
                              MetadataSchema::MDFTYPE_TREE))) {
    $G_ErrorMessages[] =
            "The <i>".$G_Field->GetDisplayName()
            ."</i> field is not one of the"
            ." types for which population is support.  Only <b>Controlled Name</b>,"
            ." <b>Option</b>, and <b>Tree</b> fields can be populated with"
            ." the prepackaged vocabularies.";
}

# if vocabulary specified
if (isset($_GET["VH"])) {
    # load specified vocabulary
    $VocFact = new VocabularyFactory($PathToVocabularyFiles);
    $G_Vocabulary = $VocFact->GetVocabularyByHash($_GET["VH"]);
    if ($G_Vocabulary === null) {
        $G_ErrorMessages[] = "No vocabulary file found with specified hash.";
    } else {
        # if vocabulary import was confirmed
        if (isset($_GET["CP"]) && !isset($G_ErrorMessages)) {
            # import specified vocabulary
            $G_IsVocabImport = true;

            $G_AddedItemCount = $G_Field->LoadVocabulary($G_Vocabulary);
        } else {
            # set flag to indicate preview/confirm
            $G_IsVocabPreview = true;
        }
    }
} else {
    # load available vocabularies
    $VocFact = new VocabularyFactory($PathToVocabularyFiles);
    $G_Vocabularies = $VocFact->GetVocabularies();
    if (count($G_Vocabularies) == 0) {
        $G_ErrorMessages[] = "No vocabulary files found in <i>"
                           .$PathToVocabularyFiles."</i>.";
    }
}
