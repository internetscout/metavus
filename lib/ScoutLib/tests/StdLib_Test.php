<?PHP

use ScoutLib\StdLib;

/**
 * Test class to hold constants for getConstantName to access
 */
class ConstantHolder
{
    const TEST_CONST_ONE = 1;
    const TEST_CONST_TWO = 2;
    const BETTER_CONST_ONE = 1;

    public function __toString()
    {
        return "For testSubstr()";
    }
}

/**
* Test cases for NeatlyTruncateString in StdLib
*/
class StdLib_Test extends PHPUnit\Framework\TestCase
{
    const TESTSENTENCE = "The quick brown fox jumps over the lazy dog.";
    const MADISONBEIJINGDISTANCE = 6481.248732121681314310990273952484130859375;
    const MADISONBEIJINGBEARING = -19.568128511961589310885756276547908782958984375;
    const MADISONBROOKFIELDDISTANCE = 63.54468614885409039061414659954607486724853515625;
    const MADISONLAT = 43.074397;
    const MADISONLNG = -89.411502;
    const BEIJINGLAT = 39.900002;
    const BEIJINGLNG = 116.413002;

    /**
    * Test neatlyTruncateString()
    */
    public function testNeatlyTruncateString()
    {
        $this->assertEquals(
            "test",
            StdLib::NeatlyTruncateString("test", 10)
        );

        $this->assertEquals(
            "this test...",
            StdLib::NeatlyTruncateString(
                "this test test test",
                10
            )
        );

        $this->assertEquals(
            "asdfgasdfg...",
            StdLib::NeatlyTruncateString(
                "asdfgasdfgasdfgasdfg",
                10
            )
        );

        $this->assertEquals(
            "test te...",
            StdLib::NeatlyTruncateString(
                "test test test",
                7,
                true
            )
        );

        $this->assertEquals(
            "<b>test</b>",
            StdLib::NeatlyTruncateString(
                "<b>test</b>",
                10
            )
        );

        $this->assertEquals(
            "<b>test test...</b>",
            StdLib::NeatlyTruncateString(
                "<b>test test test test test</b>",
                10
            )
        );

        $this->assertEquals(
            "<b>test...</b>",
            StdLib::NeatlyTruncateString(
                "<b>test asdfgasdfgasdfgadsfg</b>",
                10
            )
        );

        $this->assertEquals(
            "<b>test <i>test</i>...</b>",
            StdLib::NeatlyTruncateString(
                "<b>test <i>test</i> test</b> test",
                10
            )
        );

        $this->assertEquals(
            "<b>test <i>asdfg</i>...</b>",
            StdLib::NeatlyTruncateString(
                "<b>test <i>asdfg</i>asdfg</b>",
                10
            )
        );

        $this->assertEquals(
            "<b>test&nbsp;test&nbsp;...</b>",
            StdLib::NeatlyTruncateString(
                "<b>test&nbsp;test&nbsp;test&nbsp;test&nbsp;</b>",
                10
            )
        );

        $this->assertEquals(
            "<a href='http://www.example.com/'>test</a> <b>test</b>...",
            StdLib::NeatlyTruncateString(
                "<a href='http://www.example.com/'>test</a> <b>test</b> test test",
                10
            )
        );

        $this->assertEquals(
            "<b>abc < abc...</b>",
            StdLib::NeatlyTruncateString(
                "<b>abc < abc test test</b>",
                10
            )
        );

        $this->assertEquals(
            "<b>abc & abc ...</b>",
            StdLib::NeatlyTruncateString(
                "<b>abc & abc & abc & abc</b>",
                10
            )
        );

        $this->assertEquals(
            "<b>&testasdfg...</b>",
            StdLib::NeatlyTruncateString(
                "<b>&testasdfg asdfgasdfg</b>",
                10
            )
        );
    }

    /**
     * Test pluralize() and singularize()
     */
    public function testPluralizeAndSingularize()
    {
        $SingularPluralNouns = [
            "resource" => "resources",
            "goose" => "geese",
            "deer" => "deer",
            "mouse" => "mice",
            "index" => "indices",
            "matrix" => "matrices",
            "leaf" => "leaves",
            "basis" => "bases",
            "echo" => "echoes",
            "axis" => "axes",
            "ox" => "oxen",
            "analysis" => "analyses"
        ];
        foreach ($SingularPluralNouns as $Singular => $Plural) {
            $this->assertEquals(
                $Plural,
                StdLib::pluralize($Singular),
                "pluralize() failed to make a noun plural (".$Singular." -> ".$Plural.")."
            );
            $this->assertEquals(
                $Singular,
                StdLib::singularize($Plural),
                "singularize() failed to make a plural noun singular (".$Plural." -> ".$Singular.")."
            );
        }

        $this->assertEquals(
            "resources",
            StdLib::pluralize("resources"),
            "pluralize() attempted to further pluralize an already-plural noun."
        );

        $this->assertEquals(
            "w",
            StdLib::singularize("w"),
            "singularize() changed a string that doesn't match any plural patterns."
        );

        # attempting pluralize on anything "invalid" will return it with an s at the end,
        # which is probably how it should work
    }

    /**
     * Test getLatLngForZipCode()
     */
    public function testGetLatLngForZipCode()
    {
        $LatLng = StdLib::getLatLngForZipCode(53706);
        $this->assertEquals(
            $this::MADISONLAT,
            $LatLng["Lat"],
            "getLatLngForZipCode() failed to retrieve correct latitude for valid zip code."
        );
        $this->assertEquals(
            $this::MADISONLNG,
            $LatLng["Lng"],
            "getLatLngForZipCode() failed to retrieve correct longitude for valid zip code."
        );

        $this->assertTrue(
            !StdLib::getLatLngForZipCode(555555555),
            "getLatLngForZipCode() retrieved geographic coordinates for a non-existent zip code."
        );
    }

    /**
     * Test zipCodeDistance()
     */
    public function testZipCodeDistance()
    {
        $ValidDistance = StdLib::zipCodeDistance(53706, 53045);
        $this->assertEquals(
            $this::MADISONBROOKFIELDDISTANCE,
            $ValidDistance,
            "Failed to retrieve a distance between two valid zip codes."
        );
        $this->assertTrue(
            !StdLib::zipCodeDistance(55555555, 99999999),
            "Retrieved distance between two nonexistent zip codes."
        );
        $this->assertTrue(
            !StdLib::zipCodeDistance(55555555, 53706),
            "Retrieved distance between nonexistent zip code (arg 1) and valid zip code (arg 2)."
        );
        $this->assertTrue(
            !StdLib::zipCodeDistance(53706, 55555555),
            "Retrieved distance between valid zip code (arg 1) and nonexistent zip code (arg 2)."
        );
        $this->assertEquals(
            0,
            StdLib::zipCodeDistance(53706, 53706),
            "Failed to retrieve a distance of zero between a zip code and itself."
        );
    }

    /**
     * Test getConstantName()
     */
    public function testGetConstantName()
    {
        $this->assertEquals(
            "TEST_CONST_TWO",
            StdLib::getConstantName("ConstantHolder", 2),
            "getConstantName() failed to retrieve correct name of constant for given value."
        );
        $this->assertEquals(
            "TEST_CONST_ONE",
            StdLib::getConstantName("ConstantHolder", 1, "TEST"),
            "getConstantName() failed to retrieve correct name of constant for given value and prefix."
        );
        $this->assertEquals(
            "BETTER_CONST_ONE",
            StdLib::getConstantName("ConstantHolder", 1, "BETTER"),
            "getConstantName() failed to retrieve correct name of constant for given value and prefix."
        );
        $this->assertEquals(
            "TEST_CONST_TWO",
            StdLib::getConstantName(new ConstantHolder, 2),
            "getConstantName() failed to retrieve correct name of constant for given value."
        );
        $this->assertEquals(
            null,
            StdLib::getConstantName("ConstantHolder", 41000000000),
            "getConstantName() failed to return null on retrieving an invalid constant value."
        );
    }

    /**
     * Test getUsStatesList()
     */
    public function testGetUsStatesList()
    {
        $ActualStates = [
            "AL" => "Alabama",
            "AK" => "Alaska",
            "AZ" => "Arizona",
            "AR" => "Arkansas",
            "CA" => "California",
            "CO" => "Colorado",
            "CT" => "Connecticut",
            "DE" => "Delaware",
            "DC" => "District of Columbia",
            "FL" => "Florida",
            "GA" => "Georgia",
            "HI" => "Hawaii",
            "ID" => "Idaho",
            "IL" => "Illinois",
            "IN" => "Indiana",
            "IA" => "Iowa",
            "KS" => "Kansas",
            "KY" => "Kentucky",
            "LA" => "Louisiana",
            "ME" => "Maine",
            "MD" => "Maryland",
            "MA" => "Massachusetts",
            "MI" => "Michigan",
            "MN" => "Minnesota",
            "MS" => "Mississippi",
            "MO" => "Missouri",
            "MT" => "Montana",
            "NE" => "Nebraska",
            "NV" => "Nevada",
            "NH" => "New Hampshire",
            "NJ" => "New Jersey",
            "NM" => "New Mexico",
            "NY" => "New York",
            "NC" => "North Carolina",
            "ND" => "North Dakota",
            "OH" => "Ohio",
            "OK" => "Oklahoma",
            "OR" => "Oregon",
            "PA" => "Pennsylvania",
            "RI" => "Rhode Island",
            "SC" => "South Carolina",
            "SD" => "South Dakota",
            "TN" => "Tennessee",
            "TX" => "Texas",
            "UT" => "Utah",
            "VT" => "Vermont",
            "VA" => "Virginia",
            "WA" => "Washington",
            "WV" => "West Virginia",
            "WI" => "Wisconsin",
            "WY" => "Wyoming"
        ];
        $StdStateList = StdLib::getUsStatesList();
        $this->assertEquals(
            51,
            count($StdStateList),
            "Retrieved an incorrect list of US States (count != 51, including DC)."
        );

        foreach ($ActualStates as $Abr => $State) {
            $this->assertEquals(
                $State,
                $StdStateList[$Abr],
                "There appears to be a typo for ".$State.", or ".$State." doesn't exist in getUsStatesList()"
            );
        }
    }

    /**
     * Test closeOpenTags()
     */
    public function testCloseOpenTags()
    {
        $ToClose = [
            "<h1> number " => "</h1>",
            "<!DOCTYPE html><body> DOCTYPE " => "</body>",
            "<body class='test'> class " => "</body>",
            "<HTML> CAPS " => "</HTML>",
            "<body> self-closing tags <br /> <input /> " => "</body>"
        ];
        foreach ($ToClose as $Unclosed => $CloseTags) {
            $ClosedString = $Unclosed.$CloseTags;
            $this->assertEquals(
                $ClosedString,
                StdLib::closeOpenTags($Unclosed),
                "closeOpenTags() failed to close '".$Unclosed."' as expected."
            );
        }
    }

    /**
     * Test sortCompare()
     */
    public function testSortCompare()
    {
        $this->assertEquals(
            0,
            StdLib::sortCompare(1, 1),
            "sortCompare() failed to show that 1 is equal to 1."
        );
        $this->assertEquals(
            -1,
            StdLib::sortCompare(0, 1),
            "sortCompare() failed to show that 0 is less than 1."
        );
        $this->assertEquals(
            1,
            StdLib::sortCompare(1, 0),
            "sortCompare() failed to show that 1 is greater than 0."
        );
    }

    /**
     * Test arrayPermutations()
     */
    public function testArrayPermutations()
    {
        $ActualPermutations = [
            [3,4,5],
            [3,5,4],
            [4,3,5],
            [4,5,3],
            [5,3,4],
            [5,4,3]
        ];
        $StdLibPermutations = StdLib::arrayPermutations([3,4,5]);
        foreach ($ActualPermutations as $Permutation) {
            $this->assertTrue(
                in_array($Permutation, $StdLibPermutations),
                "arrayPermutations() failed to correctly retrieve all possible permutations."
            );
        }
        $this->assertEquals(
            6,
            count($StdLibPermutations),
            "arrayPermutations() failed to correclty retrieve all possible permutations (wrong count)."
        );
    }

    /**
     * Test hexToRgba()
     */
    public function testHexToRgba()
    {
        $HexAndRgba = [
            "ffffff" => "rgba(255,255,255,1)",
            "ff0000" => "rgba(255,0,0,1)",
            "00ff00" => "rgba(0,255,0,1)",
            "0000ff" => "rgba(0,0,255,1)",
            "eee" => "rgba(14,14,14,1)",
            "EEE" => "rgba(14,14,14,1)"
        ];
        # test with basic hex colors (pure white, red, blue, green)
        # as well as with uppercase and lowercase 3 digit hex codes
        foreach ($HexAndRgba as $Hex => $Rgba) {
            $this->assertEquals(
                $Rgba,
                StdLib::hexToRgba($Hex),
                "hexToRgba() failed to correctly convert #".$Hex." to RGBA."
            );
        }
        # test with given alpha value
        $this->assertEquals(
            "rgba(11,11,11,0.5)",
            StdLib::hexToRgba("0B0B0B", 0.5),
            "hexToRgba() failed to correctly apply the given alpha value."
        );

        # make sure exception is thrown on invalid hex color
        $this->expectException(Exception::class);
        StdLib::hexToRgba("FFFFFFF");
    }

    /**
     * Test substr()
     */
    public function testSubstr()
    {
        $this->assertEquals(
            "fox",
            StdLib::substr($this::TESTSENTENCE, 16, 3),
            "substr() failed to correctly substring out the given selection."
        );

        $this->assertTrue(
            !StdLib::substr($this::TESTSENTENCE, 700, 3),
            "substr() failed to return false on an index out of bounds exception."
        );

        $this->assertEquals(
            "Substr()",
            StdLib::substr(new ConstantHolder(), 8, 8),
            "substr()failed to return the substring of the given object's toString function."
        );
    }

    /**
     * Test strpos()
     */
    public function testStrpos()
    {
        $this->assertEquals(
            16,
            StdLib::strpos($this::TESTSENTENCE, "fox"),
            "strpos() failed to return the correct position of the given substring."
        );

        $this->assertTrue(
            !StdLib::strpos($this::TESTSENTENCE, "wolf"),
            "strpos() failed to return false on a substring not in the given string."
        );

        $this->assertEquals(
            8,
            StdLib::strpos(new ConstantHolder(), "Substr()"),
            "strpos() failed to return the correct position of the given substring in an object's toString function."
        );
    }

    /**
     * Test strrpos()
     */
    public function testStrrpos()
    {
        $this->assertEquals(
            41,
            StdLib::strrpos($this::TESTSENTENCE, "o"),
            "strrpos() failed to return the correct last position of the given substring."
        );

        $this->assertTrue(
            !StdLib::strrpos($this::TESTSENTENCE, "wolf"),
            "strrpos() failed to return false on a substring not in the given string."
        );

        $this->assertEquals(
            13,
            StdLib::strrpos(new ConstantHolder, "r"),
            "strrpos() failed to return the correct position of the given substring in an object's toString function."
        );
    }

    /**
     * Test strlen()
     */
    public function testStrlen()
    {
        $this->assertEquals(
            44,
            StdLib::strlen($this::TESTSENTENCE),
            "strlen() failed to return the correct length of the given string."
        );

        $this->assertEquals(
            0,
            StdLib::strlen(""),
            "strlen() failed to return zero for empty string."
        );

        $this->assertEquals(
            16,
            StdLib::strlen(new ConstantHolder),
            "strlen() failed to return the correct length of an object's toString function."
        );
    }

    /**
     * Test encodeStringForCdata()
     */
    public function testEncodeStringForCdata()
    {
        $ToEncode = $this::TESTSENTENCE."]]>";
        $Expected = "<![CDATA[".$this::TESTSENTENCE."]]]]><![CDATA[>]]>";
        $this->assertEquals(
            $Expected,
            StdLib::encodeStringForCdata($ToEncode),
            "Encoded string failed to match expected encoding."
        );
    }

    /**
     * Test adjustHexColor()
     */
    public function testAdjustHexColor()
    {
        $Adjustments = [
            "#000000" => ["FFFFFF", -100, 0],
            "#000000" => ["000000", 100, 0],
            "#FFFFFF" => ["010101", 25300, 0],
            "#7F7F7F" => ["FFFFFF", -50, 0],
            "#9F089F" => ["882288", 0, 50],
            "#828282" => ["818080", 1, 0],
            "#E2E2E3" => ["E1E1E2", 1, 0],
            "#000F00" => ["001000", 1, 0],
            "#00000F" => ["000010", 1, 0],
            "#080707" => ["080707", 1, 0]
        ];
        foreach ($Adjustments as $Expected => $ToAdjust) {
            $this->assertEquals(
                $Expected,
                StdLib::adjustHexColor($ToAdjust[0], $ToAdjust[1], $ToAdjust[2]),
                "adjustHexColor() failed to correctly adjust color "
                ."from ".$ToAdjust[0]." to ".$Expected."."
            );
        }
    }

    /**
     * Test computeGreatCircleDistance()
     */
    public function testComputeGreatCircleDistance()
    {
        # this will get distance between any two coordinates, even fake ones.
        $ValidDistance = StdLib::computeGreatCircleDistance(
            $this::MADISONLAT,
            $this::MADISONLNG,
            $this::BEIJINGLAT,
            $this::BEIJINGLNG
        );
        $this->assertEquals(
            $this::MADISONBEIJINGDISTANCE,
            $ValidDistance,
            "computeGreatCircleDistance() failed to retrieve distance between two coordinates."
        );
        $this->assertEquals(
            0,
            StdLib::computeGreatCircleDistance(1, 1, 1, 1),
            "computeGreatCircleDistance() failed to return that the distance between one place and itself is zero."
        );
    }

    /**
     * Test computeBearing()
     */
    public function testComputeBearing()
    {
        $ValidBearing = StdLib::computeBearing(
            $this::MADISONLAT,
            $this::MADISONLNG,
            $this::BEIJINGLAT,
            $this::BEIJINGLNG
        );
        $this->assertEquals(
            $this::MADISONBEIJINGBEARING,
            $ValidBearing,
            "computeBearing() failed to retrieve correct bearing between two coordinates."
        );
        $this->assertEquals(
            0,
            StdLib::computeBearing(1, 1, 1, 1),
            "computeBearing() failed to return correct bearing between one place and itself as zero."
        );
        $this->assertEquals(
            0,
            StdLib::computeBearing(0, 0, 1, 0),
            "computeBearing() failed to return correct bearing (0) for a destination straight east."
        );
        $this->assertEquals(
            90,
            StdLib::computeBearing(0, 0, 0, 1),
            "computeBearing() failed to return correct bearing (90) for destination straight north."
        );
        $this->assertEquals(
            180,
            StdLib::computeBearing(0, 0, -1, 0),
            "computeBearing() failed to return correct bearing (180) for a destination straight west."
        );
        $this->assertEquals(
            -90,
            StdLib::computeBearing(0, 0, 0, -1),
            "computeBearing() failed to return correct bearing (-90) for a destination straight south."
        );
    }

    /**
     * Test getMyCaller()
     */
    public function testGetMyCaller()
    {
        $this->getMyCallerLineNumber = __LINE__ + 1;
        $this->getMyCallerHelper();
    }

    private function getMyCallerHelper()
    {
        $this->assertEquals(
            basename(__FILE__).":".$this->getMyCallerLineNumber,
            StdLib::getMyCaller(),
            "getMyCaller() failed to grab the correct file and calling line."
        );
    }
    /**
     * Test checkMyCaller()
     */
    public function testCheckMyCaller()
    {
        $this->getMyCallerLineNumber = __LINE__ + 1;
        $this->checkMyCallerHelper();
    }

    private function checkMyCallerHelper()
    {
        $this->assertTrue(
            StdLib::checkMyCaller(__CLASS__),
            "checkMyCaller() couldn't confirm this was called from the right class."
        );
        $this->assertTrue(
            StdLib::checkMyCaller(basename(__FILE__)),
            "checkMyCaller() couldn't confirm this was called from the right file."
        );
        $this->assertTrue(
            StdLib::checkMyCaller(basename(__FILE__).":".($this->getMyCallerLineNumber)),
            "checkMyCaller() couldn't confirm this was called from the right line."
        );
        $this->assertTrue(
            StdLib::checkMyCaller(__CLASS__."::testCheckMyCaller"),
            "checkMyCaller() couldn't confirm this was called from the right method."
        );
        $this->assertTrue(
            !StdLib::checkMyCaller(__CLASS__."::testGetMyCaller"),
            "checkMyCaller() failed to return false on incorrect method check."
        );
        $this->assertTrue(
            !StdLib::checkMyCaller("WrongClass::testCheckMyCaller"),
            "checkMyCaller() failed to return false on incorrect class check."
        );
        # throw exception when specified
        $this->expectException(Exception::class);
        StdLib::checkMyCaller("Invalid Caller Information", "Error message");
    }

    /**
     * Test getCallerInfo()
     */
    public function testGetCallerInfo()
    {
        $this->getMyCallerLineNumber = __LINE__ + 1;
        $this->getCallerInfoHelper();
    }

    private function getCallerInfoHelper()
    {
        $this->assertEquals(
            $this->getMyCallerLineNumber,
            StdLib::getCallerInfo()["LineNumber"],
            "getCallerInfo() failed to correctly retrieve line number of caller."
        );
        $this->assertTrue(
            strpos(StdLib::getCallerInfo()["RelativeFileName"], basename(__FILE__)) >= 0,
            "getCallerInfo() failed to correctly retrieve relative file name of caller."
        );
        $this->assertEquals(
            __FILE__,
            StdLib::getCallerInfo()["FullFileName"],
            "getCallerInfo() failed to correctly retrieve full file name of caller."
        );
        $this->assertEquals(
            basename(__FILE__),
            StdLib::getCallerInfo()["FileName"],
            "getCallerInfo() failed to correctly retrieve file name of caller."
        );
    }

    /**
     * Test getFileMode()
     */
    public function testGetFileMode()
    {
        for ($Mode = 0;  $Mode <= 0777;  $Mode++) {
            $TmpFile = tempnam(sys_get_temp_dir(), "PHPUnit-testGetFileMode-");
            chmod($TmpFile, $Mode);
            $FoundMode = StdLib::getFileMode($TmpFile);
            $ErrMsg = sprintf(
                "getFileMode() returned %04o instead of the expected %04o.",
                $FoundMode,
                $Mode
            );
            $this->assertEquals($Mode, $FoundMode, $ErrMsg);
            unlink($TmpFile);
        }
    }
}
