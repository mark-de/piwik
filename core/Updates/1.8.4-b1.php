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
class Piwik_Updates_1_8_4_b1 extends Piwik_Updates
{
	
	static function isMajorUpdate()
	{
		return true;
	}
	
    static function getOracleSQL( $action, $duplicates, $visitAction, $conversion, $visit  )
    {
        $sql = array();
        
        $sql[ "DROP TABLE " . $duplicates ] = 942;
        
        $sql[ " ALTER TABLE " . $action . " ADD URL_PREFIX NUMBER(3,0)"] = 1430;
        
        $sql[ " UPDATE " . $action 
            .   " SET "
            .   "URL_PREFIX = "
                .   "CASE "
                    .   "WHEN SUBSTR(NAME, 1, 11) = 'http://www.' THEN 1 "
                    .   "WHEN SUBSTR(NAME, 1, 7) = 'http://' THEN 0 "
                    .   "WHEN SUBSTR(NAME, 1, 12) = 'https://www.' THEN 3 "
                    .   "WHEN SUBSTR(NAME, 1, 8) = 'https://' THEN 2 "
                .   "END WHERE URL_PREFIX IS NULL AND TYPE = 1" ] = false;
        
        $sql[ " UPDATE " . $action 
            .   " SET "
            .   "NAME = "
                .   "CASE "
                    .   "WHEN URL_PREFIX = 0 THEN SUBSTR(name, 8) "
                    .   "WHEN URL_PREFIX = 1 THEN SUBSTR(name, 12) "
                    .   "WHEN URL_PREFIX = 2 THEN SUBSTR(name, 9) "
                    .   "WHEN URL_PREFIX = 3 THEN SUBSTR(name, 13) "
                .   "END, "
            .   "HASH = ORA_HASH(NAME) "
            .   "WHERE "
            .   "TYPE = 1 "
            .   "AND URL_PREFIX IS NOT NULL"] = false;
        
        
        
        $sql[ "CREATE TABLE " . $duplicates 
            . " ("
            .   " BEFORE NUMBER(11,0) NOT NULL, "
            .   " AFTER NUMBER(11,0) NOT NULL, "
            .   " CONSTRAINT MAINKEY PRIMARY KEY (BEFORE)"
            .   ")"] = false;
         
        $sql[ "INSERT INTO " . $duplicates 
            .   "("
            .   " SELECT "
                .   "ACTION.IDACTION BEFORE, "
                .   "CANONICAL.IDACTION AFTER "
            .   " FROM "
                .   "("
                    .   "SELECT NAME, HASH, MIN(IDACTION) IDACTION "
                        .   "FROM "
                            .   $action . " ACTION_CANONICAL_BASE "
                        .   "WHERE "
                            .   "TYPE = 1 AND URL_PREFIX IS NOT NULL "
                        .   "GROUP BY NAME, HASH "
                        .   "HAVING COUNT(IDACTION) > 1"
                .   ") CANONICAL "
                .   "LEFT OUTER JOIN "
                    .   $action
                    .   " ACTION "
                    .   "ON (action.type = 1 AND canonical.hash = action.hash) "
					.   "AND canonical.name = action.name "
					.   "AND canonical.idaction != action.idaction "
            .   ")" ] = false;
        
 
        $sql[ "   UPDATE "
				. "(SELECT l.idaction_url idaction_url, d.after after "
                . "FROM " . $visitAction . " l "
				. "LEFT JOIN "
				.  $duplicates . " d "
				. "ON l.idaction_url = d.before "
                . ") "
                . "SET idaction_url = after "
                . "WHERE after IS NOT NULL"] = false;
        
        
        $sql[ "   UPDATE "
				. "(SELECT l.idaction_url_ref idaction_url_ref, d.after after "
                . "FROM " . $visitAction . " l "
				. "LEFT JOIN "
				.  $duplicates . " d "
				. "ON l.idaction_url = d.before "
                . ") "
                . "SET idaction_url_ref = after "
                . "WHERE after IS NOT NULL"] = false;
                
                
        $sql[ "   UPDATE "
				. "(SELECT l.idaction_url idaction_url, d.after after "
                . "FROM " . $conversion . " l "
				. "LEFT JOIN "
				.  $duplicates . " d "
				. "ON l.idaction_url = d.before "
                . ") "
                . "SET idaction_url = after "
                . "WHERE after IS NOT NULL"] = false;
        
        $sql[ "   UPDATE "
				. "(SELECT l.visit_entry_idaction_url visit_entry_idaction_url, d.after after "
                . "FROM " . $visit . " l "
				. "LEFT JOIN "
				.  $duplicates . " d "
				. "ON l.visit_entry_idaction_url = d.before "
                . ") "
                . "SET visit_entry_idaction_url = after "
                . "WHERE after IS NOT NULL"] = false;
        
        
        $sql[ "   UPDATE "
				.   "(SELECT l.visit_exit_idaction_url visit_exit_idaction_url, d.after after "
                .   "FROM " . $visit . " l "
				.   "LEFT JOIN "
				.   $duplicates . " d "
				.   "ON l.visit_exit_idaction_url = d.before "
                .   ") "
                .   "SET visit_exit_idaction_url = after "
                .   "WHERE after IS NOT NULL"] = false;
          
        $sql[ " DELETE FROM  "
                .   "( "
                .   "SELECT a.idaction idaction, d.after after "
                .   "FROM "
                .   $action . " a "
                .   " LEFT JOIN "
                .   $duplicates . " d "
                .   "ON a.idaction = d.before "
                .   ") "
                .   "WHERE after IS NOT NULL"] = false;
        
        $sql[ "DROP TABLE " . $duplicates] = 942;
        
		$sql[ "ALTER SEQUENCE " .  Piwik_Common::prefixTable('site') . "_SEQ NOCACHE"] = false;
        
        return $sql;
        
    }
    
	static function getSql($schema = 'Myisam')
	{
		$action = Piwik_Common::prefixTable('log_action');
		$duplicates = Piwik_Common::prefixTable('log_action_duplicates');
		$visitAction = Piwik_Common::prefixTable('log_link_visit_action');
		$conversion = Piwik_Common::prefixTable('log_conversion');
		$visit = Piwik_Common::prefixTable('log_visit');
		
        if( Zend_Registry::get('db') instanceof Piwik_Db_Adapter_Oracle) 
        {
            return self::getOracleSQL( $action, $duplicates, $visitAction, $conversion, $visit );
        }
            
        
		return array(
			
		    // add url_prefix column
			"   ALTER TABLE `$action` 
		    	ADD `url_prefix` TINYINT(2) NULL AFTER `type`;
		    " => 1060, // ignore error 1060 Duplicate column name 'url_prefix'
			
			// remove protocol and www and store information in url_prefix
			"   UPDATE `$action`
				SET
				  url_prefix = IF (
					LEFT(name, 11) = 'http://www.', 1, IF (
					  LEFT(name, 7) = 'http://', 0, IF (
						LEFT(name, 12) = 'https://www.', 3, IF (
						  LEFT(name, 8) = 'https://', 2, NULL
						)
					  )
					)
				  ),
				  name = IF (
					url_prefix = 0, SUBSTRING(name, 8), IF (
					  url_prefix = 1, SUBSTRING(name, 12), IF (
						url_prefix = 2, SUBSTRING(name, 9), IF (
						  url_prefix = 3, SUBSTRING(name, 13), name
						)
					  )
					)
				  ),
				  hash = CRC32(name)
				WHERE
				  type = 1 AND
				  url_prefix IS NULL;
			" => false,
			
			// find duplicates
			"   DROP TABLE IF EXISTS `$duplicates`;
			" => false,
			"   CREATE TABLE `$duplicates` (
				 `before` int(10) unsigned NOT NULL,
				 `after` int(10) unsigned NOT NULL,
				 KEY `mainkey` (`before`)
				) ENGINE=MyISAM;
			" => false,

			// grouping by name only would be case-insensitive, so we GROUP BY name,hash
			// ON (action.type = 1 AND canonical.hash = action.hash) will use index (type, hash)
			"   INSERT INTO `$duplicates` (
				  SELECT 
					action.idaction AS `before`,
					canonical.idaction AS `after`
				  FROM
					(
					  SELECT
						name,
						hash,
						MIN(idaction) AS idaction
					  FROM
						`$action` AS action_canonical_base
					  WHERE
						type = 1 AND
						url_prefix IS NOT NULL
					  GROUP BY name, hash
					  HAVING COUNT(idaction) > 1
					)
					AS canonical
				  LEFT JOIN
					`$action` AS action
					ON (action.type = 1 AND canonical.hash = action.hash)
					AND canonical.name = action.name
					AND canonical.idaction != action.idaction
				);
			" => false,
			
			// replace idaction in log_link_visit_action
			"   UPDATE
				  `$visitAction` AS link
				LEFT JOIN
				  `$duplicates` AS duplicates_idaction_url
				  ON link.idaction_url = duplicates_idaction_url.before
				SET
				  link.idaction_url = duplicates_idaction_url.after
				WHERE
				  duplicates_idaction_url.after IS NOT NULL;
			" => false,
			"   UPDATE
				  `$visitAction` AS link
				LEFT JOIN
				  `$duplicates` AS duplicates_idaction_url_ref
				  ON link.idaction_url_ref = duplicates_idaction_url_ref.before
				SET
				  link.idaction_url_ref = duplicates_idaction_url_ref.after
				WHERE
				  duplicates_idaction_url_ref.after IS NOT NULL;
			" => false,
			
			// replace idaction in log_conversion
			"   UPDATE
				  `$conversion` AS conversion
				LEFT JOIN
				  `$duplicates` AS duplicates
				  ON conversion.idaction_url = duplicates.before
				SET
				  conversion.idaction_url = duplicates.after
				WHERE
				  duplicates.after IS NOT NULL;
			" => false,
			
			// replace idaction in log_visit
			"   UPDATE
				  `$visit` AS visit
				LEFT JOIN
				  `$duplicates` AS duplicates_entry
				  ON visit.visit_entry_idaction_url = duplicates_entry.before
				SET
				  visit.visit_entry_idaction_url = duplicates_entry.after
				WHERE
				  duplicates_entry.after IS NOT NULL;
			" => false,
			"   UPDATE
				  `$visit` AS visit
				LEFT JOIN
				  `$duplicates` AS duplicates_exit
				  ON visit.visit_exit_idaction_url = duplicates_exit.before
				SET
				  visit.visit_exit_idaction_url = duplicates_exit.after
				WHERE
				  duplicates_exit.after IS NOT NULL;
			" => false,
			
			// remove duplicates from log_action
			"   DELETE action FROM
				  `$action` AS action
				LEFT JOIN
				  `$duplicates` AS duplicates
				  ON action.idaction = duplicates.before
				WHERE
				  duplicates.after IS NOT NULL;
			" => false,
			
			// remove the duplicates table
			"   DROP TABLE `$duplicates`;
			" => false
		);
	}

	static function update()
	{
		try
		{
			self::enableMaintenanceMode();
			Piwik_Updater::updateDatabase(__FILE__, self::getSql());
			self::disableMaintenanceMode();
		}
		catch(Exception $e)
		{
			self::disableMaintenanceMode();
			throw $e;
		}
	}
}
