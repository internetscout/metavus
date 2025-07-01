<?PHP
#
#   FILE:  ConfirmDeleteQualifier.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2001-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\MetadataSchema;
use Metavus\Qualifier;
use Metavus\RecordFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Confirm Delete Qualifier");

$H_QIString = StdLib::getFormValue("QI", "");
$H_QualifierIds = explode("|", $H_QIString);

if (!User::requirePrivilege(PRIV_SYSADMIN)) {
    return;
}

# act on any button press
$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Delete":
        $Schema = new MetadataSchema();
        $RFactory = new RecordFactory();

        foreach ($H_QualifierIds as $QualifierId) {
            $Qualifier = new Qualifier($QualifierId);
            $Schema->removeQualifierAssociations($Qualifier);
            $RFactory->clearQualifier($Qualifier);
            $Qualifier->destroy();
        }
        $AF->setJumpToPage("AddQualifier");
        break;

    case "Cancel":
        $AF->setJumpToPage("AddQualifier");
        break;
}
