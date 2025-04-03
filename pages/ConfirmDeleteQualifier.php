<?PHP
#
#   FILE:  ConfirmDeleteQualifier.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2001-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\MetadataSchema;
use Metavus\Qualifier;
use Metavus\RecordFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

PageTitle("Confirm Delete Qualifier");

# ----- MAIN -----------------------------------------------------------------

$H_QIString = StdLib::getFormValue("QI", "");
$H_QualifierIds = explode("|", $H_QIString);

if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

# act on any button press
$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Delete":
        $Schema = new MetadataSchema();
        $RFactory = new RecordFactory();

        foreach ($H_QualifierIds as $QualifierId) {
            $Qualifier = new Qualifier($QualifierId);
            $Schema->RemoveQualifierAssociations($Qualifier);
            $RFactory->ClearQualifier($Qualifier);
            $Qualifier->destroy();
        }
        $AF->SetJumpToPage("AddQualifier");
        break;

    case "Cancel":
        $AF->SetJumpToPage("AddQualifier");
        break;
}
