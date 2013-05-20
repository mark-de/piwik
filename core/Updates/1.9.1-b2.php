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
class Piwik_Updates_1_9_1_b2 extends Piwik_Updates
{
	static function getSql($schema = 'Myisam')
	{
		if (Zend_Registry::get('db') instanceof Piwik_Db_Adapter_Oracle)
		{
			$col = 'COLUMN';
			$error = 904;
		}
		else
		{
			$col = '';
			$error = 1091;
		}
		
		return array(
			'ALTER TABLE '
			.	Piwik_Common::prefixTable('site')
			.	" DROP " 
			.	$col 
			.	" `feedburnerName`" => $error
		);
	}
	
	static function update()
	{
		// manually remove ExampleFeedburner column
		Piwik_Updater::updateDatabase(__FILE__, self::getSql());

		// remove ExampleFeedburner plugin
		$pluginToDelete = 'ExampleFeedburner';
		self::deletePluginFromConfigFile($pluginToDelete);
	}

	public static function deletePluginFromConfigFile($pluginToDelete)
	{
		$config = Piwik_Config::getInstance();
		$config->init();
		if (isset($config->Plugins['Plugins']))
		{
			$plugins = $config->Plugins['Plugins'];
			if (($key = array_search($pluginToDelete, $plugins)) !== false) {
				unset($plugins[$key]);
			}
			$config->Plugins['Plugins'] = $plugins;

			$pluginsInstalled = $config->PluginsInstalled['PluginsInstalled'];
			if (($key = array_search($pluginToDelete, $pluginsInstalled)) !== false) {
				unset($pluginsInstalled[$key]);
			}
			$config->PluginsInstalled = $pluginsInstalled;

			$config->forceSave();
		}
	}
}
