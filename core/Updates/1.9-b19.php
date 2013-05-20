<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Updates
 */

/**
 * @package Updates
 */
class Piwik_Updates_1_9_b19 extends Piwik_Updates
{
	static function getSql($schema = 'Myisam')
	{
		$config = Piwik_Config::getInstance();
		$adapter = $config->database['adapter'];
		
		if ($adapter == 'ORACLE' ) 
		{
			return self::getOracleSql();
		}
		
		return array(
			'ALTER TABLE  `'. Piwik_Common::prefixTable('log_link_visit_action') .'`
			CHANGE `idaction_url_ref` `idaction_url_ref` INT( 10 ) UNSIGNED NULL DEFAULT 0'
			=> false,
			'ALTER TABLE  `'. Piwik_Common::prefixTable('log_visit') .'`
			CHANGE `visit_exit_idaction_url` `visit_exit_idaction_url` INT( 10 ) UNSIGNED NULL DEFAULT 0'
			=> false
		);
	}

	/**
	 * Oracle statement
	 * @return array 
	 */
	
	static function getOracleSql()
	{
		$logLinkVa = Piwik_Common::prefixTable('log_link_visit_action');
		$logVisit = Piwik_Common::prefixTable('log_visit');
		
		$sql = array(
			'ALTER TABLE ' . $logLinkVa . ' MODIFY IDACTION_URL_REF NUMBER(11,0) NULL DEFAULT 0' => false,
			'ALTER TABLE ' . $logVisit . ' MODIFY VISIT_EXIT_IDACTION_URL NUMBER(11,0) NULL DEFAULT 0'=> false
		);
		
		return $sql;
	}
	
	
	static function update()
	{
		Piwik_Updater::updateDatabase(__FILE__, self::getSql());

		$config = Piwik_Config::getInstance();
		$adapter = $config->database['adapter'];

		if ($adapter !== 'ORACLE' ) 
		{
			try 
			{
			Piwik_PluginsManager::getInstance()->activatePlugin('Transitions');
			} catch(Exception $e) 
			{
			}
		}
	}
}

