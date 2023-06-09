<?PHP
#
#   FILE:  PieChart.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

use Exception;

/**
* Class for generating and displaying a pie chart.
*/
class PieChart extends Chart_Base
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Set the precision used to display percentages.
     * @param int $Prec Number of digits to display after the decimal.
     */
    public function percentPrecision($Prec)
    {
        $this->Precision = $Prec;
    }

    /**
     * Set the style for slice labels.
     * @param string $LabelType Label type as a PieChart::LABEL_
     *   constant. LABEL_PERCENT will display percentages, LABEL_NAME will
     *   display slice names, and LABEL_RAW will display the raw data.
     * @throws Exception If an invalid slice label type is supplied.
     */
    public function sliceLabelType($LabelType)
    {
        if (!in_array(
            $LabelType,
            [static::LABEL_PERCENT, static::LABEL_NAME, static::LABEL_RAW]
        )) {
            throw new Exception("Unsupported slice label type: ".$LabelType);
        }

        $this->SliceLabelType = $LabelType;
    }

    /**
    * Set the style for values shown in the tooltip
    * @param string $LabelType Label type as a PieChart::TOOLTIP_
    * constant. TOOLTIP_PERCENT will display percentages, TOOLTIP_VALUE
    * will display raw values, and TOOLTIP_BOTH shows both.
    * @throws Exception If an invalid tooltip label type is supplied.
    */
    public function tooltipType($LabelType)
    {
        if (!in_array(
            $LabelType,
            [static::TOOLTIP_PERCENT, static::TOOLTIP_VALUE, static::TOOLTIP_BOTH]
        )) {
            throw new Exception("Unsupported tooltip label type: ".$LabelType);
        }

        $this->TooltipLabelType = $LabelType;
    }

    /**
     * Output chart HTML.
     * @param string $ContainerId HTML Id for the chart container.
     */
    public function display(string $ContainerId)
    {
        ob_start();
        // @codingStandardsIgnoreStart
        ?>
        function tooltip_value_fn(value, ratio, id, index) {
            <?PHP if ($this->TooltipLabelType == self::TOOLTIP_BOTH) { ?>
            return (new Number(100*ratio)).toFixed(<?= $this->Precision ?>)+"%&nbsp;("+value+")";
            <?PHP } elseif ($this->TooltipLabelType == self::TOOLTIP_PERCENT){ ?>
            return (new Number(100*ratio)).toFixed(<?= $this->Precision ?>)+"%";
            <?PHP } elseif ($this->TooltipLabelType == self::TOOLTIP_VALUE){ ?>
            return value;
            <?PHP } ?>
        }

        function label_format_fn(value, ratio, id, index) {
            <?PHP if ($this->SliceLabelType == self::LABEL_PERCENT) { ?>
            return (new Number(100*ratio)).toFixed(<?= $this->Precision ?>)+"%";
            <?PHP } elseif ($this->SliceLabelType == self::LABEL_RAW){ ?>
            return value;
            <?PHP } elseif ($this->SliceLabelType == self::LABEL_NAME){ ?>
            return id;
            <?PHP } ?>
        }
        <?PHP
        // @codingStandardsIgnoreEnd

        $this->HelperFunctionJSCode = ob_get_contents();
        ob_end_clean();

        parent::display($ContainerId);
    }

    # label type constants
    const LABEL_PERCENT = "Percent";
    const LABEL_RAW = "Raw";
    const LABEL_NAME = "Name";

    # tooltip type constants
    const TOOLTIP_VALUE = "Value";
    const TOOLTIP_PERCENT = "Percent";
    const TOOLTIP_BOTH = "Both";

    # ---- PRIVATE INTERFACE --------------------------------------------------

    /**
    * Prepare data for display. @see ChartBase::prepareData().
    */
    protected function prepareData()
    {
        # see http://c3js.org/reference.html#data-columns for format of 'columns' element.
        # since C3 always uses the label in 'columns' for the legend,
        # we'll need to populate the TooltipLabels array that is keyed
        # by legend label where values give the tooltip label
        $this->Chart["data"]["columns"] = [];
        foreach ($this->Data as $Index => $Value) {
            $Label = isset($this->Labels[$Index]) ?
                $this->Labels[$Index] : $Index ;

            if (isset($this->LegendLabels[$Index])) {
                $MyLabel = $this->LegendLabels[$Index];
                $this->TooltipLabels[$MyLabel] = $Label;
            } else {
                $MyLabel = $Label;
            }

            $this->Chart["data"]["columns"][] = [$MyLabel, $Value];
        }

        $this->addToChart([
            "data" => [
                "type" => "pie",
            ],
            "pie" => [
                "label" => [
                    "format" => "label_format_fn",
                ],
            ],
            "tooltip" => [
                "format" => [
                    "value" => "tooltip_value_fn",
                ],
            ],
        ]);
    }

    private $SliceLabelType = self::LABEL_PERCENT;
    private $TooltipLabelType = self::TOOLTIP_BOTH;
    private $Precision = 1;
}
