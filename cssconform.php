<?php 
header("Content-type: text/css", true);

if(!isset($_GET['f'])) die('No File Provided');
$file = $_SERVER['DOCUMENT_ROOT'].$_GET['f']; 

class RegEx {
	public $comments = '!/\*.*?\*/!s';
	public $properties = '/(?:[\*@.#a-z\:0-9][^{]+)?{(?:[^{}]+|(?R))*}/s';
	public $aniproperties = '/(?:[0-9][^{]+)?{(?:[^{}]+|(?R))*}/s';
	public $brackets = '/\{|\}/';
}

class StyleSheet {
	
	public $loaded = false;
	public $exists = null;
	public $raw = null;
	public $path = null;
	public $regex = null;
	public $media = array();
	public $keyframes = array();
	public $data = array();
	
	public $prefixes = array('moz', 'webkit', 'o', 'ms', 'khtml');
	public $prefixProps = array('background-size', 'backface-visibility', 'opacity', 'border-radius', 'transition', 'animation', 'perspective', 'transform' );
	
	function __construct( $path = null ) {
		
		$this->regex = new RegEx();
		
		$this->path = $path;
		$this->exists = file_exists($path);
		
		if($this->exists){
			$this->path = $path;
			//Strip Comments
			$content = preg_replace($this->regex->comments, '', file_get_contents( $path ));
			$this->content = str_replace('{',' {', $content );
			$this->loaded = true;
		}
    }
	
	private function isPrefixed( $array, $search ) {
		foreach($array as $val){
			$find = strpos($search, $val);
			if ($find === 0){
				return true;
				break;
			}
		}
	}
	
	public function applyPrefixes( $data = array() ){
		
		
		if(!empty($data)){
			foreach( $data as $name => $props ){
				foreach( $props as $prop => $val ){
					$pcombined = '';
					
					
					if($this->isPrefixed( $this->prefixProps, $prop  )){
						foreach( $this->prefixes as $prefix ){
							$pval = null;
							if($prop == 'transition'){
								$pval = $val;
								$trans_vals = explode(',', $val );
								foreach($trans_vals as $tv){
									$tv_props = $pair = explode(' ', $tv );
									$tv_prop = array_shift($tv_props);
									if($this->isPrefixed( $this->prefixProps, $tv_prop  )){
										$pval .= ', -'.$prefix.'-'.$tv_prop.' '.implode(' ',$tv_props);
									}
								}
							}
							
							$data[$name]['-'.$prefix.'-'.$prop] = !empty($pval) ? $pval : $val;
						}
					}
				}
			}
		}
		
		return $data;
		
	}
	
	public function modernize( $data = null ){
		
		$root = false;
		
		if( empty( $data ) ){ 
			$data = $this->data;
			$root = true;
		}
		
		if(!empty($data['styles'])){
			 $this->data['styles'] = $this->applyPrefixes($data['styles']);
		}
		
		if(!empty($data['media'])){
			foreach( $data['media'] as $name => $media ){
				if(!empty( $media['styles'])){
					 $this->data['media'][$name]['styles'] = $this->applyPrefixes($media['styles']);
				}
			}
		}
		
		if(!empty($data['keyframes'])){
			foreach( $data['keyframes'] as $name => $keyframes ){
				if(!empty( $keyframes['frames'] )){
					$frames = $this->applyPrefixes($keyframes['frames']);
					$this->data['keyframes'][$name]['frames'] = $frames;
					foreach( $this->prefixes as $prefix ){
						$this->data['keyframes'][str_replace('@', '@-'.$prefix.'-' ,$name )]['frames'] = $frames;
					}
				}
			}
		}
		
		return true;
	}
	
	public function parseStyle($style = null){
		
		$sel = explode('{', $style);
		
	
		$selector = preg_replace( '/\r?\n/', ' ', trim($sel[0], ' ') );
		$selector = preg_replace( '!\s+!', ' ', $selector );
		
		$rawprops = preg_split($this->regex->brackets, $style);
		$attributes = split(';', $rawprops[1] );
		$props = array();
		foreach( $attributes as $ii => $a ){
			$pair = explode(':', $attributes[$ii] );
			$prop = trim(array_shift($pair));
			$val = trim( implode(':',$pair), ' ' );
			if(!empty($prop)) $props[strtolower($prop)] = $val;
		}
	
		return array( 'selector' => $selector, 'properties' => $props );
	}
	
	
	
	public function parseMedia( $content = null ){
		
		$sel = explode('{', $content);
		$selector = trim($sel[0]);
		$inner = trim( str_replace( $selector, '', $content ), ' \{\}' );
		$styles = $this->parse($inner);
		return array( 'query' => $selector, 'styles' => $styles );
	}
	
	public function parseAnimation( $content = null ){
		//print_r($content);
		$sel = explode('{', $content);
		$selector = trim($sel[0]);
		//print_r($selector);
		$inner = trim( str_replace( $selector, '', $content ), ' \{\}' );
		$styles = $this->parse($inner);
		return array( 'query' => $selector, 'frames' => $styles['styles'] );
	}
	
	public function parse( $content = null ){
		
		$root = false;
		$data = array( 
			'keyframes' => array(), 
			'media' => array(), 
			'styles' => array() 
		);
		
		if( empty($content) ){ $root = true; $content = $this->content; }
		
		$content = str_replace('@charset "utf-8";','',$content);
		preg_match_all( $this->regex->properties, $content, $results);
	
		foreach( $results[0] as $style ){
			
			$style = trim($style, ' ');
			if($style[0] === '@') {
				if(strpos($style, '@media') === 0) {
					$_media = $this->parseMedia( $style );
					$data['media'][$_media['query']] = $_media['styles'];
				}elseif(strpos($style, '@keyframe') === 0) {
					$_ani = $this->parseAnimation( $style );
					
					$data['keyframes'][$_ani['query']] = $_ani;
				}
			}else{
				$_style = $this->parseStyle( $style );
				if(isset($data['styles'][$_style['selector']])){
					$data['styles'][$_style['selector']] = array_merge( $data['styles'][$_style['selector']], $_style['properties'] );
				}else{
					$data['styles'][$_style['selector']] = $_style['properties'];
				}
			}
		}
		
		if($root) $this->data = $data;
		return $data;
	}
	
	public function printSheet( $data = null ){
		if(empty( $data )) $data = $this->data;
		if(count($data['keyframes']) > 0){
			
			foreach( $data['keyframes'] as $name => $keyframe ){
				$cssText = '';
				$cssText .= $name . '{'."\n";
				foreach( $keyframe['frames'] as $kname => $vals ){
					$cssText .= "    ".$kname . '{'."\n";
				//	$cssText .= "    ".$pname.':'.$val.';'."\n";
					foreach( $vals as $pname => $val){
						$cssText .= "    "."    ".$pname.':'.trim($val).';'."\n";
					}
					$cssText .= "    ".'}'."\n";
				}
				$cssText .= '}';
				echo $cssText."\n\n";
			}
		}
		
		foreach( $data['styles'] as $name => $props ){
			$cssText = '';
			$cssText .= $name . '{'."\n";
			foreach( $props as $pname => $val ){
				$cssText .= "    ".$pname.':'.$val.';'."\n";
			}
			$cssText .= '}';
			echo $cssText."\n\n";
		}
		
		foreach( $data['media'] as $name => $media ){
			$cssText = '';
			$cssText .= $name . '{'."\n";
			echo $cssText;
			$this->printSheet( $media );
			$cssText = '}';
			echo $cssText."\n";
			
		}
	}
	
	
}

echo '@charset "utf-8";'." \n\n";

$sheet = new StyleSheet($file);
if($sheet->loaded){
	$sheet->parse();
	$sheet->modernize();
	$sheet->printSheet();
	//print_r($sheet);
}