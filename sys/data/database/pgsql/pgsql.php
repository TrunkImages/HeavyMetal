<?
/**
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
*
*/

uses('system.data.database');
uses('system.data.database.pgsql.pgsql_result');
uses("system.app.dynamic_object");
uses('system.debug.collector');

/**
 * Driver for PGSQL
 */
class PGSQLDatabase extends Database
{
    private $config=null;
    private $connection=null;
    private $connstr='';

    /**
     * Constructor 
     * 
     * @param $dsn string DSN for the connection
     */
    public function __construct($dsn)
    {
		$this->config=parse_url($dsn);
		if (!$this->config)
		    throw new DatabaseException("Invalid dsn '$dsn'.");
		
		
		$this->connstr='dbname='.trim($this->config['path'],'/');
		
		if (isset($this->config['host']))
		    $this->connstr.=' host='.$this->config['host'];
		if (isset($this->config['user']))
		    $this->connstr.=' user='.$this->config['user'];
		if (isset($this->config['port']))
			$this->connstr.=' port='.$this->config['port'];
		if (isset($this->config['pass']))
			$this->connstr.=' password='.$this->config['pass'];
			
		$this->connection=pg_connect($this->connstr);
		if (!$this->connection)
			throw new DatabaseException("Invalid database settings.");
    }


    /**
     * Determines if the driver supports a specific feature.
     *
     * @param string $feature
     */
    public function supports($feature) { return true; }
    


    /**
     * Performs an insert
     *
     * @param string $table_name Name of the table to update
     * @param array $fields Name/value pair of fields to update/insert
     */
    public function insert($table_name,$key,$fields)
    {
    	// extract the keys and values so we can build a prepared statement.

		$keys=array_keys($fields);
    	$vals=array_values($fields);
    	
    	$sql="insert into $table_name (".implode(',',$keys).") values (";
    	for($i=1; $i<=count($vals); $i++)
    		$sql.='$'.$i.',';
    	$sql=trim($sql,',');

   		$sql.=") returning $key";
   		
		Collector::StartQuery($sql, $vals);
    	$res=pg_query_params($this->connection,$sql,$vals);
    	if (!$res)
    	{
    		throw new DatabaseException(pg_last_error($this->connection));
    	}
    		
    	$row=pg_fetch_array($res);
    	Collector::EndQuery($sql);
    	
    	// return the id
    	return $row[0];
    }

    /**
     * Performs an update
     *
     * @param string $table_name Name of the table to update
     * @param string $key The name of the primary key
     * @param mixed $id The value of the key
     * @param array $fields Name/value pair of fields to update/insert
     */
    public function update($table_name,$key,$id,$fields)
    {
    	// extract the keys and values so we can build a prepared statement.
    	$keys=array_keys($fields);
    	$vals=array_values($fields);
    	
    	$sql="update $table_name set ";
    	
    	$i=0;
    	for($i=1; $i<=count($keys); $i++)
    		$sql.=$keys[$i-1].'=$'.$i.',';
    	$sql=trim($sql,',');

   		$sql.=" where $key=\$$i";
   		$vals[]=$id;
   		Collector::StartQuery($sql, $vals);
   		$res=pg_query_params($this->connection,$sql,$vals);
   		Collector::EndQuery($sql);
   		if (pg_affected_rows($res)<=0)
    		throw new DatabaseException("Could not update $table_name for $key=$id");
    		
    	return true;
    }

    /**
     * Performs a delete
     *
     * @param string $table_name Name of the table to update
     * @param string $key The name of the primary key
     * @param mixed $id The value of the key
     */
    public function delete($table_name,$key,$id)
    {
    	$sql="delete from $table_name where $key=\$1";

    	Collector::StartQuery($sql);
		$res=pg_query_params($this->connection,$sql,array($id));
		Collector::EndQuery($sql);
		
    	return (pg_affected_rows($res)>0);
    }

    /**
     * Executes a query statement
     *
     * @param string $query Query to execute.
     * @param int $offset Offset into the records to fetch
     * @param int $limit The number of records to fetch
     */
    public function execute($query,$offset=null,$limit=null)
    {
    	if ($offset)
    		$query.=" OFFSET $offset";
    		
    	if ($limit)
    		$query.=" LIMIT $limit";
    	
    	Collector::StartQuery($query);
    	$res=pg_query($this->connection,$query);
    	Collector::EndQuery($query);
    	
    	if (!$res)
    		throw new DatabaseException(pg_last_error($this->connection));
    	
    	return new PGSQLResult($res);
    }

	/**
	 * Fetches the row count for a given query
	 *
	 * @param string $key
	 * @param string $table_name
	 * @param string $where
	 */
	public function count($key,$table_name,$where=null,$distinct=false,$table_alias='')
	{
		$d=($distinct) ? 'distinct' : '';
		
		$sql="select $d count($key) from $table_name $table_alias";
		if ($where)
			$sql.=" $where;";
			
		return $this->get_one($sql);
	}


    /**
     * Executes an sql statement and returns the first value
     *
     * @param string $sql SQL to execute.
     */
    public function get_one($query)
    {
    	Collector::StartQuery($query);
    	$res=pg_query($this->connection,$query);
    	$row=pg_fetch_array($res);
    	Collector::EndQuery($query);
    	return $row[0];
    }

    /**
     * Executes an sql statement and returns the first row
     *
     * @param string $query Query to execute.
     */
    public function get_row($query)
    {
    	Collector::StartQuery($query);
    	$res=pg_query($this->connection,$query);
    	Collector::EndQuery($query);
    	
    	return pg_fetch_assoc($res);
    }

    /**
     * Fetches a single row by the table's primary key
     *
     * @param unknown_type $table_name
     * @param unknown_type $key
     * @param unknown_type $id
     */
    public function fetch_row($table_name,$key,$id)
    {
    	$query="SELECT * FROM $table_name WHERE $key=\$1";
    	  
    	Collector::StartQuery($query);
    	$res=pg_query_params($query,array($id));
   		Collector::EndQuery($query);
		
		return pg_fetch_assoc($res);
    }


    /**
     * Executes an sql statement and returns the results as an array
     *
     * @param string $query Query to execute.
     */
    public function get_rows($query)
    {
    	Collector::StartQuery($query);
    	$res=pg_query($this->connection,$query);
    	Collector::EndQuery($query);
    	return pg_fetch_all($res);
    }

    /**
     * starts a transaction
     */
    public function begin()
    {
		throw new Exception("Not implemented.");
    }

    /**
     * Ends a transaction.
     */
    public function commit()
    {
		throw new Exception("Not implemented.");
    }

	/**
	 * Escapes a value for a sql statement
	 * 
	 * @param mixed $value The value to escape
	 * @return string The escaped value
	 */
	public function escape_value($type,$value)
	{
   		$value=str_replace("'","''",$value);
		
		switch($type)
		{
			case Field::STRING:
			case Field::TEXT:
				return "'$value'";
			case Field::BOOLEAN:
				return ($value) ? "true" : "false";
			case Field::EPOCH:
				return ($value) ? $value : NULL;
			case Field::OBJECT:
				return "'".(($value instanceof DynamicObject) ? $value->to_string() : serialize($value))."'";
			default:
				if (is_numeric($value))
					return $value;
				else
					return "'$value'";
		}
	}    

	/**
	 * Parses a database array type into a php array
	 * 
	 * @param string $value
	 * @return array
	 */
	public function parse_array($value)
	{
		if ($value==null)
			return array();
		
		if (is_array($value))
			return $value;
			
		$value=trim($value,'{}');
		$result=explode(',',$value);
		
		for($i=0; $i<count($result); $i++)
			if ($result[$i]=='NULL')
				$result[$i]=null;
				
		return $result;
	}

	/**
	 * Collapses a php array into a database array type
	 * 
	 * @param array $value
	 * @return string
	 */
	public function collapse_array($value)
	{
		if (count($value)==0)
			return null;
			
		$result='';
		foreach($value as $val)
			if ($val==null)
				$result.='NULL,';
			else
				$result.="$val,";
		
		return 'ARRAY['.trim($result,',').']';
	}

	/**
	 * Returns the schemas in the database.
	 * 
	 * @return DatabaseResult
	 */
	public function schemas()
	{
		return $this->execute("SELECT nspname as schema FROM pg_namespace WHERE nspname NOT LIKE 'pg_%' AND nspname<>'information_schema'");
	}

	/**
	 * Returns the list of tables and views for a given schema.
	 * 
	 * @param string $schema
	 * @return DatabaseResult
	 */
	public function tables($schema)
	{
	  	$sql="select tablename from pg_tables where tablename not like 'pg\_%' "
			."and tablename not in ('sql_features', 'sql_implementation_info', 'sql_languages', "
	 		."'sql_packages', 'sql_sizing', 'sql_sizing_profiles') and schemaname='$schema';";
	
		return $this->execute($sql);
	}
	
	/**
	 * Returns the schema for the table.
	 * 
	 * @param $tablename
	 * @param $related
	 * @param $restricted_to_schema
	 * @return unknown_type
	 */
	public function table($schema,$tablename, $related=false, $restricted_to_schema=false)
	{
		uses('system.data.database.pgsql.pgsql_table_schema');
		$fuck=new PGSQLTableSchema($this,$schema,$tablename,$related,$restricted_to_schema);
		return $fuck;
	}
	
	/**
	 * Generates the null ordering for an order by in a select.  
	 * 
	 * Some databases don't support this, some do it differently.
	 * 
	 * @param $column the column being ordered on
	 * @param $nulls The null ordering, 'first' or 'last'
	 */
	function order_by($order)
	{
		$result=parent::order_by($order);
		
		if ($order->nulls)
			$result.=" nulls {$order->nulls}";
		
		return $result;
	}
}