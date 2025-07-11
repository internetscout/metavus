<?PHP
#
#   FILE:  PopulateField.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

# ----- MAIN -----------------------------------------------------------------

# location of vocabulary (.voc) files
$PathToVocabularyFiles = "data/Vocabularies/";

# check that user has permission for this
if (!User::requirePrivilege(PRIV_COLLECTIONADMIN)) {
    return;
}

# load metadata field
if (isset($_GET["ID"])) {
    $FieldId = intval($_GET["ID"]);
    if (MetadataSchema::fieldExistsInAnySchema($FieldId)) {
        $H_Field = MetadataField::getField($FieldId);
    }
}
if (!isset($H_Field) || ($H_Field->status() != MetadataSchema::MDFSTAT_OK)) {
    $H_ErrorMessages[] = "Could not load metadata field.";
} elseif (!($H_Field->type() & (MetadataSchema::MDFTYPE_CONTROLLEDNAME |
                              MetadataSchema::MDFTYPE_OPTION |
                              MetadataSchema::MDFTYPE_TREE))) {
    $H_ErrorMessages[] =
            "The <i>".$H_Field->getDisplayName()
            ."</i> field is not one of the"
            ." types for which population is support.  Only <b>Controlled Name</b>,"
            ." <b>Option</b>, and <b>Tree</b> fields can be populated with"
            ." the prepackaged vocabularies.";
}

# if vocabulary specified
if (isset($_GET["VH"])) {
    # load specified vocabulary
    $VocFact = new VocabularyFactory($PathToVocabularyFiles);
    $H_Vocabulary = $VocFact->getVocabularyByHash($_GET["VH"]);
    if ($H_Vocabulary === null) {
        $H_ErrorMessages[] = "No vocabulary file found with specified hash.";
    } else {
        # if vocabulary import was confirmed
        if (isset($_GET["CP"]) && !isset($H_ErrorMessages) && isset($H_Field)) {
            # import specified vocabulary
            $H_IsVocabImport = true;

            $H_AddedItemCount = $H_Field->loadVocabulary($H_Vocabulary);
        } else {
            # set flag to indicate preview/confirm
            $H_IsVocabPreview = true;
        }
    }
} else {
    # load available vocabularies
    $VocFact = new VocabularyFactory($PathToVocabularyFiles);
    $H_Vocabularies = $VocFact->getVocabularies();
    if (count($H_Vocabularies) == 0) {
        $H_ErrorMessages[] = "No vocabulary files found in <i>"
                           .$PathToVocabularyFiles."</i>.";
    }
}
