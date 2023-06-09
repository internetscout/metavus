<?PHP
#
#   FILE:  Email_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;

use ScoutLib\Email;

class Email_Test extends \PHPUnit\Framework\TestCase
{

    # ---- TESTS -------------------------------------------------------------

    /**
     * Verify that wrapHtmlAsNecessary() works correctly.
     * covers wrapHtmlAsNecessary().
     */
    public function testWrapHtmlAsNecessary()
    {
        # create a new Email test instance
        $Email = new Email();

        # we will use the following configuration for testing
        # we will set the max line length to 100 so it's easier to test
        # the same logic is still applied however.
        $MaxLineLength = 100;
        $LineEnding = "\r\n";

        # test case 1: The HTML has no long lines
        # we expect no changes to be made to the HTML in this case
        $HTML = "<p>Lorem ipsum dolor si</p>\r\n<p>Lorem ipsum dolor si</p>";
        $Expected = "<p>Lorem ipsum dolor si</p>\r\n<p>Lorem ipsum dolor si</p>";
        $this->assertEquals(
            $Expected,
            $Email::wrapHtmlAsNecessary($HTML, $MaxLineLength, $LineEnding)
        );

        # test case 2: The HTML has a long line that can be fixed without being aggressive
        $HTML = "<p id='test' class='test' title='test paragraph'>Lorem ipsum dolor sit amet, "
            ."consectetuer adipiscing elit.</p>";
        $Expected = "\r\n<!-- Line was wrapped. Aggressive: No, Max: 100, Actual: 110 -->\r\n"
            ."<p\r\nid='test'\r\nclass='test'\r\ntitle='test paragraph'>Lorem ipsum dolor sit "
            ."amet, consectetuer adipiscing elit.</p>";
        $this->assertEquals(
            $Expected,
            $Email::wrapHtmlAsNecessary($HTML, $MaxLineLength, $LineEnding)
        );

        # test case 3: The HTML has a long line that was fixed by being aggresive
        $HTML = "<p>Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo "
            ."ligula eget dolor. Aenean massa. Cum sociis nat</p>";
        $Expected = "\r\n<!-- Line was wrapped. Aggressive: Yes, Max: 100, Actual: 127 -->\r\n"
            ."<p>Lorem\r\nipsum\r\ndolor\r\nsit\r\namet,\r\nconsectetuer\r\nadipiscing\r\nelit."
            ."\r\nAenean\r\ncommodo\r\nligula\r\neget\r\ndolor.\r\nAenean\r\nmassa.\r\n"
            ."Cum\r\nsociis\r\nnat</p>";
        $this->assertEquals(
            $Expected,
            $Email::wrapHtmlAsNecessary($HTML, $MaxLineLength, $LineEnding)
        );

        # test case 4: The HTML has a long line inside one of the `ignored tags`
        # we expect no changes to be made to the HTML inside of the ignored tag
        $HTML = "<style>p {color:red; font-size:14px; font-weight:bold; font-style:italic; "
            ."font-family:Arial, Helvetica, sans-serif;}</style><p>Lorem ipsum dolor sit "
            ."amet, consectetuer adipiscing elit.</p>";
        $Expected = "\r\n<!-- Line was wrapped. Aggressive: Yes, Max: 100, Actual: 188 -->\r\n"
            ."<style>p {color:red; font-size:14px; font-weight:bold; font-style:italic; "
            ."font-family:Arial, Helvetica, sans-serif;}</style><p>Lorem\r\nipsum\r\ndolor\r\n"
            ."sit\r\namet,\r\nconsectetuer\r\nadipiscing\r\nelit.</p>";
        $this->assertEquals(
            $Expected,
            $Email::wrapHtmlAsNecessary($HTML, $MaxLineLength, $LineEnding)
        );
    }
}
