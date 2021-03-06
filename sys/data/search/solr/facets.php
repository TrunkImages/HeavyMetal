<?

/**
 * Contains a list of facets for a filter
 */
uses('system.data.search.solr.facet');

class Facets
{
	public $fields=array();	/** List of fields to facet */	
	private $model=null;		/** Reference to the filter's model */
	private $filter=null;		/** Reference to filter object */
	public $config=array(); /** any addt'l facet parameters */

	
	/*
	 * Sample parameters:  facet.mincount, facet.limit, 
	 * facet.date=timestamp&facet.date.start=NOW/DAY-5DAYS&facet.date.end=NOW/DAY%2B1DAY&facet.date.gap=%2B1DAY
	 */
	
	/**
	 * Constructor
	 * 
	 * @param Model $model A reference to the model being filtered/sorted
	 */
	public function __construct($filter,$model)
	{
		$this->filter=$filter;
		$this->model=$model;
	}
	
	
	/**
	 * Allows us to declare ordering by requesting "properties" of the object by field name.
	 * When a property is requested, a new Facet class is created for the requested
	 * field, or a pre-existing one is returned if it already exists.
	 */
	function __get($field_name)
   	{
   		if (isset($this->model->fields[$field_name]))
   		{
			if (! ($this->fields[$field_name] instanceof Facet))
   			$this->fields[$field_name] = new Facet($field_name);
   		}
   		
   		return $this->fields[$field_name];
   	}
   	

   	function __set($field_name, $prop_value)
   	{
   		$this->config[$field_name] = $prop_value;
   	}
   	
}
 