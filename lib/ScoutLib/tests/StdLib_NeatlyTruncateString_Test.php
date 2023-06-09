<?PHP

use ScoutLib\StdLib;

/**
* Test cases for NeatlyTruncateString in StdLib
*/
class NeatlyTruncateString_Test extends PHPUnit\Framework\TestCase
{

    /**
    * Test NeatlyTruncateString.
    */
    public function testNTS()
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
}
