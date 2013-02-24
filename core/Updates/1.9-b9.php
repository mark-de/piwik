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
class Piwik_Updates_1_9_b9 extends Piwik_Updates
{
	static function isMajorUpdate()
	{
		return true;
	}
	
	static function getSql($schema = 'Myisam')
	{
		$logVisit = Piwik_Common::prefixTable('log_visit');
		$logConversion = Piwik_Common::prefixTable('log_conversion');
		
		$config = Piwik_Config::getInstance();
		$adapter = $config->database['adapter'];
		
		if ($adapter == 'ORACLE' ) 
		{
			return self::getOracleSql($logVisit, $logConversion);
		}
		
		$addColumns = "DROP `location_continent`,
					   ADD `location_region` CHAR(2) NULL AFTER `location_country`,
					   ADD `location_city` VARCHAR(255) NULL AFTER `location_region`,
					   ADD `location_latitude` FLOAT(10, 6) NULL AFTER `location_city`,
			           ADD `location_longitude` FLOAT(10, 6) NULL AFTER `location_latitude`";
		
		return array(
			// add geoip columns to log_visit
			"ALTER TABLE `$logVisit` $addColumns" => 1091,
			
			// add geoip columns to log_conversion
			"ALTER TABLE `$logConversion` $addColumns" => 1091,
		);
	}

	static function getOracleSql($logVisit, $logConversion)
	{
		$dropColumns = "DROP COLUMN LOCATION_CONTINENT";
		$addColumns = "ADD ( LOCATION_REGION CHAR(2) NULL, "
					.	"LOCATION_CITY VARCHAR2(255) NULL, "
					.	"LOCATION_LATITUDE NUMBER(10, 6) NULL, "
					.	"LOCATION_LONGITUDE NUMBER(10, 6) NULL )";
		
		return array(
			"ALTER TABLE $logVisit $dropColumns" => false,
			"ALTER TABLE $logConversion $dropColumns" => false,
			"ALTER TABLE $logVisit $addColumns" => false,
			"ALTER TABLE $logConversion $addColumns" => false
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
		catch (Exception $e)
		{
			self::disableMaintenanceMode();
			throw $e;
		}
	}
}

