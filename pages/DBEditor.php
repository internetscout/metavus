<?PHP
#
#   FILE:  DBEditor.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Metadata Field Editor");

if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

# retrieve the schema ID
$SchemaId = StdLib::getArrayValue($_GET, "SC", MetadataSchema::SCHEMAID_DEFAULT);

# construct the schema
$H_Schema = new MetadataSchema($SchemaId);

# retrieve if user should be prompted to run a search DB rebuild
$H_PromptDBRebuild = StdLib::getArrayValue($_GET, "PSDBR", false);
