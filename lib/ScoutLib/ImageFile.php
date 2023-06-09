<?PHP
#
#   FILE: ImageFile.php
#
#   Part of the ScoutLib application support library
#   Copyright 2021 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

use InvalidArgumentException;

/**
 * Class to encapsulate image manipulation operations common to all image
 * formats.
 */
abstract class ImageFile
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    # image type definitions
    # (these are purposefully different from those defined by PHP GD lib)
    const IMGTYPE_UNKNOWN = 0;
    const IMGTYPE_JPEG = 1;
    const IMGTYPE_GIF = 2;
    const IMGTYPE_BMP = 4;
    const IMGTYPE_PNG = 8;
    const IMGTYPE_SVG = 16;

    /**
     * Class constructor.
     * @param string $SourceFileName Name of image file.
     */
    public function __construct(string $SourceFileName)
    {
        $this->SourceFileName = trim($SourceFileName);

        if (!is_readable($this->SourceFileName)) {
            throw new InvalidArgumentException(
                $this->SourceFileName." is not readable."
            );
        }
    }

    /**
     * Save image to a file with a new name and (optionally) a new type.
     * @param string $FileName New name for image file.
     * @param int $NewImageType New type for image.  (OPTIONAL)
     */
    abstract public function saveAs(string $FileName, int $NewImageType = null);

    /**
     * Get the image type.
     * @return int Image type.
     */
    public function format(): int
    {
        return $this->ImageFormat;
    }

    /**
     * Get the MIME type for the image.
     * @return string|false Returns the MIME type for the image, or FALSE if
     *      unable to determine the MIME type.
     */
    public function mimeType()
    {
        if (isset(self::$MimeTypes[$this->format()])) {
            return self::$MimeTypes[$this->format()];
        }

        if (strlen($this->SourceFileName)) {
            return mime_content_type($this->SourceFileName);
        }

        return false;
    }

    /**
     * Get the file name extension for the image.
     * @return string Extension (e.g. "jpg", "gif").
     */
    public function extension(): string
    {
        return self::$ImageFileExtensions[$this->format()];
    }

    /**
     * Attempt to determine the format of an image file.
     * @param string $Path Path to the file.
     * @return int An IMGTYPE_ constant.
     */
    public static function getFileFormat(string $Path) : int
    {
        # try to determine format by mime type
        $MimeType = @mime_content_type($Path);
        if ($MimeType !== false) {
            if ($MimeType == "image/svg") {
                $MimeType = "image/svg+xml";
            }
            $TypeLUT = array_flip(self::$MimeTypes);
            if (isset($TypeLUT[$MimeType])) {
                return $TypeLUT[$MimeType];
            }
        }

        # fall back to using the file extension
        $Patterns = [
            "/\\.jpeg$/i" => self::IMGTYPE_JPEG,
            "/\\.jpg$/i" => self::IMGTYPE_JPEG,
            "/\\.gif$/i" => self::IMGTYPE_GIF,
            "/\\.bmp$/i" => self::IMGTYPE_BMP,
            "/\\.png$/i" => self::IMGTYPE_PNG,
        ];
        foreach ($Patterns as $Pattern => $Type) {
            if (preg_match($Pattern, $Path)) {
                return $Type;
            }
        }

        return self::IMGTYPE_UNKNOWN;
    }

    /**
     * Determine if the given file is a vector image file.
     * @return bool TRUE for vector files, FALSE otherwise.
     */
    public static function fileIsVectorFormat(string $Path) : bool
    {
        $FileFormat = self::getFileFormat($Path);

        $VectorTypeBitmask = self::IMGTYPE_SVG;
        if (($FileFormat & $VectorTypeBitmask) != 0) {
            return true;
        }

        return false;
    }

    /**
     * Get supported image types.
     * @return int Supported formats ORed together.
     */
    public static function supportedFormats(): int
    {
        # start out assuming no formats are supported
        $Supported = 0;

        # if JPEG is supported by PHP
        if (defined("IMG_JPG") && (imagetypes() & IMG_JPG)) {
            # add JPEG to list of supported formats
            $Supported |= self::IMGTYPE_JPEG;
        }

        # if GIF is supported by PHP
        if (defined("IMG_GIF") && (imagetypes() & IMG_GIF)) {
            # add GIF to list of supported formats
            $Supported |= self::IMGTYPE_GIF;
        }

        # if PNG is supported by PHP
        if (defined("IMG_PNG") && (imagetypes() & IMG_PNG)) {
            # add PNG to list of supported formats
            $Supported |= self::IMGTYPE_PNG;
        }

        # if BMP is supported by PHP
        if (defined("IMG_BMP") && (imagetypes() & IMG_BMP)) {
            # add BMP to list of supported formats
            $Supported |= self::IMGTYPE_BMP;
        }

        # svg is always supported as it doesn't require anything from PHP
        $Supported |= self::IMGTYPE_SVG;

        # report to caller what formats are supported
        return $Supported;
    }

    /**
     * Get names (extensions in upper case) of supported image formats.
     * @return array Names of supported formats.
     */
    public static function supportedFormatNames(): array
    {
        # assume that no formats are supported
        $FormatNames = array();

        # retrieve supported formats
        $SupportedFormats = self::supportedFormats();

        # for each possible supported format
        foreach (self::$ImageFileExtensions as $ImageType => $ImageExtension) {
            # if format is supported
            if ($ImageType & $SupportedFormats) {
                # add format extension to list of supported image format names
                $FormatNames[] = strtoupper($ImageExtension);
            }
        }

        # return supported image format names to caller
        return $FormatNames;
    }

    /**
     * Get the file extension for a given image format.
     * @param int $Format Image format as an IMGTYPE_ constant.
     * @return string File extension.
     */
    public static function extensionForFormat(int $Format) : string
    {
        if (!isset(self::$ImageFileExtensions[$Format])) {
            throw new InvalidArgumentException("Invalid format");
        }

        return self::$ImageFileExtensions[$Format];
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Determine desired image type for a new image based on file name if
     * possible, falling back to the type of the current image otherwise.
     * @param string $FileName Destination file name.
     * @return int Image type.
     */
    protected function getImageTypeForFile(string $FileName) : int
    {
        $DstExtension = strtolower(
            pathinfo($FileName, PATHINFO_EXTENSION)
        );

        $FormatMap = array_flip(ImageFile::$ImageFileExtensions);
        if (isset($FormatMap[$DstExtension])) {
            $NewImageType = $FormatMap[$DstExtension];
        } else {
            $NewImageType = $this->format();
        }

        return $NewImageType;
    }

    protected $SourceFileName;
    protected $ImageFormat;

    # image file extensions
    protected static $ImageFileExtensions = array(
        self::IMGTYPE_JPEG => "jpg",
        self::IMGTYPE_GIF => "gif",
        self::IMGTYPE_BMP => "bmp",
        self::IMGTYPE_PNG => "png",
        self::IMGTYPE_SVG => "svg",
    );

    # mime types for images
    protected static $MimeTypes = [
        self::IMGTYPE_JPEG => "image/jpeg",
        self::IMGTYPE_PNG => "image/png",
        self::IMGTYPE_GIF => "image/gif",
        self::IMGTYPE_BMP => "image/bmp",
        self::IMGTYPE_SVG => "image/svg+xml",
    ];
}
