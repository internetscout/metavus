<?PHP

use ScoutLib\HtmlOptionList;
use ScoutLib\StdLib;

class HtmlOptionList_Test extends PHPUnit\Framework\TestCase
{
    /**
     * Test __construct().
     */
    public function testConstructor()
    {
        $MockFormName = "FormNameForTest";
        $MockOptions = [
            "val 0" => "label 0",
            "val 1" => "label 1",
            "val 2" => "label 2"
        ];
        $MockOptionsWithGroup = [
            "val 0" => "label 0",
            "label 1" => [
                "val 1.0" => "label 1.0",
                "val 1.1" => "label 1.1"
            ],
            "val 2" => "label 2"
        ];

        # Test basic structure of <select>
        $MsgHeader = "Test basic structure of <select>: ";
        $OptionList = new HtmlOptionList($MockFormName, []);
        $this->validateHtml($OptionList);

        $DOM = new DOMDocument();
        $DOM->loadHTML($OptionList->getHtml());
        $HTMLSelectEles = $DOM->getElementsByTagName("select");

        $this->assertSame(1, $HTMLSelectEles->count(),
                $MsgHeader . "There should be exactly 1 <select> element.");

        $SelectEle = $HTMLSelectEles->item(0);
        $this->assertSame($MockFormName, $SelectEle->getAttribute("name"),
                $MsgHeader . "Should have a correct form name.");

        $this->assertSame($MockFormName, $SelectEle->getAttribute("id"),
                $MsgHeader . "Should have a correct form id.");

        $this->assertEmpty($SelectEle->getElementsByTagName("option"),
                $MsgHeader . "Should not have any <option> when no option is provided.");

        # Test basic structure of <option>
        $MsgHeader = "Test basic structure of <option>: ";
        $OptionList = new HtmlOptionList($MockFormName, $MockOptions);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertSame(3, count($OptionEles),
                $MsgHeader . "There should be exactly 1 <option> inside <select>"
                    . " for each option provided to the constructor.");

        foreach (range(0, 2) as $Idx) {
            $OptionEle = $OptionEles->item($Idx);
            $this->assertSame("val " . $Idx, $OptionEle->getAttribute("value"),
                    $MsgHeader . "Should have a correct form value.");
            $this->assertSame("label " . $Idx, $OptionEle->textContent,
                    $MsgHeader . "Should have a correct label.");
        }

        # Test providing some grouped options to the constructor
        $MsgHeader = "Test providing some grouped options to the constructor: ";
        $OptionList = new HtmlOptionList($MockFormName, $MockOptionsWithGroup);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");
        $OptgroupEles = $SelectEle->getElementsByTagName("optgroup");

        $this->assertSame(1, count($OptgroupEles),
                $MsgHeader . "There should be exactly 1 <optgroup> inside <select>"
                    . " for each option group provided to the constructor.");

        $this->assertSame(4, count($OptionEles),
                $MsgHeader . "There should be exactly 1 <option> inside <select>"
                    . " for each option provided to the constructor.");

        $OptgroupEle = $OptgroupEles->item(0);
        $OptionEles = $OptgroupEle->getElementsByTagName("option");
        $this->assertSame(2, count($OptionEles),
                $MsgHeader . "There should be exactly 1 <option> inside a <optgroup>"
                        . " for each option within the group provided to the constructor.");

        $this->assertSame("label 1", $OptgroupEle->getAttribute("label"),
                $MsgHeader . "<optgroup> should have a correct 'label' attribute.");

        # Test specifying a single selected option
        $MsgHeader = "Test specifying a single selected option: ";
        $MockSelectedVal = "val 1";
        $OptionList = new HtmlOptionList($MockFormName, $MockOptions, $MockSelectedVal);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertTrue($OptionEles->item(1)->hasAttribute("selected"),
                $MsgHeader . "Selected <option> should have the 'selected' attribute.");

        $this->assertFalse($OptionEles->item(0)->hasAttribute("selected"),
                $MsgHeader . "<option> not selected should not have"
                        ." the 'selected' attribute.");
        $this->assertFalse($OptionEles->item(2)->hasAttribute("selected"),
                $MsgHeader . "<option> not selected should not have"
                        ." the 'selected' attribute.");

        # Test specifying an array of selected options
        $MsgHeader = "Test specifying an array of selected options: ";
        $MockSelectedVals = ["val 0", "val 2"];
        $OptionList = new HtmlOptionList($MockFormName, $MockOptions, $MockSelectedVals);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertTrue($OptionEles->item(0)->hasAttribute("selected"),
                $MsgHeader . "Selected <option> should have the 'selected' attribute.");
        $this->assertTrue($OptionEles->item(2)->hasAttribute("selected"),
                $MsgHeader . "Selected <option> should have the 'selected' attribute.");

        $this->assertFalse($OptionEles->item(1)->hasAttribute("selected"),
                $MsgHeader . "<option> not selected should not have"
                        ." the 'selected' attribute.");

        # Test pre-selecting a option-gorup's option
        $MsgHeader = "Test pre-selecting a option-gorup's option: ";
        $MockSelectedVal = "val 1.1";
        $OptionList = new HtmlOptionList($MockFormName, $MockOptionsWithGroup, $MockSelectedVal);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptgroupEle = $SelectEle->getElementsByTagName("optgroup")->item(0);
        $OptionEles = $OptgroupEle->getElementsByTagName("option");

        $this->assertTrue($OptionEles->item(1)->hasAttribute("selected"),
                $MsgHeader . "Selected <option> should have the 'selected' attribute.");

        $this->assertFalse($OptionEles->item(0)->hasAttribute("selected"),
                $MsgHeader . "<option> not selected should not have"
                        ." the 'selected' attribute.");
    }

    /**
     * Test disabledOptions().
     */
    public function testDisabledOptions()
    {
        $GenericDisabledMsg = "Disabled <option> should have the 'disabled' attribute.";
        $GenericNotDisabledMsg = "Not disabled <option> should not have the"
                . " 'disabled' attribute.";
        $GenericReturnCountMsg = "DisabledOptions() should return an array with correct size.";
        $GenericReturnHashKeyMsg = "DisabledOptions() should return an array with the"
                . " disabled option's value as its key.";

        # Test disabling a single option
        $MsgHeader = "Test disabling a single option: ";
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->disabledOptions("val 0");
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertTrue($OptionEles->item(0)->hasAttribute("disabled"),
                $MsgHeader . $GenericDisabledMsg);

        $this->assertFalse($OptionEles->item(1)->hasAttribute("disabled"),
                $MsgHeader . $GenericNotDisabledMsg);

        # Test disabling a single additional option
        $MsgHeader = "Test disabling a single additional option: ";
        $OptionList->disabledOptions("val 1");
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertTrue($OptionEles->item(0)->hasAttribute("disabled"),
                $MsgHeader . "Existing disabled <option> should stay disabled.");

        $this->assertTrue($OptionEles->item(1)->hasAttribute("disabled"),
                $MsgHeader . "The additional disabled <option> should have"
                        . " the 'disabled' attribute.");

        # Test disabling an array of options
        $MsgHeader = "Test disabling an array of options: ";
        $OptionList = $this->getTestHtmlOptionList(3);
        $OptionList->disabledOptions(["val 0" => 1, "val 2" => 1]);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertTrue($OptionEles->item(0)->hasAttribute("disabled"),
                $MsgHeader . $GenericDisabledMsg);
        $this->assertTrue($OptionEles->item(2)->hasAttribute("disabled"),
                $MsgHeader . $GenericDisabledMsg);

        $this->assertFalse($OptionEles->item(1)->hasAttribute("disabled"),
                $MsgHeader . $GenericNotDisabledMsg);

        # Test overriding the existing array of disabled options
        $MsgHeader = "Test overriding the existing array of disabled options: ";
        $OptionList->disabledOptions(["val 1" => 1, "val 2" => 1]);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertTrue($OptionEles->item(1)->hasAttribute("disabled"),
                $MsgHeader . $GenericDisabledMsg);
        $this->assertTrue($OptionEles->item(2)->hasAttribute("disabled"),
                $MsgHeader . $GenericDisabledMsg);

        $this->assertFalse($OptionEles->item(0)->hasAttribute("disabled"),
                $MsgHeader . $GenericNotDisabledMsg);

        # Test disabledOptions() returns the correct array of
        # disabled options, after disabling a single option
        $MsgHeader = "Test disabledOptions() returns the correct array of"
                ." disabled options, after disabling a single option: ";
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->disabledOptions("val 0");

        $this->assertSame(1, count($OptionList->disabledOptions()),
                $MsgHeader . $GenericReturnCountMsg);

        $this->assertArrayHasKey("val 0", $OptionList->disabledOptions(),
                $MsgHeader . $GenericReturnHashKeyMsg);

        # Test disabledOptions() returns the correct array of
        # disabled options, after disabling an array of options
        $MsgHeader = "Test disabledOptions() returns the correct array of"
                . " disabled options, after disabling an array of options: ";
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->disabledOptions(["val 0" => 1, "val 1" => 1]);
        $DisabledOptions = $OptionList->disabledOptions();

        $this->assertSame(2, count($DisabledOptions),
                $MsgHeader . $GenericReturnCountMsg);

        $this->assertArrayHasKey("val 0", $DisabledOptions,
                $MsgHeader . $GenericReturnHashKeyMsg);
        $this->assertArrayHasKey("val 1", $DisabledOptions,
                $MsgHeader . $GenericReturnHashKeyMsg);
    }

    /**
     * Test selectedValue().
     */
    public function testSelectedValue()
    {
        # Test setting a single selected optoin
        $MsgHeader = "Test setting a single selected optoin: ";
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->selectedValue("val 1");
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertFalse($OptionEles->item(0)->hasAttribute("selected"),
                $MsgHeader . "<option> not selected should not have"
                        ." the 'selected' attribute.");

        $this->assertTrue($OptionEles->item(1)->hasAttribute("selected"),
                $MsgHeader . "Selected <option> should have the 'selected' attribute.");

        # Test setting multiple selected options simultaneously
        $MsgHeader = "Test setting multiple selected options simultaneously: ";
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->selectedValue(["val 0", "val 1"]);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertTrue($OptionEles->item(0)->hasAttribute("selected"),
                $MsgHeader . "Selected <option> should have the 'selected' attribute.");
        $this->assertTrue($OptionEles->item(1)->hasAttribute("selected"),
                $MsgHeader . "Selected <option> should have the 'selected' attribute.");

        # Test setting new selected option overrides the existing one
        $MsgHeader = "Test setting new selected option overrides the existing one: ";
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->selectedValue("val 0");
        $OptionList->selectedValue("val 1");
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertFalse($OptionEles->item(0)->hasAttribute("selected"),
                $MsgHeader . "Previous selected option should have been overriden.");

        $this->assertTrue($OptionEles->item(1)->hasAttribute("selected"),
                $MsgHeader . "Option just selected should have the 'selected' attribute.");

        # Test selectedValue() returns the current selected option(s)
        $MsgHeader = "Test selectedValue() returns the current selected option(s): ";
        $OptionList = $this->getTestHtmlOptionList(2, "val 0");
        $this->assertEquals("val 0", $OptionList->selectedValue(),
            $MsgHeader . "Should return the correct currently selected option.");

        $OptionList->selectedValue(["val 1"]);
        $this->assertEquals(["val 1"], $OptionList->selectedValue(),
            $MsgHeader . "Should return the correct array of selected options.");
    }

    /**
     * Test size().
     */
    public function testSize()
    {
        # Test setting list size
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->size(100);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertEquals(100, $SelectEle->getAttribute("size"), "Test setting list size.");

        # Test size() returns the correct list size
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->size(66);
        $this->assertEquals(66, $OptionList->size(), "Test size() returns the correct list size.");
    }

    /**
     * Test multipleAllowed().
     */
    public function testMultipleAllowed()
    {
        # Test allowing selecting multiple options
        $MsgHeader = "Test allowing selecting multiple options: ";
        $MockFormName = "MockFormName";
        $OptionList = $this->getTestHtmlOptionList(2, null, $MockFormName);
        $OptionList->multipleAllowed(true);
        $SelectEle = $this->getOptionListDomEle($OptionList);

        $this->assertTrue($SelectEle->hasAttribute("multiple"),
                $MsgHeader . "<select> should have the 'multiple' attribute.");

        $this->assertEquals($MockFormName . "[]", $SelectEle->getAttribute("name"),
                $MsgHeader . "<select>'s form name should be appended with '[]'.");

        # Test disallowing selecting multiple options
        $MsgHeader = "Test disallowing selecting multiple options: ";
        $OptionList->multipleAllowed(false);
        $SelectEle = $this->getOptionListDomEle($OptionList);

        $this->assertFalse($SelectEle->hasAttribute("multiple"),
                $MsgHeader . "<select> should not have the 'multiple' attribute.");

        $this->assertEquals($MockFormName, $SelectEle->getAttribute("name"),
                $MsgHeader . "'[]' should be stripped from <select>'s form name.");

        # Test multipleAllowed() returns the correct value set before
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->multipleAllowed(true);
        $this->assertSame(true, $OptionList->multipleAllowed(),
                "Test multipleAllowed() returns the correct value set before.");

        $OptionList->multipleAllowed(false);
        $this->assertSame(false, $OptionList->multipleAllowed(),
                "Test multipleAllowed() returns the correct value set before.");
    }

    /**
     * Test submitOnChange() and onChangeAction().
     */
    public function testOnChange()
    {
        $OnChangeAct = "alert()";

        # Test an 'onChange' event listener is present when
        # submitOnChange is true and an onChangeAction is specified
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->submitOnChange(true);
        $OptionList->onChangeAction($OnChangeAct);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertSame($OnChangeAct, $SelectEle->getAttribute("onchange"),
                "Test an 'onChange' event listener is present when submitOnChange"
                        . " is true and an onChangeAction is specified.");

        # Test 'onChangeAction' has no effect when 'submitOnChange' is false
        $OptionList->submitOnChange(false);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertFalse($SelectEle->hasAttribute("onchange"),
                "Test 'onChangeAction' has no effect when 'submitOnChange' is false.");

        # Test there is always a default 'onChangeAction'
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->submitOnChange(true);
        $OptionList->onChangeAction("");
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertTrue($SelectEle->hasAttribute("onchange"),
                "Test there is always a default 'onChangeAction'.");

        # Test 'submitOnChange' returns the correct value set
        $Msg = "Test 'submitOnChange' returns the correct value set.";
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->submitOnChange(true);
        $this->assertSame(true, $OptionList->submitOnChange(), $Msg);

        $OptionList->submitOnChange(false);
        $this->assertSame(false, $OptionList->submitOnChange(), $Msg);

        # Test 'onChangeAction' returns the correct value set
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->onChangeAction($OnChangeAct);
        $this->assertSame($OnChangeAct, $OptionList->onChangeAction(),
                "Test 'onChangeAction' returns the correct value set.");
    }

    /**
     * Test printIfEmpty().
     */
    public function testPrintIfEmpty()
    {
        $MockFormName = "MockFormName";

        # Test still printing html when there is no option
        $MsgHeader = "Test still printing html when there is no option: ";
        $OptionList = new HtmlOptionList($MockFormName, []);
        $OptionList->printIfEmpty(true);
        $this->assertTrue(strpos($OptionList->getHtml(), "<select") !== false,
                $MsgHeader . "Html generated should contain a <select>.");

        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertSame($MockFormName, $SelectEle->getAttribute("name"),
                $MsgHeader . "<select> should have the correct form name.");

        # Test not printing html when there is no option
        $Option = new HtmlOptionList($MockFormName, []);
        $OptionList->printIfEmpty(false);
        $this->assertFalse(strpos($OptionList->getHtml(), "<select"),
                "Test not printing html when there is no option.");

        # Test printIfEmpty() returns the correct value set
        $OptionList = new HtmlOptionList($MockFormName, []);
        $OptionList->printIfEmpty(true);
        $this->assertSame(true, $OptionList->printIfEmpty(),
                "Test printIfEmpty() returns the correct value set.");

        $OptionList->printIfEmpty(false);
        $this->assertSame(false, $OptionList->printIfEmpty(),
                "Test printIfEmpty() returns the correct value set.");
    }

    /**
     * Test disabled().
     */
    public function testDisabled()
    {
        # Test disabling and enabling the option list
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->disabled(true);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertTrue($SelectEle->hasAttribute("disabled"),
                "Test disabling the option list.");

        $OptionList->disabled(false);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertFalse($SelectEle->hasAttribute("disabled"),
                "Test enabling disabled option list.");

        # Test disabled() returns the correct value set
        $Msg = "Test disabled() returns the correct value set.";
        $this->assertSame(false, $OptionList->disabled(), $Msg);

        $OptionList->disabled(true);
        $this->assertSame(true, $OptionList->disabled(), $Msg);
    }

    /**
     * Test classForList().
     */
    public function testClassForList()
    {
        # Test no class for the option list is set initially
        $MsgHeader = "Test no class for the option list is set initially: ";
        $OptionList = $this->getTestHtmlOptionList(2);
        $this->assertSame(null, $OptionList->classForList(),
                $MsgHeader . "classForList() should return null.");

        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertFalse($SelectEle->hasAttribute("class"),
                $MsgHeader . "<select> should not have the 'class' attribute.");

        # Test setting css class for the option list
        $MockClass = "test-class test-class-more";
        $OptionList->classForList($MockClass);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertSame($MockClass, $SelectEle->getAttribute("class"),
                "Test setting css class for the option list.");

        # Test classForList() returns the correct value set
        $this->assertSame($MockClass, $OptionList->classForList(),
                "Test classForList() returns the correct value set.");
    }

    /**
     * Test classForOptions().
     */
    public function testClassForOptions()
    {
        # Test no class for option(s) is set initially
        $MsgHeader = "Test no class for option(s) is set initially: ";
        $OptionList = $this->getTestHtmlOptionList(2);
        $this->assertSame(null, $OptionList->classForOptions(),
                $MsgHeader . "classForOptions() should return null.");

        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");
        foreach ($OptionEles as $OptionEle) {
            $this->assertFalse($OptionEle->hasAttribute("class"),
                    $MsgHeader . "<option> should not have the 'class' attribute.");
        }

        # Test setting a single class for all options
        $MockClass = "test test-more";
        $OptionList = $this->getTestHtmlOptionList(3);
        $OptionList->classForOptions($MockClass);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        foreach ($OptionEles as $OptionEle) {
            $this->assertSame($MockClass, $OptionEle->getAttribute("class"),
                    "Test setting a single class for all options.");
        }

        # Test setting an array of different classes for each option
        $Msg = "Test setting an array of different classes for each option.";
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->classForOptions([
            "val 0" => "class0",
            "val 1" => "class1"
        ]);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertSame("class0", $OptionEles->item(0)->getAttribute("class"), $Msg);
        $this->assertSame("class1", $OptionEles->item(1)->getAttribute("class"), $Msg);

        # Test setting an array of classes for only a subset of options
        $Msg = "Test setting an array of classes for only a subset of options.";
        $OptionList->classForOptions(["val 0" => "c"]);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertSame("c", $OptionEles->item(0)->getAttribute("class"), $Msg);
        $this->assertFalse($OptionEles->item(1)->hasAttribute("class"), $Msg);

        # Test classForOptions() returns the correct value set
        $Msg = "Test classForOptions() returns the correct value set.";
        $OptionList = $this->getTestHtmlOptionList(2);
        $MockClass = "test-class";
        $OptionList->classForOptions($MockClass);
        $this->assertSame($MockClass, $OptionList->classForOptions(), $Msg);

        $MockClass = ["val 0" => "c0"];
        $OptionList->classForOptions($MockClass);
        $this->assertSame($MockClass, $OptionList->classForOptions(), $Msg);
    }

    /**
     * Test dateForOptions().
     */
    public function testDateForOptions()
    {
        # Test setting an array of data attributes for options
        $Msg = "Test setting an array of data attributes for options.";
        $Data = [
            "val 0" => [
                "aaa" => 1,
                "bbb" => 2
            ],
            "val 1" => [
                "ccc" => 3
            ]
        ];
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->dataForOptions($Data);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEles = $SelectEle->getElementsByTagName("option");

        $this->assertEquals(1, $OptionEles->item(0)->getAttribute("data-aaa"), $Msg);
        $this->assertEquals(2, $OptionEles->item(0)->getAttribute("data-bbb"), $Msg);
        $this->assertEquals(3, $OptionEles->item(1)->getAttribute("data-ccc"), $Msg);

        # Test dataForOptions() returns the correct value set
        $this->assertEquals($Data, $OptionList->dataForOptions(),
                "Test dataForOptions() returns the correct value set.");

        # Test dataForOptions() returns empty array when no option data specified
        $OptionList = $this->getTestHtmlOptionList(2);
        $this->assertEmpty($OptionList->dataForOptions(),
                "Test dataForOptions() returns empty array"
                        . " when no option data specified.");
    }

    /**
     * Test maxLabelLength().
     */
    public function testMaxLabelLength()
    {
        $TestLabel = "aaabbbb";
        $MockFormName = "formname";
        $MockOptions = ["val" => $TestLabel];

        # Test setting a maximum label length
        $OptionList = new HtmlOptionList($MockFormName, $MockOptions);
        $OptionList->maxLabelLength(3);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEle = $SelectEle->getElementsByTagName("option")->item(0);

        $this->assertSame("aaa", $OptionEle->textContent,
                "Test setting a maximum label length.");

        # Test setting maxLabelLength to 0 disables the restriction
        $OptionList->maxLabelLength(0);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $OptionEle = $SelectEle->getElementsByTagName("option")->item(0);

        $this->assertSame($TestLabel, $OptionEle->textContent,
                "Test setting maxLabelLength to 0 disables the restriction.");

        # Test by default there is no maximum label length restriction
        $OptionList = new HtmlOptionList($MockFormName, $MockOptions);
        $this->assertSame(0, $OptionList->maxLabelLength(),
                "Test by default there is no maximum label length restriction.");

        # Test maxLabelLength() returns the correct value set
        $OptionList->maxLabelLength(6666);
        $this->assertSame(6666, $OptionList->maxLabelLength(),
                "Test maxLabelLength() returns the correct value set.");
    }

    /**
     * Test addAttribute().
     */
    public function testAddAttribute()
    {
        $AttributeA = self::CUSTOM_ATTRIBUTE_HEAD . "-a";
        $AttributeB = self::CUSTOM_ATTRIBUTE_HEAD . "-b";


        # Test adding a single additional attribute to the option list
        $OptionList = $this->getTestHtmlOptionList(2);
        $OptionList->addAttribute($AttributeA, 1);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertSame("1", $SelectEle->getAttribute($AttributeA),
                "Test adding a single additional attribute to the option list.");

        # Test adding more than 1 additional attributes to the option list
        $MsgHeader = "Test adding more than 1 additional attributes to the option list: ";
        $OptionList->addAttribute($AttributeB, 2);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertSame("1", $SelectEle->getAttribute($AttributeA),
                $MsgHeader . "Attribute added previously should not be overriden.");

        $this->assertSame("2", $SelectEle->getAttribute($AttributeB),
                $MsgHeader . "Attribtue just added should be present.");

        # Test overriding the value of an attribute added previously
        $OptionList->addAttribute($AttributeB, 6);
        $SelectEle = $this->getOptionListDomEle($OptionList);
        $this->assertSame("6", $SelectEle->getAttribute($AttributeB),
                "Test overriding the value of an attribute added previously.");
    }

    /**
     * Test the html of an HtmlOptionList is valid.
     * @param HtmlOptionList $OptionList Target HtmlOptionList.
     */
    private function validateHtml(HtmlOptionList $OptionList)
    {
        # We validate the option list's html by first validating
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
            "/Specification mandates value for attribute multiple/",
            "/Specification mandates value for attribute disabled/",
            "/Specification mandates value for attribute selected/",
            "/Element '.*select': Missing child element/",
            "/The attribute 'onChange' is not allowed/",
            "/The attribute 'data-\w+' is not allowed/",
            "/The attribute '" . self::CUSTOM_ATTRIBUTE_HEAD
                    . "-\w+' is not allowed/",

        ];

        $Html = '<form action=""><p>' . $OptionList->getHtml() . '</p></form>';
        $ValidationErrors = StdLib::validateXhtml($Html, $ErrorsToIgnore);

        $ErrorMsgHeader = "getHtml() returned an invalid html; document errors:\n";
        $ErrorMsgBuilder = function($Msg, $Error) {
            return $Msg . "***** " . $Error->message . "\n";
        };
        $ErrorMsg = array_reduce($ValidationErrors, $ErrorMsgBuilder, $ErrorMsgHeader);
        $this->assertEmpty($ValidationErrors, $ErrorMsg);
    }

    /**
     * Get the HtmlOptionList's html string, test its validity,
     * parse it to DOM, and then return the DOMElement for 'select'.
     * @param HtmlOptionList $OptionList Target HtmlOptionList.
     * @return DOMElement DOMElement for the root (<select></>).
     */
    private function getOptionListDomEle(HtmlOptionList $OptionList)
    {
        $this->validateHtml($OptionList);

        # return <select>
        $DOM = new DOMDocument();
        $DOM->loadHTML($OptionList->getHtml());
        return $DOM->getElementsByTagName("select")->item(0);
    }

    /**
     * Build a simple HtmlOptionList for testing purpose.
     * An option's value takes the form "val ${index}", where index
     * depends on argument $OptionCount and starts from 0.
     * The label takes the form "label ${index}".
     *
     * For example, if $OptionCount == 2. Then there will be 2 options:
     *      "val 0" => "label 0",
     *      "val 1" => "label 1"
     * @param int $OptionCount The number of options.
     * @param mixed $SelectedValues Selected values (OPTIONAL).
     * @param string $FormName Form name for the <select> (OPTIONAL).
     * @return HtmlOptionList The option list just built.
     */
    private function getTestHtmlOptionList(int $OptionCount, $SelectedValues = null,
            $FormName = "TestFormName")
    {
        foreach (range(0, $OptionCount-1) as $Idx) {
            $Options["val " . $Idx] = "label " . $Idx;
        }

        return isset($SelectedValues) ?
            new HtmlOptionList($FormName, $Options, $SelectedValues) :
            new HtmlOptionList($FormName, $Options);
    }

    /**
     * Head of custom attributes for this test.
     * Since unregistered attributes trigger errors when validating html,
     * we have to explicitly ignore those errors.
     */
    const CUSTOM_ATTRIBUTE_HEAD = "attr";
}

