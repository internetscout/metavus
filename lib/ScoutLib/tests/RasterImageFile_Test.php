<?PHP

use ScoutLib\ImageFile;
use ScoutLib\RasterImageFile;

class RasterImageFile_Test extends PHPUnit\Framework\TestCase
{
    protected static $TempDir;
    private static $FormatsToTest;

    /**
    * Create sandbox folder for storing new images.
    */
    public static function setUpBeforeClass() : void
    {
        $TmpBase = sys_get_temp_dir();
        if (substr($TmpBase, -1) != "/") {
            $TmpBase .= "/";
        }

        self::$TempDir = $TmpBase."RasterImageFile--Test-".getmypid();
        mkdir(self::$TempDir);

        # find all supported image formats
        $AllFormats = ["jpg", "gif", "png", "bmp"];
        self::$FormatsToTest = array_filter($AllFormats, function ($Format) {
            $ImageConst = "IMG_" . strtoupper($Format);
            return defined($ImageConst) && (imagetypes() & constant($ImageConst));
        });
    }

    /**
    * Destroy sandbox folder and contents.
    */
    public static function tearDownAfterClass() : void
    {
        $Files = glob(self::$TempDir."/*");
        foreach ($Files as $File) {
            if (is_file($File)) {
                unlink($File);
            }
        }
        rmdir(self::$TempDir);
    }

    /**
     * Test __construct().
     */
    public function testConstructor()
    {
        # construct Image with invalid file
        try {
            new RasterImageFile("abc123");

            $this->assertTrue(
                false,
                "Exception not thrown on invalid file."
            );
        } catch (Exception $e) {
            $this->assertEquals(
                get_class($e),
                "InvalidArgumentException",
                "Wrong exception type thrown on an invalid file."
            );
        }
    }

    /**
     * Test saveAs().
     */
    public function testSaveAs()
    {
        # Test saving an Image without making any changes
        $TestImage = new RasterImageFile("lib/ScoutLib/tests/files/TestImage--600x400.jpg");
        $this->saveImageAndVerify(
            $TestImage,
            self::$TempDir."/TestImage2--600x400",
            600,
            400,
            "Test saving an Image without making any changes: "
        );

        # Test saveAs() an image with an invalid image type
        try {
            $TestImage->saveAs(self::$TempDir."/TestImage--600x400.jpg", PHP_INT_MAX);
            $this->assertTrue(
                false,
                "Exception not thrown on saveAs() with invalid file type."
            );
        } catch (Exception $e) {
            $this->assertEquals(
                get_class($e),
                "Exception",
                "Wrong exception type thrown on an invalid image file type."
            );
        }
    }

    /**
     * Test scaling image.
     */
    public function testScaleTo()
    {
        $TestImage = new RasterImageFile("lib/ScoutLib/tests/files/TestImage--600x400.jpg");

        # Test scaleTo() without maintaining aspect ratio
        $TestImage->scaleTo(100, 200);
        $this->saveImageAndVerify(
            $TestImage,
            self::$TempDir."/TestImage--100x200",
            100,
            200,
            "Test scaleTo() without maintaining aspect ratio: "
        );
    }

    /**
     * Test cropping image.
     */
    public function testCropTo()
    {
        # Test cropTo()
        $TestImage = new RasterImageFile("lib/ScoutLib/tests/files/TestImage--600x400.jpg");
        $TestImage->cropTo(100, 150);
        $this->saveImageAndVerify(
            $TestImage,
            self::$TempDir."/TestImage--100x150",
            100,
            150,
            "Test cropTo(): "
        );
    }

    /**
     * Test type(), mimeType(), and extension().
     */
    public function testType()
    {
        $ImageTypes = [
            "jpg" => ImageFile::IMGTYPE_JPEG,
            "gif" => ImageFile::IMGTYPE_GIF,
            "bmp" => ImageFile::IMGTYPE_BMP,
            "png" => ImageFile::IMGTYPE_PNG
        ];
        $ImageMimeTypes = [
            "jpg" => "image/jpeg",
            "gif" => "image/gif",
            "bmp" => "image/bmp",
            "png" => "image/png"
        ];

        # Test with supported file types
        $TestImage = new RasterImageFile("lib/ScoutLib/tests/files/TestImage--600x400.jpg");
        foreach (self::$FormatsToTest as $Format) {
            $Path = self::$TempDir."/TestImage.".$Format;
            $TestImage->saveAs($Path);
            $SavedImage = new RasterImageFile($Path);

            $this->assertEquals(
                $ImageTypes[$Format],
                $SavedImage->format(),
                "Test format() with '".$Path."'."
            );

            $this->assertEquals(
                $ImageMimeTypes[$Format],
                $SavedImage->mimeType(),
                "Test mimeType() for ".$Format." image."
            );

            $this->assertEquals(
                $Format,
                $SavedImage->extension(),
                "Test extension() for ".$Format." image."
            );
        }
    }

    /**
     * Test getFileFormat().
     */
    public function testGetFileFormat()
    {
        $Format = RasterImageFile::getFileFormat(
            "lib/ScoutLib/tests/files/TestImage--600x400.jpg"
        );
        $this->assertEquals(
            RasterImageFile::IMGTYPE_JPEG,
            $Format,
            "Incorrect format returned by formatOfFile()."
        );
    }

    /**
     * Test fileIsVectorFormat().
     */
    public function testFileIsVectorFormat()
    {
        $IsVectorFormat = RasterImageFile::fileIsVectorFormat(
            "lib/ScoutLib/tests/files/TestImage--600x400.jpg"
        );

        $this->assertEquals(
            false,
            $IsVectorFormat,
            "TRUE returned by fileIsVectorFormat() for JPEG file."
        );
    }

    /**
     * Test supportedFormats().
     */
    public function testSupportedFormats()
    {
        # verify that supportedFormats() can be run
        $SupportedFormats = RasterImageFile::supportedFormats();

        # verify that something is supported.
        $this->assertEquals(
            true,
            $SupportedFormats != 0,
            "No formats supported, which should be impossible."
        );
    }

    /**
     * Test supportedFormatNames().
     */
    public function testSupportedFormatNames()
    {
        # verify that supportedFormatNames() can be run
        $SupportedFormatNames = RasterImageFile::supportedFormatNames();

        # and that it returns an array
        $this->assertEquals(
            true,
            is_array($SupportedFormatNames),
            "Non-array returned by supportedFormatNames()."
        );

        # and that the array is not empty
        $this->assertEquals(
            true,
            count($SupportedFormatNames) > 0,
            "Empty array returned by supportedFormatNames()."
        );
    }

    /**
     * Save the given image with the specified name for all supported
     * image formats (subset of {JPG, GIF, PNG, BMP}) and then verify
     * the saved image has correct status and size.
     * @param Image $Image The image to save.
     * @param string $Path New file path WITHOUT file name extension.
     * @param int $Width New image's width in px.
     * @param int $Height New image's height in px.
     * @param string $MsgHeader Error message header (OPTIONAL).
     */
    private function saveImageAndVerify(
        RasterImageFile $Image,
        string $Path,
        int $Width,
        int $Height,
        string $MsgHeader = ""
    ) {
        foreach (self::$FormatsToTest as $Format) {
            $PathWithExtension = $Path . "." . $Format;
            $Image->saveAs($PathWithExtension);
            $SavedImage = new RasterImageFile($PathWithExtension);

            $this->assertEquals(
                $Width,
                $SavedImage->getXSize(),
                $MsgHeader . "Testing Xsize() of " . $Width . "px width image."
            );
            $this->assertEquals(
                $Height,
                $SavedImage->getYSize(),
                $MsgHeader . "Testing Ysize() of " . $Height . "px height image."
            );
        }
    }
}
