<?PHP

# the first chunked request will come with a single `F_` form field
foreach (array_keys($_POST) as $Key){
    if (preg_match("/^F_/", $Key)) {
        define("ENTRY_FIELD", $Key);
        break;
    }
}

# subsequent requests come with a `filepond` form field
if (!defined("ENTRY_FIELD")){
    define("ENTRY_FIELD", "filepond");
}

// name to use for the file metadata object
const METADATA_FILENAME = '.metadata';

// where to write files
define("TRANSFER_DIR", realpath(__DIR__.'/../../../tmp').'/FilePondUploads');

// create TRANSFER_DIR if it does not exists
if (!is_dir(TRANSFER_DIR)) {
    mkdir(TRANSFER_DIR, 0755);
}
