<?PHP
#
#   FILE:  FixityChecks.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

namespace Metavus;

/**
 * Get a field from a File object for display.
 * @param int $Id FileId to extract data from.
 * @param string $Function File:: function to call to get data.
 * @return mixed Requested data.
 */
function getFileData(int $Id, string $Function)
{
    static $File = null;

    if (is_null($File) || $File->id() != $Id) {
        $File = new File($Id);
    }
    return $File->$Function();
}

/**
 * Get a field from an Image object for display.
 * @param int $Id ImageId to extract data from.
 * @param string $Function Image:: function to call to get data.
 * @return mixed Requested data.
 */
function getImageData(int $Id, string $Function)
{
    static $Image = null;

    if (is_null($Image) || $Image->id() != $Id) {
        $Image = new Image($Id);
    }
    return $Image->$Function();
}

# ----- MAIN -----------------------------------------------------------------

# check if current user is authorized
if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$ItemsPerPage = 50;

# define fields for the list of files
$FileFields = [
    "FileId" => [
        "Heading" => "File ID",
        "Sortable" => false,
        "ValueFunction" => function ($ItemId) {
            return $ItemId;
        }
    ],
    "FileName" => [
        "Heading" => "File Name",
        "Sortable" => false,
        "AllowHTML" => true,
        "ValueFunction" => function ($ItemId) {
            $FileName = getFileData($ItemId, "name");
            $FilePath = getFileData($ItemId, "getNameOfStoredFile");
            return "<a href='".$FilePath."'>"
                .htmlspecialchars($FileName)."</a>";
        }
    ],
    "Record" => [
        "Heading" => "Associated Record",
        "Sortable" => false,
        "AllowHTML" => true,
        "ValueFunction" => function ($ItemId) {
            $RecordId = getFileData($ItemId, "resourceId");

            if ($RecordId == Record::NO_ITEM) {
                return "(none)";
            }

            if (!Record::itemExists($RecordId)) {
                return "(Invalid record ID ".$RecordId.")";
            }

            $FieldId = getFileData($ItemId, "fieldId");

            $Record = new Record($RecordId);

            $Title = $Record->getMapped("Title");
            if (is_null($Title)) {
                $Title = "<i>Record ".$Record->id()."</i>";
            }

            return "<a href='".$Record->getViewPageUrl()."'>".
                $Title."</a> for field "
                ."<i>".$Record->getSchema()->getField($FieldId)->name()."</i>";
        }
    ]
];

# define fields for the list of images
$ImageFields = [
    "ImageId" => [
        "Heading" => "Image ID",
        "Sortable" => false,
        "ValueFunction" => function ($ItemId) {
            return $ItemId;
        },
    ],
    "FileName" => [
        "Heading" => "File Name",
        "Sortable" => false,
        "AllowHTML" => true,
        "ValueFunction" => function ($ItemId) {
            $Image = new Image($ItemId);
            return "<a href='".$Image->url("mv-image-large")
                ."'>".$Image->getFullPathForOriginalImage()."</a>";
        },
    ],
    "Record" => [
        "Heading" => "Associated Record",
        "Sortable" => false,
        "AllowHTML" => true,
        "ValueFunction" => function ($ItemId) {
            $RecordId = getImageData($ItemId, "getIdOfAssociatedItem");
            $FieldId = getImageData($ItemId, "getFieldId");

            if (!Record::itemExists($RecordId)) {
                return "Invalid RecordId: ".$RecordId;
            }

            $Record = new Record($RecordId);
            $Title = $Record->getMapped("Title");
            if (is_null($Title)) {
                $Title = "<i>Record ".$Record->id()."</i>";
            }

            return "<a href='".$Record->getViewPageUrl()."'>".
                $Title."</a> for field ".
                "<i>".$Record->getSchema()->getField($FieldId)->name()."</i>";
        }
    ],
];

# get the list of files with problems
$H_FileListUI = new ItemListUI($FileFields);
$H_FileListUI->noItemsMessage("No file integrity problems found.");
$H_FileListUI->itemsPerPage($ItemsPerPage);

$H_Files = (new FileFactory())->getFilesWithFixityProblems();
$H_NumFiles = count($H_Files);

$H_Files = array_slice(
    $H_Files,
    $H_FileListUI->transportUI()->startingIndex(),
    $H_FileListUI->transportUI()->itemsPerPage()
);

# get the list of images with problems
$H_ImageListUI = new ItemListUI($ImageFields);
$H_ImageListUI->noItemsMessage("No image integrity problems found.");
$H_ImageListUI->itemsPerPage($ItemsPerPage);

$H_Images = (new ImageFactory())->getImagesWithFixityProblems();
$H_NumImages = count($H_Images);

$H_Images = array_slice(
    $H_Images,
    $H_ImageListUI->transportUI()->startingIndex(),
    $H_ImageListUI->transportUI()->itemsPerPage()
);
