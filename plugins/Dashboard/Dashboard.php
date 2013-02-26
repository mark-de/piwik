<?php
/**
 * Piwik - Open source web analytics
 * 
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id$
 * 
 * @category Piwik_Plugins
 * @package Piwik_Dashboard
 */

/**
 * @package Piwik_Dashboard
 */
class Piwik_Dashboard extends Piwik_Plugin
{
	public function getInformation()
	{
		return array(
			'description' => Piwik_Translate('Dashboard_PluginDescription'),
			'author' => 'Piwik',
			'author_homepage' => 'http://piwik.org/',
			'version' => Piwik_Version::VERSION,
		);
	}

	public function getListHooksRegistered()
	{
		return array( 
			'AssetManager.getJsFiles' => 'getJsFiles',
			'AssetManager.getCssFiles' => 'getCssFiles',
			'UsersManager.deleteUser' => 'deleteDashboardLayout',
			'Menu.add' => 'addMenus',
			'TopMenu.add' => 'addTopMenu',
		);
	}

	public static function getAllDashboards($login) {
		
        /*
         * Ancud-IT GmbH
         * Some fallback for situations, where Piwik > 1.8 is installed 
         * on top of Piwik 1.7 tablespace. The installer clears/updates old core table
         * but leaves plugin tables untouched, the standard updater doesn't
         * provide something for this situation.
         */
        
        $sqlSelect = 'SELECT iddashboard, name
									  FROM '.Piwik_Common::prefixTable('user_dashboard') .
                                ' WHERE login = ? ORDER BY iddashboard';
        try 
        {
            $dashboards = Piwik_FetchAll($sqlSelect, array($login));


        } catch ( Exception $ex)
        {
            if( $ex->getCode() != 904 )
            {
                throw $ex;
            }


            $sql = "ALTER TABLE " . Piwik_Common::prefixTable('user_dashboard') 
                . " ADD NAME VARCHAR(100) NULL";

            Piwik_Query($sql);

            $dashboards = Piwik_FetchAll($sqlSelect, array($login));

        }
        
        /*
         * Ancud-IT GmbH end
         */
        
		$pos = 0;
		$nameless = 1;
		foreach ($dashboards AS &$dashboard) {
			if (!empty($dashboard['name'])) {
				$dashboard['name'] = $dashboard['name'];
			} else {
				$dashboard['name'] = Piwik_Translate('Dashboard_DashboardOf', $login);
				if($nameless > 1) {
					$dashboard['name'] .= " ($nameless)";
				}
				if(empty($dashboard['layout']))
				{
					$layout = '[]';
				}
				else
				{
					$layout = html_entity_decode($dashboard['layout']);
					$layout = str_replace("\\\"", "\"", $layout);
				}
				$dashboard['layout'] = Piwik_Common::json_decode($layout);
				$nameless++;
			}
			$dashboard['name'] = Piwik_Common::unsanitizeInputValue($dashboard['name']);
			$pos++;
		}
		return $dashboards;
	}

	public function addMenus()
	{
		Piwik_AddMenu('Dashboard_Dashboard', '', array('module' => 'Dashboard', 'action' => 'embeddedIndex', 'idDashboard' => 1), true, 5);

		if (!Piwik::isUserIsAnonymous()) {
			$login = Piwik::getCurrentUserLogin();

			$dashboards = self::getAllDashboards($login);
			if (count($dashboards) > 1)
			{
				$pos = 0;
				foreach ($dashboards AS $dashboard) {
					Piwik_AddMenu('Dashboard_Dashboard', $dashboard['name'], array('module' => 'Dashboard', 'action' => 'embeddedIndex', 'idDashboard' => $dashboard['iddashboard']), true, $pos);
					$pos++;
				}
			}

		}
	}

	public function addTopMenu()
	{
		$tooltip = false;
		try
		{
			$idSite = Piwik_Common::getRequestVar('idSite');
			$tooltip = Piwik_Translate('Dashboard_TopLinkTooltip', Piwik_Site::getNameFor($idSite));
		}
		catch (Exception $ex)
		{
			// if no idSite parameter, show no tooltip
		}
		
		$urlParams = array('module' => 'CoreHome', 'action' => 'index');
		Piwik_AddTopMenu('General_Dashboard', $urlParams, true, 1, $isHTML = false, $tooltip);
	}

	/**
	 * @param Piwik_Event_Notification $notification  notification object
	 */
	function getJsFiles( $notification )
	{
		$jsFiles = &$notification->getNotificationObject();
		
		$jsFiles[] = "plugins/Dashboard/templates/widgetMenu.js";
		$jsFiles[] = "libs/javascript/json2.js";
		$jsFiles[] = "plugins/Dashboard/templates/dashboardObject.js";
		$jsFiles[] = "plugins/Dashboard/templates/dashboardWidget.js";
		$jsFiles[] = "plugins/Dashboard/templates/dashboard.js";
	}

	/**
	 * @param Piwik_Event_Notification $notification  notification object
	 */
	function getCssFiles( $notification )
	{
		$cssFiles = &$notification->getNotificationObject();
		
		$cssFiles[] = "plugins/CoreHome/templates/datatable.css";
		$cssFiles[] = "plugins/Dashboard/templates/dashboard.css";
	}

	/**
	 * @param Piwik_Event_Notification $notification  notification object
	 */
	function deleteDashboardLayout($notification)
	{
		$userLogin = $notification->getNotificationObject();
		Piwik_Query('DELETE FROM ' . Piwik_Common::prefixTable('user_dashboard') . ' WHERE login = ?', array($userLogin));
	}

	public function install()
	{
		// we catch the exception
		try{
			$sql = "CREATE TABLE ". Piwik_Common::prefixTable('user_dashboard')." (
					login VARCHAR( 100 ) NOT NULL ,
					iddashboard INT NOT NULL ,
					name VARCHAR( 100 ) NULL DEFAULT NULL ,
					layout TEXT NOT NULL,
					PRIMARY KEY ( login , iddashboard )
					)  DEFAULT CHARSET=utf8 " ;
			Piwik_Exec($sql);
		} catch(Exception $e){
			// mysql code error 1050:table already exists
			// see bug #153 http://dev.piwik.org/trac/ticket/153
			// Ancud-IT GmbH    
			// -	Oracle error code for this is 0955  
			// -	the Oracle-adapter throws a Zend_Db_Exception
			//		if it finds a DDL-Statement,
			//		including the error code!
			if( Piwik_Common::isOracle()) {
				if( $e->getCode() != 955 ) {
				throw $e;
			}
			} else if( !Zend_Registry::get('db')->isErrNo($e, '1050')) {
				throw $e;
		}
	}
	}
	
	public function uninstall()
	{
		Piwik_DropTables(Piwik_Common::prefixTable('user_dashboard'));
	}
	
}
