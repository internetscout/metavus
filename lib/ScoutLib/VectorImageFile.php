<?PHP
#
#   FILE: VectorImageFile.php
#
#   Part of the ScoutLib application support library
#   Copyright 2021 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

use Exception;
use InvalidArgumentException;

/**
 * Class to encapsulate image manipulation operations for vector format images.
 */
class VectorImageFile extends ImageFile
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param string $SourceFileName Name of image file.
     */
    public function __construct(string $SourceFileName)
    {
        parent::__construct($SourceFileName);

        $MimeType = mime_content_type($this->SourceFileName);

        switch ($MimeType) {
            case "image/svg":
            case "image/svg+xml":
                $this->ImageFormat = self::IMGTYPE_SVG;
                break;

            default:
                throw new InvalidArgumentException(
                    $this->SourceFileName." is not in a supported image format."
                );
        }
    }

    /**
     * Save image to a file with a new name and (optionally) a new type.
     * @param string $FileName New name for image file.
     * @param int $NewImageType New type for image.  (OPTIONAL)
     */
    public function saveAs(string $FileName, int $NewImageType = null)
    {
        # error out if destination file exists and is not writable
        if (file_exists($FileName) && (is_writable($FileName) != true)) {
            throw new Exception(
                $FileName." exists but is not writeable."
            );
        }

        # error out if destination directory does not exist or cannot be written to
        if (is_writable(dirname($FileName)) != true) {
            throw new Exception(
                dirname($FileName)." is not writeable."
            );
        }

        # if no image type specified try to determine based on file name or use source file type
        if (is_null($NewImageType)) {
            $NewImageType = $this->getImageTypeForFile($FileName);
        }

        # if a conversion was requested, throw an error as we do not support
        # converting SVGs to other formats
        if ($NewImageType != self::IMGTYPE_SVG) {
            throw new InvalidArgumentException(
                "Converting from vector to non-vector image formats "
                ."is not supported."
            );
        }

        # otherwise, attempt to copy the file
        $Result = copy($this->SourceFileName, $FileName);
        if ($Result !== true) {
            throw new Exception(
                "Error copying source image."
            );
        }
    }
}
