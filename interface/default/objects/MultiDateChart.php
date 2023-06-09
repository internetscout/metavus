<?PHP
#
#   FILE: MultiDateChart.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

use Exception;

/**
 * Class for generating and displaying a trio of day/month/year date
 * charts inside a tabbed comtainer.
 */
class MultiDateChart
{
    /**
     * Create new MultiDateChart and initialize default values.
     */
    public function __construct()
    {
        foreach ($this->ChartTypes as $ChartType) {
            $this->Charts[$ChartType] = new BarChart();
            $this->Charts[$ChartType]->axisType(
                constant("Metavus\\BarChart::AXIS_TIME_".strtoupper($ChartType))
            );
        }

        $this->gapless(false);
        $this->autoscale(true);
        $this->zoom(true);
        $this->subchart(true);
    }

    /**
     * Forward calls to BarChart setters to the Daily/Weekly/Monthly
     * bar charts contained in thes MultiDateChart.
     * @param string $Function Function that was calld.
     * @param array $Args Function arguments.
     * @see http://php.net/manual/en/language.oop5.overloading.php#object.call
     */
    public function __call($Function, $Args)
    {
        $Delegated = [
            "AUTOSCALE",
            "AUTOSCALEMIN",
            "BARWIDTH",
            "COLORS",
            "DATA",
            "GAPLESS",
            "HEIGHT",
            "LABELS",
            "LEGENDPOSITION",
            "STACKED",
            "SUBCHART",
            "WIDTH",
            "ZOOM",
        ];

        # if this function is one we delegate down to our component BarCharts
        if (in_array(strtoupper($Function), $Delegated)) {
            # call the delegated function on each chart
            foreach ($this->ChartTypes as $ChartType) {
                call_user_func_array(
                    [$this->Charts[$ChartType], $Function],
                    $Args
                );
            }
        } else {
            # otherwise, we don't know how to handle this function
            throw new Exception(
                "Call to invalid function ".$Function
            );
        }

        # if data was updated
        if ($Function == "Data") {
            foreach ($this->ChartTypes as $Type) {
                # produce an array that sums each group of bars for a given
                # time-slice to get a total height
                $Data = array_map(
                    "array_sum",
                    $this->Charts[$Type]->data()
                );

                # set chart autoscaling based on this total
                $this->Charts[$Type]->autoscaleMin(
                    BarChart::computeAutoscaleThreshold($Data)
                );
            }
        }
    }

    /**
     * Display the HTML for this multi-date chart.
     * @param string $IdPrefix HTML Id prefix for this chart, which
     *     must be unique on the page where it is displayed.
     */
    public function display(string $IdPrefix)
    {
        $TabUI = new TabbedContentUI();

        foreach ($this->ChartTypes as $ChartType) {
            $TabUI->beginTab($ChartType);
            $this->Charts[$ChartType]->display($IdPrefix."_".$ChartType);
        }

        $TabUI->display($IdPrefix."_Tabs");
    }


    private $Charts = [];
    private $ChartTypes = ["Daily", "Weekly", "Monthly"];
}
