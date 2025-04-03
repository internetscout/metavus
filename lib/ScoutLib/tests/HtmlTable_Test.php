<?PHP

use ScoutLib\HtmlTable;

require_once("lib/ScoutLib/tests/HtmlValidationTestTrait.php");

class HtmlTable_Test extends PHPUnit\Framework\TestCase
{
    use HtmlValidationTestTrait;

    const VALIDATION_ERRORS_TO_IGNORE = [
        "/Element '.*table': Missing child element/",
    ];

    /**
     * Test setTableClass()
     */
    public function testSetTableClass()
    {
        $MockTableClass = "test-table-class";
        $Table = new HtmlTable();
        $Table->setTableClass($MockTableClass);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $this->assertSame(
            $MockTableClass,
            $MainEle->getAttribute("class"),
            "Table should have test class"
        );
    }

    /**
     * Test isPseudoTable()
     */
    public function testIsPseudoTable()
    {
        $Table = new HtmlTable();

        # Test default behavior
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $this->assertSame(
            "table",
            $MainEle->tagName,
            "Test main element is <table> for default table"
        );

        # Test non-pseudo table main element
        $Table->isPseudoTable(false);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $this->assertSame(
            "table",
            $MainEle->tagName,
            "Test main element is <table> for non-pseudo table"
        );

        # Test pseudo table main element
        $Table->isPseudoTable(true);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $this->assertSame(
            "div",
            $MainEle->tagName,
            "Test main element is <div> for pseudo table"
        );
    }

    /**
     * Test addRow()
     */
    public function testAddRow()
    {
        $MockFirstRowClass = "test-first-row-class";
        $MockFirstRow = [
            "row 0, cell 0",
            "row 0, cell 1",
            "row 0, cell 2"
        ];

        $MockSecondRowClass = "test-second-row-class";
        $MockSecondRow = [
            "row 1, cell 0",
            "row 1, cell 1",
        ];

        # Test non-pseudo table
        $MsgHeaderForTable = "Non-pseudo table: ";
        # Test no rows
        $Table = new HtmlTable();
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $this->assertSame(
            0,
            $MainEle->childElementCount,
            $MsgHeaderForTable . "Test table with no rows has no children"
        );

        # Test single row
        $MsgHeader = $MsgHeaderForTable . "Test table with single, classless row: ";
        $Table->addRow($MockFirstRow);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $MainEle->getElementsByTagName("tr");
        $this->assertSame(
            1,
            $Rows->count(),
            $MsgHeader . "Should have exactly 1 <tr>."
        );

        $FirstRow = $Rows->item(0);
        $this->assertSame(
            "",
            $FirstRow->getAttribute("class"),
            $MsgHeader . "<tr> should have no class."
        );

        $Cells = $FirstRow->getElementsByTagName("td");
        $this->assertSame(
            3,
            $Cells->count(),
            $MsgHeader . "Row should have 3 <td> elements."
        );

        foreach (range(0, 2) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockFirstRow[$Idx],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }

        # Test single row with class
        $MsgHeader = $MsgHeaderForTable . "Test table with single row with class: ";
        $Table = new HtmlTable();
        $Table->addRow($MockFirstRow, $MockFirstRowClass);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $MainEle->getElementsByTagName("tr");
        $FirstRow = $Rows->item(0);
        $this->assertSame(
            $MockFirstRowClass,
            $FirstRow->getAttribute("class"),
            $MsgHeader . "<tr> should have correct class."
        );

        # Test adding another row
        $MsgHeader = $MsgHeaderForTable
            . "Test table with multiple rows with different classes: ";
        $Table->addRow($MockSecondRow, $MockSecondRowClass);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $MainEle->getElementsByTagName("tr");
        $this->assertSame(
            2,
            $Rows->count(),
            $MsgHeader . "Should have exactly 2 <tr> elements."
        );

        $FirstRow = $Rows->item(0);
        $this->assertSame(
            $MockFirstRowClass,
            $FirstRow->getAttribute("class"),
            $MsgHeader . "First <tr> should have correct class."
        );

        $SecondRow = $Rows->item(1);
        $this->assertSame(
            $MockSecondRowClass,
            $SecondRow->getAttribute("class"),
            $MsgHeader . "Second <tr> should have correct class."
        );

        $Cells = $FirstRow->getElementsByTagName("td");
        $this->assertSame(
            3,
            $Cells->count(),
            $MsgHeader . "First row should have 3 <td> elements."
        );

        foreach (range(0, 2) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockFirstRow[$Idx],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }

        $Cells = $SecondRow->getElementsByTagName("td");
        $this->assertSame(
            2,
            $Cells->count(),
            $MsgHeader . "Second row should have 2 <td> elements."
        );

        foreach (range(0, 1) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockSecondRow[$Idx],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }

        # Test pseudo table
        $MsgHeaderForTable = "Pseudo table: ";
        # Test no rows
        $Table = new HtmlTable();
        $Table->isPseudoTable(true);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $this->assertSame(
            0,
            count($this->getChildEles($MainEle)),
            $MsgHeaderForTable . "Test table with no rows has no children"
        );

        # Test single row
        $MsgHeader = $MsgHeaderForTable . "Test table with single, classless row: ";
        $Table->addRow($MockFirstRow);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $this->getChildEles($MainEle);
        $this->assertSame(
            1,
            count($Rows),
            $MsgHeader . "Main <div> should have one child node."
        );

        $FirstRow = $Rows[0];
        $this->assertSame(
            "div",
            $FirstRow->tagName,
            $MsgHeader . "Row element should be <div>."
        );

        $Cells = $FirstRow->getElementsByTagName("div");
        $this->assertSame(
            3,
            $Cells->count(),
            $MsgHeader . "Row should have 3 <div> elements."
        );

        foreach (range(0, 2) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockFirstRow[$Idx],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }

        # Test single row with class
        $MsgHeader = $MsgHeaderForTable . "Test table with single row with class: ";
        $Table = new HtmlTable();
        $Table->isPseudoTable(true);
        $Table->addRow($MockFirstRow, $MockFirstRowClass);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $this->getChildEles($MainEle);

        # Test adding another row
        $MsgHeader = $MsgHeaderForTable
            . "Test table with multiple rows with different classes: ";
        $Table->addRow($MockSecondRow, $MockSecondRowClass);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $this->getChildEles($MainEle);
        $this->assertSame(
            2,
            count($Rows),
            $MsgHeader . "Main <div> should have two child nodes."
        );

        $FirstRow = $Rows[0];
        $this->assertSame(
            "div",
            $FirstRow->tagName,
            $MsgHeader . "Row element should be <div>."
        );

        $SecondRow = $Rows[1];
        $this->assertSame(
            "div",
            $SecondRow->tagName,
            $MsgHeader . "Row element should be <div>."
        );

        $Cells = $FirstRow->getElementsByTagName("div");
        $this->assertSame(
            3,
            $Cells->count(),
            $MsgHeader . "First row should have 3 child <div> elements."
        );

        foreach (range(0, 2) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockFirstRow[$Idx],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }

        $Cells = $SecondRow->getElementsByTagName("div");
        $this->assertSame(
            2,
            $Cells->count(),
            $MsgHeader . "Second row should have 2 child <div> elements"
        );

        foreach (range(0, 1) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockSecondRow[$Idx],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }
    }

    /**
     * Test addRows()
     */
    public function testAddRows()
    {
        $MockRowClass = "test-first-row-class";
        $MockFirstRow = [
            "row 0, cell 0",
            "row 0, cell 1",
            "row 0, cell 2"
        ];
        $MockSecondRow = [
            "row 1, cell 0",
            "row 1, cell 1",
        ];

        # Test non-pseudo table
        $MsgHeaderForTable = "Non-pseudo table: ";
        # Test adding single row
        $MsgHeader = $MsgHeaderForTable . "Adding single row: ";
        $AddRowTable = new HtmlTable();
        $AddRowsTable = new HtmlTable();
        $AddRowTable->addRow($MockFirstRow, $MockRowClass);
        $AddRowsTable->addRows([$MockFirstRow], $MockRowClass);
        $this->assertSame(
            $AddRowTable->getHtml(),
            $AddRowsTable->getHtml(),
            $MsgHeaderForTable . "HTML should be equivalent to calling addRow once."
        );

        # Test adding multiple rows
        $MsgHeader = $MsgHeaderForTable . "Adding multiple rows";
        $AddRowsTable = new HtmlTable();
        $AddRowTable->addRow($MockSecondRow, $MockRowClass);
        $AddRowsTable->addRows([$MockFirstRow, $MockSecondRow], $MockRowClass);
        $this->assertSame(
            $AddRowTable->getHtml(),
            $AddRowsTable->getHtml(),
            $MsgHeader . "HTML should be equivalent to calling addRow twice."
        );

        # Test pseudo table
        $MsgHeaderForTable = "Pseudo table: ";
        # Test adding single row
        $MsgHeader = $MsgHeaderForTable . "Adding single row: ";
        $AddRowTable = new HtmlTable();
        $AddRowTable->isPseudoTable(true);
        $AddRowsTable = new HtmlTable();
        $AddRowsTable->isPseudoTable(true);
        $AddRowTable->addRow($MockFirstRow, $MockRowClass);
        $AddRowsTable->addRows([$MockFirstRow], $MockRowClass);
        $this->assertSame(
            $AddRowTable->getHtml(),
            $AddRowsTable->getHtml(),
            $MsgHeaderForTable . "HTML should be equivalent to calling addRow once."
        );

        # Test adding multiple rows
        $MsgHeader = $MsgHeaderForTable . "Adding multiple rows";
        $AddRowsTable = new HtmlTable();
        $AddRowsTable->isPseudoTable(true);
        $AddRowTable->addRow($MockSecondRow, $MockRowClass);
        $AddRowsTable->addRows([$MockFirstRow, $MockSecondRow], $MockRowClass);
        $this->assertSame(
            $AddRowTable->getHtml(),
            $AddRowsTable->getHtml(),
            $MsgHeader . "HTML should be equivalent to calling addRow twice."
        );
    }

    /**
     * Test addRowWithHeader()
     */
    public function testAddRowWithHeader()
    {
        $MockFirstRowClass = "test-first-row-class";
        $MockFirstRow = [
            "row 0, cell 0",
            "row 0, cell 1",
            "row 0, cell 2"
        ];

        $MockSecondRowClass = "test-second-row-class";
        $MockSecondRow = [
            "row 1, cell 0",
            "row 1, cell 1",
        ];

        # Test non-pseudo table
        $MsgHeaderForTable = "Non-pseudo table: ";
        # Test no rows
        $Table = new HtmlTable();
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $this->assertSame(
            0,
            $MainEle->childElementCount,
            $MsgHeaderForTable . "Test table with no rows has no children"
        );

        # Test single row
        $MsgHeader = $MsgHeaderForTable . "Test table with single, classless row: ";
        $Table->addRowWithHeader($MockFirstRow);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $MainEle->getElementsByTagName("tr");
        $this->assertSame(
            1,
            $Rows->count(),
            $MsgHeader . "Should have exactly 1 <tr>."
        );

        $FirstRow = $Rows->item(0);
        $this->assertSame(
            "",
            $FirstRow->getAttribute("class"),
            $MsgHeader . "<tr> should have no class."
        );

        $Header = $FirstRow->getElementsByTagName("th");
        $this->assertSame(
            1,
            $Header->count(),
            $MsgHeader . "Row should have 1 <th>."
        );

        $Cells = $FirstRow->getElementsByTagName("td");
        $this->assertSame(
            2,
            $Cells->count(),
            $MsgHeader . "Row should have 2 <td> elements."
        );

        $this->assertSame(
            $MockFirstRow[0],
            $Header->item(0)->textContent,
            $MsgHeader . "Should have correct text content."
        );

        foreach (range(0, 1) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockFirstRow[$Idx + 1],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }

        # Test single row with class
        $MsgHeader = $MsgHeaderForTable . "Test table with single row with class: ";
        $Table = new HtmlTable();
        $Table->addRowWithHeader($MockFirstRow, $MockFirstRowClass);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $MainEle->getElementsByTagName("tr");
        $FirstRow = $Rows->item(0);
        $this->assertSame(
            $MockFirstRowClass,
            $FirstRow->getAttribute("class"),
            $MsgHeader . "<tr> should have correct class."
        );

        # Test adding another row
        $MsgHeader = $MsgHeaderForTable
            . "Test table with multiple rows with different classes: ";
        $Table->addRowWithHeader($MockSecondRow, $MockSecondRowClass);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $MainEle->getElementsByTagName("tr");
        $this->assertSame(
            2,
            $Rows->count(),
            $MsgHeader . "Should have exactly 2 <tr> elements."
        );

        $FirstRow = $Rows->item(0);
        $this->assertSame(
            $MockFirstRowClass,
            $FirstRow->getAttribute("class"),
            $MsgHeader . "First <tr> should have correct class."
        );

        $SecondRow = $Rows->item(1);
        $this->assertSame(
            $MockSecondRowClass,
            $SecondRow->getAttribute("class"),
            $MsgHeader . "Second <tr> should have correct class."
        );

        $Header = $FirstRow->getElementsByTagName("th");
        $this->assertSame(
            1,
            $Header->count(),
            $MsgHeader . "First row should have 1 <th>."
        );

        $Cells = $FirstRow->getElementsByTagName("td");
        $this->assertSame(
            2,
            $Cells->count(),
            $MsgHeader . "First row should have 2 <td> elements."
        );

        $this->assertSame(
            $MockFirstRow[0],
            $Header->item(0)->textContent,
            $MsgHeader . "Should have correct text content."
        );

        foreach (range(0, 1) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockFirstRow[$Idx + 1],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }

        $Header = $SecondRow->getElementsByTagName("th");
        $this->assertSame(
            1,
            $Header->count(),
            $MsgHeader . "First row should have 1 <th>."
        );

        $Cells = $SecondRow->getElementsByTagName("td");
        $this->assertSame(
            1,
            $Cells->count(),
            $MsgHeader . "Second row should have 1 <td>."
        );

        $this->assertSame(
            $MockSecondRow[0],
            $Header->item(0)->textContent,
            $MsgHeader . "Should have correct text content."
        );

        $this->assertSame(
            $MockSecondRow[1],
            $Cells->item(0)->textContent,
            $MsgHeader . "Should have correct text content."
        );

        # Test pseudo table
        $MsgHeaderForTable = "Pseudo table: ";
        # Test no rows
        $Table = new HtmlTable();
        $Table->isPseudoTable(true);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $this->assertSame(
            0,
            count($this->getChildEles($MainEle)),
            $MsgHeaderForTable . "Test table with no rows has no children"
        );

        # Test single row
        $MsgHeader = $MsgHeaderForTable . "Test table with single, classless row: ";
        $Table->addRowWithHeader($MockFirstRow);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $this->getChildEles($MainEle);
        $this->assertSame(
            1,
            count($Rows),
            $MsgHeader . "Main <div> should have one child node."
        );

        $FirstRow = $Rows[0];
        $this->assertSame(
            "div",
            $FirstRow->tagName,
            $MsgHeader . "Row element should be <div>."
        );

        $Cells = $FirstRow->getElementsByTagName("div");
        $this->assertSame(
            3,
            $Cells->count(),
            $MsgHeader . "Row should have 3 <div> elements."
        );

        foreach (range(0, 2) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockFirstRow[$Idx],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }

        # Test single row with class
        $MsgHeader = $MsgHeaderForTable . "Test table with single row with class: ";
        $Table = new HtmlTable();
        $Table->isPseudoTable(true);
        $Table->addRowWithHeader($MockFirstRow, $MockFirstRowClass);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $this->getChildEles($MainEle);
        $FirstRow = $Rows[0];
        $this->assertStringContainsString(
            $MockFirstRowClass,
            $FirstRow->getAttribute("class"),
            $MsgHeader . "Row <div> should have correct class."
        );

        # Test adding another row
        $MsgHeader = $MsgHeaderForTable
            . "Test table with multiple rows with different classes: ";
        $Table->addRowWithHeader($MockSecondRow, $MockSecondRowClass);
        $DOM = $this->validateAndLoadHtml($Table);
        $MainEle = $DOM->getElementsByTagName("body")->item(0)->firstChild;
        $Rows = $this->getChildEles($MainEle);
        $this->assertSame(
            2,
            count($Rows),
            $MsgHeader . "Main <div> should have two child nodes."
        );

        $FirstRow = $Rows[0];
        $this->assertSame(
            "div",
            $FirstRow->tagName,
            $MsgHeader . "Row element should be <div>."
        );
        $this->assertStringContainsString(
            $MockFirstRowClass,
            $FirstRow->getAttribute("class"),
            $MsgHeader . "First row should have correct class."
        );

        $SecondRow = $Rows[1];
        $this->assertSame(
            "div",
            $SecondRow->tagName,
            $MsgHeader . "Row element should be <div>."
        );
        $this->assertStringContainsString(
            $MockSecondRowClass,
            $SecondRow->getAttribute("class"),
            $MsgHeader . "Second row should have correct class."
        );

        $Cells = $FirstRow->getElementsByTagName("div");
        $this->assertSame(
            3,
            $Cells->count(),
            $MsgHeader . "First row should have 3 child <div> elements."
        );

        foreach (range(0, 2) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockFirstRow[$Idx],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }

        $Cells = $SecondRow->getElementsByTagName("div");
        $this->assertSame(
            2,
            $Cells->count(),
            $MsgHeader . "Second row should have 2 child <div> elements"
        );

        foreach (range(0, 1) as $Idx) {
            $Cell = $Cells->item($Idx);
            $this->assertSame(
                $MockSecondRow[$Idx],
                $Cell->textContent,
                $MsgHeader . "Should have correct text content."
            );
        }
    }

    /**
     * Test addRowsWithHeaders()
     */
    public function testAddRowsWithHeaders()
    {
        $MockRowClass = "test-first-row-class";
        $MockFirstRow = [
            "row 0, cell 0",
            "row 0, cell 1",
            "row 0, cell 2"
        ];
        $MockSecondRow = [
            "row 1, cell 0",
            "row 1, cell 1",
        ];

        # Test non-pseudo table
        $MsgHeaderForTable = "Non-pseudo table: ";
        # Test adding single row
        $MsgHeader = $MsgHeaderForTable . "Adding single row: ";
        $AddRowTable = new HtmlTable();
        $AddRowsTable = new HtmlTable();
        $AddRowTable->addRowWithHeader($MockFirstRow, $MockRowClass);
        $AddRowsTable->addRowsWithHeaders([$MockFirstRow], $MockRowClass);
        $this->assertSame(
            $AddRowTable->getHtml(),
            $AddRowsTable->getHtml(),
            $MsgHeaderForTable . "HTML should be equivalent to calling addRowWithHeader once."
        );

        # Test adding multiple rows
        $MsgHeader = $MsgHeaderForTable . "Adding multiple rows";
        $AddRowsTable = new HtmlTable();
        $AddRowTable->addRowWithHeader($MockSecondRow, $MockRowClass);
        $AddRowsTable->addRowsWithHeaders([$MockFirstRow, $MockSecondRow], $MockRowClass);
        $this->assertSame(
            $AddRowTable->getHtml(),
            $AddRowsTable->getHtml(),
            $MsgHeader . "HTML should be equivalent to calling addRowWithHeader twice."
        );

        # Test pseudo table
        $MsgHeaderForTable = "Pseudo table: ";
        # Test adding single row
        $MsgHeader = $MsgHeaderForTable . "Adding single row: ";
        $AddRowTable = new HtmlTable();
        $AddRowTable->isPseudoTable(true);
        $AddRowsTable = new HtmlTable();
        $AddRowsTable->isPseudoTable(true);
        $AddRowTable->addRowWithHeader($MockFirstRow, $MockRowClass);
        $AddRowsTable->addRowsWithHeaders([$MockFirstRow], $MockRowClass);
        $this->assertSame(
            $AddRowTable->getHtml(),
            $AddRowsTable->getHtml(),
            $MsgHeaderForTable . "HTML should be equivalent to calling addRowWithHeader once."
        );

        # Test adding multiple rows
        $MsgHeader = $MsgHeaderForTable . "Adding multiple rows";
        $AddRowsTable = new HtmlTable();
        $AddRowsTable->isPseudoTable(true);
        $AddRowTable->addRowWithHeader($MockSecondRow, $MockRowClass);
        $AddRowsTable->addRowsWithHeaders([$MockFirstRow, $MockSecondRow], $MockRowClass);
        $this->assertSame(
            $AddRowTable->getHtml(),
            $AddRowsTable->getHtml(),
            $MsgHeader . "HTML should be equivalent to calling addRow twice."
        );
    }

    /**
     * Test the html of an HtmlTable is valid and load it onto a new DOM Document
     * @param HtmlTable $Table Target HtmlTable.
     * @return DOMDocument DOMDocument containing the html of the InputSet.
     */
    protected function validateAndLoadHtml(HtmlTable $Table)
    {
        $this->validateHtml($Table->getHtml(), $this::VALIDATION_ERRORS_TO_IGNORE);
        $DOM = new DOMDocument();
        $DOM->loadHtml($Table->getHtml());
        return $DOM;
    }

    /**
     * Get child nodes and only keep DOMElements
     * @param DOMNode $ParentNode Node to get children of.
     * @return array Child DOMElements
     */
    private function getChildEles(DOMNode $ParentNode) : array
    {
        $Rows = [];
        foreach ($ParentNode->childNodes as $Node) {
            if ($Node instanceof DOMElement) {
                $Rows[] = $Node;
            }
        }
        return $Rows;
    }
}
