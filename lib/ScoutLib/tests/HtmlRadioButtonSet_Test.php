<?php

use ScoutLib\HtmlRadioButtonSet;
use ScoutLib\StdLib;

class HtmlRadioButtonSet_Test extends PHPUnit\Framework\TestCase
{
    /**
     * Test __construct().
     */
    public function testConstructor()
    {
        $MockFormName = "MockFormName";
        $MockOptions = [
            "val 0" => "label 0",
            "val 1" => "label 1",
            "val 2" => "label 2"
        ];

        # Test basic structure of the HtmlRadioButtonSet
        $MsgHeader = "Test basic structure of the HtmlRadioButtonSet: ";
        $ButtonSet = new HtmlRadioButtonSet($MockFormName, $MockOptions);
        $this->validateHtml($ButtonSet);

        $DOM = new DOMDocument();
        $ButtonSetHtml = $ButtonSet->getHtml();
        $DOM->loadHTML($ButtonSetHtml);
        $InputEles = $DOM->getElementsByTagName("input");
        $LabelEles = $DOM->getElementsByTagName("label");

        $this->assertSame(
            3,
            count($InputEles),
            $MsgHeader."There should be exactly 1 <input> for each option provided."
        );
        $this->assertSame(
            3,
            count($LabelEles),
            $MsgHeader."There should be exactly 1 <label> for each optoin provided."
        );

        foreach (range(0,2) as $Idx) {
            $InputEle = $InputEles->item($Idx);
            $LabelEle = $LabelEles->item($Idx);
            $InputVal = $InputEle->getAttribute("value");
            $InputId = $InputEle->getAttribute("id");

            $this->assertSame(
                "radio",
                $InputEle->getAttribute("type"),
                $MsgHeader."<input>'s 'type' attribute should be 'radio'."
            );
            $this->assertSame(
                $MockFormName,
                $InputEle->getAttribute("name"),
                $MsgHeader."<input> should have a correct form name."
            );
            $this->assertSame(
                "val ".$Idx,
                $InputVal,
                $MsgHeader."<input> should have a correct form value."
            );
            $this->assertSame(
                $MockFormName."_val".$Idx,
                $InputId,
                $MsgHeader."<input> should have a correct form id."
            );

            $this->assertSame(
                $InputId,
                $LabelEle->getAttribute("for"),
                $MsgHeader."<label>'s 'for' attribute should be the same as"
                        ." <input>'s form id."
            );
            $this->assertSame(
                "label ".$Idx,
                $LabelEle->textContent,
                $MsgHeader."<label> should have the correct label text."
            );

            # this regex is saying: there can only be whitespaces between the <input>
            # whose value is $InputVal and the <label> whose for is $InputId
            $Regex = '/<input.*value="'.$InputVal.'"\s*>\s*<label for="'.$InputId.'"/';
            $this->assertTrue(
                !!preg_match($Regex, $ButtonSetHtml),
                $MsgHeader."The <label> for a given <input> should be right next to it."
            );
        }

        # Test specifying a single selected option
        $MsgHeader = "Test specifying a single selected option: ";
        $MockSelected = "val 1";
        $ButtonSet = new HtmlRadioButtonSet($MockFormName, $MockOptions, $MockSelected);
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        $this->assertTrue(
            $ButtonEles[$MockSelected]->hasAttribute("checked"),
            $MsgHeader."selected button should have the 'checked' attribute."
        );

        $this->assertFalse(
            $ButtonEles["val 0"]->hasAttribute("checked"),
            $MsgHeader."unselected button should not have the 'checked' attribute."
        );
        $this->assertFalse(
            $ButtonEles["val 2"]->hasAttribute("checked"),
            $MsgHeader."unselected button should not have the 'checked' attribute."
        );

        # Test specifying an array of selected options
        $MsgHeader = "Test specifying an array of selected options: ";
        $MockSelected = [
            "val 0",
            "val 2"
        ];
        $ButtonSet = new HtmlRadioButtonSet($MockFormName, $MockOptions, $MockSelected);
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        $this->assertTrue(
            $ButtonEles["val 0"]->hasAttribute("checked"),
            $MsgHeader."selected button should have the 'checked' attribute."
        );
        $this->assertTrue(
            $ButtonEles["val 2"]->hasAttribute("checked"),
            $MsgHeader."selected button should have the 'checked' attribute."
        );

        $this->assertFalse(
            $ButtonEles["val 1"]->hasAttribute("checked"),
            $MsgHeader."unselected button should not have the 'checked' attribute."
        );
    }

    /**
     * Test disabledOptions().
     */
    public function testDisabledOptions()
    {
        # Test disabling a single option
        $MsgHeader = "Test disabling a single option: ";
        $ButtonSet = $this->getRadioButtonSetForTest(2);
        $ButtonSet->disabledOptions("val 1");
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        $this->assertTrue(
            $ButtonEles["val 1"]->hasAttribute("disabled"),
            $MsgHeader."Disabled button should have the 'disabled' attribute."
        );
        $this->assertFalse(
            $ButtonEles["val 0"]->hasAttribute("disabled"),
            $MsgHeader."Not disabled button should not have the"
                    ." 'disabled' attribute."
        );
        $this->assertSame(
            ["val 1"],
            array_keys($ButtonSet->disabledOptions()),
            $MsgHeader."disabledOptions() should return an array with exactly"
                    ." 1 key which equals the value of the option just disabled."
        );

        # Test adding another disabled option
        $MsgHeader = "Test adding another disabled option: ";
        $ButtonSet = $this->getRadioButtonSetForTest(3);
        $ButtonSet->disabledOptions("val 1");
        $ButtonSet->disabledOptions("val 0");
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        $this->assertTrue(
            $ButtonEles["val 1"]->hasAttribute("disabled"),
            $MsgHeader."First disabled option should still have the"
                    ." 'disabled' attribute."
        );
        $this->assertTrue(
            $ButtonEles["val 0"]->hasAttribute("disabled"),
            $MsgHeader."The most recently disabled option should have"
                    ." the 'disabled' attribute."
        );
        $this->assertFalse(
            $ButtonEles["val 2"]->hasAttribute("disabled"),
            $MsgHeader."Not disabled button should not have the"
                    ." 'disabled' attribute."
        );
        $this->assertSame(
            ["val 1", "val 0"],
            array_keys($ButtonSet->disabledOptions()),
            $MsgHeader."disabledOptions() should return an array with keys"
                    ." exactly equal the value of the options just disabled."
        );

        # Test disabling an array of options
        $MsgHeader = "Test disabling an array of options: ";
        $DisabledOptions = [
            "val 0" => 1,
            "val 1" => 1
        ];
        $ButtonSet = $this->getRadioButtonSetForTest(3);
        $ButtonSet->disabledOptions($DisabledOptions);
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        $this->assertTrue(
            $ButtonEles["val 0"]->hasAttribute("disabled"),
            $MsgHeader."Disabled button should have the 'disabled' attribute."
        );
        $this->assertTrue(
            $ButtonEles["val 1"]->hasAttribute("disabled"),
            $MsgHeader."Disabled button should have the 'disabled' attribute."
        );
        $this->assertFalse(
            $ButtonEles["val 2"]->hasAttribute("disabled"),
            $MsgHeader."Not disabled button should not have the"
                    ." 'disabled' attribute."
        );
        $this->assertSame(
            ["val 0", "val 1"],
            array_keys($ButtonSet->disabledOptions()),
            $MsgHeader."disabledOptions() should return an array with keys"
                    ." exactly equal the value of the options just disabled."
        );

        # Test overriding the existing array of disabled options
        $MsgHeader = "Test overriding the existing array of disabled options";
        $DisabledOptions = [
            "val 0" => 1,
            "val 1" => 1
        ];
        $ButtonSet = $this->getRadioButtonSetForTest(3);
        $ButtonSet->disabledOptions($DisabledOptions);
        $ButtonSet->disabledOptions(["val 2" => 1]);
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        $this->assertTrue(
            $ButtonEles["val 2"]->hasAttribute("disabled"),
            $MsgHeader."Disabled button should have the 'disabled' attribute."
        );
        $this->assertFalse(
            $ButtonEles["val 0"]->hasAttribute("disabled"),
            $MsgHeader."Previous disabled options should no longer have"
                    ." the 'disabled' option."
        );
        $this->assertFalse(
            $ButtonEles["val 1"]->hasAttribute("disabled"),
            $MsgHeader."Previous disabled options should no longer have"
                    ." the 'disabled' option."
        );
        $this->assertSame(
            ["val 2"],
            array_keys($ButtonSet->disabledOptions()),
            $MsgHeader."disabledOptions() should return the array of disabled"
                    ." options set most recently."
        );
    }

    /**
     * Test selectedValue().
     */
    public function testSelectedValue()
    {
        # Test setting a single selected value
        $MsgHeader = "Test setting a single selected value: ";
        $MockSelected = "val 1";
        $ButtonSet = $this->getRadioButtonSetForTest(2);
        $ButtonSet->selectedValue($MockSelected);
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        $this->assertTrue(
            $ButtonEles["val 1"]->hasAttribute("checked"),
            $MsgHeader."selected button should have the 'checked' attribute."
        );
        $this->assertFalse(
            $ButtonEles["val 0"]->hasAttribute("checked"),
            $MsgHeader."unselected button should not have the 'checked' attribute."
        );
        $this->assertSame(
            $MockSelected,
            $ButtonSet->selectedValue(),
            $MsgHeader."selectedValue() should return that string value."
        );

        # Test setting an array of selected values
        $MsgHeader = "Test setting an array of selected values: ";
        $MockSelected = [
            "val 0",
            "val 2"
        ];
        $ButtonSet = $this->getRadioButtonSetForTest(3);
        $ButtonSet->selectedValue($MockSelected);
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        $this->assertTrue(
            $ButtonEles["val 0"]->hasAttribute("checked"),
            $MsgHeader."selected button should have the 'checked' attribute."
        );
        $this->assertTrue(
            $ButtonEles["val 2"]->hasAttribute("checked"),
            $MsgHeader."selected button should have the 'checked' attribute."
        );
        $this->assertFalse(
            $ButtonEles["val 1"]->hasAttribute("checked"),
            $MsgHeader."unselected button should not have the 'checked' attribute."
        );
        $this->assertSame(
            $MockSelected,
            $ButtonSet->selectedValue(),
            $MsgHeader."selectedValue() should return an array of selected values."
        );
    }

    /**
     * Test disabled().
     */
    public function testDisabled()
    {
        # Test disabling the whole button set
        $Values = ["val 0", "val 1", "val 2"];
        $ButtonSet = $this->getRadioButtonSetForTest(3);
        $ButtonSet->disabled(true);
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        foreach ($Values as $Key) {
            $this->assertTrue(
                $ButtonEles[$Key]->hasAttribute("disabled"),
                "Test disabled(true) disables all buttons: Disabled"
                        ." button should have the 'disabled' attribute."
            );
        }

        # Test enabling the whole button set
        $ButtonSet->disabled(false);
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        foreach ($Values as $Key) {
            $this->assertFalse(
                $ButtonEles[$Key]->hasAttribute("disabled"),
                "Test disabled(false) enables all buttons: Enabled button"
                        ." should not have the 'disabled' attribute."
            );
        }

        # Test disabled(false) does not affect disabledOptions()
        $MsgHeader = "Test disabled(false) does not affect disabledOptions(): ";
        $ButtonSet = $this->getRadioButtonSetForTest(2);
        $ButtonSet->disabledOptions("val 1");
        $ButtonSet->disabled(false);
        $ButtonEles = $this->getRadioButtonSetDomEles($ButtonSet);

        $this->assertTrue(
            $ButtonEles["val 1"]->hasAttribute("disabled"),
            $MsgHeader."Option specified in disabledOptions() should still"
                    ." have the 'disabled' attribute."
        );
        $this->assertFalse(
            $ButtonEles["val 0"]->hasAttribute("disabled"),
            $MsgHeader."Option not specified in disabledOptions() should not"
                    ." have the 'disabled' attribute."
        );

        # Test disabled() returns the correct value set
        $Msg = "Test disabled() returns the correct value set.";
        $ButtonSet = $this->getRadioButtonSetForTest(2);
        $ButtonSet->disabled(true);
        $this->assertTrue($ButtonSet->disabled(), $Msg);

        $ButtonSet->disabled(false);
        $this->assertFalse($ButtonSet->disabled(), $Msg);
    }

    /**
     * Given a HtmlRadioButtonSet, get its HTML string, test its validity,
     * parse it to DOM, and then return an array of <input> for the buttons.
     * Note this function assumes the generated HTML string has the correct
     * structure (this is tested in testConstructor).
     * @param HtmlRadioButtonSet $ButtonSet The target HtmlRadioButtonSet.
     * @return array An associative array with the button option value as
     * key. The value is the "input" DOMElement.
     */
    private function getRadioButtonSetDomEles(HtmlRadioButtonSet $ButtonSet)
    {
        $this->validateHtml($ButtonSet);

        $DOM = new DOMDocument();
        $DOM->loadHTML($ButtonSet->getHtml());
        $Inputs = $DOM->getElementsByTagName("input");

        $Buttons = [];
        foreach ($Inputs as $Input) {
            $Value = $Input->getAttribute("value");
            $Buttons[$Value] = $Input;
        }
        return $Buttons;
    }

    /**
     * Build a HtmlRadioButtonSet for test.
     * This HtmlRadioButtonSet has no selected value.
     * The options have key in this form: "val ${Idx}". The label
     * is in this form: "label ${$Idx}".
     * For example, if $ButtonCount == 2, the options will be:
     *      "val 0" => "label 0",
     *      "val 1" => "label 1"
     * @param int $ButtonCount The number of options.
     * @return HtmlRadioButtonSet The HtmlRadioButtonSet just built.
     */
    private function getRadioButtonSetForTest(int $ButtonCount)
    {
        foreach (range(0, $ButtonCount-1) as $Idx) {
            $Options["val ".$Idx] = "label ".$Idx;
        }
        return new HtmlRadioButtonSet("TestFormName", $Options);
    }

    /**
     * Test the html of an HtmlRadioButtonSet is valid.
     * @param HtmlRadioButtonSet $ButtonSet Target HtmlRadioButtonSet.
     */
    private function validateHtml(HtmlRadioButtonSet $ButtonSet)
    {
        # We validate the button set's html by first validating
        # it against XHTML and then filtering out error
        # messages of errors that are not error in HTML.
        # We do this instead of just using DOMDocument::validate()
        # because DOMDocument::validate(), which internally uses
        # libxml2's xmlValidateDocument(), will attempt to
        # fetch DTD from W3C, which will then get blocked (see
        # https://www.w3.org/blog/systeam/2008/02/08/w3c_s_excessive_dtd_traffic/).
        # Fetching HTML4.01 DTD manually and then using it as a local
        # DTD does not solve the problem because it's using a DTD
        # syntax that libxml2 cannot parse. For HTML5, since it's
        # no longer SGML based, there is no DTD to use.
        $ErrorsToIgnore = [
            "/The document has no document element/",
            "/Specification mandates value for attribute disabled/",
            "/Specification mandates value for attribute checked/",
        ];

        # append </input> because a closing tag is required
        # by XHTML even though <input ...> is the correct html5
        $InputPattern = "/<input.*?>/";
        $Replacement = "\\0</input>";
        $Html = preg_replace($InputPattern, $Replacement, $ButtonSet->getHtml());
        $Html = "<div>".$Html."</div>";

        # validate and build error message
        $Errors = StdLib::validateXhtml($Html, $ErrorsToIgnore);
        $Message = "getHtml() returned an invalid html; document errors:\n".
            "HTML Was:\n"
            .$Html
            ."Errors were:\n"
            .array_reduce(
                $Errors,
                function ($Carry, $Value) {
                    return $Carry . " ".$Value->message."\n";
                },
                ""
            );
        $this->assertEmpty($Errors, $Message);
    }
}
