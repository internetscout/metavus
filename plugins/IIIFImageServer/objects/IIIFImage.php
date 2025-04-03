<?PHP
#
#   FILE:  IIIFImage.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\IIIFImageServer;

use Exception;
use Metavus\Image;
use Metavus\Plugins\IIIFImageServer\IIIFError;
use ScoutLib\RasterImageFile;

/**
 * IIIF Image encapsulating the transformations and conversions required for
 * the IIIF Image API.
 */
class IIIFImage
{
    /**
     * Load an IIIFImage from the original, full-resolution image for a given
     * Metavus\Image.
     * @param int $Id Image ID to load.
     * @throws Exception if the provided image is an unsupported format or
     *   cannot be loaded.
     */
    public function __construct(int $Id)
    {
        $Image = new Image($Id);
        $SrcFile = $Image->getFullPathForOriginalImage();

        $this->Image = new RasterImageFile($SrcFile);
    }

    /**
     * Select a region from a source image.
     * @param string $Region Region to select following the IIIF Region syntax.
     * @return ?IIIFError NULL on success, IIIFError otherwise.
     * @see https://iiif.io/api/image/3.0/#41-region
     */
    public function selectRegion(string $Region): ?IIIFError
    {
        if ($Region == "full") {
            return null;
        }

        $SrcWidth = $this->Image->getXSize();
        $SrcHeight = $this->Image->getYSize();

        if ($Region == "square") {
            # if image is already square, nothing to do
            if ($SrcWidth == $SrcHeight) {
                return null;
            }
            # otherwise take a square portion that is centered on the middle
            # of the image
            $Length = (int) min($SrcWidth, $SrcHeight);
            $XOffset = (int) (($SrcWidth - $Length) / 2);
            $YOffset = (int) (($SrcHeight - $Length) / 2);
            $Width = $Length;
            $Height = $Length;
        } elseif (preg_match("%^([0-9]+),([0-9]+),([0-9]+),([0-9]+)$%", $Region, $Matches)) {
            # matches x,y,w,h format
            $XOffset = (int) $Matches[1];
            $YOffset = (int) $Matches[2];
            $Width = (int) $Matches[3];
            $Height = (int) $Matches[4];
        } elseif (preg_match("%^pct:([0-9]+),([0-9]+),([0-9]+),([0-9]+)$%", $Region, $Matches)) {
            # matches pct:x,y,w,h format
            $XOffset = (int) ((int)$Matches[1] * $SrcWidth / 100);
            $YOffset = (int) ((int)$Matches[2] * $SrcHeight / 100);
            $Width = (int) ((int)$Matches[3] * $SrcWidth / 100);
            $Height = (int) ((int)$Matches[4] * $SrcHeight / 100);
        } else {
            return new IIIFError(400, "Invalid region requested.");
        }

        # if requested region has zero width or zero height
        if ($Width == 0 || $Height == 0) {
            return new IIIFError(400, "Requested region has zero width or height.");
        }

        # if requested region is outside the image
        if ($XOffset > $SrcWidth && $YOffset > $SrcHeight) {
            return new IIIFError(400, "Requested region is outside source image.");
        }

        # clip region not to exceed the size of the source image
        if ($Width + $XOffset > $SrcWidth) {
            $Width = $SrcWidth - $XOffset;
        }
        if ($Height + $YOffset > $SrcHeight) {
            $Height = $SrcHeight - $YOffset;
        }

        $this->Crop = true;
        $this->CropXOrigin = $XOffset;
        $this->CropYOrigin = $YOffset;
        $this->CropWidth = $Width;
        $this->CropHeight = $Height;

        return null;
    }

    /**
     * Scale a source image to a specified size.
     * @param string $Size Desired size following the IIIF Size syntax.
     * @return ?IIIFError NULL on success, IIIFError otherwise.
     * @see https://iiif.io/api/image/3.0/#42-size
     * @throws Exception When unable to scale image
     */
    public function scaleImage(string $Size) : ?IIIFError
    {
        if ($Size == "max") {
            return null;
        }

        $SrcWidth = $this->Image->getXSize();
        $SrcHeight = $this->Image->getYSize();

        $AllowUpscaling = false;
        if (strlen($Size) > 0 && $Size[0] == "^") {
            $AllowUpscaling = true;
            $Size = substr($Size, 1);
        }

        # format descriptions to match below taken from
        # https://iiif.io/api/image/3.0/#42-size
        if (preg_match('%^([0-9]+),$%', $Size, $Matches)) {
            # matches 'w,' - scale image to width w, maintaining aspect ratio
            $DstWidth = (int)$Matches[1];
            $DstHeight = floor($SrcHeight * ($DstWidth / $SrcWidth));
        } elseif (preg_match('%^,([0-9]+)$%', $Size, $Matches)) {
            # matches ',h' - scale image to height h, maintaining aspect ratio
            $DstHeight = (int)$Matches[1];
            $DstWidth = floor($SrcWidth * ($DstHeight / $SrcHeight));
        } elseif (preg_match('%^pct:([0-9]+)$%', $Size, $Matches)) {
            # matches 'pct:n' - scale to n percent of the original
            $Percentage = (int)$Matches[1];
            $DstWidth = floor($Percentage * $SrcWidth / 100);
            $DstHeight = floor($Percentage * $SrcHeight / 100);
        } elseif (preg_match('%^([0-9]+),([0-9]+)$%', $Size, $Matches)) {
            # matches 'w,h' - scale to width w and height h, allowing distortion
            $DstWidth = $Matches[1];
            $DstHeight = $Matches[2];
        } elseif (preg_match('%^!([0-9]+),([0-9]+)$%', $Size, $Matches)) {
            # matches '!w,h' - scale to fit within width w and height h while
            # maintaining the aspect ratio of the original image
            $DstWidth = (int)$Matches[1];
            $DstHeight = (int)$Matches[2];

            # figure out the scale factor to get the width or height to fit the
            # specified bounding box
            $XScale = $DstWidth / $SrcWidth;
            $YScale = $DstHeight / $SrcHeight;

            # use which ever of the scales shrinks the image more, as that will be
            # the one that fits within the bounding box
            $Scale = min($XScale, $YScale);

            # adjust requested size appropriately
            $DstWidth = floor($Scale * $SrcWidth);
            $DstHeight = floor($Scale * $SrcHeight);
        } else {
            return new IIIFError(400, "Invalid image size requested.");
        }

        if ($DstWidth < 1 || $DstHeight < 1) {
            return new IIIFError(400, "Requested size less than 1px.");
        }

        if ($DstWidth > $SrcWidth || $DstHeight > $SrcHeight) {
            if (!$AllowUpscaling) {
                return new IIIFError(400, "Requested size larger than source image.");
            }
        }

        $this->Scale = true;
        $this->OutputWidth = (int)$DstWidth;
        $this->OutputHeight = (int)$DstHeight;

        return null;
    }

    /**
     * Rotate a source image by a specified amount.
     * @param string $Rotation Rotation following the IIIF Rotation syntax.
     * @return ?IIIFError NULL on success, IIIFError otherwise.
     * @see https://iiif.io/api/image/3.0/#43-rotation
     */
    public function rotateImage(string $Rotation) : ?IIIFError
    {
        if (strlen($Rotation) > 0 && $Rotation[0] == "!") {
            $Rotation = substr($Rotation, 1);
            $this->Mirror = true;
        }

        if ($Rotation == "0") {
            return null;
        }

        if ($Rotation < 0 || $Rotation > 360) {
            return new IIIFError(400, "Invalid rotation.");
        }

        $this->Image->setRotation((int)$Rotation);

        return null;
    }

    /**
     * Select an image "quality" (i.e. color / gray / bitonal)
     * @param string $Quality Quality following the IIIF Quality syntax.
     * @return ?IIIFError NULL on success, IIIFError otherwise.
     * @see https://iiif.io/api/image/3.0/#quality
     */
    public function selectQuality($Quality) : ?IIIFError
    {
        if ($Quality == "default" || $Quality == "color") {
            return null;
        }

        if ($Quality == "gray") {
            $this->Filter = RasterImageFile::FILTER_GRAYSCALE;
            return null;
        }

        if ($Quality == "bitonal") {
            $this->Filter = RasterImageFile::FILTER_BLACK_AND_WHITE;
            return null;
        }

        return new IIIFError(400, "Unsupported quality.");
    }


    /**
     * Save an image in a specified format to a specfied location.
     * @param string $Format Format in IIIF Format syntax (= file extension like jpg, png, etc)
     * @param string $DstPath Destination path.
     * @return ?IIIFError NULL on success, IIIFError otherwise.
     * @see https://iiif.io/api/image/3.0/#45-format
     * (per https://iiif.io/api/image/3.0/compliance/#35-format jpg is required
     * for all compliance levels, png is required for level 2 compliance, and all
     * other formats are optional)
     */
    public function saveImageInFormat($Format, $DstPath) : ?IIIFError
    {
        $SupportedFormats = ["jpg", "png"];
        if (!in_array($Format, $SupportedFormats)) {
            return new IIIFError(400, "Unsupported format.");
        };


        if ($this->Mirror) {
            $this->Image->setMirroring(IMG_FLIP_HORIZONTAL);
        }

        if ($this->Rotation !== null) {
            $this->Image->setRotation($this->Rotation);
        }

        if ($this->Filter !== null) {
            $this->Image->addFilter($this->Filter);
        }

        if ($this->Crop) {
            if ($this->Scale) {
                $this->Image->cropAndScaleTo(
                    $this->CropWidth,
                    $this->CropHeight,
                    $this->CropXOrigin,
                    $this->CropYOrigin,
                    $this->OutputWidth,
                    $this->OutputHeight
                );
            } else {
                $this->Image->cropTo(
                    $this->CropWidth,
                    $this->CropHeight,
                    $this->CropXOrigin,
                    $this->CropYOrigin
                );
            }
        } elseif ($this->Scale) {
            $this->Image->scaleTo(
                $this->OutputWidth,
                $this->OutputHeight
            );
        }

        $this->Image->saveAs($DstPath);

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $Image;

    private $Crop = false;
    private $Scale = false;
    private $Mirror = false;

    private $Rotation = null;

    private $Filter = null;

    private $CropXOrigin = null;
    private $CropYOrigin = null;
    private $CropWidth = null;
    private $CropHeight = null;

    private $OutputWidth = null;
    private $OutputHeight = null;
}
