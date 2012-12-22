<?php

namespace Altamira\JsWriter;

use Altamira\JsWriter\Ability;
use Altamira\ChartDatum;

class Flot
    extends JsWriterAbstract
    implements Ability\Cursorable,
               Ability\Datable,
               Ability\Fillable,
               Ability\Griddable,
               Ability\Highlightable,
               Ability\Legendable,
               Ability\Shadowable,
               Ability\Zoomable,
               Ability\Labelable,
               Ability\Lineable
{
    const LIBRARY = 'flot';
    
    protected $library = 'flot';
    protected $typeNamespace = '\\Altamira\\Type\\Flot\\';

    protected $dateAxes = array('x'=>false, 'y'=>false);
    protected $zooming = false;
    protected $highlighting = false;
    protected $pointLabels = array();
    protected $labelSettings = array('location'=>'w','xpadding'=>'0','ypadding'=>'0');

    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\JsWriterAbstract::getScript()
     */
    public function getScript()
    {
        $name = $this->chart->getName();
        $dataArrayJs = '[';

        $counter = 0;
        foreach ($this->chart->getSeries() as $title=>$series) {

            $dataArrayJs .= $counter++ > 0 ? ', ' : '';

            $dataArrayJs .= '{';

            // associate Xs with Ys in cases where we need it
            $data = $series->getData();

            $oneDimensional = array_keys($data) == range(0, count($data)-1, 1);

            if (! empty($this->seriesLabels[$title]) ) {
                $labelCopy = $this->seriesLabels[$title];
            }
            $formattedData = array();
            foreach ($data as $datum) { 
                if (! $datum instanceof ChartDatum\ChartDatumAbstract ) {
                    throw new \UnexpectedValueException('Chart data should be an object inheriting from ChartDatumAbstract');
                }
                foreach ($this->dateAxes as $axis=>$flag) { 
                    if ($flag) {
                        //@todo we can probably accomplish this with less iterations
                        switch ($axis) {
                            case 'x':                                
                                $date = \DateTime::createFromFormat('m/d/Y', $datum['x']);
                                $datum['x'] = $date->getTimestamp() * 1000;
                                break;
                            case 'y':
                                $date = \DateTime::createFromFormat('m/d/Y', $datum['y']);
                                $datum['y'] = $date->getTimestamp() * 1000;
                                break;
                        }
                    }
                }
                        
                if (!empty($this->seriesLabels[$title])) {
                    $dataPoints = "{$datum['x']},{$datum['y']}";
                    $datum->setLabel( $labelCopy );
                    $this->pointLabels[$dataPoints] = array_shift($labelCopy);
                }
                
                $formattedData[] = $datum->getRenderData();
            }
            
            $dataArrayJs .= 'data: '.$this->makeJSArray($formattedData);
            
            if (isset($this->types['default']) && 
               ($this->types['default'] instanceOf \Altamira\Type\Flot\Bubble
                || $this->types['default'] instanceOf \Altamira\Type\Flot\Donut ) ) {
                $dataArrayJs .= ', label: "' . str_replace('"', '\\"', $series->getTitle() ) . '"';
            }

            $this->prepOpts( $this->options['seriesStorage'][$title] );

            $opts = substr(json_encode($this->options['seriesStorage'][$title]), 1, -1);

            if (strlen($opts) > 2) {
                $dataArrayJs .= ',' . $opts;
            }

            $dataArrayJs .= '}';
        }


        $dataArrayJs .= ']';

        $optionsJs = ($js = $this->getOptionsJs()) ? ", {$js}" : ', {}';

        $extraFunctionCallString = implode("\n", $this->getExtraFunctionCalls($dataArrayJs, $optionsJs));

        return <<<ENDSCRIPT
jQuery(document).ready(function() {
    var placeholder = jQuery('#{$name}');
    var plot = jQuery.plot(placeholder, {$dataArrayJs}{$optionsJs});
    {$extraFunctionCallString}
});

ENDSCRIPT;

    }

    public function getExtraFunctionCalls($dataArrayJs, $optionsJs)
    {
        $extraFunctionCalls = array();

        if ($this->zooming) {
            $extraFunctionCalls[] = sprintf( self::ZOOMING_FUNCTION, $dataArrayJs, $optionsJs, $dataArrayJs, $optionsJs );
        }

        if ($this->useLabels) {
            $seriesLabels = json_encode($this->pointLabels);

            $top = '';
            $left = '';
            $pixelCount = '15';

            for ( $i = 0; $i < strlen($this->labelSettings['location']); $i++ ) {
                switch ( $this->labelSettings['location'][$i] ) {
                    case 'n':
                        $top = '-'.$pixelCount;
                        break;
                    case 'e':
                        $left = '+'.$pixelCount;
                        break;
                    case 's':
                        $top = '+'.$pixelCount;
                        break;
                    case 'w':
                        $left = '-'.$pixelCount;
                }
            }

            $paddingx = '-'.(isset($this->labelSettings['xpadding']) ? $this->labelSettings['xpadding'] : '0');
            $paddingy = '-'.(isset($this->labelSettings['ypadding']) ? $this->labelSettings['ypadding'] : '0');

            $extraFunctionCalls[] = sprintf( self::LABELS_FUNCTION, $seriesLabels, $left, $paddingx, $top, $paddingy );

        }

        if ($this->highlighting) {

            $formatPoints = "x + ',' + y";

            foreach ($this->dateAxes as $axis=>$flag) {
                if ($flag) {
                    $formatPoints = str_replace($axis, "(new Date(parseInt({$axis}))).toLocaleDateString()",$formatPoints);
                }
            }

            $extraFunctionCalls[] =  sprintf( self::HIGHLIGHTING_FUNCTION, $formatPoints );

        }

        return $extraFunctionCalls;

    }

    /**
     * Sets an option for a given axis
     * @param string $axis
     * @param string $name
     * @param mixed $value
     * @return \Altamira\JsWriter\Flot
     */
    public function setAxisOptions($axis, $name, $value)
    {
        if( strtolower($axis) === 'x' || strtolower($axis) === 'y' ) {
            $axis = strtolower($axis) . 'axis';

            if ( array_key_exists( $name, $this->nativeOpts[$axis] ) ) {
                $this->setNestedOptVal( $this->options, $axis, $name, $value );
            } else {
                $key = 'axes.'.$axis.'.'.$name;

                if ( isset( $this->optsMapper[$key] ) ) {
                    $this->setOpt($this->options, $this->optsMapper[$key], $value);
                }

                if ( $name == 'formatString' ) {
                    $this->options[$axis]['tickFormatter'] = $this->getCallbackPlaceholder('function(val, axis){return "'.$value.'".replace(/%d/, val);}');
                }

            }
        }

        return $this;
    }

    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\JsWriterAbstract::initializeSeries()
     */
    public function initializeSeries( $series )
    {
        parent::initializeSeries($series);
        $title = $this->getSeriesTitle($series);
        $this->options['seriesStorage'][$title]['label'] = $title; 
        return $this;
    }

    /**
     * Mutates the option array to the format required for flot
     * @return Ambigous <string, mixed>
     */
    public function getOptionsJS()
    {
        foreach ($this->optsMapper as $opt => $mapped)
        {
            if (($currOpt = $this->getOptVal($this->options, $opt)) && ($currOpt !== null)) {
                $this->setOpt($this->options, $mapped, $currOpt);
                $this->unsetOpt($this->options, $opt);
            }
        }

        $opts = $this->options;

        // stupid pie plugin
        if ( $this->getOptVal( $opts, 'seriesStorage', 'pie', 'show' ) === null ) {
            $this->setNestedOptVal( $opts, 'seriesStorage', 'pie', 'show', false );
        }
        
        $this->unsetOpt( &$opts, 'seriesStorage' );
        $this->unsetOpt( &$opts, 'seriesDefault' );

        return $this->makeJSArray($opts);
    }

    /**
     * Retrieves a nested value or null
     * @param array $opts
     * @param mixed $option
     * @return Ambigous <>|NULL|multitype:
     */
    protected function getOptVal(array $opts, $option)
    {
        $ploded = explode('.', $option);
        $arr = $opts;
        $val = null;
        while ($curr = array_shift($ploded)) {
            if (isset($arr[$curr])) {
                if (is_array($arr[$curr])) {
                    $arr = $arr[$curr];
                } else {
                    return $arr[$curr];
                }
            } else {
                return null;
            }
        }
    }

    /**
     * Sets a value in a nested array based on a dot-concatenated string
     * Used primarily for mapping
     * @param array $opts
     * @param string $mapperString
     * @param mixed $val
     */
    protected function setOpt(array &$opts, $mapperString, $val)
    {
        $args = explode( '.', $mapperString );
        array_unshift( $args, &$opts );
        array_push( $args, $val );
        call_user_func_array( array( $this, 'setNestedOptVal' ), $args );
    }

    /**
     * Handles nested mappings 
     * @param array $opts
     * @param string $mapperString
     */
    protected function unsetOpt(array &$opts, $mapperString)
    {
        $ploded = explode('.', $mapperString);
        $arr = &$opts;
        while ($curr = array_shift($ploded)) {
            if (isset($arr[$curr])) {
                if (is_array($arr[$curr])) {
                    $arr = &$arr[$curr];
                } else {
                    unset($arr[$curr]);
                }
            }
        }
    }

    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Highlightable::useHighlighting()
     */
    public function useHighlighting(array $opts = array('size'=>7.5))
    {
        $this->highlighting = true;

        return $this->setNestedOptVal( $this->options, 'grid', 'hoverable', true )
                    ->setNestedOptVal( $this->options, 'grid', 'autoHighlight', true );
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Cursorable::useCursor()
     */
    public function useCursor()
    {
        return $this->setNestedOptVal( $this->options, 'cursor', array('show' => true, 'showTooltip' => true) );
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Datable::useDates()
     */
    public function useDates($axis = 'x')
    {
        $this->dateAxes[$axis] = true;
        
        $this->setNestedOptVal( $this->options, $axis.'axis', 'mode', 'time' );
        $this->setNestedOptVal( $this->options, $axis.'axis', 'timeformat', '%d-%b-%y' );

        array_push($this->files, 'jquery.flot.time.js');

        return $this;
    }

    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Zoomable::useZooming()
     */
    public function useZooming( array $options = array('mode'=>'xy') )
    {
        $this->zooming = true;
        $this->setNestedOptVal( $this->options, 'selection', 'mode', $options['mode'] );
        $this->files[] = 'jquery.flot.selection.js';
        return $this;
    }

    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Griddable::setGrid()
     */
    public function setGrid(array $opts)
    {

        $gridMapping = array('on'=>'show',
                             'background'=>'backgroundColor'
                            );
        
        foreach ($opts as $key=>$value) {
            if ( array_key_exists( $key, $this->nativeOpts['grid'] ) ) {
                $this->setNestedOptVal( $this->options, 'grid', $key, $value );
            } else if ( isset( $gridMapping[$key] ) ) {
                $this->setNestedOptVal( $this->options, 'grid', $gridMapping[$key], $value );
            }
        }

        return $this;

    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Legendable::setLegend()
     */
    public function setLegend(array $opts = array('on' => 'true', 
                                                  'location' => 'ne', 
                                                  'x' => 0, 
                                                  'y' => 0))
    {
        $opts['on']       = isset($opts['on']) ? $opts['on'] : true;
        $opts['location'] = isset($opts['location']) ? $opts['location'] : 'ne';

        $legendMapper = array('on'       => 'show',
                              'location' => 'position');

        foreach ($opts as $key=>$val) {
            if ( array_key_exists($key, $this->nativeOpts['legend']) ) {
                $this->setNestedOptVal( $this->options, 'legend', $key, $val );
            } else if ( in_array($key, array_keys($legendMapper)) ) {
                $this->setNestedOptVal( $this->options, 'legend', $legendMapper[$key], $val );
            }
        }

        $margin = array(
                    isset($opts['x']) ? $opts['x'] : 0, 
                    isset($opts['y']) ? $opts['y'] : 0
                );

        return $this->setNestedOptVal( $this->options, 'legend', 'margin', $margin );
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Fillable::setFill()
     */
    public function setFill($series, $opts = array('use'    => true,
                                                   'stroke' => false,
                                                   'color'  => null,
                                                   'alpha'  => null
                                                  ))
    {

        // @todo add a method of telling flot whether the series is a line, bar, point
        if ( isset( $opts['use'] ) && $opts['use'] == true ) {
            $this->setNestedOptVal( $this->options, 'seriesStorage', $this->getSeriesTitle( $series ), 'line', 'fill', true );
            
            if ( isset( $opts['color'] ) ) {
                $this->setNestedOptVal( $this->options, 'seriesStorage', $this->getSeriesTitle( $series ), 'line', 'fillColor', $opts['color'] );
            }
        }

        return $this;
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Shadowable::setShadow()
     */
    public function setShadow($series, $opts = array('use'    => true,
                                                     'angle'  => 45,
                                                     'offset' => 1.25,
                                                     'depth'  => 3,
                                                     'alpha'  => 0.1) )
    {
        
        if (! empty( $opts['use'] ) ) {
            $depth = ! empty( $opts['depth'] ) ? $opts['depth'] : 3;
            $this->setNestedOptVal( $this->options, 'seriesStorage', $this->getSeriesTitle( $series ), 'shadowSize', $depth );
        }

        return $this;
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Labelable::useSeriesLabels()
     */
    public function useSeriesLabels( $seriesTitle, array $labels = array() )
    {
        $this->useLabels = true;
        $this->seriesLabels[$seriesTitle] = $labels;
        return $this->setNestedOptVal( $this->options, 'seriesStorage', $seriesTitle, 'pointLabels', 'edgeTolerance', 3 );
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Labelable::setSeriesLabelSetting()
     */
    public function setSeriesLabelSetting( $seriesTitle, $name, $value )
    {
        // jqplot supports this, but we're just going to do global settings. overwrite at your own peril.
        $this->labelSettings[$name] = $value;
        return $this;
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Lineable::setSeriesLineWidth()
     */
    public function setSeriesLineWidth( $seriesTitle, $value )
    {
        return $this->setNestedOptVal( $this->options, 'seriesStorage', $seriesTitle, 'lines', 'linewidth', $value );
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Lineable::setSeriesShowLine()
     */
    public function setSeriesShowLine( $seriesTitle, $bool )
    {
        return $this->setNestedOptVal( $this->options, 'seriesStorage', $seriesTitle, 'lines', 'show', $bool );
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Lineable::setSeriesShowMarker()
     */
    public function setSeriesShowMarker( $seriesTitle, $bool )
    {
        return $this->setNestedOptVal( $this->options, 'seriesStorage', $seriesTitle, 'points', 'show', $bool );
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Lineable::setSeriesMarkerStyle()
     */
    public function setSeriesMarkerStyle( $seriesTitle, $value )
    {
        // jqplot compatibility preprocessing
        $value = str_replace('filled', '', $value);
        $value = strtolower($value);

        if (! in_array('jquery.flot.symbol.js', $this->files)) {
            $this->files[] = 'jquery.flot.symbol.js';
        }
        
        return $this->setNestedOptVal( $this->options, 'seriesStorage', $seriesTitle, 'points', 'symbol', $value );
    }
    
    /**
     * (non-PHPdoc)
     * @see \Altamira\JsWriter\Ability\Lineable::setSeriesMarkerSize()
     */
    public function setSeriesMarkerSize( $seriesTitle, $value )
    {
        return $this->setNestedOptVal( $this->options, 'seriesStorage', $this->getSeriesTitle( $seriesTitle ), 'points', 'radius', (int) ($value / 2) );
    }

    /**
     * Responsible for setting the tick labels on a given axis
     * @param string $axis
     * @param array $ticks
     * @return \Altamira\JsWriter\Flot
     */
    public function setAxisTicks($axis, array $ticks = array() )
    {
        if ( in_array($axis, array('x', 'y') ) ) {

            $isString = false;
            $alternateTicks = array();
            $cnt = 1;

            foreach ($ticks as $tick) {
                if (!(ctype_digit($tick) || is_int($tick))) {
                    $isString = true;
                    // this is O(2N) so deal with it
                    foreach ( $ticks as $tick ) {
                        $alternateTicks[] = array($cnt++, $tick);
                    }
                    break;
                }
            }
            
            $this->setNestedOptVal( $this->options, $axis.'axis', 'ticks', $isString ? $alternateTicks : $ticks );

        }

        return $this;
    }

    /**
     * Prepares default values for a series array
     * @param array $opts
     */
    public function prepOpts( &$opts = array() )
    {
        $opts = is_null( $opts ) ? array() : $opts ;
        if (   (!(isset($this->types['default']) && $this->types['default'] instanceOf \Altamira\Type\Flot\Bubble))
            && (!(isset($this->types['default']) && $this->types['default'] instanceOf \Altamira\Type\Flot\Bar))
                ) {
            if ( (! isset($this->options['seriesStorage']['points'])) && (!isset($opts['points']) || !isset($opts['points']['show'])) ) {
                // show points by default
                $this->setNestedOptVal( $opts, 'points', 'show', true );
            }
            
            if ( (! isset($this->options['seriesStorage']['lines'])) && (!isset($opts['lines']) || !isset($opts['lines']['show'])) ) {
                // show lines by default
                $this->setNestedOptVal( $opts, 'lines', 'show', true );
            }
        }
    }

    // maps jqplot-originating option data structure to flot
    private $optsMapper = array('axes.xaxis.tickInterval' => 'xaxis.tickSize',
                                'axes.xaxis.min'          => 'xaxis.min',
                                'axes.xaxis.max'          => 'xaxis.max',
                                'axes.xaxis.mode'         => 'xaxis.mode',
                                'axes.xaxis.ticks'        => 'xaxis.ticks',

                                'axes.yaxis.tickInterval' => 'yaxis.tickSize',
                                'axes.yaxis.min'          => 'yaxis.min',
                                'axes.yaxis.max'          => 'yaxis.max',
                                'axes.yaxis.mode'         => 'yaxis.mode',
                                'axes.yaxis.ticks'        => 'yaxis.ticks',

                                'legend.show'             => 'legend.show',
                                'legend.location'         => 'legend.position',
                                'seriesColors'            => 'colors',
                                );


    // api-native functionality
    private $nativeOpts = array('legend' => array(  'show'=>null,
                                                    'labelFormatter'=>null,
                                                    'labelBoxBorderColor'=>null,
                                                    'noColumns'=>null,
                                                    'position'=>null,
                                                    'margin'=>null,
                                                    'backgroundColor'=>null,
                                                    'backgroundOpacity'=>null,
                                                    'container'=>null),

                                'xaxis' => array(   'show'=>null,
                                                    'position'=>null,
                                                    'mode'=>null,
                                                    'color'=>null,
                                                    'tickColor'=>null,
                                                    'min'=>null,
                                                    'max'=>null,
                                                    'autoscaleMargin'=>null,
                                                    'transform'=>null,
                                                    'inverseTransform'=>null,
                                                    'ticks'=>null,
                                                    'tickSize'=>null,
                                                    'minTickSize'=>null,
                                                    'tickFormatter'=>null,
                                                    'tickDecimals'=>null,
                                                    'labelWidth'=>null,
                                                    'labelHeight'=>null,
                                                    'reserveSpace'=>null,
                                                    'tickLength'=>null,
                                                    'alignTicksWithAxis'=>null,
                                                ),

                                'yaxis' => array(   'show'=>null,
                                                    'position'=>null,
                                                    'mode'=>null,
                                                    'color'=>null,
                                                    'tickColor'=>null,
                                                    'min'=>null,
                                                    'max'=>null,
                                                    'autoscaleMargin'=>null,
                                                    'transform'=>null,
                                                    'inverseTransform'=>null,
                                                    'ticks'=>null,
                                                    'tickSize'=>null,
                                                    'minTickSize'=>null,
                                                    'tickFormatter'=>null,
                                                    'tickDecimals'=>null,
                                                    'labelWidth'=>null,
                                                    'labelHeight'=>null,
                                                    'reserveSpace'=>null,
                                                    'tickLength'=>null,
                                                    'alignTicksWithAxis'=>null,
                                                ),

                                 'xaxes' => null,
                                 'yaxes' => null,

                                 'series' => array(
                                                    'lines' => array('show'=>null, 'lineWidth'=>null, 'fill'=>null, 'fillColor'=>null),
                                                    'points'=> array('show'=>null, 'lineWidth'=>null, 'fill'=>null, 'fillColor'=>null),
                                                    'bars' => array('show'=>null, 'lineWidth'=>null, 'fill'=>null, 'fillColor'=>null),
                                                  ),

                                 'points' => array('radius'=>null, 'symbol'=>null),

                                 'bars' => array('barWidth'=>null, 'align'=>null, 'horizontal'=>null),

                                 'lines' => array('steps'=>null),

                                 'shadowSize' => null,

                                 'colors' => null,

                                 'grid' =>  array(  'show'=>null,
                                                    'aboveData'=>null,
                                                    'color'=>null,
                                                    'backgroundColor'=>null,
                                                    'labelMargin'=>null,
                                                    'axisMargin'=>null,
                                                    'markings'=>null,
                                                    'borderWidth'=>null,
                                                    'borderColor'=>null,
                                                    'minBorderMargin'=>null,
                                                    'clickable'=>null,
                                                    'hoverable'=>null,
                                                    'autoHighlight'=>null,
                                                    'mouseActiveRadius'=>null
                                                )


                                );

    const ZOOMING_FUNCTION = <<<ENDSCRIPT
placeholder.bind("plotselected", function (event, ranges) {
    jQuery.plot(placeholder, %s,
      $.extend(true, {}%s, {
      xaxis: { min: ranges.xaxis.from, max: ranges.xaxis.to },
      yaxis: { min: ranges.yaxis.from, max: ranges.yaxis.to }
  }));
});
placeholder.on('dblclick', function(){ plot.clearSelection(); jQuery.plot(placeholder, %s%s); });
ENDSCRIPT;
    
    const LABELS_FUNCTION = <<<ENDJS
var pointLabels = %s;

$.each(plot.getData()[0].data, function(i, el){
    var o = plot.pointOffset({
        x: el[0], y: el[1]});
        $('<div class="data-point-label">' + pointLabels[el[0] + ',' + el[1]] + '</div>').css( {
            position: 'absolute',
            left: o.left%s%s,
            top: o.top-5%s%s,
            display: 'none',
            'font-size': '10px'
        }).appendTo(plot.getPlaceholder()).fadeIn('slow');
});
ENDJS;
    
    const HIGHLIGHTING_FUNCTION = <<<ENDJS

function showTooltip(x, y, contents) {
    $('<div id="flottooltip">' + contents + '</div>').css( {
        position: 'absolute',
        display: 'none',
        top: y + 5,
        left: x + 5,
        border: '1px solid #fdd',
        padding: '2px',
        'background-color': '#fee',
        opacity: 0.80
    }).appendTo("body").fadeIn(200);
}

var previousPoint = null;

placeholder.bind("plothover", function (event, pos, item) {
    if (item) {
        if (previousPoint != item.dataIndex) {
            previousPoint = item.dataIndex;

            $("#flottooltip").remove();
            var x = item.datapoint[0].toFixed(2),
                y = item.datapoint[1].toFixed(2);

            showTooltip(item.pageX, item.pageY,
                        %s);
        }
    }
    else {
        $("#flottooltip").remove();
        previousPoint = null;
    }
});
ENDJS;
   
}