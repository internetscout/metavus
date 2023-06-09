/**
 * FILE:  CW-Graph.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2014-2020 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * D3 plotting of line graphs with date axis.
 *
 * @scout:eslint
 */

/* global cw, d3 */

function Graph(chart_no, data, x_label, y_label, margin, width_tot, height_tot) {
    this.ylab_length = Math.max(3, d3.max(data, function(d) {
        return String(d.Y0).length;
    }) );

    this.width = Math.min( width_tot, jQuery('div#chart'+parseInt(chart_no)).width()) -
        margin.left - margin.right - 14*this.ylab_length ;
    this.height = height_tot - margin.top - margin.bottom;

    this.x = this.setup_x();
    this.y = this.setup_y();

    this.xAxis = d3.svg.axis()
        .scale(this.x)
        .orient("bottom")
        .tickSize(-this.height, 0)
        .tickPadding(6);

    this.yAxis = d3.svg.axis()
        .scale(this.y)
        .orient("right")
        .tickSize(-this.width)
        .tickPadding(6);

    // Create the SVG to hold our chart:
    this.svg = d3.select('div#chart'+chart_no)
        .append("svg")
        .attr("width", this.width + margin.left + margin.right + 14*this.ylab_length)
        .attr("height", this.height + margin.top + margin.bottom)
        .append("g")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    // Create the Y axis and label it:
    this.svg.append("g")
        .attr("class", "y axis")
        .attr("transform", "translate(" + this.width + ",0)");
    this.svg.append("text")
        .attr("class", "y-label"+chart_no)
        .attr("text-anchor", "middle")
        .attr("x", -this.height/2)
        .attr("y", this.width + 14*this.ylab_length)
        .attr("transform", "rotate(-90)")
        .text(y_label);

    // Create the X axis and label it:
    this.svg.append("g")
        .attr("class", "x axis")
        .attr("transform", "translate(0," + this.height + ")");
    this.svg.append("text")
        .attr("class", "x-label"+chart_no)
        .attr("text-anchor", "middle")
        .attr("x", this.width/2)
        .attr("y", this.height+35 )
        .text(x_label);

    // Create the clipping area that all the lines will adhere to:
    this.svg.append("clipPath")
        .attr("id", "clip"+chart_no)
        .append("rect")
        .attr("x", this.x(0))
        .attr("y", this.y(1))
        .attr("width", this.width)
        .attr("height", this.y(0) - this.y(1));
}

function DateGraph(chart_no, data, x_label, y_label, margin, width_tot, height_tot, legend_text) {
    this.setup_x = function() {
        return d3.time.scale().range([0, this.width]);
    };

    DateGraph.base.call(this, chart_no, data, x_label, y_label, margin, width_tot, height_tot, legend_text);
} cw.extend(DateGraph, Graph);

function LineDateGraph(chart_no, data, x_label, y_label, margin, width_tot, height_tot, legend_text) {
    this.setup_y = function(){
        return d3.scale.linear().range([this.height, 0]);
    };

    LineDateGraph.base.call(this, chart_no, data, x_label, y_label, margin, width_tot, height_tot, legend_text);

    // Pull out variables that callbacks will need:
    var x = this.x;
    var y = this.y;
    var xAxis = this.xAxis;
    var yAxis = this.yAxis;
    var svg = this.svg;
    var width = this.width;

    // A function for generating lines on this plot:
    var line = d3.svg.line()
        .interpolate("step-after")
        .x(function(d) {
            return x(d.X);
        })
        .y(function(d) {
            return y(d.Y);
        });

    // Reformat our input data so that it can be more easily iterated over.
    // This involves creating an arry of X/Y values for each data series,
    //  with the X's repeated in each.  This repitition is why we're doing
    //  a client-side transformation.
    var Yi = d3.keys(data[0])
        .filter(function(key) {
            return key !== "X";
        })
        .map( function(name){
            return { name: name,
                values: data.map(function(d){
                    return { X: d.X, Y: +d[name] };
                } )};
        });

    y.domain([0, 1.1 * d3.max(Yi,
        function(d) {
            return d3.max(d.values, function(e){
                return e.Y;
            });
        }) ] );

    // Configure the domain of the X:
    var x_domain = [d3.min(data, function(d) {
        return +d.X;
    } ),
    d3.max(data, function(d) {
        return +d.X;
    } ) ];
    x.domain(x_domain);

    svg.select("g.x.axis").call(xAxis);
    svg.select("g.y.axis").call(yAxis);

    // Grab all the elements of the svg of class .series
    //  Join those agaisnt the data in Yi.
    // Since the svg is currently empty, this puts everything in the
    //  'enter' selection, which we use to create a corresponding
    //  group for each element of the input data
    var series = svg.selectAll(".series")
        .data(Yi)
        .enter()
        .append("g").attr("class", "series");

    // For each newly created group, add a line to the chart:
    series.append("path")
        .attr("class", function(d,i){
            return "line graph_color"+i;
        })
        .attr("d", function(d) {
            return line(d.values);
        })
        .attr("clip-path", "url(#clip"+chart_no+")");

    // Also add a little 'focus' widget to each data series:
    var focus = series.append("g")
        .attr("class", "focus")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")")
        .style("display", "none");

    // Give the focus widgets a circle and a line of text:
    focus.append("circle").attr("r", 4.5);
    focus.append("text")
        .attr("x", 0)
        .attr("dy", "1em");

    // Set up zooming for the x axis:
    var zoom = d3.behavior.zoom().on("zoom", draw).scaleExtent([ 1, 20 ] );
    zoom.x(x);

    // Create a rectangle over the SVG that will listen for zoom
    //  and mouse events, calling the callbacks as necessary:
    this.svg.append("rect")
        .attr("class", "pane")
        .attr("width", width)
        .attr("height", this.height)
        .on("mouseover", function() {
            focus.style("display", null);
        })
        .on("mouseout", function()  {
            focus.style("display", "none");
        })
        .on("mousemove", mousemove)
        .call(zoom);

    // Set up callbacks to handle mouse movement and zooming:
    function mousemove() {
        focus.style("display", null);
        // Get the position of the mouse along our x axis:
        var x0 = x.invert(d3.mouse(this)[0]);

        // Foreach of the little 'focus' widgets:
        focus.each( function(){
            // Identify the closest x to the mouse:
            var data = d3.select(this).datum().values,
                ix = d3.bisector( function(d) {
                    return d.X;
                }).left( data, x0, 1),
                d0 = data[ix - 1],
                d1 = data[ix],
                d_tgt = x0 - d0.X > d1.X - x0 ? d1 : d0;
            // Move this circle and label to the new position:
            d3.select(this).attr("transform", "translate(" + x(d_tgt.X) + "," + y(d_tgt.Y) + ")");
            var this_date = new Date(d_tgt.X);
            d3.select(this).select("text").text(this_date.toDateString()+": "+d_tgt.Y);
        } );
    }

    // For zoom events:
    function draw() {
        // Hide the 'focus' objects:
        focus.style("display", "none");

        // Limit panning:
        var new_domain = x.domain();

        if (new_domain[0].getTime() < x_domain[0])
            zoom.translate( [0,0] );

        if (new_domain[1].getTime() > x_domain[1])
            zoom.translate( [width - d3.event.scale*width, 0] );

        // Redraw the axes and lines:
        svg.select("g.x.axis").call(xAxis);
        svg.select("g.y.axis").call(yAxis);
        svg.selectAll("path.line").attr("d", function(d) {
            return line(d.values);
        });
    }
} cw.extend(LineDateGraph, DateGraph);

function BarDateGraph(chart_no, data, x_label, y_label, margin, width_tot, height_tot, legend_text, bar_width_s) {
    this.setup_y = function(){
        return d3.scale.linear().range([this.height, 0]);
    };

    LineDateGraph.base.call(this, chart_no, data, x_label, y_label, margin, width_tot, height_tot, legend_text);

    // Pull out members that event callbacks will need:
    var x = this.x;
    var y = this.y;
    var width = this.width;
    var xAxis = this.xAxis;
    var yAxis = this.yAxis;
    var svg = this.svg;

    // Reformat our input data so that it can be more easily iterated over.
    // This involves creating an arry of X/Y values for each data series,
    //  with the X's repeated in each.  This repitition is why we're doing
    //  a client-side transformation.
    var Yi = d3.keys(data[0])
        .filter(function(key) {
            return key !== "X";
        })
        .map( function(name){
            return { name: name,
                values: data.map(function(d){
                    if (name == "Y0") {
                        Y0 = 0;
                    } else {
                        var this_n = parseInt(name.slice(1));
                        var Y0 = +d["Y0"];
                        for (var i=1; i<this_n; i++) {
                            Y0 = Y0 + d["Y"+i];
                        }
                    }
                    return { X: d.X, Y: +d[name], Y0: Y0 };
                } )};
        });

    // Configure the domain of the X:
    var x_domain = [d3.min(data, function(d) {
        return +d.X;
    }) - bar_width_s * 1000,
    d3.max(data, function(d) {
        return +d.X;
    }) + 2 * bar_width_s * 1000];
    x.domain(x_domain);

    // Set up a function for computing the bar width:
    var bar_width = null;
    if (bar_width_s < 28*86400) {
        bar_width = function(){
            var x_now = x.domain();
            return 0.9 * Math.max(1, width /
                                  ( (x_now[1].getTime() - x_now[0].getTime() ) /
                                    ( bar_width_s * 1000 ) ) ) ;
        };
    } else {
        xAxis.ticks(d3.time.month, 1);
        bar_width = function(ts){
            var x_now = x.domain();

            var this_date = new Date (ts);
            var this_month = this_date.getMonth();
            var n_days;

            if (this_month == 1){
                if ( this_date.getYear()%4==0) {
                    n_days = 29;
                } else {
                    n_days = 28;
                }
            } else if ( this_month ==  3 || this_month ==  5 ||
                        this_month ==  8 || this_month == 10 ){
                n_days = 30;
            } else {
                n_days = 31;
            }

            return Math.max(1, width /
                            ( (x_now[1].getTime() - x_now[0].getTime()) / ( (n_days-1) * 86400 * 1000 ))) ;
        } ;
    }

    var customTimeFormat = d3.time.format.multi([
        ["%I:%M", function(d) {
            return d.getMinutes();
        }],
        ["%I %p", function(d) {
            return d.getHours();
        }],
        ["%a %d", function(d) {
            return d.getDay() && d.getDate() != 1;
        }],
        ["%b %d", function(d) {
            return d.getDate() != 1;
        }],
        ["%b", function(d) {
            return d.getMonth();
        }],
        ["%Y", function() {
            return true;
        }]
    ]);

    xAxis.tickFormat(customTimeFormat);

    y.domain([0, 1.1 * d3.max(Yi,
        function(d) {
            return d3.max(d.values, function(e){
                return e.Y0+e.Y;
            });
        }) ] );

    // Now that we know the domains involved, draw the axes:
    svg.select("g.x.axis").call(xAxis);
    svg.select("g.y.axis").call(yAxis);

    // Set up zooming for the x axis:
    var zoom = d3.behavior.zoom()
        .on("zoom", draw).scaleExtent([1, (x_domain[1]-x_domain[0])/(3*1000*bar_width_s) ]);
    zoom.x(x);

    // Create a rectangle over the SVG that will listen for zoom
    //  and mouse events, calling the callbacks as necessary:
    svg.append("rect")
        .attr("class", "pane")
        .attr("width", width)
        .attr("height", this.height)
        .on("mouseover", function() {
            focus.style("display", null);
        })
        .on("mouseout", function()  {
            focus.style("display", "none");
        })
        .call(zoom);

    // Grab all the elements of the svg of class .series
    //  Join those agaisnt the data in Yi.
    // Since the svg is currently empty, this puts everything in the
    //  'enter' selection, which we use to create a corresponding
    //  group for each element of the input data
    var series = svg.selectAll(".series")
        .data(Yi)
        .enter()
        .append("g")
        .attr("class", function(d,i){
            return "area graph_color"+i;
        });

    series.selectAll("rect")
        .data( function(d) {
            return d.values;
        } )
        .enter().append("rect")
        .attr("class", "bar")
        .attr("clip-path", "url(#clip"+chart_no+")")
        .attr("x", function(d){
            return x(d.X);
        } )
        .attr("y", function(d){
            return y(d.Y + d.Y0);
        } )
        .attr("width", function(d) {
            return bar_width(d.X);
        } )
        .attr("height", function(d){
            return 0.1 + y(d.Y0) - y(d.Y0 + d.Y);
        } )
        .style("pointer-events", "all")
        .on("mouseover", mousemove);

    // Also add a little 'focus' widget for the SVG:
    var focus = svg.append("g")
        .attr("class", "focus")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")")
        .style("display", "none");

    // Give the focus widgets a circle and a line of text:
    focus.append("circle").attr("r", 4.5);
    focus.append("text")
        .attr("x", "0")
        .attr("dy", "-0.5em");

    if (legend_text.length > 0) {
        var legend = series.append('g').attr('class','legend');

        legend.append('rect')
            .attr('x', 0)
            .attr('y', function(d, i){
                return i *  20;
            })
            .attr('width', 10)
            .attr('height', 10)
            .attr('class', function(d,i) {
                return "graph_color"+i;
            });

        legend.append('text')
            .attr('x', 12)
            .attr('y', function(d, i){
                return (i *  20) + 9;
            })
            .text(function(d, i){
                return legend_text[i];
            });
    }

    // Set up callbacks to handle mouse movement and zooming:
    function mousemove(d) {
        if (d === null) return;
        focus.style("display", null);

        var this_date = new Date(d.X);
        // Move this circle and label to the new position:
        focus.attr("transform", "translate(" + x(d.X) + "," + y(d.Y + d.Y0) + ")");

        var date_label = null;
        if (bar_width_s <= 86400) {
            date_label = (this_date.getMonth()+1) + "/" + this_date.getDate();
        } else if (bar_width_s <= 7*86400) {
            var next_date = new Date(d.X + bar_width_s*1000);

            date_label = (this_date.getMonth()+1) + "/" + this_date.getDate() +
                 " - " + (next_date.getMonth()+1) + "/" + next_date.getDate();
        } else {
            date_label = (this_date.getMonth()+1) + "/" + (1900+this_date.getYear());
        }

        focus.select("text").text(date_label+": "+d.Y);
    }

    // For zoom events:
    function draw() {
        // Hide the 'focus' objects:
        focus.style("display", "none");

        // Limit panning:
        var new_domain = x.domain();

        if (new_domain[0].getTime() < x_domain[0])
            zoom.translate( [0,0] );

        if (new_domain[1].getTime() > x_domain[1])
            zoom.translate( [width - d3.event.scale*width, 0] );

        // Redraw the axes and lines:
        svg.select("g.x.axis").call(xAxis);
        svg.select("g.y.axis").call(yAxis);

        svg.selectAll("rect.bar").attr("x", function(d){
            return x(d.X);
        } );
        svg.selectAll("rect.bar").attr("width", function(d) {
            return bar_width(d.X);
        });
    }
}  cw.extend(BarDateGraph, DateGraph);

function OrdinalGraph(chart_no, data, x_label, y_label, margin, width_tot, height_tot, legend_text) {
    this.setup_x = function() {
        return d3.scale.ordinal();
    };

    OrdinalGraph.base.call(this, chart_no, data, x_label, y_label, margin, width_tot, height_tot, legend_text);

} cw.extend(OrdinalGraph, Graph);

function OrdinalBarGraph(chart_no, data, x_label, y_label, margin, width_tot, height_tot, legend_text){
    this.setup_y = function(){
        return d3.scale.linear().range([this.height, 0]);
    };

    OrdinalBarGraph.base.call(this, chart_no, data, x_label, y_label, margin, width_tot, height_tot, legend_text);

    // Pull out members that event callbacks will need:
    var x = this.x;
    var y = this.y;
    var width = this.width;
    var xAxis = this.xAxis;
    var yAxis = this.yAxis;
    var svg = this.svg;

    // Reformat our input data so that it can be more easily iterated over.
    // This involves creating an arry of X/Y values for each data series,
    //  with the X's repeated in each.  This repitition is why we're doing
    //  a client-side transformation.
    var Yi = d3.keys(data[0])
        .filter(function(key) {
            return key !== "X";
        })
        .map( function(name){
            return { name: name,
                values: data.map(function(d){
                    if (name == "Y0") {
                        Y0 = 0;
                    } else {
                        var this_n = parseInt(name.slice(1));
                        var Y0 = +d["Y0"];
                        for (var i=1; i<this_n; i++) {
                            Y0 = Y0 + d["Y"+i];
                        }
                    }
                    return { X: d.X, Y: +d[name], Y0: Y0 };
                } )};
        });

    // Configure the domain of the X:
    x.domain( Yi[0].values.map( function(d) {
        return d.X;
    }) )
        .rangeRoundBands([0, width], 0.1);

    y.domain([0, 1.1 * d3.max(Yi,
        function(d) {
            return d3.max(d.values, function(e){
                return e.Y0+e.Y;
            });
        }) ] );

    // Now that we know the domains involved, draw the axes:
    svg.select("g.x.axis").call(xAxis);
    svg.select("g.y.axis").call(yAxis);

    var zoom = d3.behavior.zoom()
        .on("zoom", draw).scaleExtent([1, Infinity]);

    // Create a rectangle over the SVG that will listen for zoom
    //  and mouse events, calling the callbacks as necessary:
    svg.append("rect")
        .attr("class", "pane")
        .attr("width", width)
        .attr("height", this.height)
        .on("mouseover", function() {
            focus.style("display", null);
        })
        .on("mouseout", function()  {
            focus.style("display", "none");
        })
        .call(zoom);

    // Grab all the elements of the svg of class .series
    //  Join those agaisnt the data in Yi.
    // Since the svg is currently empty, this puts everything in the
    //  'enter' selection, which we use to create a corresponding
    //  group for each element of the input data
    var series = svg.selectAll(".series")
        .data(Yi)
        .enter()
        .append("g")
        .attr("class", function(d,i){
            return "area graph_color"+i;
        });

    var bar_width = x.rangeBand();

    series.selectAll("rect")
        .data( function(d) {
            return d.values;
        } )
        .enter().append("rect")
        .attr("class", "bar")
        .attr("clip-path", "url(#clip"+chart_no+")")
        .attr("x", function(d){
            return x(d.X);
        } )
        .attr("y", function(d){
            return y(d.Y + d.Y0);
        } )
        .attr("width", bar_width )
        .attr("height", function(d){
            return 0.1 + y(d.Y0) - y(d.Y0 + d.Y);
        } )
        .style("pointer-events", "all")
        .on("mouseover", mousemove);

    // Also add a little 'focus' widget for the SVG:
    var focus = svg.append("g")
        .attr("class", "focus")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")")
        .style("display", "none");

    // Give the focus widgets a circle and a line of text:
    focus.append("circle").attr("r", 4.5);
    focus.append("text")
        .attr("x", "0")
        .attr("dy", "-0.5em");

    if (legend_text.length > 0) {
        var legend = series.append('g').attr('class','legend');

        legend.append('rect')
            .attr('x', 0)
            .attr('y', function(d, i){
                return i *  20;
            })
            .attr('width', 10)
            .attr('height', 10)
            .attr('class', function(d,i) {
                return "graph_color"+i;
            });

        legend.append('text')
            .attr('x', 12)
            .attr('y', function(d, i){
                return (i *  20) + 9;
            })
            .text(function(d, i){
                return legend_text[i];
            });
    }

    // Set up callbacks to handle mouse movement and zooming:
    function mousemove(d) {
        if (d === null) return;
        focus.style("display", null);

        // Move this circle and label to the new position:
        focus.attr("transform", "translate(" + x(d.X) + "," + y(d.Y + d.Y0) + ")");
        focus.select("text").text(d.X+": "+d.Y);
    }


    // Track an index of where we are:
    var prev_scale = zoom.scale();
    var total_domain = x.domain();
    var index = [0, total_domain.length];


    // For zoom events:
    function draw() {
        // Hide the 'focus' objects:
        focus.style("display", "none");

        // Pull out the zoom and translation values for this event:
        var tx_vector = zoom.translate();
        var z_scale = zoom.scale();

        // Cancel any zooming, because ordinal scales don't support it :p
        zoom.translate( [0,0] );

        // When the scaling changes, zoom:
        if (prev_scale != z_scale ) {
            if (z_scale == 1) {
                index = [0, total_domain.length];
            } else if ( zoom.scale() > prev_scale ) {
                index[0] = Math.min( total_domain.length, index[0] + 1 );
            } else {
                index[0] = Math.max(0, index[0] - 1);
            }
            prev_scale = zoom.scale();
        } else {
            // Otherwise, see which way we should pan:

            var tx = - Math.round(tx_vector[0] / x.rangeBand()  );
            var n_bars = index[1] - index[0];

            if (tx > 0 ){
                index[0] = Math.min( total_domain.length-n_bars, index[0] + tx);
                index[1] = Math.min( total_domain.length,        index[1] + tx);
            } else if (tx < 0) {
                index[0] = Math.max( 0,      index[0] + tx );
                index[1] = Math.max( n_bars, index[1] + tx );
            }
        }

        var new_domain = total_domain.slice(index[0], index[1]);

        x.domain(new_domain).rangeRoundBands([0, width], 0.1);

        // Redraw the axes and lines:
        svg.select("g.x.axis").call(xAxis);
        svg.select("g.y.axis").call(yAxis);

        svg.selectAll("rect.bar").attr("display", function(d){
            if ( typeof x(d.X) == "undefined") {
                return "none";
            } else {
                return null;
            }
        });

        svg.selectAll("rect.bar").attr("x", function(d){
            return x(d.X);
        } );
        svg.selectAll("rect.bar").attr("width", x.rangeBand() );
    }

} cw.extend(OrdinalBarGraph, OrdinalGraph);
