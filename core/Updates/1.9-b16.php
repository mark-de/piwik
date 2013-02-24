<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id$
 *
 * @category Piwik
 * @package Updates
 */

/**
 * @package Updates
 */
class Piwik_Updates_1_9_b16 extends Piwik_Updates
{
	static function isMajorUpdate()
	{
		return true;
	}
	
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
			CHANGE `idaction_url` `idaction_url` INT( 10 ) UNSIGNED NULL DEFAULT NULL'
			=> false,


			'ALTER TABLE  `'. Piwik_Common::prefixTable('log_visit') .'`
			ADD visit_total_searches SMALLINT(5) UNSIGNED NOT NULL AFTER `visit_total_actions`'
			=> 1060,

			'ALTER TABLE  `'. Piwik_Common::prefixTable('site') .'`
			ADD sitesearch TINYINT DEFAULT 1 AFTER `excluded_parameters`,
            ADD sitesearch_keyword_parameters TEXT NOT NULL AFTER `sitesearch`,
            ADD sitesearch_category_parameters TEXT NOT NULL AFTER `sitesearch_keyword_parameters`'
				=> 1060,

			// enable Site Search for all websites, users can manually disable the setting
			'UPDATE `'. Piwik_Common::prefixTable('site') .'`
		    	SET `sitesearch` = 1' => false,
		);
	}

	/**
	 * Oracle statements
	 * @return array 
	 */
	
	static function getOracleSql()
	{
		$logLinkVa = Piwik_Common::prefixTable('log_link_visit_action');
		$logVisit = Piwik_Common::prefixTable('log_visit');
		$site = Piwik_Common::prefixTable('site');
		
		/**
		 * It is not possible to ADD cols to an Oracle table with NOT NULL constraints
		 */
		
		$sql = array( 
			'ALTER TABLE ' . $logLinkVa . ' MODIFY IDACTION_URL NUMBER(11,0) NULL' => false,
			'ALTER TABLE ' . $logVisit . ' ADD VISIT_TOTAL_SEARCHES NUMBER(11,0)' => 1430,
			'ALTER TABLE ' . $site . ' ADD ( SITESEARCH NUMBER(3,0) DEFAULT 1, '
							.	'sitesearch_keyword_parameters VARCHAR2(4000),  '
							.	'sitesearch_category_parameters VARCHAR2(4000))' => false,
			'UPDATE ' . $site . ' SET SITESEARCH = 1' => false
		) ;
		
		return $sql;
	}

	
	
	static function update()
	{
		Piwik_Updater::updateDatabase(__FILE__, self::getSql());
	}
}

