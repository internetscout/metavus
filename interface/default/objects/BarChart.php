<?PHP
#
#   FILE:  BarChart.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;

/**
 * Class for generating and displaying a bar chart.
 * @see https://plotly.com/javascript/bar-charts/
 * @see https://plotly.com/javascript/reference/bar/
 */
class BarChart extends Chart
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get/set data for chart.
     * @param array $NewValue Data for chart. Keys are X values for each bar
     *         (either a category name, a unix timestamp, or any format that
     *         strtotime can parse). Values are ints for charts with only one
     *         set of bars or associative arrays where the keys give bar names
     *         and the values give bar heights for charts with multiple bars.
     *         (OPTIONAL)
     */
    public function data($NewValue = null): array
    {
        # normalize data to array of arrays format if necessary
        if (($NewValue !== null) && !is_array(reset($NewValue))) {
            $this->LegendPosition = static::LEGEND_NONE;

            $Data = [];
            foreach ($NewValue as $Name => $Val) {
                $Data[$Name] = [$Name => $Val];
            }
            $NewValue = $Data;
        }

        return parent::data($NewValue);
    }

    /**
     * Get/set the axis type of a bar chart (if this method is not called, the
     *         default is AXIS_CATEGORY).
     * @param string $NewValue Axis type as a BarChart::AXIS_
     *         constant. Allowed values are AXIS_CATEGORY for categorical
     *         charts or one of AXIS_TIME_{DAILY,WEEKLY,MONTHLY,YEARLY} for
     *         time series plotting (OPTIONAL).
     * @return mixed Current AxisType.
     * @throws Exception If an invalid axis type is supplied.
     */
    public function axisType($NewValue = null)
    {
        if (func_num_args() > 0) {
            $ValidTypes = [
                self::AXIS_CATEGORY,
                self::AXIS_TIME_DAILY,
                self::AXIS_TIME_WEEKLY,
                self::AXIS_TIME_MONTHLY,
                self::AXIS_TIME_YEARLY,
            ];

            # toss exception if the given type is not valid
            if (!in_array($NewValue, $ValidTypes)) {
                throw new Exception("Invalid axis type for bar charts: ".$NewValue);
            }

            $this->AxisType = $NewValue;
        }

        return $this->AxisType;
    }

    /**
     * Get/set the Y axis label for a bar chart.
     * @param string $NewValue Label to use.
     * @return string Current YLabel.
     */
    public function yLabel(?string $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->YLabel = $NewValue;
        }
        return $this->YLabel;
    }

    /**
     * Get/set bar width as a percentage of the distance between ticks.
     * @param int|null $NewValue Updated bar width or NULL to use the C3 default.
     * @return int|null Current Barwidth setting.
     * @throws Exception If an invalid bar width is supplied.
     */
    public function barWidth($NewValue = null)
    {
        if (func_num_args() > 0) {
            if (!is_null($NewValue) && $NewValue <= 0 || $NewValue > 100) {
                throw new Exception("Invalid bar width: ".$NewValue);
            }
            $this->BarWidth = $NewValue;
        }

        return $this->BarWidth;
    }

    /**
     * Enable/Disable zooming for this chart.
     * @param bool $NewValue TRUE to enable zooming, or FALSE to disable.
     * @return bool Current Zoom setting.
     */
    public function zoom(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Zoom = $NewValue;
        }
        return $this->Zoom;
    }

    /**
     * Enable/Disable automatic scaling after zooming for this chart.
     * @param bool $NewValue TRUE to enable autoscale, or FALSE to disable.
     * @return bool Current Autoscale setting.
     */
    public function autoscale(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Autoscale = $NewValue;
        }
        return $this->Autoscale;
    }

    /**
     * Enable/Disable the subchart (a small chart below the main chart showing
     *         the full range of the x-axis that displays the currently zoomed
     *         portion of the data; disabled by default when this method has
     *         not been called).
     * @param bool $NewValue TRUE to enable subchart, or FALSE to disable.
     * @return bool Current Subchart setting.
     */
    public function showSubchart(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Subchart = $NewValue;
        }
        return $this->Subchart;
    }

    /**
     * Get/Set bar stacking setting.
     * @param bool $NewValue TRUE to generate a stacked chart.
     * @return bool Current stacking setting.
     */
    public function stacked(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Stacked = $NewValue;
        }
        return $this->Stacked;
    }

    /**
     * Get/Set horizontal display setting
     * @param bool $NewValue TRUE to generate a horizontal bar chart.
     * @return bool Current horizontal setting.
     * @throws Exception If an invalid value is supplied.
     */
    public function horizontal(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Horizontal = $NewValue;
        }
        return $this->Horizontal;
    }

    /**
     * Enable/disable display of grid lines.
     * @param bool $NewValue TRUE to show grid lines.
     * @return bool Current grid line sitting.
     */
    public function showGridlines(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Gridlines = $NewValue;
        }
        return $this->Gridlines;
    }

    /**
     * Enable/disable display of category labels along the X axis on
     *         categorical charts (by default, they are shown).
     * @param bool $NewValue TRUE to show category labels.
     * @return bool Current category labels setting.
     */
    public function showCategoryLabels(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->ShowCategoryLabels = $NewValue;
        }
        return $this->ShowCategoryLabels;
    }

    # axis types
    const AXIS_CATEGORY = "category";
    const AXIS_TIME_DAILY = "daily";
    const AXIS_TIME_WEEKLY = "weekly";
    const AXIS_TIME_MONTHLY = "monthly";
    const AXIS_TIME_YEARLY = "yearly";


    # ---- PRIVATE INTERFACE --------------------------------------------------

    /**
     * Get data in the format required by Plotly for the 'data' argument to
     *         Plotly.newPlot().
     * @see https://plotly.com/javascript/plotlyjs-function-reference/#plotlynewplot
     * @see https://plotly.com/javascript/bar-charts/
     * @see https://plotly.com/javascript/reference/bar/
     */
    protected function getChartData(): array
    {
        if ($this->AxisType == self::AXIS_CATEGORY) {
            $Data = $this->Data;
        } else {
            $Data = [];
            foreach ($this->sortDataIntoBins() as $TS => $Values) {
                $Index = date("Y-m-d", $TS);
                $Data[$Index] = $Values;
            }
        }

        $DataSets = [];
        foreach ($Data as $Index => $Values) {
            $Label = $this->Labels[$Index] ?? $Index;
            $Legend = $this->LegendLabels[$Index] ?? $Label;

            $DataSetIndex = 0;
            foreach ($Values as $VIndex => $Value) {
                if ($this->AxisType != self::AXIS_CATEGORY &&
                    !isset($DataSets[$DataSetIndex])) {
                    $DataSets[$DataSetIndex]["name"] = $this->Labels[$VIndex] ?? $VIndex;
                    if (isset($this->Colors[$VIndex])) {
                        $DataSets[$DataSetIndex]["marker"]["color"] = $this->Colors[$VIndex];
                    }
                }

                $DataSets[$DataSetIndex]["x"][] = $Legend;
                $DataSets[$DataSetIndex]["y"][] = $Value;
                if ($this->AxisType == self::AXIS_CATEGORY) {
                    $Color = $this->Colors[$Index] ?? $this->generateRgbColorString($Index);
                    $DataSets[$DataSetIndex]["text"][] = $Label;
                    $DataSets[$DataSetIndex]["marker"]["color"][] = $Color;
                }
                $DataSetIndex++;
            }
        }

        foreach (array_keys($DataSets) as $Index) {
            $DataSets[$Index]["type"] = "bar";
            $DataSets[$Index]["textposition"] = "none";
            if ($this->AxisType == self::AXIS_CATEGORY) {
                $DataSets[$Index]["hovertemplate"] = "%{text} | %{value}"
                    ."<extra></extra>";
            }

            if ($this->BarWidth !== null) {
                $DataSets[$Index]["width"] = $this->BarWidth;
            }
        }

        return $DataSets;
    }

    /**
     * Get chart layout information in the format required for the 'layout'
     *         argument to Plotly.newPlot().
     * @see https://plotly.com/javascript/plotlyjs-function-reference/#plotlynewplot
     * @see https://plotly.com/javascript/reference/layout/
     * @see Chart::getChartLayout()
     */
    protected function getChartLayout(): array
    {
        $Layout = parent::getChartLayout();

        $Layout["margin"]["t"] = 0;
        $Layout["margin"]["r"] = 0;
        $Layout["margin"]["b"] = 40;
        $Layout["margin"]["l"] = $this->yLabel() === null ? 20 : 60;

        if ($this->showSubchart()) {
            $Layout["xaxis"]["rangeslider"]["thickness"] = 0.05;
            $Layout["xaxis"]["rangeslider"]["visible"] = true;
        }

        if ($this->zoom()) {
            $Layout["yaxis"]["fixedrange"] = false;
        }

        if ($this->yLabel() !== null) {
            $Layout["yaxis"]["title"]["text"] = $this->yLabel();
        }

        if ($this->showGridlines()) {
            $Layout["xaxis"]["showgrid"] = true;
            $Layout["yaxis"]["showgrid"] = true;
        }

        if (!$this->showCategoryLabels()) {
            $Layout["xaxis"]["showticklabels"] = false;
        }

        return $Layout;
    }

    /**
     * Get chart configuration information in the format required for the
     *         'config' argument to Plotly.newPlot().
     * @see https://plotly.com/javascript/plotlyjs-function-reference/#plotlynewplot
     * @see https://plotly.com/javascript/configuration-options/
     * @see Chart::getChartConfig()
     */
    protected function getChartConfig(): array
    {
        $Config = parent::getChartConfig();

        if ($this->stacked()) {
            $Config["barmode"] = "stack";
        }

        return $Config;
    }

    /**
     * Get Javascript that should be printed after the chart, without an
     *         enclosing <script> tag.
     * @return string Javascript to print.
     */
    protected function getPostChartJS(): string
    {
        if (!$this->autoscale()) {
            return "";
        }

        return "Plot.then(function(Plot) {"
            ."Plot.on('plotly_relayout', function(Event) {"
            ."Chart.autoscaleChart(Plot, Event);"
            ."});"
            ."});";
    }

    /**
     * Sort the user-provided data into bins with sizes given by
     *         $this->AxisType.
     * @return array Binned data.
     */
    protected function sortDataIntoBins()
    {
        # create an array to store the binned data
        $BinnedData = [];

        # iterate over all our input data.
        foreach ($this->Data as $TS => $Entries) {
            # place this timestamp in the appropriate bin
            $TS = $this->binTimestamp($TS);

            # if we have no results in this bin, then these are
            # the first
            if (!isset($BinnedData[$TS])) {
                $BinnedData[$TS] = $Entries;
            } else {
                # otherwise, iterate over the keys we were given
                foreach ($Entries as $Key => $Val) {
                    # if we have a value for this key
                    if (isset($BinnedData[$TS][$Key])) {
                        # then add this new value to it
                        $BinnedData[$TS][$Key] += $Val;
                    } else {
                        # otherwise, insert the new value
                        $BinnedData[$TS][$Key] = $Val;
                    }
                }
            }
        }

        ksort($BinnedData);
        reset($BinnedData);

        return $BinnedData;
    }

    /**
     * Determine which bin a specified timestamp belongs in.
     * @param mixed $TS Input timestamp.
     * @return int UNIX timestamp for the left edge of the bin.
    */
    protected function binTimestamp($TS)
    {
        if (!preg_match("/^[0-9]+$/", $TS)) {
            $TS = strtotime($TS);
        }

        switch ($this->AxisType) {
            case self::AXIS_TIME_DAILY:
                $Time = strtotime(date("Y-m-d 00:00:00", $TS));
                break;

            case self::AXIS_TIME_WEEKLY:
                $DateInfo = @strptime(
                    date("Y-m-d 00:00:00", $TS),
                    "%Y-%m-%d %H:%M:%S"
                );

                if ($DateInfo === false) {
                    throw new Exception(
                        "strptime() failed - should be impossible."
                    );
                }

                $Year = $DateInfo["tm_year"] + 1900;
                $Month = $DateInfo["tm_mon"] + 1;
                $Day = $DateInfo["tm_mday"] - $DateInfo["tm_wday"];

                $Time = mktime(0, 0, 0, $Month, $Day, $Year);
                break;

            case self::AXIS_TIME_MONTHLY:
                $Time = strtotime(date("Y-m-01 00:00:00", $TS));
                break;

            case self::AXIS_TIME_YEARLY:
                $Time = strtotime(date("Y-01-01 00:00:00", $TS));
                break;

            default:
                throw new Exception("Unknown axis type (".$this->AxisType.").");
        }

        if ($Time === false) {
            throw new Exception("strtotime() conversion failed.");
        }
        return $Time;
    }

    /**
     * Get the next bin.
     * @param int $BinTS UNIX timestamp for the left edge of the current bin.
     * @return int UNIX timestamp for the left edge of the next bin.
     */
    protected function nextBin($BinTS)
    {
        $ThisBin = strftime("%Y-%m-%d %H:%M:%S", $BinTS);
        $Units = [
            self::AXIS_TIME_DAILY => "day",
            self::AXIS_TIME_WEEKLY => "week",
            self::AXIS_TIME_MONTHLY => "month",
            self::AXIS_TIME_YEARLY => "year",
        ];

        $Time = strtotime($ThisBin." + 1 ".$Units[$this->AxisType]);
        if ($Time === false) {
            throw new Exception("strtotime() conversion failed.");
        }
        return $Time;
    }


    protected $AxisType = self::AXIS_CATEGORY;
    protected $YLabel = null;
    protected $Zoom = false;
    protected $Autoscale = false;
    protected $Subchart = false;
    protected $Stacked = false;
    protected $Horizontal = false;
    protected $Gridlines = true;
    protected $ShowCategoryLabels = true;
    protected $BarWidth = null;
}
