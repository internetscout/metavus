<?PHP
#
#   FILE:  HtmlTable.php
#
#   Part of the ScoutLib application support library
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Class for building and displaying HTML tables.
 */
class HtmlTable
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Generate and return HTML for table.
     * TO DO: Add support for specifying cells spanning multiple columns by
     *      setting subsequent cell content to FALSE.
     * @return string Generated HTML.
     */
    public function getHtml(): string
    {
        return $this->IsPseudoTable ? $this->getDivHtml() : $this->getTableHtml();
    }

    /**
     * Add body row to table, with the first cell being a header for the row.
     * @param array $Cells Array of content, with the first entry holding
     *      content for the row header cell.
     * @param ?string $Class Class for row.  (OPTIONAL)
     */
    public function addRowWithHeader(array $Cells, ?string $Class = null): void
    {
        $this->RowData[] = $this->prepareRowData($Cells, $Class, true);
    }

    /**
     * Add body rows to table, with the first cell in each row being a header
     * for the row.
     * @param array $Rows Array of arrays, with the outer array being rows,
     *      the inner arrays being content for each cell within that row,
     *      and the first entry in each inner array holding content for the
     *      row header cell.
     * @param ?string $Class Class for rows.  (OPTIONAL)
     */
    public function addRowsWithHeaders(array $Rows, ?string $Class = null): void
    {
        foreach ($Rows as $Cells) {
            $this->addRowWithHeader($Cells, $Class);
        }
    }

    /**
     * Add body row to table.
     * @param array $Cells Array of content.
     * @param ?string $Class Class for row.  (OPTIONAL)
     * @return void
     */
    public function addRow(array $Cells, ?string $Class = null): void
    {
        $this->RowData[] = $this->prepareRowData($Cells, $Class);
    }

    /**
     * Add header row to table.
     * @param array $Cells Array of content.
     * @param ?string $Class Class for row.  (OPTIONAL)
     */
    public function addHeaderRow(array $Cells, ?string $Class = null): void
    {
        $this->HeaderRowData[] = $this->prepareRowData($Cells, $Class, false, true);
    }

    /**
     * Add footer row to table.
     * @param array $Cells Array of content.
     * @param ?string $Class Class for row.  (OPTIONAL)
     */
    public function addFooterRow(array $Cells, ?string $Class = null): void
    {
        $this->FooterRowData[] = $this->prepareRowData($Cells, $Class);
    }

    /**
     * Add body rows to table.
     * @param array $Rows Array of arrays, with the outer array being rows,
     *      the inner arrays being content for each cell within that row.
     * @param ?string $Class Class for rows.  (OPTIONAL)
     * @return void
     */
    public function addRows(array $Rows, ?string $Class = null): void
    {
        foreach ($Rows as $Cells) {
            $this->addRow($Cells, $Class);
        }
    }

    /**
     * Set CSS class(es) for entire table (i.e. base <table> or <div> tag).
     * @param string $Class CSS class(es).
     * @return void
     */
    public function setTableClass(string $Class): void
    {
        $this->TableClass =  $Class;
    }

    /**
     * Determine if the generated HTML will use <table> tags or a CSS-based
     *     grid of <div>s for layout. (Default behavior is to use <table>
     *     tags.)
     * @param bool $NewValue TRUE to use div/grid layout, FALSE for HTML
     *     tables.
     * @return void
     */
    public function isPseudoTable(bool $NewValue) : void
    {
        $this->IsPseudoTable = $NewValue;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $RowData = [];
    protected $HeaderRowData = [];
    protected $FooterRowData = [];
    protected $TableClass = "";
    protected $IsPseudoTable = false;

    /**
     * Get formatted HTML tag attribute string.
     * @param string $Attrib Attribute name.
     * @param string $Value Attribute value.
     * @return string Formatted attribute string, or empty string if supplied
     *      value was empty.
     */
    protected static function getAttrib(string $Attrib, string $Value): string
    {
        return strlen($Value)
            ? " ".$Attrib."=\"".trim($Value)."\""
            : "";
    }

    /**
     * Generate HTML using <table> tags.
     * @return string Generated HTML.
     */
    protected function getTableHtml() : string
    {
        $Html = "<table".self::getAttrib("class", $this->TableClass).">\n";
        $Html .= $this->getSectionHtml("thead", $this->HeaderRowData);
        $Html .= $this->getSectionHtml("tbody", $this->RowData);
        $Html .= $this->getSectionHtml("tfoot", $this->FooterRowData);
        $Html .= "</table>\n";
        return $Html;
    }

    /**
     * Create and return HTML for a single section (<thead>, <tbody>, or
     * <tfoot>) of a table.
     * @param string $SectionTag The name of the table section
     *      - thead, tbody, or tfoot.
     * @param array $RowData The array of cell information for that section.
     * @return string The HTML for the section of a table.
     */
    private function getSectionHtml(string $SectionTag, array $RowData): string
    {
        $Html = "";
        if (count($RowData) > 0) {
            $Html .= ("<" . $SectionTag . ">");
            foreach ($RowData as $Cells) {
                $RowClass = $Cells[0]["Class"] ?? "";
                $Html .= "<tr" . self::getAttrib("class", $RowClass) . ">\n";
                foreach ($Cells as $Cell) {
                    $Tag = $Cell["IsHeader"] ? "th" : "td";
                    $Html .= "<" . $Tag . ">" . $Cell["Content"] . "</" . $Tag . ">\n";
                }
                $Html .= "</tr>\n";
            }
            $Html .= ("</" . $SectionTag . "> \n");
        }
        return $Html;
    }

    /**
     * Generate HTML using <div> tags.
     * @return string Generated HTML.
     */
    protected function getDivHtml() : string
    {
        $TableClass = self::PSEUDOTABLE_CLASS." ".$this->TableClass;
        $Html = "<div".self::getAttrib("class", $TableClass).">\n";
        foreach ($this->RowData as $Cells) {
            $RowClass = self::PSEUDOTABLE_ROW_CLASS." ".($Cells[0]["Class"] ?? "");
            $Html .= "<div".self::getAttrib("class", $RowClass).">\n";
            foreach ($Cells as $Cell) {
                $CellClass = $Cell["IsHeader"] ?
                    self::PSEUDOTABLE_HEADER_COL_CLASS :
                    self::PSEUDOTABLE_COL_CLASS;
                $Html .= "<div".self::getAttrib("class", $CellClass).">"
                        .$Cell["Content"]."</div>\n";
            }
            $Html .= "</div>\n";
        }
        $Html .= "</div>\n";
        return $Html;
    }

    # css classes to use for div-based pseudotables
    # (values here are for Bootstrap)
    private const PSEUDOTABLE_CLASS = "container-fluid";
    private const PSEUDOTABLE_ROW_CLASS = "row";
    private const PSEUDOTABLE_COL_CLASS = "col";
    private const PSEUDOTABLE_HEADER_COL_CLASS = "col fw-bold";

    /**
     * Process array of cell data and classes, arranging them into format
     * we save with metadata
     * @param array $Cells Array of content, with the first entry holding
     *       content for the row header cell.
     * @param ?string $Class Class for row.
     * @param bool $FirstHeader If the first cell should be a <th> header.
     * @param bool $AllHeaders If all the cells should be <th> headers.
     * @return array The data for a row in a table
     */
    private function prepareRowData(
        array $Cells,
        ?string $Class,
        bool $FirstHeader = false,
        bool $AllHeaders = false
    ): array {
        # check if first cell should be a header
        $IsHeader = $FirstHeader || $AllHeaders;
        $Data = [];
        foreach ($Cells as $Cell) {
            $Data[] = [
                "IsHeader" => $IsHeader,
                "Content" => $Cell,
                "Class" => $Class
            ];
            # check if subsequent cells should be headers
            $IsHeader = $AllHeaders;
        }
        return $Data;
    }
}
