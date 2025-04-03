<?PHP
#
#   FILE:  BarChart.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;
use Exception;

/**
 * Class for generating and displaying a bar chart.
 */
class BarChart extends Chart_Base
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get/set data for chart.
     * @param array $NewValue Data for chart. Keys are X values for each
     *   bar (either a category name, a unix timestamp, or any format
     *   that strtotime can parse). Values are ints for charts with
     *   only one set of bars or associative arrays where the keys give
     *   bar names and the values give bar heights for charts with
     *   multiple bars.  (OPTIONAL)
     */
    public function data($NewValue = null): array
    {
        # normalize data to array of arrays format if necessary
        if (($NewValue !== null) && !is_array(reset($NewValue))) {
            $this->SingleCategory = true;
            $this->Stacked = true;
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
     * Get/set the axis type of a bar chart (default is AXIS_CATEGORY).
     * @param string $NewValue Axis type as a BarChart::AXIS_
     *   constant. Allowed values are AXIS_CATEGORY for categorical
     *   charts or one of AXIS_TIME_{DAILY,WEEKLY,MONTHLY,YEARLY} for
     *   time series plotting (OPTIONAL).
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
     * Set the smallest maximum Y-axis value to be used for autoscaling.
     * @param float|bool $NewValue New value for AutoscaleMin or FALSE
     * to clear any current value.
     * @return mixed Current setting.
     */
    public function autoscaleMin($NewValue = null)
    {
        if ($NewValue !== null) {
            $this->AutoscaleMin = $NewValue;
        }
        return $this->AutoscaleMin;
    }

    /**
     * Enable/Disable the subchart (a small chart below the main chart
     *   showing the full range of the x-axis that displays the currently
     *   zoomed portion of the data).
     * @param bool $NewValue TRUE to enable subchart, or FALSE to disable.
     * @return bool Current Subchart setting.
     * @see https://c3js.org/samples/options_subchart.html
     */
    public function subchart(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Subchart = $NewValue;
        }
        return $this->Subchart;
    }

    /**
     * Enable/Disable gapless time axes for this chart, which will fill
     *   in zeros for any missing time intervals.
     * @param bool $NewValue TRUE to enable gapless display, or FALSE to disable.
     * @return bool Current Gapless setting.
    */
    public function gapless(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Gapless = $NewValue;
        }
        return $this->Gapless;
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
    public function gridlines(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->Gridlines = $NewValue;
        }
        return $this->Gridlines;
    }

    /**
     * Enable/disable display of category labels along the X axis on
     * categorical charts (by default, they are shown).
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

    /**
     * Output chart HTML.
     * @param string $ContainerId HTML Id for the chart container.
     */
    public function display(string $ContainerId): void
    {
        if ($this->Autoscale && $this->AutoscaleMin !== false) {
            ob_start();
            ?>
            function zoom_rescale_fn(domain) {
                var max_y = <?= $this->AutoscaleMin ?>;
                $.each(chart.data.shown(), function(set_ix, set_values){
                    $.each(set_values.values, function(v_ix, v_val) {
                        if (domain[0] <= v_val.x && v_val.x <= domain[1] &&
                            v_val.value > max_y) {
                            max_y = v_val.value;
                        }
                    });
                });

                chart.axis.max({y: max_y});
            }
            <?PHP
            $this->HelperFunctionJSCode = ob_get_contents();
            ob_end_clean();

            $this->PostChartInitJSCode = 'zoom_rescale_fn(chart.zoom());' ;
        } else {
            $this->HelperFunctionJSCode = "";
            $this->PostChartInitJSCode = "";
        }

        parent::display($ContainerId);
    }


    /**
     * Compute an autoscale threshold that should cover all non-outlier
     * data. Here, outliers are defined as any values more than 3 standard
     * deviations away from the mean.
     * @param array $Data Input data.
     * @return float Autoscale threshold.
     */
    public static function computeAutoscaleThreshold($Data)
    {
        # if we have no data, autoscale to 10
        if (count($Data) == 0) {
            return 10;
        }

        # count repeated values once each
        $Data = array_unique($Data);

        # iterate over the data, removing outliers
        do {
            # start off assuming no outliers
            $Done = true;

            # compute the average of the data
            $Mean = array_sum($Data) / count($Data);

            # compute the sample variance
            $Variance = array_sum(
                array_map(
                    function ($x_i) use ($Mean) {
                        return pow($x_i - $Mean, 2);
                    },
                    $Data
                )
            );

            # compute the standard deviation
            $StDev = sqrt($Variance / count($Data));

            # build up an array of data that are less than 3 sigma
            # away from the mean
            $Tmp = [];
            foreach ($Data as $x_i) {
                $Dist = abs($x_i - $Mean);
                if ($Dist <= 3 * $StDev) {
                    $Tmp[] = $x_i;
                } else {
                    $Done = false;
                }
            }

            $Data = $Tmp;
        } while (!$Done);

        return max($Data);
    }

    # axis types
    const AXIS_CATEGORY = "category";
    const AXIS_TIME_DAILY = "daily";
    const AXIS_TIME_WEEKLY = "weekly";
    const AXIS_TIME_MONTHLY = "monthly";
    const AXIS_TIME_YEARLY = "yearly";


    # ---- PRIVATE INTERFACE --------------------------------------------------

    /**
     * Prepare data for plotting.
     * @see ChartBase::prepareData().
     */
    protected function prepareData(): void
    {
        # input data is
        #  [ CatNameOrTimestamp => [Data1 => Val, Data2 => Val, ...], ... ]
        #
        # and the format that C3 expects is
        # [ "Data1", Val, Val, ... ]
        # [ "Data2", Val, Val, ... ]

        # extract the names of all the bars
        $BarNames = [];
        foreach ($this->Data as $Entries) {
            foreach ($Entries as $BarName => $YVal) {
                $BarNames[$BarName] = 1;
            }
        }
        $BarNames = array_keys($BarNames);

        # start the chart off with no data
        $this->Chart["data"]["columns"] = [];

        if ($this->AxisType == self::AXIS_CATEGORY) {
            # for categorical plots, data stays in place
            $Data = $this->Data;

            # set up X labels
            if ($this->ShowCategoryLabels) {
                $this->Chart["axis"]["x"]["categories"] =
                    $this->shortCategoryNames($BarNames);
            } else {
                $this->Chart["axis"]["x"]["categories"] =
                    array_fill(0, count($BarNames), "");
            }

            # and fix up the display for single-category charts
            if ($this->SingleCategory) {
                $this->Chart["tooltip"]["grouped"] = false;
            }
        } else {
            # for time series data, we need to sort our data into bins
            $Data = $this->sortDataIntoBins($BarNames);

            # convert our timestamps to JS-friendly date strings
            $Timestamps = array_keys($Data);
            array_walk($Timestamps, function (&$Val, $Key) {
                $Val = date("Y-m-d", $Val);
            });
            array_unshift($Timestamps, "x-timestamp-x");

            # add this in to our data columns
            $this->Chart["data"]["columns"][] = $Timestamps;
        }

        # generate one row of data per bar to use for plotting

        # see http://c3js.org/reference.html#data-columns for format of 'columns' element.
        # since C3 always uses the label in 'columns' for the legend,
        # we'll need to populate the TooltipLabels array that is keyed
        # by legend label where values give the tooltip label
        foreach ($BarNames as $BarName) {
            $Label = isset($this->Labels[$BarName]) ?
                $this->Labels[$BarName] : $BarName ;

            if (isset($this->LegendLabels[$BarName])) {
                $MyLabel = $this->LegendLabels[$BarName];
                $this->TooltipLabels[$MyLabel] = $Label;
            } else {
                $MyLabel = $Label;
            }

            $DataRow = [$MyLabel];
            foreach ($Data as $Entries) {
                $DataRow[] = isset($Entries[$BarName]) ? $Entries[$BarName] : 0;
            }
            $this->Chart["data"]["columns"][] = $DataRow;
        }

        $this->Chart["data"]["type"] = "bar";

        if ($this->AxisType == self::AXIS_CATEGORY) {
            $this->Chart["axis"]["x"]["type"] = "category";
        } else {
            $FormatStrings = [
                self::AXIS_TIME_DAILY => "%b %e %Y",
                self::AXIS_TIME_WEEKLY => "%b %e %Y",
                self::AXIS_TIME_MONTHLY => "%b %Y",
                self::AXIS_TIME_YEARLY => "%Y",
            ];

            $this->addToChart([
                "data" => [
                    "x" => "x-timestamp-x",
                    "xFormat" => "%Y-%m-%d",
                ],
                "axis" => [
                    "x" => [
                        "type" => "timeseries",
                        "tick" => [
                            "format" => $FormatStrings[$this->AxisType],
                            "rotate" => 45
                        ],
                    ],
                ],
            ]);

            # if the leftmost event is more than a year ago
            $YearAgo = strtotime("-1 year");
            if (count($Timestamps) > 1 &&
                strtotime($Timestamps[1]) < $YearAgo) {
                # zoom to show the last year by default
                $this->Chart["axis"]["x"]["extent"] = [
                    strftime("%Y-%m-%d", $YearAgo),
                    end($Timestamps)
                ];

                $TickIntervals = [
                    self::AXIS_TIME_DAILY => "1 month",
                    self::AXIS_TIME_WEEKLY => "1 months",
                    self::AXIS_TIME_MONTHLY => "3 months",
                    self::AXIS_TIME_YEARLY => "1 year",
                ];

                $TickLocations = [];
                $EndTS = strtotime(end($Timestamps));
                $CurTS = strtotime(strftime("%Y-01-01", strtotime($Timestamps[1])));
                while ($CurTS <= $EndTS) {
                    $TickLocations[] = strftime("%Y-%m-%d", $CurTS);

                    $CurTS = strtotime(
                        strftime("%Y-%m-%d", $CurTS) ." + ".$TickIntervals[$this->AxisType]
                    );
                }

                # add axis settings to the chart
                $this->addToChart([
                    "axis" => [
                        "x" => [
                            "tick" => [
                                "values" => $TickLocations,
                            ],
                        ],
                    ],
                ]);
            }

            # if no bar width was specified
            if (is_null($this->BarWidth)) {
                # set the bar width based on the number of bars between tick marks
                $BarWidths = [
                    self::AXIS_TIME_DAILY => 0.0305,
                    self::AXIS_TIME_WEEKLY => 0.2,
                    self::AXIS_TIME_MONTHLY => 0.3,
                    self::AXIS_TIME_YEARLY => 0.9,
                ];
                $this->Chart["bar"]["width"]["ratio"] = $BarWidths[$this->AxisType];
            }
        }

        if (!is_null($this->BarWidth)) {
            $this->Chart["bar"]["width"]["ratio"] = $this->BarWidth / 100;
        }

        if (!is_null($this->YLabel)) {
            $this->Chart["axis"]["y"]["label"] = $this->YLabel;
        }

        if ($this->Zoom) {
            $this->Chart["zoom"]["enabled"] = true;
        }

        if ($this->Subchart) {
            $this->Chart["subchart"]["show"] = true;
        }

        if ($this->Autoscale) {
            if ($this->AutoscaleMin !== false) {
                $this->Chart["zoom"]["onzoomend"] = "zoom_rescale_fn";
                $this->Chart["subchart"]["onbrush"] = "zoom_rescale_fn";
            } else {
                $this->Chart["zoom"]["rescale"] = true;
            }
        }

        if ($this->Stacked) {
            $this->Chart["data"]["groups"] = [
                $this->shortCategoryNames($BarNames),
            ];
        }

        if ($this->Horizontal) {
            $this->Chart["axis"]["rotated"] = true;
        }

        if ($this->Gridlines) {
            $this->Chart["grid"]["y"]["show"] = true;
        }
    }

    /**
     * Sort the user-provided data into bins with sizes given by
     *   $this->AxisType, filling in any gaps in the data given by the
     *   user.
     * @param array $BarNames Bars that all bins should have.
     * @return Array of binned data.
     */
    protected function sortDataIntoBins($BarNames)
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

        if (!$this->Gapless) {
            return $BinnedData;
        }

        # build up a revised data set with no gaps
        $GaplessData = [];
        # prime the revised set with the first element
        $GaplessData[key($BinnedData)] = current($BinnedData);

        # iterate over the remaining elements
        while (($Row = next($BinnedData)) !== false) {
            $BinsAdded = 0;

            # if the next element is not the next bin, add an empty element
            while (key($BinnedData) != $this->nextBin(key($GaplessData))) {
                $GaplessData[$this->nextBin(key($GaplessData))] =
                    array_fill_keys($BarNames, 0);
                end($GaplessData);

                if ($BinsAdded > 1000) {
                    throw new Exception(
                        "Over 1000 empty bins added. "
                        ."Terminating possible infinite loop."
                    );
                }
            }

            # and add the current element
            $GaplessData[key($BinnedData)] = $Row;
            end($GaplessData);
        }

        return $GaplessData;
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

    /**
     * Get abbreviated category names (e.g., for the legend).
     * @param array $LongNames Array of data keyed by long category names.
     * @return array of possibly abbreviated category names.
     */
    protected function shortCategoryNames($LongNames)
    {
        $ShortNames = [];

        foreach ($LongNames as $Name) {
            $ShortNames[] = isset($this->LegendLabels[$Name]) ?
                $this->LegendLabels[$Name] : $Name ;
        }

        return $ShortNames;
    }

    protected $AxisType = self::AXIS_CATEGORY;
    protected $YLabel = null;
    protected $Zoom = false;
    protected $Autoscale = false;
    protected $AutoscaleMin = false;
    protected $Subchart = false;
    protected $Gapless = true;
    protected $Stacked = false;
    protected $Horizontal = false;
    protected $SingleCategory = false;
    protected $Gridlines = true;
    protected $ShowCategoryLabels = true;
    protected $BarWidth = null;
}
