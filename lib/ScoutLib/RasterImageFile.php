<?PHP
#
#   FILE: RasterImageFile.php
#
#   Part of the ScoutLib application support library
#   Copyright 2021-2025 Edward Almasy and Internet Scout Research Group
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

    # scaling goals (see setScalingGoal())
    public const SCALE_NONE = 1;
    public const SCALE_TO_FIT = 2;
    public const SCALE_TO_FILL = 3;
    # cropping methods (OR'd together -- see setCroppingMethod())
    public const CROP_TOP = 1;
    public const CROP_RIGHT = 2;
    public const CROP_BOTTOM = 4;
    public const CROP_LEFT = 8;
    # filtering options
    public const FILTER_GRAYSCALE = 1;
    public const FILTER_BLACK_AND_WHITE = 2;

    const DEFAULT_JPEG_SAVE_QUALITY = 80;
    const DEFAULT_PNG_COMPRESSION_LEVEL = 9;        # (max zlib compression)
    const DEFAULT_REDUCE_INTERPOLATION_METHOD = "IMG_GENERALIZED_CUBIC";
    const DEFAULT_ENLARGE_INTERPOLATION_METHOD = "IMG_GAUSSIAN";

    /**
     * Class constructor.
     * @param string $SourceFileName Name of image file.
     * @param int $ImageFormat Image format as an IMGTYPE_XX constant, if
     *   known. When provided, this value is assumed to be correct and strange
     *   errors will result if it is not.
     */
    public function __construct(
        string $SourceFileName,
        ?int $ImageFormat = null
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
                throw new Exception("Invalid image type.");
        }

        # if there was an error creating the ImageObject, throw an exception
        if ($this->ImageObject === false) {
            throw new Exception("Unable to create GDImage object.");
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
     * Save image to a file with a new name and (optionally) a new type.
     * @param string $FileName New name for image file.
     * @param int $NewImageType New type for image.  (OPTIONAL)
     */
    public function saveAs(string $FileName, ?int $NewImageType = null): void
    {
        if (file_exists($FileName)) {
            if (is_writable($FileName) != true) {
                throw new Exception($FileName." exists but is not writeable");
            }
        } else {
            if (is_writable(dirname($FileName)) != true) {
                throw new Exception(dirname($FileName)." is not writeable.");
            }
        }

        # if no image type specified try to determine based on file name
        #       or use source file type
        if ($NewImageType == null) {
            $NewImageType = $this->getImageTypeForFile($FileName);
        }

        # if input and output types both supported
        if (!self::imageFormatSupportedByPhp($NewImageType)) {
            throw new Exception("Unsupported output image type.");
        }

        # make sure width and height are set
        if (!isset($this->CropWidth)) {
            $this->CropWidth = $this->getXSize();
            $this->CropHeight = $this->getYSize();
            $this->ScaleWidth = $this->getXSize();
            $this->ScaleHeight = $this->getYSize();
        }

        # get copy of image to save
        $DstImage = self::getCroppedAndScaledCopyOfImage(
            $this->ImageObject,
            $this->CropXOrigin,
            $this->CropYOrigin,
            $this->CropWidth,
            $this->CropHeight,
            $this->ScaleWidth,
            $this->ScaleHeight
        );

        # mirror image if requested
        if ($this->Mirroring !== null) {
            imageflip($DstImage, $this->Mirroring);
        }

        # filter image if requested
        foreach ($this->Filters as $FilterType) {
            $DstImage = self::filterImage($DstImage, $FilterType);
        }

        # rotate image if requested
        if ($this->Rotation != 0) {
            $DstImage = self::rotateImage($DstImage, $this->Rotation);
        }

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
                throw new Exception("Internal Error.");
        }

        if ($Result === false) {
            throw new Exception("image*() function failed to save image file.");
        }
    }

    /**
     * Specify a size to scale the image to for the next saveAs().
     * Overrides any previous cropping/scaling settings from cropTo(),
     * cropAndScaleTo(), or cropAndScaleToFit() calls for this image.
     * @param int $Width New horizontal (X) size.
     * @param int $Height New vertical (Y) size.
     */
    public function scaleTo(int $Width, int $Height): void
    {
        # save cropping/scaling values for use by next save
        $this->CropXOrigin = 0;
        $this->CropYOrigin = 0;
        $this->CropWidth = $this->getXSize();
        $this->CropHeight = $this->getYSize();
        $this->ScaleWidth = $Width;
        $this->ScaleHeight = $Height;
    }

    /**
     * Specify a size to crop the image to for the next saveAs().
     * Overrides any previous cropping/scaling settings from scaleTo(),
     * cropAndScaleTo(), or cropAndScaleToFit() calls for this image.
     * @param int $CropWidth New horizontal (X) size.
     * @param int $CropHeight New vertical (Y) size.
     * @param int $CropXOrigin new Left (horizontal) origin for cropped
     *      area.  (OPTIONAL, defaults to 0)
     * @param int $CropYOrigin new Top (vertical) origin for cropped
     *      area.  (OPTIONAL, defaults to 0)
     * @throws InvalidArgumentException if cropping parameters are invalid.
     */
    public function cropTo(
        int $CropWidth,
        int $CropHeight,
        int $CropXOrigin = 0,
        int $CropYOrigin = 0
    ): void {
        $this->checkCropParams($CropWidth, $CropHeight, $CropXOrigin, $CropYOrigin);

        # save cropping/scaling values for use by next save
        $this->CropXOrigin = $CropXOrigin;
        $this->CropYOrigin = $CropYOrigin;
        $this->CropWidth = $CropWidth;
        $this->CropHeight = $CropHeight;
        $this->ScaleWidth = $CropWidth;
        $this->ScaleHeight = $CropHeight;
    }

    /**
     * Specify the size to crop and scale the image to for the next saveAs().
     * (Cropping is done first, and then scaling.)  Overrides any previous
     * cropping/scaling settings from scaleTo(), cropTo(), or cropAndScaleToFit()
     * calls for this image.
     * @param int $CropWidth Horizontal (X) size of area to crop image to.
     * @param int $CropHeight Vertical (Y) size of area to crop image to.
     * @param int $CropXOrigin Left (horizontal) origin for cropped area.
     * @param int $CropYOrigin Top (vertical) origin for cropped area.
     * @param int $ScaleWidth Horizontal (X) size to scale cropped image to.
     * @param int $ScaleHeight Vertical (Y) size to scale cropped image to.
     * @throws InvalidArgumentException if cropping parameters are invalid.
     */
    public function cropAndScaleTo(
        int $CropWidth,
        int $CropHeight,
        int $CropXOrigin,
        int $CropYOrigin,
        int $ScaleWidth,
        int $ScaleHeight
    ): void {
        $this->checkCropParams($CropWidth, $CropHeight, $CropXOrigin, $CropYOrigin);

        # save cropping/scaling values for use by next save
        $this->CropXOrigin = $CropXOrigin;
        $this->CropYOrigin = $CropYOrigin;
        $this->CropWidth = $CropWidth;
        $this->CropHeight = $CropHeight;
        $this->ScaleWidth = $ScaleWidth;
        $this->ScaleHeight = $ScaleHeight;
    }

    /**
     * Crop and scale the image to fit within the specified boundaries,
     * based on the current scaling goal and cropping method set.  Setting
     * the scaling goal and/or cropping method must be done BEFORE calling
     * this method, for them to be used.  This method overrides any previous
     * cropping or scaling settings from any scaleTo(), cropTo(), or
     * cropAndScaleTo() calls for this image.
     * @param int $TgtWidth Horizontal (X) size of area to fit image within.
     * @param int $TgtHeight Vertical (Y) size of area to fit image within.
     * @see setScalingGoal()
     * @see setCroppingMethod()
     */
    public function cropAndScaleToFit(int $TgtWidth, int $TgtHeight): void
    {
        /**
         * Coming in, we have:
         *  CurWidth / CurHeight - size of current image
         *  TgtWidth / TgtHeight - size of window to fit image within
         * And we need to determine:
         *  SrcXOrigin / SrcYOrigin - upper left corner of section of image to copy
         *  SrcWidth / SrcHeight - size of section of image to copy
         *  DstWidth / DstHeight - size of new cropped/scaled image
         */

        # copy values to local vars for convenience/clarity
        $this->readSize();
        $CurWidth = $this->ImageXSize;
        $CurHeight = $this->ImageYSize;
        $Crop = $this->CroppingMethod;

        # determine if target aspect ratio is wider or taller than current
        $TgtWider = ($TgtWidth / $TgtHeight) > ($CurWidth / $CurHeight);

        switch ($this->ScalingGoal) {
            case self::SCALE_NONE:
                # resulting image size will be no larger than target window size
                $SrcWidth = min($CurWidth, $TgtWidth);
                $SrcHeight = min($CurHeight, $TgtHeight);
                $DstWidth = min($CurWidth, $TgtWidth);
                $DstHeight = min($CurHeight, $TgtHeight);

                # if left (and not right) cropping requested
                if (($Crop & self::CROP_LEFT) && !($Crop & self::CROP_RIGHT)) {
                    # set horizontal origin to crop off left side if larger than window
                    $SrcXOrigin = max(0, $CurWidth - $TgtWidth);
                # else if right (and not left) cropping requested
                } elseif (($Crop & self::CROP_RIGHT) && !($Crop & self::CROP_LEFT)) {
                    # set horizontal origin to crop off right side if larger than window
                    $SrcXOrigin = 0;
                # else image should be horizontally-centered within window
                } else {
                    $SrcXOrigin = max(0, (($CurWidth / 2) - ($TgtWidth / 2)));
                    $SrcXOrigin = (int)round($SrcXOrigin);
                }

                # if top (and not bottom) cropping requested
                if (($Crop & self::CROP_TOP) && !($Crop & self::CROP_BOTTOM)) {
                    # set vertical origin to crop off top if larger than window
                    $SrcYOrigin = max(0, $CurHeight - $TgtHeight);
                # else if right (and not left) cropping requested
                } elseif (($Crop & self::CROP_BOTTOM) && !($Crop & self::CROP_TOP)) {
                    # set vertical origin to crop off bottom if larger than window
                    $SrcYOrigin = 0;
                # else image should be vertically-centered within window
                } else {
                    $SrcYOrigin = max(0, (($CurHeight / 2) - ($TgtHeight / 2)));
                    $SrcYOrigin = (int)round($SrcYOrigin);
                }
                break;

            case self::SCALE_TO_FIT:
                # set source origin and size to include entire image
                $SrcXOrigin = 0;
                $SrcYOrigin = 0;
                $SrcWidth = $CurWidth;
                $SrcHeight = $CurHeight;

                # if target aspect ratio is wider than current aspect ratio
                if ($TgtWider) {
                    # set destination height to match target
                    #       and scale width proportionately
                    $DstHeight = $TgtHeight;
                    $DstWidth = $TgtHeight * ($CurWidth / $CurHeight);
                    $DstWidth = (int)round($DstWidth);
                } else {
                    # set destination width to match target
                    #       and scale height proportionately
                    $DstWidth = $TgtWidth;
                    $DstHeight = $TgtWidth * ($CurHeight / $CurWidth);
                    $DstHeight = (int)round($DstHeight);
                }
                break;

            case self::SCALE_TO_FILL:
                # set destination area to match target area
                $DstWidth = $TgtWidth;
                $DstHeight = $TgtHeight;

                # if target aspect ratio is wider than current aspect ratio
                if ($TgtWider) {
                    # set source to include entire horizontal distance
                    $SrcXOrigin = 0;
                    $SrcWidth = $CurWidth;

                    # set source to include vertical section proportionate to horizontal
                    $SrcHeight = $CurWidth * ($TgtHeight / $TgtWidth);
                    $SrcHeight = (int)round($SrcHeight);

                    # if left (and not right) cropping requested
                    if (($Crop & self::CROP_TOP) && !($Crop & self::CROP_BOTTOM)) {
                        # set source Y origin to crop top
                        $SrcYOrigin = $CurHeight - $SrcHeight;
                    # else if right (and not left) cropping requested
                    } elseif (($Crop & self::CROP_BOTTOM) && !($Crop & self::CROP_TOP)) {
                        # set source Y origin to crop bottom
                        $SrcYOrigin = 0;
                    } else {
                        # set source Y origin to be centered within image
                        $SrcYOrigin = ($CurHeight / 2) - ($SrcHeight / 2);
                        $SrcYOrigin = (int)round($SrcYOrigin);
                    }
                } else {
                    # set source to include entire vertical distance
                    $SrcYOrigin = 0;
                    $SrcHeight = $CurHeight;

                    # set source to include horizontal section proportionate to vertical
                    $SrcWidth = $CurHeight * ($TgtWidth / $TgtHeight);
                    $SrcWidth = (int)round($SrcWidth);

                    # if left (and not right) cropping requested
                    if (($Crop & self::CROP_LEFT) && !($Crop & self::CROP_RIGHT)) {
                        # set source X origin to crop left side
                        $SrcXOrigin = $CurWidth - $SrcWidth;
                    # else if right (and not left) cropping requested
                    } elseif (($Crop & self::CROP_RIGHT) && !($Crop & self::CROP_LEFT)) {
                        # set source X origin to crop right side
                        $SrcXOrigin = 0;
                    } else {
                        # set source X origin to be centered within image
                        $SrcXOrigin = ($CurWidth / 2) - ($SrcWidth / 2);
                        $SrcXOrigin = (int)round($SrcXOrigin);
                    }
                }
                break;

            default:
                throw new Exception("Unknown scaling goal \"".$this->ScalingGoal."\".");
        }

        # save cropping/scaling values for use by next save
        $this->CropXOrigin = $SrcXOrigin;
        $this->CropYOrigin = $SrcYOrigin;
        $this->CropWidth = $SrcWidth;
        $this->CropHeight = $SrcHeight;
        $this->ScaleWidth = $DstWidth;
        $this->ScaleHeight = $DstHeight;
    }

    /**
     * Set goal for how images should scale relative to the target area,
     * when cropping and scaling images to fit.  The default is no scaling.
     * @param int $Goal Goal (SCALE_TO_* constant) when scaling.
     * @see cropAndScaleToFit()
     */
    public function setScalingGoal(int $Goal): void
    {
        $this->ScalingGoal = $Goal;
    }

    /**
     * Set how images should be cropped relative to the target area, when
     * cropping and scaling images to fit.  If the scaling goal is set to
     * SCALE_TO_FIT, this setting will be ignored, since no cropping will
     * be done.  The default is no cropping.
     * @param int $Method Method (CROP_* constant OR'd together) to use.
     * @see cropAndScaleToFit()
     */
    public function setCroppingMethod(int $Method): void
    {
        $this->CroppingMethod = $Method;
    }

    /**
     * Set mirroring to perform on image at next saveAs, after any
     * cropping and/or scaling but before any rotation.
     * @param int $MirrorType IMG_FLIP_ constant (HORIZONTAL, VERTICAL,
     *      or BOTH) defined by PHP.
     */
    public function setMirroring(int $MirrorType): void
    {
        $this->Mirroring = $MirrorType;
    }

    /**
     * Set rotation to perform on image at next saveAs(), after any
     * cropping, scaling, or mirroring is done.
     * @param float $Rotation Angle to rotate image clockwise, in degrees.
     */
    public function setRotation(float $Rotation): void
    {
        $this->Rotation = $Rotation;
    }

    /**
     * Add filtering effect to be applied to image at next saveAs().
     * Filters are applied in the order added, after any cropping, scaling,
     * or mirroring, and before any rotation.
     * @param int $FilterType Type of filter (FILTER_ constant).
     */
    public function addFilter(int $FilterType): void
    {
        $this->Filters[] = $FilterType;
    }

    /**
     * Set quality (0-100) for JPEG images created with saveAs().
     * @param int $NewValue New quality setting.
     */
    public function setJpegQuality(int $NewValue): void
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
     * @see https://www.php.net/manual/en/function.imagepng.php
     */
    public function setPngCompressionLevel(int $NewValue): void
    {
        if ($NewValue < 0 || $NewValue > 9) {
            throw new InvalidArgumentException(
                "PNG Compression Level must be between 0 and 9."
            );
        }

        $this->PngCompressionLevel = $NewValue;
    }

    /**
     * Set the interpolation method used for shrinking images.
     * @param string $NewValue String giving an IMG_XX interpolation method
     *   (provided as a string so we can validate it).
     * @see https://www.php.net/manual/en/function.imagesetinterpolation.php
     * @see https://www.php.net/manual/en/function.imagescale.php
     * @throws InvalidArgumentException if interpolation method is invalid.
     */
    public static function setReduceInterpolationMethod(string $NewValue) : void
    {
        self::checkInterpolationMethod($NewValue);
        self::$ReduceInterpolationMethod = $NewValue;
    }

    /**
     * Set the interpolation method used for growing images.
     * @param string $NewValue String giving an IMG_XX interpolation method
     *   (provided as a string so we can validate it).
     # @see https://www.php.net/manual/en/function.imagesetinterpolation.php
     # and https://www.php.net/manual/en/function.imagescale.php
     * @throws InvalidArgumentException if interpolation method is invalid.
     */
    public static function setEnlargeInterpolationMethod(string $NewValue) : void
    {
        self::checkInterpolationMethod($NewValue);
        self::$EnlargeInterpolationMethod = $NewValue;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $ImageObject = null;
    protected $ImageXSize;
    protected $ImageYSize;
    protected $SourceFileName;

    # values for cropping/scaling on next saveAs()
    protected $CropXOrigin = 0;
    protected $CropYOrigin = 0;
    protected $CropWidth;
    protected $CropHeight;
    protected $ScaleWidth;
    protected $ScaleHeight;

    # settings for cropping/scaling to fit
    protected $CroppingMethod = 0;
    protected $ScalingGoal = self::SCALE_NONE;

    # other image manipulations
    protected $Filters = [];
    protected $Mirroring = null;
    protected $Rotation = 0;

    # quality/compression settings
    protected $JpegSaveQuality = self::DEFAULT_JPEG_SAVE_QUALITY;
    protected $PngCompressionLevel = self::DEFAULT_PNG_COMPRESSION_LEVEL;

    # interpolation methods to use when scaling images
    private static $EnlargeInterpolationMethod
            = self::DEFAULT_ENLARGE_INTERPOLATION_METHOD;
    private static $ReduceInterpolationMethod
            = self::DEFAULT_REDUCE_INTERPOLATION_METHOD;

    /**
     * Set (internal) image size values.  (Method is idempotent.)
     */
    protected function readSize(): void
    {
        # if we do not already have image info
        if (!isset($this->ImageXSize)) {
            # read size information from image object
            $this->ImageXSize = imagesx($this->ImageObject);
            $this->ImageYSize = imagesy($this->ImageObject);
        }
    }

    /**
     * Create scaled and (possibly) cropped version of specified image.
     * @param \GdImage $SrcImage Source image to copy.
     * @param int $SrcOriginX X origin for source image area.
     * @param int $SrcOriginY Y origin for source image area.
     * @param int $SrcWidth X (horizontal) size of source image area.
     * @param int $SrcHeight Y (vertical) size of source image area.
     * @param int $DstWidth X (horizontal) size of destination image area.
     * @param int $DstHeight Y (vertical) size of destination image area.
     * @return \GdImage Created scaled/cropped image.
     */
    private static function getCroppedAndScaledCopyOfImage(
        $SrcImage,
        int $SrcOriginX,
        int $SrcOriginY,
        int $SrcWidth,
        int $SrcHeight,
        int $DstWidth,
        int $DstHeight
    ) {
        # create blank destination image with a black background
        # (same size as source because we will scale it to destination size later)
        if ($SrcWidth < 1) {
            throw new Exception("Illegal source width (\"".$SrcWidth
                    ."\") (should be impossible).");
        }
        if ($SrcHeight < 1) {
            throw new Exception("Illegal source width (\"".$SrcHeight
                    ."\") (should be impossible).");
        }
        $DstImage = imagecreatetruecolor($SrcWidth, $SrcHeight);
        if ($DstImage === false) {
            throw new Exception("Unable to create blank destination image.");
        }

        # turn off blending in destination image so that copy is exact
        # (in blending mode, gd uses color + alpha information to compute the
        # final color of each pixel and stores the result as an opaque pixel
        # non-blending mode preserves the alpha channel)
        $Result = imagealphablending($DstImage, false);
        if ($Result === false) {
            throw new Exception("Unable to disable alpha blending.");
        }

        # add 'transparent' to the palette of colors our image contains
        # alpha color 127 is used to get a completely transparent background
        $TransparentColor = imagecolorallocatealpha($DstImage, 0, 0, 0, 127);
        if ($TransparentColor === false) {
            throw new Exception("Unable to allocate transparent color to image.");
        }

        # fill the new black image with a transparent background
        imagefill($DstImage, 0, 0, $TransparentColor);

        # copy requested portion of source image to destination image
        $Result = imagecopy(
            $DstImage,
            $SrcImage,
            0,
            0,
            $SrcOriginX,
            $SrcOriginY,
            $SrcWidth,
            $SrcHeight
        );
        if ($Result === false) {
            throw new Exception("Unable to copy portion of image.");
        }

        # if destination size and requested source size are not the same
        if (($DstWidth != $SrcWidth) || ($DstHeight != $DstWidth)) {
            # select scaling method based on reducing or enlarging image
            $SrcArea = $SrcWidth * $SrcHeight;
            $DstArea = $DstWidth * $DstHeight;
            $InterpolationMethod = ($DstArea < $SrcArea)
                    ? self::$ReduceInterpolationMethod
                    : self::$EnlargeInterpolationMethod;

            # scale image
            $DstImage = imagescale(
                $DstImage,
                $DstWidth,
                $DstHeight,
                constant($InterpolationMethod)
            );
            if ($DstImage === false) {
                throw new Exception("Unable to scale image (interpolation method \""
                        .$InterpolationMethod."\" may not be supported).");
            }
        }

        # make sure alpha channel is preserved when image is saved
        $Result = imagesavealpha($DstImage, true);
        if ($Result === false) {
            throw new Exception("Unable to enable saving alpha channel in images.");
        }

        # return destination image to caller
        return $DstImage;
    }

    /**
     * Applied specified filter to supplied image.
     * @param \GdImage $Image Image to rotate.
     * @param int $FilterType Type of filter to apply (FILTER_ constant).
     * @return \GdImage Filtered image.
     */
    private static function filterImage($Image, int $FilterType)
    {
        switch ($FilterType) {
            case self::FILTER_GRAYSCALE:
                imagefilter($Image, IMG_FILTER_GRAYSCALE);
                break;

            case self::FILTER_BLACK_AND_WHITE:
                imagefilter($Image, IMG_FILTER_CONTRAST, -1000);
                break;

            default:
                throw new Exception("Unknown filter type (\"".$FilterType."\").");
        }
        return $Image;
    }

    /**
     * Rotate supplied image in a clockwise direction by specified amount.
     * @param \GdImage $Image Image to rotate.
     * @param float $Rotation Amount to rotate image clockwise, in degrees.
     * @return \GdImage Rotated image.
     */
    private static function rotateImage($Image, float $Rotation)
    {
        # get transparent background color to use for any area uncovered by rotation
        $Background = imagecolorallocatealpha($Image, 0, 0, 0, 127);
        if ($Background === false) {
            throw new Exception("Color allocation failed.");
        }

        # rotate image
        $RotatedImage = imagerotate($Image, 0 - $Rotation, $Background);
        if ($RotatedImage === false) {
            throw new Exception("Image rotation failed.");
        }

        # turn off alpha blending in rotated image
        $Result = imagealphablending($RotatedImage, false);
        if ($Result === false) {
            throw new Exception("Unable to disable alpha blending.");
        }

        # make sure alpha channel is preserved in rotated image
        $Result = imagesavealpha($RotatedImage, true);
        if ($Result === false) {
            throw new Exception("Unable to enable saving alpha channel in images.");
        }

        return $RotatedImage;
    }

    /**
     * Check to make sure that specified cropping parameters are legal.
     * @param int $CropWidth New horizontal (X) size.
     * @param int $CropHeight New vertical (Y) size.
     * @param int $CropXOrigin Left (horizontal) origin for cropped area.
     * @param int $CropYOrigin Top (vertical) origin for cropped area.
     * @throws InvalidArgumentException if cropping parameters are invalid.
     */
    private function checkCropParams(
        int $CropWidth,
        int $CropHeight,
        int $CropXOrigin,
        int $CropYOrigin
    ): void {
        $RemainingWidth = $this->getXSize() - $CropXOrigin;
        $RemainingHeight = $this->getYSize() - $CropYOrigin;
        if ($CropWidth > $RemainingWidth || $CropHeight > $RemainingHeight) {
            throw new InvalidArgumentException(
                "Invalid cropping parameters provided."
            );
        }
    }

    /**
     * Check to make sure that specified interpolation method is valid.
     * @param string $Method String giving an IMG_XX interpolation method.
     * @throws InvalidArgumentException if interpolation method is invalid.
     */
    private static function checkInterpolationMethod(string $Method): void
    {
        if ((strpos($Method, "IMG_") !== 0)
                || !defined($Method)) {
            throw new InvalidArgumentException(
                $Method." is not a supported interpolation method."
            );
        }
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
