<?PHP

use ScoutLib\HtmlCheckboxSet;

require_once("lib/ScoutLib/tests/HtmlValidationTestTrait.php");

class HtmlCheckboxSet_Test extends PHPUnit\Framework\TestCase
{
    use HtmlValidationTestTrait;

    const VALIDATION_ERRORS_TO_IGNORE = [
        "/The document has no document element/",
        "/Specification mandates value for attribute disabled/",
        "/Specification mandates value for attribute checked/",
        "/The attribute 'data-[\w-]+' is not allowed/",
    ];

    /**
     * Test __construct().
     */
    public function testConstructor()
    {
        # Test with no options provided
        $MsgHeader = "Test with no options provided: ";
        $CheckboxSet = $this->getCheckboxSetForTest(0);
        $this->assertSame(
            "",
            $CheckboxSet->getHtml(),
            $MsgHeader . "HTML should be empty when no option is provided"
        );

        # Test basic structure of Checkbox Set
        $MsgHeader = "Test basic structure of Checkbox Set: ";
        $MockFormName = "FormNameForTest";
        $CheckboxSet = $this->getCheckboxSetForTest(3, null, $MockFormName);
        $DOM = $this->validateAndLoadHtml($CheckboxSet);

        $DivEles = $DOM->getElementsByTagName("div");
        $this->assertSame(
            1 + 3,
            count($DivEles),
            $MsgHeader . "There should be exactly 4 <div> elements"
        );

        $InputEles = $DOM->getElementsByTagName("input");

        $this->assertSame(
            3,
            count($InputEles),
            $MsgHeader . "There should be exactly 1 <input> for each option"
        );

        $LabelEles = $DOM->getElementsByTagName("label");

        $this->assertSame(
            3,
            count($LabelEles),
            $MsgHeader . "There should be exactly 1 <label> for each option"
        );

        foreach (range(0, 2) as $Idx) {
            $InputEle = $InputEles->item($Idx);
            $LabelEle = $LabelEles->item($Idx);

            $this->assertSame(
                "checkbox",
                $InputEle->getAttribute("type"),
                $MsgHeader . "<input>'s 'type' attribute should be 'checkbox'."
            );

            $this->assertSame(
                $MockFormName . "[]",
                $InputEle->getAttribute("name"),
                $MsgHeader . "<input> should have a correct form name."
            );

            $this->assertSame(
                "val ".$Idx,
                $InputEle->getAttribute("value"),
                $MsgHeader . "<input> should have a correct form value."
            );

            $this->assertSame(
                $MockFormName . "_val" . $Idx,
                $InputEle->getAttribute("id"),
                $MsgHeader . "<input> should have a correct form id."
            );

            $this->assertSame(
                $InputEle->getAttribute("id"),
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
            $Regex = '/<input.*value="'.$InputEle->getAttribute("value").'"\s*>\s*<label for="'
                    .$InputEle->getAttribute("id").'"/';
            $this->assertTrue(
                !!preg_match($Regex, $CheckboxSet->getHtml()),
                $MsgHeader."The <label> for a given <input> should be right next to it."
            );
        }

        # Test specifying a single selected option
        $MsgHeader = "Test specifying a single selected option";
        $MockSelectedVal = "val 1";
        $CheckboxSet = $this->getCheckboxSetForTest(3, $MockSelectedVal);
        $CheckboxEles = $this->getCheckboxSetDomEles($CheckboxSet);

        $this->assertTrue(
            $CheckboxEles["val 1"]->hasAttribute("checked"),
            $MsgHeader . "Selected input shoud have 'checked' attribute"
        );

        $this->assertFalse(
            $CheckboxEles["val 0"]->hasAttribute("checked"),
            $MsgHeader . "Unselected input should not have the 'checked' attribute."
        );

        $this->assertFalse(
            $CheckboxEles["val 2"]->hasAttribute("checked"),
            $MsgHeader . "Unselected input should not have the 'checked' attribute."
        );

        # Test specifying an array of selected options
        $MsgHeader = "Test specifying an array of selected options";
        $MockSelectedVals = ["val 0", "val 2"];
        $CheckboxSet = $this->getCheckboxSetForTest(3, $MockSelectedVals);
        $CheckboxEles = $this->getCheckboxSetDomEles($CheckboxSet);

        $this->assertTrue(
            $CheckboxEles["val 0"]->hasAttribute("checked"),
            $MsgHeader . "Selected input shoud have 'checked' attribute"
        );

        $this->assertTrue(
            $CheckboxEles["val 2"]->hasAttribute("checked"),
            $MsgHeader . "Selected input shoud have 'checked' attribute"
        );

        $this->assertFalse(
            $CheckboxEles["val 1"]->hasAttribute("checked"),
            $MsgHeader . "Unselected input should not have the 'checked' attribute."
        );
    }

     /**
     * Test disabledOptions().
     */
    public function testDisabledOptions()
    {
        # Test disabling a single option
        $MsgHeader = "Test disabling a single option: ";
        $CheckboxSet = $this->getCheckboxSetForTest(2);
        $CheckboxSet->disabledOptions("val 1");
        $InputEles = $this->getCheckboxSetDomEles($CheckboxSet);

        $this->assertTrue(
            $InputEles["val 1"]->hasAttribute("disabled"),
            $MsgHeader."Disabled input should have the 'disabled' attribute."
        );
        $this->assertFalse(
            $InputEles["val 0"]->hasAttribute("disabled"),
            $MsgHeader."Not disabled input should not have the"
                    ." 'disabled' attribute."
        );
        $this->assertSame(
            ["val 1"],
            array_keys($CheckboxSet->disabledOptions()),
            $MsgHeader."disabledOptions() should return an array with exactly"
                    ." 1 key which equals the value of the option just disabled."
        );

        # Test adding another disabled option
        $MsgHeader = "Test adding another disabled option: ";
        $CheckboxSet = $this->getCheckboxSetForTest(3);
        $CheckboxSet->disabledOptions("val 1");
        $CheckboxSet->disabledOptions("val 0");
        $InputEles = $this->getCheckboxSetDomEles($CheckboxSet);

        $this->assertTrue(
            $InputEles["val 1"]->hasAttribute("disabled"),
            $MsgHeader."First disabled option should still have the"
                    ." 'disabled' attribute."
        );
        $this->assertTrue(
            $InputEles["val 0"]->hasAttribute("disabled"),
            $MsgHeader."The most recently disabled option should have"
                    ." the 'disabled' attribute."
        );
        $this->assertFalse(
            $InputEles["val 2"]->hasAttribute("disabled"),
            $MsgHeader."Not disabled input should not have the"
                    ." 'disabled' attribute."
        );
        $this->assertSame(
            ["val 1", "val 0"],
            array_keys($CheckboxSet->disabledOptions()),
            $MsgHeader."disabledOptions() should return an array with keys"
                    ." exactly equal the value of the options just disabled."
        );

        # Test disabling an array of options
        $MsgHeader = "Test disabling an array of options: ";
        $DisabledOptions = [
            "val 0" => 1,
            "val 1" => 1
        ];
        $CheckboxSet = $this->getCheckboxSetForTest(3);
        $CheckboxSet->disabledOptions($DisabledOptions);
        $InputEles = $this->getCheckboxSetDomEles($CheckboxSet);

        $this->assertTrue(
            $InputEles["val 0"]->hasAttribute("disabled"),
            $MsgHeader."Disabled input should have the 'disabled' attribute."
        );
        $this->assertTrue(
            $InputEles["val 1"]->hasAttribute("disabled"),
            $MsgHeader."Disabled input should have the 'disabled' attribute."
        );
        $this->assertFalse(
            $InputEles["val 2"]->hasAttribute("disabled"),
            $MsgHeader."Not disabled input should not have the"
                    ." 'disabled' attribute."
        );
        $this->assertSame(
            ["val 0", "val 1"],
            array_keys($CheckboxSet->disabledOptions()),
            $MsgHeader."disabledOptions() should return an array with keys"
                    ." exactly equal the value of the options just disabled."
        );

        # Test overriding the existing array of disabled options
        $MsgHeader = "Test overriding the existing array of disabled options";
        $DisabledOptions = [
            "val 0" => 1,
            "val 1" => 1
        ];
        $CheckboxSet = $this->getCheckboxSetForTest(3);
        $CheckboxSet->disabledOptions($DisabledOptions);
        $CheckboxSet->disabledOptions(["val 2" => 1]);
        $InputEles = $this->getCheckboxSetDomEles($CheckboxSet);

        $this->assertTrue(
            $InputEles["val 2"]->hasAttribute("disabled"),
            $MsgHeader."Disabled input should have the 'disabled' attribute."
        );
        $this->assertFalse(
            $InputEles["val 0"]->hasAttribute("disabled"),
            $MsgHeader."Previous disabled options should no longer have"
                    ." the 'disabled' option."
        );
        $this->assertFalse(
            $InputEles["val 1"]->hasAttribute("disabled"),
            $MsgHeader."Previous disabled options should no longer have"
                    ." the 'disabled' option."
        );
        $this->assertSame(
            ["val 2"],
            array_keys($CheckboxSet->disabledOptions()),
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
        $CheckboxSet = $this->getCheckboxSetForTest(2);
        $CheckboxSet->selectedValue($MockSelected);
        $InputEles = $this->getCheckboxSetDomEles($CheckboxSet);

        $this->assertTrue(
            $InputEles["val 1"]->hasAttribute("checked"),
            $MsgHeader."selected input should have the 'checked' attribute."
        );
        $this->assertFalse(
            $InputEles["val 0"]->hasAttribute("checked"),
            $MsgHeader."unselected input should not have the 'checked' attribute."
        );
        $this->assertSame(
            $MockSelected,
            $CheckboxSet->selectedValue(),
            $MsgHeader."selectedValue() should return that string value."
        );

        # Test setting an array of selected values
        $MsgHeader = "Test setting an array of selected values: ";
        $MockSelected = [
            "val 0",
            "val 2"
        ];
        $CheckboxSet = $this->getCheckboxSetForTest(3);
        $CheckboxSet->selectedValue($MockSelected);
        $InputEles = $this->getCheckboxSetDomEles($CheckboxSet);

        $this->assertTrue(
            $InputEles["val 0"]->hasAttribute("checked"),
            $MsgHeader."selected input should have the 'checked' attribute."
        );
        $this->assertTrue(
            $InputEles["val 2"]->hasAttribute("checked"),
            $MsgHeader."selected input should have the 'checked' attribute."
        );
        $this->assertFalse(
            $InputEles["val 1"]->hasAttribute("checked"),
            $MsgHeader."unselected input should not have the 'checked' attribute."
        );
        $this->assertSame(
            $MockSelected,
            $CheckboxSet->selectedValue(),
            $MsgHeader."selectedValue() should return an array of selected values."
        );
    }

    /**
     * Test disabled().
     */
    public function testDisabled()
    {
        # Test disabling the whole checkbox set
        $Values = ["val 0", "val 1", "val 2"];
        $CheckboxSet = $this->getCheckboxSetForTest(3);
        $CheckboxSet->disabled(true);
        $InputEles = $this->getCheckboxSetDomEles($CheckboxSet);

        foreach ($Values as $Key) {
            $this->assertTrue(
                $InputEles[$Key]->hasAttribute("disabled"),
                "Test disabled(true) disables all checkboxes: Disabled"
                        ." input should have the 'disabled' attribute."
            );
        }

        # Test enabling the whole checkbox set
        $CheckboxSet->disabled(false);
        $InputEles = $this->getCheckboxSetDomEles($CheckboxSet);

        foreach ($Values as $Key) {
            $this->assertFalse(
                $InputEles[$Key]->hasAttribute("disabled"),
                "Test disabled(false) enables all checkboxes: Enabled checkbox"
                        ." should not have the 'disabled' attribute."
            );
        }

        # Test disabled(false) does not affect disabledOptions()
        $MsgHeader = "Test disabled(false) does not affect disabledOptions(): ";
        $CheckboxSet = $this->getCheckboxSetForTest(2);
        $CheckboxSet->disabledOptions("val 1");
        $CheckboxSet->disabled(false);
        $InputEles = $this->getCheckboxSetDomEles($CheckboxSet);

        $this->assertTrue(
            $InputEles["val 1"]->hasAttribute("disabled"),
            $MsgHeader."Option specified in disabledOptions() should still"
                    ." have the 'disabled' attribute."
        );
        $this->assertFalse(
            $InputEles["val 0"]->hasAttribute("disabled"),
            $MsgHeader."Option not specified in disabledOptions() should not"
                    ." have the 'disabled' attribute."
        );

        # Test disabled() returns the correct value set
        $Msg = "Test disabled() returns the correct value set.";
        $CheckboxSet = $this->getCheckboxSetForTest(2);
        $CheckboxSet->disabled(true);
        $this->assertTrue($CheckboxSet->disabled(), $Msg);

        $CheckboxSet->disabled(false);
        $this->assertFalse($CheckboxSet->disabled(), $Msg);
    }

    /**
     * Preprocess html for validation.
     * - Appends </input> because a closing tag is required by XHTML
     * even though <input ...> is the correct html5.
     * - Wraps entire html in div.
     */
    private function preprocessHtml(string $CheckboxSetHtml)
    {
        $InputPattern = "/<input.*?>/";
        $Replacement = "\\0</input>";
        $Html = preg_replace($InputPattern, $Replacement, $CheckboxSetHtml);
        $Html = "<div>".$Html."</div>";
        return $Html;
    }

    /**
     * Build an HtmlCheckboxSet for test.
     * The options have key in this form: "val ${Idx}". The label
     * is in this form: "label ${$Idx}".
     * For example, if $OptionCount == 2, the options will be:
     *      "val 0" => "label 0",
     *      "val 1" => "label 1"
     * @param int $OptionCount The number of options.
     * @param mixed $SelectedValues Currently selected form value or array
     *       of currently selected form values.  (OPTIONAL)
     * @param string $FormName Name of form variable for select element.
     *       By default this is TestFormName.  (OPTIONAL)
     */
    private function getCheckboxSetForTest(
        int $OptionCount,
        $SelectedValues = null,
        string $FormName = "TestFormName"
    ) {
        $Options = [];
        for ($Idx = 0; $Idx < $OptionCount; $Idx++) {
            $Options["val " . $Idx] = "label " . $Idx;
        }

        return new HtmlCheckboxSet($FormName, $Options, $SelectedValues);
    }

    /**
     * Test the html of an HtmlInputSet is valid and load it onto a new DOM Document
     * @param HtmlCheckboxSet $CheckboxSet Target HtmlCheckboxSet.
     * @return DOMDocument DOMDocument containing the html of the InputSet.
     */
    protected function validateAndLoadHtml(HtmlCheckboxSet $CheckboxSet)
    {
        $this->validateHtml($CheckboxSet->getHtml(), $this::VALIDATION_ERRORS_TO_IGNORE);
        $DOM = new DOMDocument();
        $DOM->loadHtml($CheckboxSet->getHtml());
        return $DOM;
    }

    /**
     * Given a HtmlCheckboxSet, get its HTML string, test its validity,
     * parse it to DOM, and then return an array of <input> for the checkboxes.
     * Note this function assumes the generated HTML string has the correct
     * structure (this is tested in testConstructor).
     * @param HtmlCheckboxSet $CheckboxSet The target HtmlCheckboxSet.
     * @return array An associative array with the checkbox value attribute as
     * key. The value is the "input" DOMElement.
     */
    private function getCheckboxSetDomEles(HtmlCheckboxSet $CheckboxSet)
    {
        $DOM = $this->validateAndLoadHtml($CheckboxSet);
        $Inputs = $DOM->getElementsByTagName("input");

        $Checkboxes = [];
        foreach ($Inputs as $Input) {
            $Value = $Input->getAttribute("value");
            $Checkboxes[$Value] = $Input;
        }
        return $Checkboxes;
    }
}
