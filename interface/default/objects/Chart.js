/**
 * FILE: Chart.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2025 Edward Almasy and Internet Scout Research Group
 * http://scout.wisc.edu
 *
 * JS functionality for Metavus charts.
 *
 * @scout:eslint
 */

/* global Plotly */

class Chart {
    /**
     * Handler for plotly_relayout event that implements automatic scaling
     * of the Y axis in plots when the x-axis is zoomed. Attached to the
     * relevant event in the JS generated in BarChart.php.
     * @param Object Plot Plotly object representing the plot being zoomed.
     * @param Object Event Plotly event giving the details of the change
     * to the plot.
     * @see https://plotly.com/javascript/plotlyjs-events/
     */
    static autoscaleChart(Plot, Event) {
        var yMax = 0;

        // if event was a resize of the X axis that uses automatic
        // scaling, adjust Y range to cover everything
        if (Event['xaxis.autorange'] === true) {
            Plot._fullData.forEach(function(Dataset){
                yMax = Math.max(yMax, ...(Dataset.y));
            });
            Plotly.relayout(Plot, {'yaxis.range': [0, yMax]});
            return;
        }

        var xMin = null;
        var xMax = null;
        if (Event['xaxis.range'] !== undefined) {
            xMin = (new Date(Event['xaxis.range'][0])).getTime();
            xMax = (new Date(Event['xaxis.range'][1])).getTime();
        } else if (Event['xaxis.range[0]'] !== undefined) {
            xMin = (new Date(Event['xaxis.range[0]'])).getTime();
            xMax = (new Date(Event['xaxis.range[1]'])).getTime();
        } else {
            return;
        }

        Plot._fullData.forEach(function(Dataset) {
            for (let Index = 0; Index < Dataset._length; Index++) {
                var xTimestamp = (new Date(Dataset.x[Index])).getTime();
                if (xMin <= xTimestamp && xTimestamp <= xMax) {
                    var yVal = parseFloat(Dataset.y[Index]);
                    if (yVal > yMax) {
                        yMax = yVal;
                    }
                }
            }
        });

        var yRange = Plot._fullLayout.yaxis.range;
        if (yMax != yRange[1] && yMax > 0) {
            Plotly.relayout(Plot, {'yaxis.range': [0, yMax]} );
        }
    }

    /**
     * Handler for DOM ready or DOM resize events to auto-size charts
     * based on the width of their container and their configured
     * aspect ratio.
     */
    static resizeCharts() {
        $(".mv-chart-autosize").each(function(Index, Element) {
            var width = $(Element).width();
            var height = Math.round( width / $(Element).data('aspect'));

            Plotly.relayout( Element, {width: width, height: height} );
        });
    }
}

$(document).ready( Chart.resizeCharts );
$(window).resize( Chart.resizeCharts );
