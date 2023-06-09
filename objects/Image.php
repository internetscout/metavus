<?PHP
#
#   FILE:  Image.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;
use ScoutLib\Item;
use ScoutLib\ImageFile;
use ScoutLib\RasterImageFile;
use ScoutLib\VectorImageFile;

/**
 * Encapsulates a full-size, preview, and thumbnail image.
 */
class Image extends Item
{
    use StoredFile;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    # legacy support for a fixed set of image sizes
    const SIZE_FULL = 3;
    const SIZE_PREVIEW = 2;
    const SIZE_THUMBNAIL = 1;

    /**
     * Get the path to an image of the specified size.
     * @param string $CSSSizeName Name of the image size to retrieve.
     * @return string Returns the path to the requested image size.
     * @throws InvalidArgumentException if provided size is not defined by
     *   any interface.ini files that apply for the active user interface.
     */
    public function url(string $CSSSizeName): string
    {
        if (!isset(self::$ImageSizes[$CSSSizeName])) {
            throw new InvalidArgumentException("Invalid image size: ".$CSSSizeName);
        }

        if ($this->format() != ImageFile::IMGTYPE_SVG) {
            $ImageSize = self::$ImageSizes[$CSSSizeName];
            $Path = $this->getScaledImage(
                $ImageSize["Width"],
                $ImageSize["Height"]
            );
        } else {
            $Path = $this->getFullPathForOriginalImage();
        }

        return $Path;
    }

    /**
     * Get the full path of the original, unmodified image file.
     * @return string full path of original image file
     */
    public function getFullPathForOriginalImage()
    {
        if (is_null($this->FileName)) {
            $Extension = ImageFile::extensionForFormat($this->format());

            $this->FileName = ImageFactory::IMAGE_STORAGE_LOCATION."/"
                ."Img--".sprintf("%08d.", $this->Id).$Extension;
        }

        return $this->FileName;
    }

    /**
     * Check if the original image is valid and can be read by PHP.
     * @return bool TRUE for valid images, FALSE otherwise
     */
    public function originalImageFileIsValid()
    {
        $FileName = $this->getFullPathForOriginalImage();
        try {
            if (ImageFile::fileIsVectorFormat($FileName)) {
                new VectorImageFile($FileName);
            } else {
                new RasterImageFile(
                    $FileName,
                    $this->format()
                );
            }
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Get url for the 'preview' size image.
     * @deprecated
     * @return string Image Url
     */
    public function previewUrl() : string
    {
        ApplicationFramework::getInstance()
            ->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "Call to deprecated Image::".__FUNCTION__." at "
                .StdLib::getMyCaller()
            );
        return $this->url("mv-image-preview");
    }

    /**
     * Get url for the 'thumbnail' size image.
     * @deprecated
     * @return string Image Url
     */
    public function thumbnailUrl() : string
    {
        ApplicationFramework::getInstance()
            ->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "Call to deprecated Image::".__FUNCTION__." at "
                .StdLib::getMyCaller()
            );
        return $this->url("mv-image-thumbnail");
    }

    /**
     * Get url for the 'large' size image.
     * @deprecated
     * @return string Image Url
     */
    public function sourceUrl() : string
    {
        ApplicationFramework::getInstance()
            ->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "Call to deprecated Image::".__FUNCTION__." at "
                .StdLib::getMyCaller()
            );
        return $this->url("mv-image-large");
    }

    /**
     * Get or set the alternate text value for the image.
     * @param string $NewValue New alternate text value. (OPTIONAL)
     * @return string Returns the current alternate text value.
     */
    public function altText(string $NewValue = null): string
    {
        $Val = $this->DB->updateValue("AltText", $NewValue);
        return ($Val !== false) ? $Val : "";
    }

    /**
     * Get the format of this image.
     * @return int Image format as a \ScoutLib\ImageFile::IMGTYPE_ constant
     */
    public function format(): int
    {
        $Format = $this->DB->updateValue("Format");

        # if no format information available in the database
        if ($Format === false) {
            # try to determine from the filesystem
            $MatchingFiles = glob(
                ImageFactory::IMAGE_STORAGE_LOCATION."/"
                ."Img--".sprintf("%08d.", $this->Id)."*"
            );

            if ($MatchingFiles === false) {
                throw new Exception(
                    "Error listing image files when trying "
                    ."to determine format for ImageId ".$this->Id
                );
            } elseif (count($MatchingFiles) == 0) {
                throw new Exception(
                    "No file found for ImageId ".$this->Id
                    ." when trying to determine format."
                );
            } elseif (count($MatchingFiles) > 1) {
                throw new Exception(
                    "Multiple files found for ImageId ".$this->Id
                    ." when trying to determine format."
                );
            }

            $FileName = reset($MatchingFiles);
            $Format = ImageFile::getFileFormat($FileName);
            $this->DB->updateValue("Format", $Format);
        }

        return $Format;
    }

    /**
     * Get the mime type of this image.
     * @return string MIME type.
     */
    public function mimeType() : string
    {
        if (is_null($this->MimeType)) {
            $FileName = $this->getFullPathForOriginalImage();
            if (ImageFile::fileIsVectorFormat($FileName)) {
                $Image = new VectorImageFile($FileName);
            } else {
                $Image = new RasterImageFile(
                    $FileName,
                    $this->format()
                );
            }

            $this->MimeType = $Image->mimeType();
        }

        return $this->MimeType;
    }

    /**
     * Get the HTML to display this image.
     * @param string $CssSizeName CSS class specifying the size of the image.
     * @throws InvalidArgumentException if provided size is not defined by
     *   any interface.ini files.
     */
    public function getHtml(string $CssSizeName) : string
    {
        if (!isset(self::$ImageSizes[$CssSizeName])) {
            throw new InvalidArgumentException(
                "Invalid image size: ".$CssSizeName
            );
        }

        $Width = self::$ImageSizes[$CssSizeName]["Width"];
        $Height = self::$ImageSizes[$CssSizeName]["Height"];
        $SafeAltText = htmlspecialchars(
            $this->altText(),
            ENT_QUOTES | ENT_HTML5
        );

        # and construct the HTML for this image tag
        return "<div class='".$CssSizeName."-container'>"
            ."<img class='".$CssSizeName."'"
            ." src='".$this->url($CssSizeName)."'"
            ." alt='".$SafeAltText."'></div>";
    }

    /**
     * Destroy the image, that is, remove its record from the database and delete
     * the associated image files from the file system.
     */
    public function destroy()
    {
        $FileName = $this->getFullPathForOriginalImage();
        if (file_exists($FileName)) {
            unlink($FileName);
        }

        # look for scaled versions of this image
        $ScaledImagePattern = sprintf(
            ImageFactory::SCALED_STORAGE_LOCATION."/img_%08d_*x*.%s",
            $this->Id,
            ImageFile::extensionForFormat($this->format())
        );
        $ScaledImages = glob($ScaledImagePattern);

        # if any were found, delete them
        if ($ScaledImages !== false && count($ScaledImages) > 0) {
            foreach ($ScaledImages as $ScaledImage) {
                unlink($ScaledImage);
            }
        }

        # delete image info record in database
        $this->DB->query("DELETE FROM Images WHERE ImageId = ".$this->Id);
    }

    /**
     * Get the Id of the item with which this image is associated.
     * @return int Associated item Id.
     */
    public function getIdOfAssociatedItem() : int
    {
        return $this->DB->updateIntValue("ItemId");
    }

    /**
     * Associate this image with an item.
     * @param int $NewValue Id of the item.
     */
    public function setItemId(int $NewValue)
    {
        $this->DB->updateIntValue("ItemId", $NewValue);
    }

    /**
     * Get the Id of the field with which this image is associated.
     * @return int Field Id.
     */
    public function getFieldId() : int
    {
        return $this->DB->updateIntValue("FieldId");
    }

    /**
     * Associate this image with a field.
     * @param int $NewValue Id of the field.
     */
    public function setFieldId(int $NewValue)
    {
        $this->DB->updateIntValue("FieldId", $NewValue);
    }

    /**
     * Create a new copy of this image.
     * @return Image Duplicate image.
     */
    public function duplicate()
    {
        return self::create($this->getFullPathForOriginalImage());
    }

    /**
     * Remove the legacy large/preview/thumbnail size versions of a specified
     * image that are no longer used in favor of the images generated by
     * getScaledImage() based on ImageSize settings from interface.ini. Method
     * can be removed after we no longer support upgrading from CWIS.
     */
    public function deleteLegacyScaledImages()
    {
        # clean up old scaled images (we have a new storage location for them now)
        $FileNamePrefixes = [
            "local/data/images/large/Large",
            "local/data/images/previews/Preview",
            "local/data/images/thumbnails/Thumb",
            "ImageStorage/Previews/Preview",
            "ImageStorage/Thumbnails/Thumb",
        ];

        $Extension = ImageFile::extensionForFormat(
            $this->format()
        );

        foreach ($FileNamePrefixes as $Prefix) {
            $FilePath = sprintf(
                "%s--%08d.%s",
                $Prefix,
                $this->id(),
                $Extension
            );

            if (file_exists($FilePath)) {
                unlink($FilePath);
            }
        }
    }

    # ---- PUBLIC STATIC INTERFACE -------------------------------------------

    /**
     * Create a new \Metavus\Image from a specified image file.
     * @param string $FileName.
     * @return Image Newly created image.
     */
    public static function create(
        string $FileName
    ) {
        # if file does not exist or is not readable
        $IsReadable = @is_readable($FileName);
        if ($IsReadable !== true) {
            # set error status
            throw new Exception("Source file is not readable.");
        }

        if (ImageFile::fileIsVectorFormat($FileName)) {
            $SrcImage = new VectorImageFile($FileName);
        } else {
            $SrcImage = new RasterImageFile($FileName);
        }

        # generate a new ImageId
        $DB = new Database();
        $DB->query("INSERT INTO Images (Format) VALUES ('".$SrcImage->format()."')");
        $ImageId = $DB->getLastInsertId();

        # create a new Image object
        $Image = new self($ImageId);

        # set file names
        $TargetFileName = $Image->getFileName();

        # attempt to copy provided file
        #   if our image file name differs from file name passed in
        if (realpath($TargetFileName) != realpath($FileName)) {
            # attempt to copy original, reporting an error if one occurs
            if (!copy($FileName, $TargetFileName)) {
                throw new Exception("Unable to copy image file to image storage.");
            }
        }

        # store checksums and file length
        $Image->storeFileLengthAndChecksum();

        # and return the image object
        return $Image;
    }

    /**
     * Register an image size for use in user interfaces.
     * @param string $CSSSizeName Name of the image size as a CSS class
     *   (e.g., mv-image-large)
     * @param int $Width Pixel width of this image size.
     * @param int $Width Pixel height of this image size.
     */
    public static function addImageSize(string $CSSSizeName, int $Width, int $Height)
    {
        self::$ImageSizes[$CSSSizeName] = [
            "Width" => $Width,
            "Height" => $Height,
        ];
    }

    /**
     * Determine if a given image size name is defined by the current
     * user interface.
     * @param string $CSSSizeName Name of the image size as a CSS class
     *   (e.g., mv-image-large)
     * @return bool TRUE for sizes that are in use
     */
    public static function isSizeNameValid(string $CSSSizeName) : bool
    {
        return isset(self::$ImageSizes[$CSSSizeName]);
    }

    /**
     * Determine if a given scaled image size is being used by the current
     * user interface.
     * @param int $Width Image width.
     * @param int $Height Image height.
     * @return bool TRUE for sizes that are in use
     */
    public static function isSizeValid(int $Width, int $Height) : bool
    {
        foreach (self::$ImageSizes as $ImageSize) {
            if ($ImageSize["Width"] == $Width &&
                $ImageSize["Height"] == $Height) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the CSS name for a given scaled image size.
     * @param int $Width Image width.
     * @param int $Height Image height.
     * @return string CSS size name.
     * @throws InvalidArgumentException if provided size is not defined by
     *   the current interface.
     */
    public static function getSizeName(int $Width, int $Height) : string
    {
        static $SizeNames = [];

        $Key = $Width."x".$Height;
        if (!isset($SizeNames[$Key])) {
            foreach (self::$ImageSizes as $SizeName => $ImageSize) {
                if ($ImageSize["Width"] == $Width && $ImageSize["Height"] == $Height) {
                    $SizeNames[$Key] = $SizeName;
                    return $SizeName;
                }
            }

            throw new InvalidArgumentException(
                "Invalid image size provided: ".$Key
            );
        }

        return $SizeNames[$Key];
    }

    /**
     * Get the CSS name of a scaled image size defined by the
     *   current interface that is closest (in terms of squared error in image
     *   area) to a given size.
     * @param int $Width desired width.
     * @param int $Height desired height.
     * @return string CSS size name.
     */
    public static function getClosestSize(int $Width, int $Height) : string
    {
        $DesiredArea = $Width * $Height;

        $BestSoFar = INF;
        $SelectedSize = "";
        foreach (self::$ImageSizes as $SizeName => $ImageSize) {
            $Area = $ImageSize["Width"] * $ImageSize["Height"];
            $SquaredError = pow($Area - $DesiredArea, 2);
            if ($SquaredError < $BestSoFar) {
                $BestSoFar = $SquaredError;
                $SelectedSize = $SizeName;
            }
        }

        return $SelectedSize;
    }

    /**
     * Get the next largest size with dimensions larger than both the provided
     * width and height.
     * @param int $Width The width of the image.
     * @param int $Height The height of the image.
     * @return string CSS size name.
     */
    public static function getNextLargestSize(int $Width, int $Height) : string
    {
        # areas are sorted in ascending order
        static $ImageSizeAreas = [];
        static $ImageSizeCount = 0;

        # sort image sizes by ascending total area
        if ($ImageSizeCount != count(self::$ImageSizes)) {
            foreach (self::$ImageSizes as $ImageSize => $Dimensions) {
                $ImageSizeAreas[$ImageSize] = $Dimensions["Width"] *
                        $Dimensions["Height"];
            }
            asort($ImageSizeAreas);
            $ImageSizeCount = count(self::$ImageSizes);
        }

        # get next largest size
        foreach (array_keys($ImageSizeAreas) as $ImageSize) {
            if (self::$ImageSizes[$ImageSize]["Width"] > $Width &&
                self::$ImageSizes[$ImageSize]["Height"] > $Height) {
                return (string)$ImageSize;
            }
        }

        # if no size is larger, get largest size
        return (string)array_key_last($ImageSizeAreas);
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private static $ImageSizes = [];
    private $FileName = null;
    private $MimeType = null;

    /**
     * Get a scaled version of the image that will fill a box of specified dimensions.
     * @param int $Width Pixel width of constraining box.
     * @param int $Height Pixel height of constraining box.
     * @return string Path to scaled image.
     */
    private function getScaledImage(int $Width, int $Height) : string
    {
        ImageFactory::checkImageStorageDirectories();

        # determine where the appropriately sized version of this image should live
        $Path = sprintf(
            ImageFactory::SCALED_STORAGE_LOCATION."/img_%08d_%dx%d.%s",
            $this->Id,
            $Width,
            $Height,
            ImageFile::extensionForFormat($this->format())
        );

        # if source file does not exist (e.g., because we're running in a
        # sandbox), just return the path
        if (!file_exists($this->getFullPathForOriginalImage())) {
            return $Path;
        }

        # create the scaled version if needed
        if (!file_exists($Path)) {
            $Image = new RasterImageFile(
                $this->getFullPathForOriginalImage(),
                $this->format()
            );

            # scale so that the large edge of the image fills the bounding box
            # without growing the image
            $Scale = min(
                min(1, $Width / $Image->getXSize()),
                min(1, $Height / $Image->getYSize())
            );

            $Image->scaleTo(
                (int)round($Scale * $Image->getXSize()),
                (int)round($Scale * $Image->getYSize())
            );

            $Image->saveAs($Path);
        }

        return $Path;
    }

    /**
     * Get the path of the unmodified image.
     * (required by StoredFile trait)
     */
    private function getFileName() : string
    {
        return $this->getFullPathForOriginalImage();
    }

    /**
     * Provide a copy of our database for traits we use.
     * (required by StoredFile trait).
     * @return Database The database.
     */
    protected function getDB() : Database
    {
        return $this->DB;
    }

    # ---- PRIVATE STATIC INTERFACE ------------------------------------------

    /**
     * Set the database access values (table name, ID column name, name column
     * name) for specified class.  This may be overridden in a child class, if
     * different values are needed.
     * @param string $ClassName Class to set values for.
     */
    protected static function setDatabaseAccessValues(string $ClassName)
    {
        if (!isset(self::$ItemIdColumnNames[$ClassName])) {
            self::$ItemIdColumnNames[$ClassName] = "ImageId";
            self::$ItemNameColumnNames[$ClassName] = null;
            self::$ItemTableNames[$ClassName] = "Images";
        }
    }
}
