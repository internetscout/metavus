<?PHP
#
#   FILE:  CKEditorSetup.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

# require the CKEditor javascript
$GLOBALS["AF"]->RequireUIFile("ckeditor.js");
$GLOBALS["AF"]->RequireUIFile("ckeditor_setup.js");
$GLOBALS["AF"]->RequireUIFile("adapters/jquery.js");
