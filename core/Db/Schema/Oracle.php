<?php

/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id: Oracle.php 6109 2012-03-24 11:49:29Z Ancud-IT GmbH $
 *
 * @category Piwik
 * @package Piwik
 */

/**
 * MySQL schema
 *
 * @package Piwik
 * @subpackage Piwik_Db
 */
class Piwik_Db_Schema_Oracle extends Piwik_Db_Schema_Myisam
{

	public function createTables()
	{
		parent::createTables();
	}

	/**
	 * Is Oracle database available?
	 *
	 * @return bool True if available and enabled; false otherwise
	 */
	public static function isAvailable()
	{
		return Piwik_Db_Schema_Oracle::hasConnectionToOracle( 'X' );
	}
	/**
	 *
	 * @param String $dummy
	 * @return boolean 
	 */
	private static function hasConnectionToOracle( $dummy )
	{
		$db	 = Zend_Registry::get( 'db' );
		$result = $db->fetchOne( 'SELECT dummy from DUAL' );
		return $result == $dummy;
	}
	/**
	 * Ancud-IT GmbH
	 * The anonymous user is the user that is assigned by default
	 *  note that the token_auth value is anonymous, which is assigned by default as well in the Login plugin
	 */
	public function createAnonymousUser()
	{
		
		$db = Zend_Registry::get( 'db' );
		$db->query  ( 
            "INSERT INTO " . Piwik_Common::prefixTable( "user" ) 
                . " VALUES ( 'anonymous', ' ', 'anonymous', 'anonymous@example.org', " 
                . "'anonymous', TO_TIMESTAMP('" 
                . Piwik_Date::factory( 'now' )->getDatetime() 
                . "', 'YYYY-MM-DD HH24:MI:SS') )" 
                    );
	}

	/**
	 *
	 * @param array $doNotDelete
	 * @throws Exception 
	 */
	public function dropTables( $doNotDelete = array() )
	{
		$db = Zend_Registry::get( 'db' );
		
		
		// cleanup trash and auto-generated stuff first 
		
		$db->exec('PURGE RECYCLEBIN');
		
		$triggers = $db->fetchCol( 'SELECT TRIGGER_NAME FROM USER_TRIGGERS'  );
		foreach ( $triggers as $trigger )
			$db->query( "DROP TRIGGER ".$trigger);
		
		$sequences = $db->fetchCol( 'SELECT SEQUENCE_NAME FROM USER_SEQUENCES' );
		foreach ( $sequences as $sequence )
			$db->query( "DROP SEQUENCE ".$sequence );
		
		$tablesAlreadyInstalled = $this->getTablesInstalled();

		$doNotDeletePattern = '/(' . implode( '|', $doNotDelete ) . ')/';

		foreach( $tablesAlreadyInstalled as $tableName )
		{
			if( count( $doNotDelete ) == 0
					|| (!in_array( $tableName, $doNotDelete )
					&& !preg_match( $doNotDeletePattern, $tableName ) ) )
			{
				try
				{
					$db->query( "DROP TABLE $tableName" );
				} catch( Exception $eUnquoted )
				{
					try
					{
						$db->query( "DROP TABLE \"$tableName\"" );
					} catch( Exception $eQuoted )
					{
						throw $eQuoted;
					}
				}		
			}
		}
	}
	
	
	
	public function dropDatabase()
	{
		$this->dropTables(); // Ancud-IT GmbH just clean up all tables
	}

	
	
	public function createDatabase( $dbName = null )
	{
		; // @TODO Ancud-IT GmbH,  that means to create a new user or 
	// a new tablespace in Oracle, for both extended privileges are required, so this
	// function is to be dropped;
	}
	
	
	/**
	 * Truncate all tables
	 */
	public function truncateAllTables()
	{
		$tablesAlreadyInstalled = $this->getTablesInstalled($forceReload = true);
		foreach($tablesAlreadyInstalled as $table)
		{
			Piwik_Query("TRUNCATE TABLE " . $table);
		}
	}


}

