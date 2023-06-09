<?PHP
#
#   FILE: RasterImageFile.php
#
#   Part of the ScoutLib application support library
#   Copyright 2021-2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

use Exception;
use InvalidArgumentException;

/**
 * Class to encapsulate image manipulation operations for raster format images.
 */
class RasterImageFile extends ImageFile
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param string $SourceFileName Name of image file.
     * @param int $ImageFormat Image format as an IMGTYPE_XX constant, if
     *   known. When provided, this value is assumed to be correct and strange
     *   errors will result if it is not.
     */
    public function __construct(
        string $SourceFileName,
        int $ImageFormat = null
    ) {
        parent::__construct($SourceFileName);

        # if format was not provided, detect it
        if (is_null($ImageFormat)) {
            switch (@exif_imagetype($this->SourceFileName)) {
                case IMAGETYPE_GIF:
                    $this->ImageFormat = self::IMGTYPE_GIF;
                    break;

                case IMAGETYPE_JPEG:
                    $this->ImageFormat = self::IMGTYPE_JPEG;
                    break;

                case IMAGETYPE_PNG:
                    $this->ImageFormat = self::IMGTYPE_PNG;
                    break;

                case IMAGETYPE_BMP:
                    $this->ImageFormat = self::IMGTYPE_BMP;
                    break;

                default:
                    throw new InvalidArgumentException(
                        $this->SourceFileName." is not in a supported image format."
                    );
            }
        } else {
            $ValidTypeBitmask = self::IMGTYPE_GIF | self::IMGTYPE_JPEG
                | self::IMGTYPE_PNG | self::IMGTYPE_BMP;
            if (($ValidTypeBitmask & $ImageFormat) == 0) {
                throw new InvalidArgumentException(
                    "Invalid image type provided: ".$ImageFormat
                );
            }
            $this->ImageFormat = $ImageFormat;
        }

        # load image file
        switch ($this->ImageFormat) {
            case self::IMGTYPE_GIF:
                $this->ImageObject = imagecreatefromgif($this->SourceFileName);
                break;

            case self::IMGTYPE_JPEG:
                $this->ImageObject = imagecreatefromjpeg($this->SourceFileName);
                break;

            case self::IMGTYPE_PNG:
                $this->ImageObject = imagecreatefrompng($this->SourceFileName);
                break;

            case self::IMGTYPE_BMP:
                $this->ImageObject = imagecreatefrombmp($this->SourceFileName);
                break;

            default:
                # should be impossible, but static analysis
                throw new Exception(
                    "Invalid image type."
                );
        }

        # if there was an error creating the ImageObject, throw an exception
        if ($this->ImageObject === false) {
            throw new Exception(
                "Unable to create GDImage object."
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
        # if destination file exists and is not writable
        if (file_exists($FileName) && (is_writable($FileName) != true)) {
            throw new Exception(
                $FileName." exists but is not writeable"
            );
        }

        if (is_writable(dirname($FileName)) != true) {
            throw new Exception(
                dirname($FileName)." is not writeable."
            );
        }

        # if no image type specified try to determine based on file name or use source file type
        if ($NewImageType == null) {
            $NewImageType = $this->getImageTypeForFile($FileName);
        }

        # if input and output types both supported
        if (!self::imageFormatSupportedByPhp($NewImageType)) {
            throw new Exception("Unsupported output image type.");
        }

        # get version of image to save
        $DstImage = $this->getImageToSave();

        # save image to new file
        switch ($NewImageType) {
            case self::IMGTYPE_GIF:
                $Result = imagegif($DstImage, $FileName);
                break;

            case self::IMGTYPE_JPEG:
                $Result = imagejpeg($DstImage, $FileName, $this->JpegSaveQuality);
                break;

            case self::IMGTYPE_PNG:
                $Result = imagepng($DstImage, $FileName, $this->PngCompressionLevel);
                break;

            case self::IMGTYPE_BMP:
                $Result = imagebmp($DstImage, $FileName);
                break;

            default:
                # (should not be possible, but included for static analysis)
                throw new Exception(
                    "Internal Error."
                );
        }

        if ($Result === false) {
            throw new Exception(
                "image*() function failed to save image file."
            );
        }
    }

    /**
     * Get horizontal size of image in pixels.
     * @return int Size in pixels.
     */
    public function getXSize(): int
    {
        $this->readSize();
        return $this->ImageXSize;
    }

    /**
     * Get vertical size of image in pixels.
     * @return int Size in pixels.
     */
    public function getYSize(): int
    {
        $this->readSize();
        return $this->ImageYSize;
    }

    /**
     * Specify the size to scale the image to for the next saveAs().
     * Overrides any previous cropping/scaling settings from scaleTo(),
     * scaleXTo(), scaleYTo(), or cropTo() calls.
     * @param int $ScaledXSize New horizontal size.
     * @param int $ScaledYSize New vertical size.
     */
    public function scaleTo(int $ScaledXSize, int $ScaledYSize)
    {
        # save size for scaling
        $this->TransformImageBeforeSaving = "SCALE";
        $this->NewXSize = $ScaledXSize;
        $this->NewYSize = $ScaledYSize;
        $this->NewXOrigin = 0;
        $this->NewYOrigin = 0;
    }

    /**
     * Specify an image width to scale to for the next saveAs(), with the
     * height being automatically determined to preserve the aspect ratio.
     * Overrides any previous cropping/scaling settings from scaleTo(),
     * scaleXTo(), scaleYTo(), or cropTo() calls.
     * @param int $NewXSize New image width.
     */
    public function scaleXTo(int $NewXSize)
    {
        $YXRatio = $this->getYSize() / $this->getXSize();

        $this->TransformImageBeforeSaving = "SCALE";
        $this->NewXSize = $NewXSize;
        $this->NewYSize = round($NewXSize * $YXRatio);
        $this->NewXOrigin = 0;
        $this->NewYOrigin = 0;
    }

    /**
     * Specify an image height to scale to for the next saveAs(), with the
     * width being automatically determined to preserve the aspect ratio.
     * Overrides any previous cropping/scaling settings from scaleTo(),
     * scaleXTo(), scaleYTo(), or cropTo() calls.
     * @param int $NewYSize New image height.
     */
    public function scaleYTo(int $NewYSize)
    {
        $XYRatio = $this->getXSize() / $this->getYSize();

        $this->TransformImageBeforeSaving = "SCALE";
        $this->NewYSize = $NewYSize;
        $this->NewXSize = round($NewYSize * $XYRatio);
        $this->NewXOrigin = 0;
        $this->NewYOrigin = 0;
    }

    /**
     * Specify the size to crop the image to for the next saveAs().
     * Overrides any previous cropping/scaling settings from scaleTo(),
     * scaleXTo(), scaleYTo(), or cropTo() calls.
     * @param int $CroppedXSize New horizontal size.
     * @param int $CroppedYSize New vertical size.
     * @param int $CroppedXOrigin new Left (horizontal) origin for cropped
     *      area.  (OPTIONAL, defaults to 0)
     * @param int $CroppedYOrigin new Top (vertical) origin for cropped
     *      area.  (OPTIONAL, defaults to 0)
     */
    public function cropTo(
        int $CroppedXSize,
        int $CroppedYSize,
        int $CroppedXOrigin = 0,
        int $CroppedYOrigin = 0
    ) {
        $RemainingWidth = $this->getXSize() - $CroppedXOrigin;
        $RemainingHeight = $this->getYSize() - $CroppedYOrigin;

        if ($CroppedXSize > $RemainingWidth || $CroppedYSize > $RemainingHeight) {
            throw new InvalidArgumentException(
                "Invalid cropping parameters provided."
            );
        }

        # save origin and size for cropping
        $this->TransformImageBeforeSaving = "CROP";
        $this->NewXSize = $CroppedXSize;
        $this->NewYSize = $CroppedYSize;
        $this->NewXOrigin = $CroppedXOrigin;
        $this->NewYOrigin = $CroppedYOrigin;
    }

    /**
     * Set quality (0-100) for JPEG images created with saveAs().
     * @param int $NewValue New quality setting.
     */
    public function setJpegQuality(int $NewValue)
    {
        if ($NewValue < 0 || $NewValue > 100) {
            throw new InvalidArgumentException(
                "JPEG Quality must be between 0 and 100."
            );
        }
        $this->JpegSaveQuality = $NewValue;
    }

    /**
     * Set compression level (0-9) used for zlib compression of PNG images
     * created with saveAs().
     * @param int $NewValue New compression level setting.
     */
    public function setPngCompressionLevel(int $NewValue)
    {
        if ($NewValue < 0 || $NewValue > 9) {
            throw new InvalidArgumentException(
                "PNG Compression Level must be between 0 and 9."
            );
        }

        $this->PngCompressionLevel = $NewValue;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $ImageObject = null;
    protected $SourceFileName;
    protected $ImageXSize;
    protected $ImageYSize;

    # settings for cropping/scaling on saveAs()
    protected $TransformImageBeforeSaving = "NONE"; # one of NONE, CROP, or SCALE
    protected $NewXSize;
    protected $NewYSize;
    protected $NewXOrigin = 0;
    protected $NewYOrigin = 0;

    # quality/compression settings for saveAs()
    protected $JpegSaveQuality = 80;

    # use maximum zlib compression for PNG files we create
    # (see: https://www.php.net/manual/en/function.imagepng.php)
    protected $PngCompressionLevel = 9;

    /**
     * Set (internal) image size values.  (Method is idempotent.)
     */
    protected function readSize()
    {
        # if we do not already have image info
        if (!isset($this->ImageXSize)) {
            # read size information from image object
            $this->ImageXSize = imagesx($this->ImageObject);
            $this->ImageYSize = imagesy($this->ImageObject);
        }
    }

    /**
     * Get a potentially cropped and resized version of the image to save.
     * @return \GdImage Image object.
     */
    private function getImageToSave()
    {
        # if no changes needed just return the source image
        if ($this->TransformImageBeforeSaving == "NONE") {
            return $this->ImageObject;
        }

        if ($this->TransformImageBeforeSaving == "CROP") {
            $SrcXSize = $this->NewXSize;
            $SrcYSize = $this->NewYSize;
        } else {
            $SrcXSize = $this->getXSize();
            $SrcYSize = $this->getYSize();
        }

        # otherwise crop/scale original image to destination image
        $DstImage = imagecreatetruecolor(
            $this->NewXSize,
            $this->NewYSize
        );
        if ($DstImage === false) {
            throw new Exception("Failure creating new image.");
        }
        imagealphablending($DstImage, false);
        imagesavealpha($DstImage, true);
        imagecopyresampled(
            $DstImage,
            $this->ImageObject,
            0,
            0,
            $this->NewXOrigin,
            $this->NewYOrigin,
            $this->NewXSize,
            $this->NewYSize,
            $SrcXSize,
            $SrcYSize
        );

        return $DstImage;
    }

    /**
     * Attempt to determine whether image type is supported by PHP.
     * @param int $Format Image type to check.
     * @return bool TRUE if image type appears to be supported, otherwise FALSE.
     */
    private static function imageFormatSupportedByPhp(int $Format): bool
    {
        if (!function_exists("imagetypes")) {
            return false;
        }

        switch ($Format) {
            case self::IMGTYPE_JPEG:
                return (imagetypes() & IMG_JPG) ? true : false;

            case self::IMGTYPE_GIF:
                return (imagetypes() & IMG_GIF) ? true : false;

            case self::IMGTYPE_BMP:
                if (defined("IMG_BMP")) {
                    return (imagetypes() & IMG_BMP) ? true : false;
                } else {
                    return false;
                }

            case self::IMGTYPE_PNG:
                return (imagetypes() & IMG_PNG) ? true : false;

            default:
                return false;
        }
    }
}
