<?
/**
 * The main request dispatcher.
 * 
 * @copyright     Copyright 2009-2012 Jon Gilkison and Trunk Archive Inc
 * @package       application
 * 
 * Copyright (c) 2009, Jon Gilkison and Trunk Archive Inc.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * - Redistributions of source code must retain the above copyright notice,
 *   this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright
 *   notice, this list of conditions and the following disclaimer in the
 *   documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * This is a modified BSD license (the third clause has been removed).
 * The BSD license may be found here:
 * 
 * http://www.opensource.org/licenses/bsd-license.php
 */

uses('sys.app.screen');
uses('sys.app.request');
uses('sys.app.controller');
uses('sys.app.attribute_reader');

/**
 * Base Dispatcher Exception
 * 
 * @package		application
 * @subpackage	dispatcher
 */
class DispatcherException extends Exception {}

/**
 * Controller not found exception
 * 
 * @package		application
 * @subpackage	dispatcher
 */
class ControllerNotFoundException extends DispatcherException {}

/**
 * Controller method not found exception
 * 
 * @package		application
 * @subpackage	dispatcher
 */
class ControllerMethodNotFoundException extends DispatcherException {}

/**
 * Ignored method called exception
 * 
 * @package		application
 * @subpackage	dispatcher
 */
class IgnoredMethodCalledException extends DispatcherException {}

/**
 * Responsible for dispatching requests to controllers and rendering views.
 * 
 * @package		application
 * @subpackage	dispatcher
 * @link          http://wiki.getheavy.info/index.php/Dispatcher
 */
abstract class Dispatcher
{
	protected $latin_normalizer = array(
    'Š'=>'S', 'š'=>'s', 'Ð'=>'Dj','Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A',
    'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I',
    'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U',
    'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss','à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a',
    'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i',
    'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u',
    'ú'=>'u', 'ü'=>'u', 'û'=>'u', 'ý'=>'y', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y', 'ƒ'=>'f');
	
	protected $query=null;  // Need this when dispatching internally for portlets (no $_GET present)
	
	/**
	 * Root path to application's controller directory.
	 * @var string
	 */
	protected $controller_root;

	/**
	 * Root path to application's view directory.
	 * @var string
	 */
	protected $view_root;
	
	/**
	 * The URI being dispatched.
	 * @var string
	 */
	protected $path;

	/**
	 * Path broken into an array
	 * @var array
	 */
	protected $path_array=array();
	
	/**
	 * File path for located controller
	 * @var string
	 */
	protected $controller_path='';
	
	/**
	 * Segments of the URI following the path to the controller
	 * @var array
	 */
	protected $segments=array();
	
	/**
	 * The class file for the found controller
	 * @var string
	 */
	protected $controller='';
	
	/**
	 * The action/method to be called on the controller
	 * @var string
	 */
	protected $action='';
	
	/**
	 * The view to be rendered.
	 * @var string
	 */
	protected $view=null;
	
	/**
	 * The request type
	 * @var string
	 */
	protected static $req_type=null;
	
	/**
	 * Constructor
	 * 
	 * @param string $path The URI to dispatch
	 * @param string $controller_root The root path to where the application's controllers are
	 * @param string $view_root The root path to where the application's views are
	 * @param bool $use_routes Controls if routes are used.
	 * @param bool $force_routes If true, only URI's that match a route are callable.
	 */
	public function __construct($path=null,$controller_root=null,$view_root=null,$use_routes=true,$force_routes=false)
	{
		$this->controller_root=($controller_root) ? $controller_root : PATH_APP.'controller/';
		$this->view_root=($view_root) ? $view_root : PATH_APP.'view/';
		$this->path=$path;
		
		$this->use_routes=$use_routes;
		$this->force_routes=$force_routes;
					
		$this->parse_path();
		
		// preload helpers
		$conf=Config::Get('helpers');
		if ($conf->auto->items)
      		foreach($conf->auto->items as $helper)
            	uses("helper.$helper");
	}
	
	/**
	 * Recursively parses uri segments to find a file match
	 */
	protected function recurse_segment(&$segments)
	{
		if ((count($segments) > 0) && (file_exists($this->controller_root . $this->controller_path . $segments[0] . EXT)))
		{
			$this->controller = $segments[0];
			$segments = array_slice($segments, 1);

			if (count($segments) >= 1)
			{
				$this->action = str_replace('-','_',$segments[0]);
				$segments = array_slice($segments, 1);
			}

			return true;
		}

		if ((count($segments) > 0) && (is_dir($this->controller_root . $this->controller_path . $segments[0])))
		{
			// Set the directory and remove it from the segment array
			$this->controller_path .= $segments[0] . '/';
			$segments = array_slice($segments, 1);

			if (count($segments) == 0)
				$segments[0] = 'index';

			return $this->recurse_segment($segments);
		}

		return false;
	}

	/**
	 * Parses a URI into a request structure.
	 */
	protected function parse_path()
	{
		if (!$this->path)
			$this->path='/';
			
		// If using routes ...
		if ($this->use_routes)
			try
			{
				$routes=Config::Get('routes');
				$route=(isset($routes->items['default'])) ? $routes->items['default'] : '';
				$found=false;
				if (($route) && ($route->routes))
					foreach($route->routes->items as $key => $val)
					{
						if (preg_match('#^'.$key.'$#', $this->path))
						{
							$matches=array();
							preg_match('#^'.$key.'$#', $this->path,$matches);
							$this->path=preg_replace('#^'.$key.'$#',$val,$this->path);
							$found=true;
							break;
						}
					}
				
				if ((($route) && ($route->required==TRUE)) && (!$found))
					throw new NotFoundException();
			}
			catch(ConfigException $ex)
			{
				
			}
		// explode it's segments
		$path_array = explode('/', preg_replace('|/*(.+?)/*$|', '\\1', $this->path));
		$segments = array();
		$this->path_array=$path_array;
		// If it's the root uri, this is easy fo sheezy.
		if ($this->path == '/' || $this->path == '')
		{
			// set the controller and method
			$this->controller_path = '';
			$this->controller = 'index';
			$this->action= 'index';
			$this->segments = array ();
			$this->path_array = $path_array;
		}
		else
		{
			// parse the segments out for security
			foreach ($path_array as $val)
			{
				$val = strtr($val, $this->latin_normalizer);

				if (!preg_match('|^[a-z 0-9~%".:_\-\+\(\);&]+$|i', $val))
					$val=preg_replace('|[^a-z 0-9~%".:_\-\+\(\);&]*|i','',$val);

				$val = trim($val);

				if ($val != '')
					$segments[] = $val;
			}			
		}
		// setup the parsed uri result
		$this->controller_path = '';
		$this->controller = 'index';
		$this->action = 'index';
		
		// Does the requested controller exist in the root folder?
		if (count($segments)>0)
		{
			if (file_exists($this->controller_root  . '/' . $segments[0] . EXT))
			{
				$this->controller = $segments[0];
				$segments = array_slice($segments, 1);
	
				if (count($segments) > 0)
				{
					$this->action = $segments[0];
					$segments = array_slice($segments, 1);
				}
			}
			// Is the controller in a sub-folder?
			else if (is_dir($this->controller_root . $segments[0]))
				$this->recurse_segment($segments);
			else
				$this->action = $segments[0];
		
			if ((count($segments)>0) && ($this->action=='index')) 
			{
				$this->action = $segments[0];
				$segments=array_slice($segments,1);
			}
		}
		
		
		$this->segments=$segments;
	}	

	/**
	 * Builds a Request
	 * 
	 * @param $root
	 * @return Request
	 */
	abstract function build_request($root=null);
	
	/**
	 * Returns a new instance of a dispatcher
	 * 
	 * @param string $path The URI to dispatch
	 * @param string $controller_root The root path to where the application's controllers are
	 * @param string $view_root The root path to where the application's views are
	 * @param bool $use_routes Controls if routes are used.
	 * @param bool $force_routes If true, only URI's that match a route are callable.
	 */
	abstract function new_instance($path=null,$controller_root=null,$view_root=null,$use_routes=true,$force_routes=false);
	
	public function find()
	{
		if (!file_exists($this->controller_root.$this->controller_path.$this->controller.EXT))
			throw new ControllerNotFoundException("Could not find a suitable controller: ".$this->controller_root.$this->controller_path.$this->controller.EXT);

		require_once($this->controller_root.$this->controller_path.$this->controller.EXT);
		$classname=str_replace('/','',$this->controller_path).$this->controller.'Controller';
		
		if (!class_exists($classname))
			throw new ControllerNotFoundException("'$classname' can not be found in '".$this->controller."'.");
			
		$request_method = Request::get_request_method();
		$found_action=find_methods($classname, $request_method."_".str_replace('-','_',$this->action), str_replace('-','_',$this->action));

		if (!$found_action)
		{
			$found_action=find_methods($classname, $request_method."_index", 'index');
   			array_unshift($this->segments,$this->action);  // so here we put that mistakenly stripped parameter back on.
		}
		
		if (!$found_action)
		{
			throw new ControllerMethodNotFoundException("Could not find an action to call.");
		}
		
		
		// Handle the fact that some URIs contain extra segments that are not part of the controller/action root
		$root = $this->controller_path . 
			(($this->controller!='index') ? $this->controller . "/" : "") .
			(($found_action!='index' && $found_action!='index') ? $found_action : "");

		$root = rtrim($root,'/');			
		
		return array(
			'request_method' => $request_method,
			'classname' => $classname,
			'found_action' => $found_action,
			'root' => $root
		);
	}
	
	/**
	 * Executes a controller, returning the data
	 *
	 * @return array The data from the executed controller.
	 */
	public function call()
	{
		$data = array(); // any data to return to the view from the controller
		
		$cfound=$this->find();
		$request_method=$cfound['request_method'];
		$classname=$cfound['classname'];
		$found_action=$cfound['found_action'];
		$root=$cfound['root'];
		$this->action=$found_action;
		
		if (!file_exists($this->controller_root.$this->controller_path.$this->controller.EXT))
			throw new ControllerNotFoundException("Could not find a suitable controller: ".$this->controller_root.$this->controller_path.$this->controller.EXT);
			
		require_once($this->controller_root.$this->controller_path.$this->controller.EXT);
		$classname=str_replace('/','',$this->controller_path).$this->controller.'Controller';
		
		if (!class_exists($classname))
			throw new ControllerNotFoundException("'$classname' can not be found in '".$this->controller."'.");

		$request_method = Request::get_request_method();
		$found_action=find_methods($classname, $request_method."_".str_replace('-','_',$this->action), str_replace('-','_',$this->action));

		if (!$found_action)
		{
			$found_action=find_methods($classname, $request_method."_index", 'index');
   			array_unshift($this->segments,$this->action);  // so here we put that mistakenly stripped parameter back on.
		}
		
		if (!$found_action)
		{
			throw new ControllerMethodNotFoundException("Could not find an action to call.");
		}
		
		$this->action=$found_action;
		
		// Handle the fact that some URIs contain extra segments that are not part of the controller/action root
		$root = $this->controller_path . 
			(($this->controller!='index') ? $this->controller . "/" : "") .
			(($this->action!='index' && $found_action!='index') ? $this->action : "");

		$root = rtrim($root,'/');		
			
		$request=$this->build_request($root);
		$class=new $classname($request);
		
		if ((isset ($class->ignored)) && (in_array($this->action, $class->ignored)))
			throw new IgnoredMethodCalledException("Ignored method called.");
		
		$meta=AttributeReader::MethodAttributes($class,$found_action);
			
		// Call the before screens	
		$screen_data=array();
		$method_args=$this->segments;
		Screen::Run('before',$class,$meta,$screen_data,$method_args);
		// call the method and pass the segments (add returned data to any initially returned by screens)	
		
		$data = call_user_func_array(array(&$class, $found_action), $method_args);
		if (is_array($data))
			$data=array_merge($screen_data,$data);
		else
			$data=$screen_data;
		// Call the after screens
		Screen::Run('after',$class,$meta,$data,$method_args);
				
		$class->session->save();
		
		if ($class->view)
			$this->view=$class->view;
		else if ($meta->view)
			$this->view=$meta->view;
			
		$data['controller']=&$class;
		$data['session']=&$class->session;
		return $data;
	}

	/**
	 * Transforms the data into it's final output represetnation.  Basically, renders a view.
	 * 
	 * @param array $data The data to render in the view.
	 * @param string $req_type The request type, overrides the "auto-detect" mode.
	 */
	abstract function transform(&$data, $req_type=null);


	/**
	 * Dispatches the URI to it's controller and transforms the output.
	 * 
	 * @param string $req_type Request type.  Optional.  Overrides the "auto-detected" request type (HTML, Text, AJAX, etc.)
	 */
	public function dispatch($req_type=null)
	{
		$data=$this->call();
		
		return $this->transform($data,$req_type);
	}
	
	/**
	 * Returns the request type
	 */
	public static function RequestType()
	{
		return self::$req_type;
	}
}
