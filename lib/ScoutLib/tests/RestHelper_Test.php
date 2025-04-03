<?PHP
#
#   FILE:  RestHelper_Test.php
#
#   Part of the ScoutLib application support library
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace ScoutLib;

class RestHelper_Test extends \PHPUnit\Framework\TestCase
{
    # ---- SETUP -------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        # determine user name and home directory
        $UserInfo = posix_getpwuid(fileowner(__FILE__));
        $UserName = $UserInfo["name"];
        $HomeDir = $UserInfo["dir"];

        # determine working location for rest endpoint test file
        $EndpointFile = dirname(__FILE__)."/files/RestEndpoint.php";
        self::$EndpointTempFile = $HomeDir."/public_html/".basename($EndpointFile);

        # copy rest endpoint test file into working location
        copy($EndpointFile, self::$EndpointTempFile);

        # determine URL for rest endpoint test file in working location
        self::$EndpointUrl = "https://test.scout.wisc.edu/~".$UserName."/"
                .basename($EndpointFile);
    }

    public static function tearDownAfterClass(): void
    {
        unlink(self::$EndpointTempFile);
    }

    # ---- TESTS -------------------------------------------------------------

    public function testIssueCall()
    {
        $Params = [
            "NumberToIncrement" => 5,
            "StringToReverse" => "This is a test.",
        ];

        # test successful REST API call
        $ExpectedResponse = [
            "NumberToIncrement" => 6,
            "StringToReverse" => ".tset a si sihT",
        ];
        $RHelper = new RestHelper(self::$EndpointUrl);
        $Response = $RHelper->issueCall($Params);
        $this->assertEquals($ExpectedResponse, $Response);

        # test REST API call with bad URL
        $BadUrl = "XXX".self::$EndpointUrl;
        $ExpectedResponse = null;
        $ExpectedError = "Protocol \"XXXhttps\" not supported or disabled in libcurl";
        $RHelper = new RestHelper($BadUrl);
        $Response = $RHelper->issueCall($Params);
        $this->assertEquals($Response, $ExpectedResponse);
        $this->assertEquals($RHelper->getCurlError(), $ExpectedError);
    }

    # ---- PRIVATE -----------------------------------------------------------

    private static $EndpointTempFile;
    private static $EndpointUrl;
}
