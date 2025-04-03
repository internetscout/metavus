<?PHP
#
#   FILE:  CKEditorSetup.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

# require the CKEditor javascript
$AF = ApplicationFramework::getInstance();

$AF->RequireUIFile("ckeditor.js");
$AF->RequireUIFile("ckeditor_setup.js");
$AF->RequireUIFile("adapters/jquery.js");
