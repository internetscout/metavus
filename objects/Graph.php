<?PHP
#
#   FILE:  Graph.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;

class Graph
{
    const TYPE_DATE = 1;
    const TYPE_DATE_BAR = 2;

    const OK = 1;
    const NO_DATA = 2;
    const ERROR_UNKNOWN_TYPE = 3;

    const DAILY = 0;
    const WEEKLY = 1;
    const MONTHLY = 2;

    /**
     * Object constructor, used to create a new Graph.
     * @param int $GraphType Graph::TYPE_* giving the graph type.
     * @param array $GraphData Array giving the data to be plotted.  Keys
     *  give x-coordinates, and values give an array of y-coordinates.
     */
    public function __construct(int $GraphType, array $GraphData)
    {
        if (count($GraphData) == 0) {
            $this->Status = self::NO_DATA;
        } elseif ($GraphType == self::TYPE_DATE ||
            $GraphType == self::TYPE_DATE_BAR ) {
            $this->Status = self::OK;
            # Convert input data into a form that Javascript will deal with

            if ($GraphType == self::TYPE_DATE) {
                $this->Data = $this->toJsFormat($GraphData);
            } else {
                # Summarize the search data passed in into daily, weekly, monthly:
                $DailyData = [];
                $WeeklyData = [];
                $MonthlyData = [];

                foreach ($GraphData as $Xval => $Yvals) {
                    $DailyTS = strtotime(date('Y-m-d', $Xval));
                    if ($DailyTS === false) {
                        throw new Exception("String timestamp conversion failed for daily ".
                        "timestamp. Timestamp value: ".$Xval);
                    }
                    # Find the Monday preceeding this day for the weekly TS:
                    $WeeklyTS = $DailyTS - 86400 * (int)(date('N', $Xval) - 1);
                    $MonthlyTS = strtotime(date('Y-m-01', $Xval));
                    if ($MonthlyTS === false) {
                        throw new Exception("String timestamp conversion failed for monthly "
                        ."timestamp. Timestamp value: ".$Xval);
                    }
                    $this->addToArray($DailyData, $DailyTS, $Yvals);
                    $this->addToArray($WeeklyData, $WeeklyTS, $Yvals);
                    $this->addToArray($MonthlyData, $MonthlyTS, $Yvals);
                }

                $this->Data = [
                    "Daily"  => $this->toJsFormat($DailyData),
                    "Weekly" => $this->toJsFormat($WeeklyData),
                    "Monthly" => $this->toJsFormat($MonthlyData)
                ];
            }

            $this->Type = $GraphType;

            $this->Width = 960;
            $this->Height = 500;

            $this->LabelChars = strlen(max(array_map('max', $GraphData))) ;

            $this->TopMargin = 5;
            $this->LeftMargin = 5;
            $this->RightMargin = 50;
            $this->BottomMargin = 40;

            $this->LabelFontSize = 14;

            $this->Legend = [];

            $this->Scale = self::DAILY;
            $this->Title = "";
        } else {
            $this->Status = self::ERROR_UNKNOWN_TYPE;
        }
    }

    /**
     * Determine if graph creation succeeded.
     * @return int Graph::OK on success, other on failure.
     */
    public function status(): int
    {
        return $this->Status;
    }

    /**
     * Set X axis label.
     * @param string $Label X axis label value.
     */
    public function xLabel(string $Label)
    {
        $this->XLabel = $Label;
    }

    /**
     * Set Y axis label.
     * @param string $Label Y axis label value.
     */
    public function yLabel(string $Label)
    {
        $this->YLabel = $Label;
    }

    /**
     * Set label font size.
     * @param int $Size Font size in points.
     */
    public function labelFontSize(int $Size)
    {
        $this->LabelFontSize = $Size;
    }

    /**
     * Set top margin.
     * @param int $Margin Top margin in pixels.
     */
    public function topMargin(int $Margin)
    {
        $this->TopMargin = $Margin;
    }

    /**
     * Set right side margin.
     * @param int $Margin Right margin in pixels.
     */
    public function rightMargin(int $Margin)
    {
        $this->RightMargin = $Margin;
    }

    /**
     * Set bottom margin.
     * @param int $Margin New bottom margin in pixels.
     */
    public function bottomMargin(int $Margin)
    {
        $this->BottomMargin = $Margin;
    }

    /**
     * Set left margin.
     * @param int $Margin New left margin in pixels.
     */
    public function leftMargin(int $Margin)
    {
        $this->LeftMargin = $Margin;
    }

    /**
     * Set graph width.
     * @param int $Width Width in pixels.
     */
    public function width(int $Width)
    {
        $this->Width = $Width;
    }

    /**
     * Set graph height.
     * @param int $Height Height in pixels.
     */
    public function height(int $Height)
    {
        $this->Height = $Height;
    }

    /**
     * Set graph legend.
     * @param array $Legend Updated legend text as an array of strings, with
     *   each element giving the name of one data set.
     */
    public function legend(array $Legend)
    {
        $this->Legend = $Legend;
    }

    /**
     * Determine default granularity for bar charts.
     * @param int $Scale Scale one of Graph::DAILY, Graph::WEEKLY, or Graph::MONTHLY
     */
    public function scale(int $Scale)
    {
        $this->Scale = $Scale;
    }

    /**
     * Set graph title.
     * @param string $Title HTML fragment giving the title
     *       element (e.g., <h3>Shiny graph</h3).
     */
    public function title(string $Title)
    {
        $this->Title = $Title;
    }

    /**
     * Generate HTML/CSS/Javascript to display a graph.
     */
    public function display()
    {
        if ($this->Status == self::NO_DATA) {
            print $this->Title;
            print "<p><em>No data to display</em></p>";
            return;
        }

        if ($this->Status != self::OK) {
            return;
        }

        $ChartNumber = self::getNumber();

        global $AF;
        // @codingStandardsIgnoreStart
        ?>

        <?PHP if($ChartNumber==0) { ?>
        <script type="text/javascript" src="<?PHP $AF->PUIFile("d3.js"); ?>"></script>
        <script type="text/javascript" src="<?PHP $AF->PUIFile("CW-Graph.js"); ?>"></script>
        <style>
        .cw-chart { width: 100%; }
            .cw-chart-button { padding: 5px; margin-bottom: 15px; cursor: pointer; }
        svg { font: 12px sans-serif;}
        .axis { shape-rendering: crispEdges; }

        .axis path, .axis line {
            fill: none;
            stroke-width: .5px;
        }

        .x.axis path { stroke: #000; }
        .x.axis line { stroke: #fff; stroke-opacity: .5; }
        .y.axis line { stroke: #ddd; }

        path.line {
            fill: none;
            stroke-width: 1.5px;
        }

        rect.pane {
            cursor: move;
            fill: none;
            pointer-events: all;
        }

        .focus circle {
          fill: none;
          stroke: steelblue;
        }

        .focus { fill: black; }

        .line.graph_color0 { stroke: #4D7588; }
        .line.graph_color1 { stroke: ##975078; }

        .area.graph_color0 { fill: #4D7588; }
        .area.graph_color1 { fill: #975078; }
        .area.graph_color2 { fill: #818181; }
        .area.graph_color3 { fill: #5CAA5C; }
        .area.graph_color4 { fill: #DDBC6D; }

        </style>
        <?PHP } ?>

        <style>
          <?PHP if ($this->Type == self::TYPE_DATE) { ?>
          .x-label<?= $ChartNumber; ?> { font-weight: bold; font-size: <?= $this->LabelFontSize; ?>px; }
          .y-label<?= $ChartNumber; ?> { font-weight: bold; font-size: <?= $this->LabelFontSize; ?>px; }
          <?PHP } elseif ($this->Type == self::TYPE_DATE_BAR) { ?>
          .x-label<?= $ChartNumber; ?>a { font-weight: bold; font-size: <?= $this->LabelFontSize; ?>px; }
          .y-label<?=  $ChartNumber; ?>a { font-weight: bold; font-size: <?= $this->LabelFontSize; ?>px; }

          .x-label<?= $ChartNumber; ?>b { font-weight: bold; font-size: <?= $this->LabelFontSize; ?>px; }
          .y-label<?= $ChartNumber; ?>b { font-weight: bold; font-size: <?= $this->LabelFontSize; ?>px; }

          .x-label<?= $ChartNumber; ?>c { font-weight: bold; font-size: <?= $this->LabelFontSize; ?>px; }
          .y-label<?= $ChartNumber; ?>c { font-weight: bold; font-size: <?= $this->LabelFontSize; ?>px; }
          <?PHP } ?>
        </style>

        <?PHP if ($this->Type == self::TYPE_DATE) { ?>
          <?= $this->Title; ?>
          <div class="cw-chart" id="chart<?= $ChartNumber; ?>"></div>
        <?PHP } elseif ($this->Type == self::TYPE_DATE_BAR) { ?>
          <div style="max-width: <?= $this->Width; ?>px;">

          <div>
            <span style="float: right; margin-top: 3px; padding-right: <?= $this->LabelChars + 1 ; ?>em; margin-right: <?= $this->RightMargin; ?>px; ">
                <span class="cw-chart-button" id="cw-chart-button<?= $ChartNumber; ?>a"
                  <?PHP if ($this->Scale == self::DAILY) { ?> style="font-weight: bold;" <?PHP } ?>
                        >Daily</span>|
                <span class="cw-chart-button" id="cw-chart-button<?= $ChartNumber; ?>b"
                  <?PHP if ($this->Scale == self::WEEKLY) { ?> style="font-weight: bold;" <?PHP } ?>
                        >Weekly</span>|
                <span class="cw-chart-button" id="cw-chart-button<?= $ChartNumber; ?>c"
                   <?PHP if ($this->Scale == self::MONTHLY) { ?> style="font-weight: bold;" <?PHP } ?>
                        >Monthly</span>
            </span>
            <?= $this->Title; ?>
          </div>

          <div id="chart<?= $ChartNumber; ?>">
            <div class="cw-chart" id="chart<?= $ChartNumber; ?>a"
              <?PHP if ($this->Scale != self::DAILY) { ?> style="display: none;" <?PHP } ?> ></div>
            <div class="cw-chart" id="chart<?= $ChartNumber; ?>b"
              <?PHP if ($this->Scale != self::WEEKLY) { ?> style="display: none;" <?PHP } ?> ></div>
            <div class="cw-chart" id="chart<?= $ChartNumber; ?>c"
              <?PHP if ($this->Scale != self::MONTHLY) { ?> style="display: none;" <?PHP } ?> ></div>
          </div>
          </div>
          <script type="text/javascript">
            jQuery('#cw-chart-button<?= $ChartNumber; ?>a').click( function(){
              jQuery('#cw-chart-button<?= $ChartNumber; ?>a').css('font-weight', 'bold');
              jQuery('#cw-chart-button<?= $ChartNumber; ?>b').css('font-weight', 'normal');
              jQuery('#cw-chart-button<?= $ChartNumber; ?>c').css('font-weight', 'normal');
              jQuery('#chart<?= $ChartNumber; ?>a').show();
              jQuery('#chart<?= $ChartNumber; ?>b').hide();
              jQuery('#chart<?= $ChartNumber; ?>c').hide();
              });
            jQuery('#cw-chart-button<?= $ChartNumber; ?>b').click( function(){
              jQuery('#cw-chart-button<?= $ChartNumber; ?>a').css('font-weight', 'normal');
              jQuery('#cw-chart-button<?= $ChartNumber; ?>b').css('font-weight', 'bold');
              jQuery('#cw-chart-button<?= $ChartNumber; ?>c').css('font-weight', 'normal');
              jQuery('#chart<?= $ChartNumber; ?>a').hide();
              jQuery('#chart<?= $ChartNumber; ?>b').show();
              jQuery('#chart<?= $ChartNumber; ?>c').hide();
              });
            jQuery('#cw-chart-button<?= $ChartNumber; ?>c').click( function(){
              jQuery('#cw-chart-button<?= $ChartNumber; ?>a').css('font-weight', 'normal');
              jQuery('#cw-chart-button<?= $ChartNumber; ?>b').css('font-weight', 'normal');
              jQuery('#cw-chart-button<?= $ChartNumber; ?>c').css('font-weight', 'bold');
              jQuery('#chart<?= $ChartNumber; ?>a').hide();
              jQuery('#chart<?= $ChartNumber; ?>b').hide();
              jQuery('#chart<?= $ChartNumber; ?>c').show();
              });
         </script>
        <?PHP } ?>

        <script type="text/javascript">
              <?PHP if ($this->Type == self::TYPE_DATE ) { ?>
              jQuery(document).ready(function(){
                new LineDateGraph(<?= $ChartNumber; ?>,
                          <?= json_encode($this->Data); ?>,
                          "<?= $this->XLabel; ?>",
                          "<?= $this->YLabel; ?>",
                          {top: <?= $this->TopMargin; ?>, right: <?= $this->RightMargin; ?>,
                           bottom: <?= $this->BottomMargin; ?>, left: <?= $this->LeftMargin; ?> },
                                  <?= $this->Width; ?>, <?= $this->Height; ?>, <?= json_encode($this->Legend); ?> ); });
              <?PHP } else if ($this->Type == self::TYPE_DATE_BAR) { ?>
              jQuery(document).ready(function(){
                new BarDateGraph(
                          "<?= $ChartNumber; ?>a",
                          <?= json_encode($this->Data["Daily"]); ?>,
                          "<?= $this->XLabel; ?>",
                          "<?= $this->YLabel; ?> (Daily)",
                          {top: <?= $this->TopMargin; ?>, right: <?= $this->RightMargin; ?>,
                           bottom: <?= $this->BottomMargin; ?>, left: <?=$this->LeftMargin; ?> },
                          <?= $this->Width; ?>, <?= $this->Height; ?>, <?= json_encode($this->Legend); ?>,
                          <?= (22*3600); ?>); });
             jQuery(document).ready(function(){
                    new BarDateGraph(
                          "<?= $ChartNumber; ?>b",
                          <?= json_encode($this->Data["Weekly"]); ?>,
                          "<?= $this->XLabel; ?>",
                          "<?= $this->YLabel; ?> (Weekly)",
                          {top: <?= $this->TopMargin; ?>, right: <?= $this->RightMargin; ?>,
                           bottom: <?= $this->BottomMargin; ?>, left: <?= $this->LeftMargin; ?> },
                          <?= $this->Width; ?>, <?= $this->Height; ?>, <?= json_encode($this->Legend); ?>,
                          <?= (7*86400); ?>); });
            jQuery(document).ready(function(){
                     new BarDateGraph(
                          "<?= $ChartNumber; ?>c",
                          <?= json_encode($this->Data["Monthly"]); ?>,
                          "<?= $this->XLabel; ?>",
                          "<?= $this->YLabel; ?> (Monthly)",
                          {top: <?= $this->TopMargin; ?>, right: <?= $this->RightMargin; ?>,
                           bottom: <?= $this->BottomMargin; ?>, left: <?= $this->LeftMargin; ?> },
                          <?= $this->Width; ?>, <?= $this->Height; ?>, <?= json_encode($this->Legend); ?>,
                         <?= (28*86400); ?>); });
              <?PHP } ?>
        </script>
        <?PHP
        // @codingStandardsIgnoreStart
    }

    private $Status;
    private static $ChartNumber = 0;

    protected $Data;
    protected $Type;
    protected $Height;
    protected $Width;
    protected $LabelChars;
    protected $TopMargin;
    protected $LeftMargin;
    protected $BottomMargin;
    protected $RightMargin;
    protected $Legend;
    protected $Scale;
    protected $XLabel;
    protected $YLabel;
    protected $LabelFontSize;
    protected $Title;

    /**
     */
    private function getNumber(): int
    {
        return self::$ChartNumber++;
    }

    /**
     * Helper function for summarizing data.
     * @param array $Array The array into which data will be aggregated.
     * @param int $Key The index where this data should go (usually a timestamp)
     * @param array $Value Array of new values.
     * If no entry exists in $Array for $Key, create one.  If an entry does exist,
     * perform element-wise addition to update that entry.
     */
    private function addToArray(&$Array, int $Key, array $Value)
    {
        if (!isset($Array[$Key])) {
            $Array[$Key] = $Value;
        }
        else {
            $Array[$Key] = array_map(function($a,$b) { return $a+$b; }, $Array[$Key], $Value);
        }
    }

    /**
     * Helper function to convert the Graph data format to something
     * that is easier to iterate over in JS.
     * @param array $Data Data to convert, in Key => [Values] format.
     * @param bool $IsDate Optional, defaults to true.
     * @return array Data in [ ["X" => Key, "Y0"=> Value, ... "Yn"=>Value],
     *       ... ] format.
     */
    private function toJsFormat(array $Data, bool $IsDate=TRUE): array
    {
        $Result = [];
        foreach ($Data as $Xval => $Yvals) {
            $DataRow = [];

            if ($IsDate) {
                $DataRow["X"] = 1000 * $Xval;
            } else {
                $DataRow["X"] = $Xval;
            }

            $Count = 0;
            foreach ($Yvals as $Yval)
            {
                $DataRow["Y".$Count++] = $Yval;
            }

            $Result[] = $DataRow;
        }

        return $Result;
    }
}
