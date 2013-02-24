<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id$
 *
 * @category Piwik
 * @package Piwik
 */

/**
 * Database-backed session save handler
 *
 * @package Piwik
 * @subpackage Piwik_Session
 */
class Piwik_Session_SaveHandler_DbTable implements Zend_Session_SaveHandler_Interface
{
	protected $config;
	protected $maxLifetime;

	/**
	 * @param array  $config
	 */
	function __construct($config)
	{
		$this->config = $config;
		$this->maxLifetime = ini_get('session.gc_maxlifetime');
	}

	/**
	 * Destructor
	 *
	 * @return void
	 */
	public function __destruct()
	{
		Zend_Session::writeClose();
	}

	/**
	 * Open Session - retrieve resources
	 *
	 * @param string  $save_path
	 * @param string  $name
	 * @return boolean
	 */
	public function open($save_path, $name)
	{
		$this->config['db']->getConnection();

		return true;
	}

	/**
	 * Close Session - free resources
	 *
	 * @return boolean
	 */
	public function close()
	{
		return true;
	}

	/**
	 * Read session data
	 *
	 * @param string  $id
	 * @return string
	 */
	public function read($id)
	{
		$sql = 'SELECT '.$this->config['dataColumn'].' FROM '.$this->config['name']
			.' WHERE '.$this->config['primary'].' = ?'
				.' AND '.$this->config['modifiedColumn'].' + '.$this->config['lifetimeColumn'].' >= ?';

		$result = $this->config['db']->fetchOne($sql, array($id, time()));
		if(!$result)
			$result = '';

		return $result;
	}

	/**
	 * Write Session - commit data to resource
	 *
	 * @param string  $id
	 * @param mixed   $data
	 * @return boolean
	 */
	public function write($id, $data)
	{
		if( !Piwik_Common::isOracle() ) 
		{ 
		$sql = 'INSERT INTO '.$this->config['name']
			.' ('.$this->config['primary'].','
				.$this->config['modifiedColumn'].','
				.$this->config['lifetimeColumn'].','
				.$this->config['dataColumn'].')'
			.' VALUES (?,?,?,?)'
			.' ON DUPLICATE KEY UPDATE '
				.$this->config['modifiedColumn'].' = ?,'
				.$this->config['lifetimeColumn'].' = ?,'
				.$this->config['dataColumn'].' = ?';

			$aBind = array($id, time(), $this->maxLifetime, $data, time(), $this->maxLifetime, $data);
		}
		else
		{
			$idCol = $this->config['primary'];
			$modCol = $this->config['modifiedColumn'];
			$lifeCol = $this->config['lifetimeColumn'];
			$dataCol = $this->config['dataColumn'];
			
			$tableName = $this->config['name'];
			
			$sql = "MERGE INTO " . $tableName . " TARGET USING " 
				.	"(SELECT '" . $id . "' AS " . $idCol .", "
				.	time() . " AS " . $modCol.", "
				.	$this->maxLifetime . " AS " . $lifeCol . ", '"
				.	$data . "' AS " . $dataCol . " FROM DUAL) "
				.	"SOURCE ON (TARGET." . $idCol . " = SOURCE." . $idCol . ") " 
				.	"WHEN MATCHED THEN " 
				.	"UPDATE SET TARGET."	.	$modCol		. " = SOURCE." . $modCol . ", "
				.	"TARGET."			.	$lifeCol	. " = SOURCE." . $lifeCol . ", "
				.	"TARGET."			.	$dataCol	. " = SOURCE." . $dataCol . " " 
				.	"WHEN NOT MATCHED THEN INSERT ( TARGET.". $idCol .", TARGET."
				.	$modCol . ", TARGET.". $lifeCol
				.	", TARGET." . $dataCol . " ) VALUES ( ?, ?, ?, ?)" ;
			
			$aBind = array($id, time(), $this->maxLifetime, $data);
		}
		
		$this->config['db']->query( $sql, $aBind );

		return true;
	}

	/**
	 * Destroy Session - remove data from resource for
	 * given session id
	 *
	 * @param string  $id
	 * @return boolean
	 */
	public function destroy($id)
	{
		$sql = 'DELETE FROM '.$this->config['name']
			.' WHERE '.$this->config['primary'].' = ?';

		$this->config['db']->query($sql, array($id));

		return true;
	}

	/**
	 * Garbage Collection - remove old session data older
	 * than $maxlifetime (in seconds)
	 *
	 * @param int  $maxlifetime  timestamp in seconds
	 * @return true
	 */
	public function gc($maxlifetime)
	{
		$sql = 'DELETE FROM '.$this->config['name']
			.' WHERE '.$this->config['modifiedColumn'].' + '.$this->config['lifetimeColumn'].' < ?';

		$this->config['db']->query($sql, array(time()));

		return true;
	}
}
