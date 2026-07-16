<?PHP
#
#   FILE:  PieChart.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;

/**
 * Class for generating and displaying a pie chart.
 * @see https://plotly.com/javascript/pie-charts/
 * @see https://plotly.com/javascript/reference/pie/
 */
class PieChart extends Chart
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Set the style for slice labels. If set via this method, defaults to
     *         LABEL_PERCENT.
     * @param string $LabelType Label type as a PieChart::LABEL_
     *   constant. LABEL_PERCENT will display percentages, LABEL_NAME will
     *   display slice names, and LABEL_RAW will display the raw data.
     * @throws Exception If an invalid slice label type is supplied.
     */
    public function setSliceLabelType($LabelType): void
    {
        $ValidTypes = [
            static::LABEL_PERCENT,
            static::LABEL_NAME,
            static::LABEL_RAW
        ];
        if (!in_array($LabelType, $ValidTypes)) {
            throw new Exception("Unsupported slice label type: ".$LabelType);
        }

        $this->SliceLabelType = $LabelType;
    }

    /**
     * Set the style for values shown in the tooltip. If not set via this
     *         method, defaults to TOOLTIP_BOTH.
     * @param string $LabelType Label type as a PieChart::TOOLTIP_
     *         constant. TOOLTIP_PERCENT will display percentages,
     *         TOOLTIP_VALUE will display raw values, and TOOLTIP_BOTH shows
     *         both.
     * @throws Exception If an invalid tooltip label type is supplied.
     */
    public function setTooltipType($LabelType): void
    {
        $ValidTypes = [
            static::TOOLTIP_PERCENT,
            static::TOOLTIP_VALUE,
            static::TOOLTIP_BOTH
        ];
        if (!in_array($LabelType, $ValidTypes)) {
            throw new Exception("Unsupported tooltip label type: ".$LabelType);
        }

        $this->TooltipLabelType = $LabelType;
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
     * Get chart layout information in the format required for the 'layout'
     *         argument to Plotly.newPlot().
     * @see https://plotly.com/javascript/plotlyjs-function-reference/#plotlynewplot
     * @see https://plotly.com/javascript/reference/layout/
     * @see Chart::getChartLayout()
     */
    protected function getChartLayout(): array
    {
        $Layout = parent::getChartLayout();

        foreach (["t", "r", "b", "l"] as $Side) {
            $Layout["margin"][$Side] = 0;
        }

        $Layout["legend"]["indentation"] = -10;

        return $Layout;
    }

    /**
     * Get data in the format required by Plotly for the 'data' argument to
     *         Plotly.newPlot().
     * @see https://plotly.com/javascript/plotlyjs-function-reference/#plotlynewplot
     * @see https://plotly.com/javascript/pie-charts/
     * @see https://plotly.com/javascript/reference/pie/
     */
    protected function getChartData(): array
    {
        $Data = [];
        $Labels = [];
        $Legend = [];
        $Colors = [];
        foreach ($this->Data as $Index => $Value) {
            if ($Value == 0) {
                continue;
            }

            $Label = $this->Labels[$Index] ?? $Index;
            $Data[] = $Value;
            $Labels[] = $Label;
            $Legend[] = $this->LegendLabels[$Index] ?? $Label;
            $Colors[] = $this->Colors[$Index] ?? $this->generateRgbColorString($Index);
        }

        $Datasets = [];
        $Datasets[] = [
            "values" => $Data,
            "text" => $Labels,
            "labels" => $Legend,
            "marker" => [
                "colors" => $Colors,
            ],
            "texttemplate" => $this->getTextTemplate(),
            "hovertemplate" => $this->getHoverTemplate(),
            "textposition" => "inside",
            "sort" => false,
            "type" => "pie",
        ];

        return $Datasets;
    }

    /**
     * Get the labels to display on pie chart slices.
     */
    private function getTextTemplate(): string
    {
        switch ($this->SliceLabelType) {
            case self::LABEL_PERCENT:
                $TextTemplate = "%{percent:.1%}";
                break;

            case self::LABEL_RAW:
                $TextTemplate = "%{value}";
                break;

            case self::LABEL_NAME:
                $TextTemplate = "%{text}";
                break;

            default:
                throw new Exception(
                    "Unknown Slice Label Type - should be impossible."
                );
        }

        return $TextTemplate;
    }

    /**
     * Get the labels to display in hover text.
     */
    private function getHoverTemplate(): string
    {
        switch ($this->TooltipLabelType) {
            case self::TOOLTIP_BOTH:
                $HoverTemplate = "%{text} | %{percent:.1%} (%{value})";
                break;

            case self::TOOLTIP_PERCENT:
                $HoverTemplate = "%{text} | %{percent.1%}";
                break;

            case self::TOOLTIP_VALUE:
                $HoverTemplate = "%{text} | %{value}";
                break;

            default:
                throw new Exception(
                    "Unknown Tooltip Label Type - should be impossible."
                );
        }

        return $HoverTemplate."<extra></extra>";
    }

    private $SliceLabelType = self::LABEL_PERCENT;
    private $TooltipLabelType = self::TOOLTIP_BOTH;
}
