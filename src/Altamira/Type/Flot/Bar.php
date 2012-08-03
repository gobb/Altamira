<?php

namespace Altamira\Type\Flot;

class Bar extends \Altamira\Type\TypeAbstract
{

    protected $options = array('lines'    =>    array('show' => false),
                               'bars'     =>    array('show' => true),
                               'points'   =>    array('show' => false)
                              );
    
	public function getOptions()
	{
		return $this->options;
	}

	public function getRendererOptions()
	{
	}

	public function getUseTags()
	{
		if(isset($this->options['horizontal']) && $this->options['horizontal'])
			return true;

		return false;
	}
	
	public function setOption($name, $value)
	{
	    switch ($name) {
	        case 'horizontal':
	            $this->options['bars']['horizontal'] = $value;
                break;	        
	        default:
	            parent::setOption($name, $value);
	    }
	    
	    return $this;
	}
}

?>