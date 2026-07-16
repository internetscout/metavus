<?PHP
#
#   FILE:  LineChart.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;

/**
 * Class for generating and displaying a line chart.
 * @see https://plotly.com/javascript/line-charts/
 * @see https://plotly.com/javascript/reference/scatter/
 */
class LineChart extends Chart
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get/set data for chart.
     * @param array $NewValue Data for chart where keys give X coordinates and
     *    values are either Y coordinates or arrays of Y coordinates. (OPTIONAL)
     * @return array Current chart data.
     */
    public function data(?array $NewValue = null): array
    {
        # normalize data to array of arrays format if necessary
        if (($NewValue !== null) && !is_array(reset($NewValue))) {
            $Data = [];
            foreach ($NewValue as $Name => $Val) {
                $Data[$Name] = [$Name => $Val];
            }
            $NewValue = $Data;
        }

        return parent::data($NewValue);
    }

    /**
     * Get/set the axis type of a line chart (if this method is not called, the
     *         default is AXIS_NUMERIC).
     * @param string $NewValue Axis type as a LineChart::AXIS_ constant.
     *         Allowed values are AXIS_NUMERIC and AXIS_DATE.  (OPTIONAL).
     * @return string Current AxisType.
     * @throws Exception If an invalid axis type is supplied.
     */
    public function axisType(?string $NewValue = null): string
    {
        if (func_num_args() > 0) {
            $ValidTypes = [
                self::AXIS_DATE,
                self::AXIS_NUMERIC,
            ];

            # toss exception if the given type is not valid
            if (!in_array($NewValue, $ValidTypes)) {
                throw new Exception("Invalid axis type for line charts: ".$NewValue);
            }

            $this->AxisType = $NewValue;
        }

        return $this->AxisType;
    }

    /**
     * Get/set the Y axis label for a line chart.
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
            $this->ShowSubchart = $NewValue;
        }
        return $this->ShowSubchart;
    }

    /**
     * Enable/disable display of grid lines.
     * @param bool $NewValue TRUE to show grid lines.
     * @return bool Current grid line sitting.
     */
    public function showGridlines(?bool $NewValue = null)
    {
        if ($NewValue !== null) {
            $this->ShowGridlines = $NewValue;
        }
        return $this->ShowGridlines;
    }



    # axis types
    const AXIS_DATE = "date";
    const AXIS_NUMERIC = "numeric";


    # ---- PRIVATE INTERFACE --------------------------------------------------

    /**
     * Get data in the format required by Plotly for the 'data' argument to
     *         Plotly.newPlot().
     * @return array Chart data
     * @see https://plotly.com/javascript/plotlyjs-function-reference/#plotlynewplot
     * @see https://plotly.com/javascript/line-charts/
     * @see https://plotly.com/javascript/reference/scatter/
     */
    protected function getChartData(): array
    {
        $Data = $this->Data;

        $DataSets = [];
        foreach ($Data as $Index => $Values) {
            $DataSetIndex = 0;
            foreach ($Values as $VIndex => $Value) {
                $DataSets[$DataSetIndex]["x"][] =
                    ($this->AxisType == self::AXIS_DATE) ?
                    date("Y-m-d", $Index) : $Index;
                $DataSets[$DataSetIndex]["y"][] = $Value;
                $DataSetIndex++;
            }
        }

        foreach (array_keys($DataSets) as $Index) {
            $Label = $this->Labels[$Index] ?? $Index;
            $Legend = $this->LegendLabels[$Index] ?? $Label;

            $DataSets[$Index]["type"] = "scatter";
            $DataSets[$Index]["name"] = $Legend;
        }

        return $DataSets;
    }

    /**
     * Get chart layout information in the format required for the 'layout'
     *         argument to Plotly.newPlot().
     * @return array Chart layout
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
        $Layout["margin"]["l"] = ($this->yLabel() === null) ? 20 : 60;

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

        return $Layout;
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

    protected $AxisType = self::AXIS_NUMERIC;
    protected $YLabel = null;
    protected $Zoom = false;
    protected $Autoscale = false;
    protected $ShowSubchart = false;
    protected $ShowGridlines = true;
}
