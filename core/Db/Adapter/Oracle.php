<?php

/**
 * Piwik - Open source web analytics
 * 
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id: Oracle.php 4690 2011-05-15 21:02:40Z Ancud-IT GmbH $ * 
 * @category Piwik
 * @package Piwik
 */

/**
 * @package Piwik
 * @subpackage Piwik_Db
 */
class Piwik_Db_Adapter_Oracle extends Zend_Db_Adapter_Oracle implements Piwik_Db_Adapter_Interface
{

	/**
	 * Default class name for a DB statement.
	 *
	 * @var string
	 */
	protected $_defaultStmtClass = 'Piwik_Db_Statement_Oracle';
	/**
	*Constructor method
	* @param array $config 
	*/
	public function __construct( $config )
	{
		$config['driver_options'] = array('lob_as_string' => false);
		$config['options'] = array(
			Zend_Db::AUTO_QUOTE_IDENTIFIERS => false,
			Zend_Db::CASE_FOLDING => Zend_Db::CASE_NATURAL );

		//	oci_connect expects easy-connect-string as third parameter
		//  $config['dbname'] = $config['host'] .':'.$config['port'] .'/' . strtoupper( $config['dbname'] );
		//	But depending on your Oracle-SYSDBA eays-connect-string moghz not work!
		//  So ressorting to the traditional connect string, does always work!

		$config['dbname'] = "(
            DESCRIPTION=
            ( ADDRESS=
                (PROTOCOL=TCP)
                (HOST=" . $config['host'] . ")
                (PORT=" . $config['port'] . ") )
            (  CONNECT_DATA= ( SID=" . $config['dbname'] . ") )
                             )";

		parent::__construct( $config );
	}

	/**
	 * Ancud-IT		adapted for Oracle/Piwik
	 * Overrides and implements specific "NATURAL"-interpretation!
	 * It let field/identifiers defined/quoted in camelcase style
	 * through, otherwise it defaults to lowercase.
	 * 
     * ####################################################
	 * Helper method to change the case of the strings used
     * when returning result sets in FETCH_ASSOC and FETCH_BOTH
     * modes.
     *
     * This is not intended to be used by application code,
     * but the method must be public so the Statement class
     * can invoke it.
     *
     * @param string $key
     * @return string
     */
	
	public function foldCase($key)
	{
		if( Zend_Db::CASE_FOLDING == Zend_Db::CASE_NATURAL ) {
			if( strtoupper($key) == $key ) {
				return strtolower( (string) $key);
			} else {
				return (string) $key;
			}	
		}
		
		return (string) $key;
	}
	
	/**
	 * @param type $sql
	 * @param type $bind
	 * @param type $fetchMode
	 * @return array 
	 */
	public function fetchRow( $sql, $bind = array(), $fetchMode = null )
	{
		return parent::fetchRow( $sql, $bind, $fetchMode );
    }
	
	/**
	 * @param type $sql
	 * @param type $bind
	 * @param type $fetchMode
	 * @return array 
	 */
	public function fetchAll( $sql, $bind = array(), $fetchMode = null )
	{
		return parent::fetchAll( $sql, $bind, $fetchMode );
	}

	/**
	 * Ancud-IT GmbH
	 * @param string $sql
	 * @param array $bind
	 * @return array 
	 */
	public function fetchCol( $sql, $bind = array() )
	{
		$results = array_map('strtolower', parent::fetchCol( $sql, $bind ) );

		return $results;
	}

	/**
	 * Ancud-IT GmbH
	 * We direct CREATE Table to db->query() in order to filter/correct 
	 * invalid MySQL-Statements sent by third party plugins
	 * @param	string	$sqlQuery
	 * @return	int		
	 */
	public function exec( $sqlQuery )
	{
		$this->_connect();
        $ddlPattern = "/\s*\bCREATE\b\s*\bTABLE\b\s*|\s*\bALTER\b\s*\bTABLE\b\s*/i";
        
		if( preg_match( $ddlPattern, $sqlQuery ) == 1 )
		{
			$stmt = $this->query( $sqlQuery );
            // Ancud-IT GmbH: some all failed queries don't throw an exception, 
            // but return null !
			return is_null($stmt) ? 0 : $stmt->rowCount() ;
		}

		$stmt = oci_parse( $this->_connection, $sqlQuery );
		$retval = @oci_execute( $stmt );

        if ($retval === false) {
            /**
             * @see Zend_Db_Adapter_Oracle_Exception
             */
            // require_once 'Zend/Db/Statement/Oracle/Exception.php';
            throw new Zend_Db_Statement_Oracle_Exception(oci_error($stmt));
        }

		return oci_num_rows( $stmt );
	}

	/**
	 * Ancud-IT GmbH
	 * OracleDB must be newer >= 10g
	 * @throws Exception 
	 */
	public function checkServerVersion()
	{
		$serverVersion   = $this->getServerVersion();
		$requiredVersion = Zend_Registry::get( 'config' )->General->minimum_oracle_version;
		if( version_compare( $serverVersion, $requiredVersion ) === -1 )
		{
			throw new Exception 
            ( 
                Piwik_TranslateException
                ( '
                    General_ExceptionDatabaseVersion', 
                    array('Oracle', $serverVersion, $requiredVersion ) 
                                            
                ) 
            );
		}
	}

	/**
	 * Ancud-IT GmbH
	 * @throws Exception 
	 */
	public function checkClientVersion()
	{
		$serverVersion = $this->getServerVersion();
		$clientVersion = $this->getClientVersion();

		if( version_compare( $clientVersion, $serverVersion ) < 0 )
		{
			throw new Exception
            ( 
                Piwik_TranslateException
                ( 
                    'General_ExceptionIncompatibleClientServerVersions', 
                        array('Oracle', $clientVersion, $serverVersion) 
                )         
            );
		}
	}

	/**
	 * alwas true for Oracle
	 * @return boolean 
	 */
	public function hasBlobDataType()
	{
		return true;
	}

	/**
	 * @return boolean 
	 */
	public function hasBulkLoader()
	{
		return false;
	}

	/**
	 * @return boolean 
	 */
	public function isConnectionUTF8()
	{
		/*
		 * Oracle needs named parameters
		 * http://framework.zend.com/manual/de/zend.db.adapter.html#zend.db.adapter.adapter-notes.oracle
		 */
		$sql	= "SELECT VALUE FROM NLS_DATABASE_PARAMETERS WHERE PARAMETER = :nls_characterset";
		$params = array(':nls_characterset' => 'NLS_CHARACTERSET');
		return stripos( $this->fetchOne( $sql, $params ), 'UTF8' ) !== false;
	}

	/**
	 * TODO: Find a useful implementation
	 *
	 * @param type $e
	 * @param type $errno
	 * @return null 
	 */
	public function isErrNo( $e, $errno )
	{
	    
        $code = $e->getCode();
        if(isset($code) || !is_null( $code))
        {
            $test = $code == $errno;
            return $test;
        }
        
        return false;
			
	}

	/**
	 * TODO: lob_as_string isn't preserved after installation 
	 * Ancud-IT GmbH
	 * Unlike the MySQL-adapter we don't reset the array as a whole
	 * to keep the driver-options for läter use!
	 */
	public function resetConfig()
	{
		unset( $this->_config['username'] );
		unset( $this->_config['password'] );
		unset( $this->_config['host'] );
		unset( $this->_config['port'] );
		//		$this->_config = array( );
	}

	/**
	 * 1521 is Oracle's default port
	 * @return int 
	 */
	public static function getDefaultPort()
	{
		return 1521;
	}
	
	/**
	 *
	 * @param String $tableName
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

		return $this->fetchCol($sql);
	}

	/**
	 * @return bool 
	 */
	public static function isEnabled()
	{
		$extensions = @get_loaded_extensions();
		return in_array( 'oci8', $extensions );
	}

	/**
	 * @return mixed 
	 */
	public function getClientVersion()
	{
		$clientVersion = oci_client_version();

		if( $clientVersion !== false )
		{
			$matches = null;
			if( preg_match( '/((?:[0-9]{1,2}\.){1,3}[0-9]{1,2})/', $clientVersion, $matches ) )
			{
				return $matches[1];
			} else
			{
				return null;
			}
		} else
		{
			return null;
		}
	}

	/**
     * Ancud-IT GmbH
     * @param string $sql
     * @param array $bind
     * @return zendDb statement
     * @throws Exception 
     */
    public function query($sql, $bind = array ())
    {
        try
        {
            $stmt = parent::query($sql, $bind);
            return $stmt;
        } catch (Zend_Db_Statement_Oracle_Exception $ex)  // Ancud-IT GmbH  Statement_Exceptions most likely here ...
        {
            if ($ex->getCode() == 942)  // Ancud-IT GmbH  table not found error
            { // likely cause: some mysql-backticks around the table identifier
                $config       = Piwik_Config::getInstance();
                $prefixTables = $config->database['tables_prefix'];
                $sql          = preg_replace('/(`)(\s*\b' . $prefixTables . '[^\.]+\b\s*)(`)/i', "$2", $sql);
                return parent::query($sql, $bind);
            } else if ($ex->getCode() == 1722)
            {
                var_dump($ex->getMessage(), $bind, $sql);
                throw $ex;
            } else if ($ex->getCode() == 1451)
            { // cols are already nullable
                return;
            } else
            {
                throw $ex;
            }
        }
    }
	
	
    /**
    * Convert positional (?) into named placeholders (:param<num>)
    *
    * Oracle does not support positional parameters, hence this method converts all
    * positional parameters into artificially named parameters. Note that this conversion
    * is not perfect. All question marks (?) in the original statement are treated as
    * placeholders and converted to a named parameter.
    *
    * The algorithm uses a state machine with two possible states: InLiteral and NotInLiteral.
    * Question marks inside literal strings are therefore handled correctly by this method.
    * This comes at a cost, the whole sql statement has to be looped over.
    *
    * @todo review and test for lost spaces. we experienced missing spaces with oci8 in some sql statements.
    * @param string $statement The SQL statement to convert.
    * @return string
    */
    
	public function convertPositionalParameters( $sql )
	{
		$count	 = 0;
		$inLiteral = false; // a valid query never starts with quotes
		$stmtLen   = strlen($sql);

		for( $i = 0; $i < $stmtLen; $i++ )
		{
			if( $sql[$i] == '?' && !$inLiteral )
			{
				// real positional parameter detected
				$namedParameter = ":p$count";
				$len			= strlen($namedParameter);
				$sql			= substr_replace($sql, ":p$count", $i, 1);
				$i += $len - 1; // jump ahead
				$stmtLen		= strlen($sql); // adjust statement length
				++$count;
			} else if( $sql[$i] == "'" || $sql[$i] == '"' || $sql[$i] == '`' )
			{
				$inLiteral = !$inLiteral; // switch state!	
			}
		}

		return $sql;
	}
	
	
	public function insertBlob( $sql, $row, $blobFields, $fields )
	{
		$this->beginTransaction();
		
		$sql = $this->convertPositionalParameters($sql);
		
		$i = 0;
		$pos = array();
		
		foreach( $blobFields as $blobField )
		{
			foreach( $fields as $field )
			{
				if ( $field == $blobField)
				{
					$pos[] = $i;
					break;
				}	$i++;
			}
		}	
		
		$lobDescriptors = array();
		
		foreach( $pos as $p)
		{
			$sql = str_replace( ":p".$p  , 'EMPTY_BLOB()' , $sql);
			$lobParams[] = ":p".$p;
			$lobData[":p.$p"] = $row[$p];
			$lobDescriptors[":p.$p"] = oci_new_descriptor($this->_connection, OCI_D_LOB);
			$row[$p] = $lobDescriptors[":p.$p"];
		}
		
		$sql = $sql . " RETURNING " . implode( ', ', $blobFields) . " INTO " 
				. implode( ', ', $lobParams);
		
		$stmt = new $this->_defaultStmtClass($this, $sql);
			
		$row = $stmt->_prepareBind( $row );
		
		foreach (array_keys($row) as $name) 
		{
			if (in_array($name, $lobParams))
			{
				oci_bind_by_name($stmt->getStmt(), $name, $row[$name], -1, OCI_B_BLOB);
			} else 
			{
				oci_bind_by_name($stmt->getStmt(), $name, $row[$name], -1);
			}
		}

		//Execute without committing
		try 
		{
			$stmt->execute();
		}
			catch ( Exception $e )
		{
			$this->rollBack();
			throw $e;
		}
		
		foreach ( $lobDescriptors as $name => $lobDescriptor ) 
		{
			$lobDescriptor->write($lobData[$name]);
			$lobDescriptor->free();
		}
		
		oci_free_statement($stmt->getStmt());
		
		$this->commit();		
	}
	
}