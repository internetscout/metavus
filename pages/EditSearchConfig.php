<?PHP
#
#   FILE:  EditSearchConfig.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2006-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\FormUI;
use Metavus\SearchEngine;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

# retrieve synonym list
$SearchEngine = new SearchEngine();
$SynonymList = $SearchEngine->getAllSynonyms();


# place synonyms into a single string, each word+synonyms on a new line (formatted for parsing).
$SynonymListText = "";
foreach ($SynonymList as $Word => $Synonyms) {
        $SynonymListText .= $Word." = ".join(", ", $Synonyms)."\n";
}

# set form field to display in FormUI
$FormFields = [
    "Synonyms" => [
        "Type" => FormUI::FTYPE_PARAGRAPH,
        "Label" => "Synonym List",
        "Value" => $SynonymListText,
        "Rows" => 25,
        "Width" => 50,
    ],
];

# instantiate formUI using form fields
$H_FormUI = new FormUI($FormFields);

# act on any button press
$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Save Changes":
        # check values and bail out if any are invalid
        if ($H_FormUI->validateFieldInput()) {
            return;
        }

        # get updated form values
        $ConfigValues = $H_FormUI->getNewValuesFromForm();

        # get synonym list using form value
        $SynonymList = $ConfigValues["Synonyms"];

        if (strlen(trim($SynonymList)) > 0) {
            # convert synonym list into array of lines
            $SynonymList = explode("\n", $SynonymList);

            # update synonym list
            $SearchEngine = new SearchEngine();
            try {
                $SynonymList = $SearchEngine->parseSynonymsFromText($SynonymList);
            } catch (Exception $Exception) {
                $H_FormUI->logError($Exception->getMessage());
                return;
            }
            $SearchEngine->setAllSynonyms($SynonymList);
        }

        # set page to administration
        $AF->setJumpToPage("SysAdmin");
        break;

    case "Cancel":
        # don't save anything and set page to administration.
        $AF->setJumpToPage("SysAdmin");
        break;
}
