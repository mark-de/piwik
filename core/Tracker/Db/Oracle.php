<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of Oracle
 * @author Ancud-IT GmbH
 */
class Piwik_Tracker_Db_Oracle extends Piwik_Tracker_Db
{

	protected $connection = null;
	private $host;
	private $port;
	private $socket;
	private $dbname;
	private $username;
	private $password;
	private $charset;
	private $lastInsertId;
	private $tables_prefix;


	/**
	 * Builds the DB object 
	 * @param array $dbInfo
	 * @param String $deriverName
	 */
	public function __construct($dbInfo, $driverName = 'oracle')
	{
		if(isset($dbInfo['unix_socket']) && $dbInfo['unix_socket'][0] == '/')
		{
			$this->host = null;
			$this->port = null;
			$this->socket = $dbInfo['unix_socket'];
		} else if($dbInfo['port'][0] == '/')
		{
			$this->host = null;
			$this->port = null;
			$this->socket = $dbInfo['port'];
		} else
		{
			$this->host = $dbInfo['host'];
			$this->port = $dbInfo['port'];
			$this->socket = null;
		}
		$this->dbname = $dbInfo['dbname'];
		$this->username = $dbInfo['username'];
		$this->password = $dbInfo['password'];
		$this->charset = isset($dbInfo['charset']) ? $dbInfo['charset'] : null;
		$this->tables_prefix = isset($dbInfo['tables_prefix']) ? $dbInfo['tables_prefix'] : '';
	}


	public function __destruct()
	{
		$this->connection = null;
	}


	/**
	 * Connects to the DB 
	 *  
	 * @throws Exception if there was an error connecting the DB 
	 */
	public function connect()
	{
		if(self::$profiling)
		{
			$timer = $this->initProfiler();
		}
		
		
		$tnsListener = "( DESCRIPTION=
								( ADDRESS=
									(PROTOCOL=TCP)
									(HOST=" . $this->host . ")
									(PORT=" . $this->port . ") )
								(  CONNECT_DATA= ( SID=" . $this->dbname . ")))";

		$this->connection = oci_pconnect($this->username, $this->password, $tnsListener);
		
		if(!$this->connection)
		{
			throw new Piwik_Tracker_Db_Exception("Connect failed: " . oci_error());
		}
			
		$this->password = '';

		if(self::$profiling)
		{
			$this->recordQueryProfile('connect', $timer);
		}
	}


	/**
	 * Disconnects from the server 
	 */
	public function disconnect()
	{
		oci_close($this->connection);
		$this->connection = null;
	}


	/**
	 * Returns an array containing all the rows of a query result, using 
	  optional bound parameters.
	 *  
	 * @param string Query  
	 * @param array Parameters to bind 
	 * @see also query() 
	 * @throws Exception if an exception occured 
	 */
	public function fetchAll($query, $parameters = array())
	{
		try
		{
			if(self::$profiling)
			{
				$timer = $this->initProfiler();
			}
			
			$stid	   = $this->query( $query, $parameters );

			if(!$stid)
			{
				throw new Piwik_Tracker_Db_Exception('fetchAll() failed: ' .
						oci_error($this->connection) . ' : ' . $query);
			}

			$rows = array();
			while($row = oci_fetch_array($stid, OCI_ASSOC + OCI_RETURN_NULLS))
			{
				$rowToLower = array();
				
				foreach($row as $key => $val)
					$rowToLower[strtolower($key)] = $val;
				
				$rows[]	= $rowToLower;
			}

			oci_free_statement($stid);

			if(self::$profiling)
			{
				$this->recordQueryProfile($query, $timer);
			}

			return $rows;
			
		} catch(Exception $e)
		{
			throw new Piwik_Tracker_Db_Exception("Error query: " . $e->getMessage() . "\nDB-Anweisung: " . $query);
		}
	}


	/**
	 * Returns the first row of a query result, using optional bound 
	  parameters.
	 *  
	 * @param string Query  
	 * @param array Parameters to bind 
	 * @see also query() 
	 *  
	 * @throws Exception if an exception occured 
	 */
	public function fetch($query, $parameters = array())
	{
		try
		{
			if(self::$profiling)
			{
				$timer = $this->initProfiler();
			}

			$stid	   = $this->query( $query, $parameters);
			
			if(!$stid)
			{
				throw new Piwik_Tracker_Db_Exception('fetch() failed: ' .
						oci_error($this->connection) . ' : ' . $query);
			}
			
			$row = oci_fetch_array($stid, OCI_ASSOC + OCI_RETURN_NULLS);
			$rowToLower = array();
			
			if(is_array($row))
			{
				foreach($row as $key => $val)
				{
					$rowToLower[strtolower($key)] = $val;
				}
			}

			oci_free_statement($stid);


			if(self::$profiling)
			{
				$this->recordQueryProfile($query, $timer);
			}

			return $rowToLower;
		
			
		} catch(Exception $e)
		{
			throw new Piwik_Tracker_Db_Exception("Error query: " . $e->getMessage());
		}
	}


	/**
     * Executes a query, using optional bound parameters. 
     *  
     * @param string Query  
     * @param array|string Parameters to bind array('idsite'=> 1) 
     *  
     * @return bool|resource false if failed 
     * @throws Exception if an exception occured 
     */
    public function query($query, $parameters = array ())
    {
        if (is_null($this->connection))
            return false;

        if (self::$profiling)
            $timer = $this->initProfiler();

        $queryParams = $this->prepare(array ('query' => $query, 'params' => $parameters));
        $query      = $queryParams['query'];
        $parameters = $queryParams['params'];
        $stid       = oci_parse($this->connection, $query);

        foreach ($parameters as $key => $val)
            oci_bind_by_name($stid, $key, $parameters[$key]);

        $lastInsertedId = null;

        if (strpos($query, ':primary') !== false)
            oci_bind_by_name($stid, ':primary', $lastInsertedId, 11);


//		if (preg_match('/\bINSERT\b|\bUPDATE\b/i', $query) == 1)
//		{
//			var_dump( "INSERT IN TRACKER  ", $query, $parameters);
//		}

        if (!@oci_execute($stid))
        {
            $error = oci_error($stid);

            if ($error['code'] != 1 && $error['code'] != 1451)
            {
                // ignore inserts violating unique constraints
                // and redefining cols to be nullable that are
                // already nullable!
                throw new Exception($error['message']);
            }

            return false;
        }

        if (isset($lastInsertedId))
            $this->lastInsertId = $lastInsertedId;

        if (self::$profiling)
            $this->recordQueryProfile($query, $timer);

        return $stid;
    }

	
	/**
	 * Returns the last inserted ID in the DB 
	 *  
	 * @return int 
	 */
	public function lastInsertId()
	{
		return $this->lastInsertId;
//		
//		$seqName = $tableName . '_seq';
//		$sql = 'SELECT '.$seqName.'.CURRVAL FROM dual';
//		$lastInsertId = $this->fetch($sql);
//		return $lastInsertId['currval']; //$this->lastInsertId;
	}

   
	/**
	 * Input is a array including a prepared SQL statement 
	 * with unsupported positional parameters and the indexed 
	 * bind array.
	 * Returns an array with an SQL statement with named parameters
	 * and an associative array
	 * 
	 * @param array $queryParams  
	 * @return array 
	 */
	private function prepare($queryParams)
	{
		$parameters = $queryParams['params'];

		if(!$parameters)
		{
			$parameters = array();
		} else if(!is_array($parameters))
		{
			$parameters = array($parameters);
		}

		$count		   = 0;
		$namedParameters = array();
		$nparams = 0;
		$strArray = array();
		
		$query	= $queryParams['query'];
		
		$queryParts = explode( '"', $query );
		
		// step over quoted string elements
		for ($i = 0; $i < count($queryParts); $i+=2 )
		{
			$queryParts[$i] = str_replace('%', '%%', $queryParts[$i]);
			$queryParts[$i] = str_replace('?', '%s', $queryParts[$i], $count);
			
			if ($count > 0)
			{
				$start = $nparams;
				$nparams += $count;
				
				for($k = $start; $k < $nparams; $k++)
				{
					$strArray[]					 = ":p" . $k;
					$namedParameters[$strArray[$k]] = $parameters[$k];
				}	
			}
		}
		
		$query = implode( '"', $queryParts );
		$query = vsprintf( $query, $strArray );
		$queryParams['query']  = $query;
		$queryParams['params'] = $namedParameters;
		return $queryParams;
	}


	/**
	 * Ancud-IT GmbH: Get primaries and unique constraints
	 * @param type $tableName
	 * @return array
	 */
	public function getUniqueConstraints($tableName)
	{
		$sql .= "SELECT cols.column_name ";
		$sql .= "FROM user_constraints cons, ";
		$sql .= "  user_cons_columns cols ";
		$sql .= "WHERE cons.table_name     = '" . strtoupper($tableName) . "' ";
		$sql .= "AND cons.CONSTRAINT_NAME  = cols.CONSTRAINT_NAME ";
		$sql .= "AND cons.table_name       = cols.table_name ";
		$sql .= "AND cons.CONSTRAINT_TYPE IN ('U','P');";

		return $this->fetch($sql);
	}


	/**
	 * Test error number 
	 * 
	 * @param Exception $e 
	 * @param string $errno 
	 * @return bool 
	 */
	public function isErrNo($e, $errno)
	{
		// @TODO Ancud-IT GmbH --> not correct !
		return oci_error($this->_connection) == $errno;
	}


	/**
	 * Return number of affected rows in last query 
	 * 
	 * @param mixed $queryResult Result from query() 
	 * @return int 
	 */
	public function rowCount($stid)
	{
		return is_bool($stid) ? 0 : oci_num_rows($stid);
	}


}


