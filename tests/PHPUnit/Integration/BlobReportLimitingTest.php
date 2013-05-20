<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

require_once PIWIK_INCLUDE_PATH . '/tests/PHPUnit/MockLocationProvider.php';

/**
 * Test Piwik's report limiting code. Make sure the datatable_archiving_maximum_rows_...
 * config options limit the size of certain reports when archiving.
 */
class Test_Piwik_Integration_BlobReportLimitingTest extends IntegrationTestCase
{
	protected static $dateTime  = '2010-01-03 01:22:33';

	protected static $idSite	= 1;

	public static function setUpBeforeClass()
	{
		self::setUpConfigOptions();
		parent::setUpBeforeClass($dbName = false, $createEmptyDatabase = true, $createConfig = false);
		try {
			self::setUpWebsitesAndGoals();
			self::setMockLocationProvider();
			self::trackVisits();
		} catch(Exception $e) {
			// Skip whole test suite if an error occurs while setup
			throw new PHPUnit_Framework_SkippedTestSuiteError($e->getMessage());
		}
	}
	
	public static function tearDownAfterClass()
	{
		self::unsetMockLocationProvider();
		parent::tearDownAfterClass();
	}

	public function getApiForTesting()
	{
		// TODO: test Provider plugin? Not sure if it's possible.
		$apiToCall = array(
			'Actions.getPageUrls', 'Actions.getPageTitles', 'Actions.getDownloads', 'Actions.getOutlinks',
			'CustomVariables.getCustomVariables',
			'Referers.getRefererType', 'Referers.getKeywords', 'Referers.getSearchEngines',
			'Referers.getWebsites', /* TODO 'Referers.getCampaigns', */
			'UserSettings.getResolution', 'UserSettings.getConfiguration', 'UserSettings.getOS',
			'UserSettings.getBrowserVersion',
			'UserCountry.getRegion', 'UserCountry.getCity',
		);
		
		return array(
			array($apiToCall, array('idSite' 	=> self::$idSite,
									'date'		=> self::$dateTime,
									'periods'	=> array('day')))
		);
	}
	
	/**
	 * @dataProvider getApiForTesting
	 * @group		Integration
	 * @group		BlobReportLimiting
	 */
	public function testApi($api, $params)
	{
		$this->runApiTests($api, $params);
	}
	
	/**
	 * @group		Integration
	 * @group		BlobReportLimiting
	 */
	public function testApiWithRankingQuery()
	{
		// custom setup
		self::deleteArchiveTables();
		$generalConfig['datatable_archiving_maximum_rows_referers'] = 4;
		$generalConfig['datatable_archiving_maximum_rows_subtable_referers'] = 4;
		$generalConfig['datatable_archiving_maximum_rows_actions'] = 4;
		$generalConfig['datatable_archiving_maximum_rows_subtable_actions'] = 4;
		$generalConfig['datatable_archiving_maximum_rows_standard'] = 4;
		Piwik_Config::getInstance()->General['archiving_ranking_query_row_limit'] = 3;
		Piwik_Actions_ArchivingHelper::reloadConfig();
		
		foreach ($this->getApiForTesting() as $pair)
		{
			list($apiToCall, $params) = $pair;
			$params['testSuffix'] = '_rankingQuery';
			
			$this->runApiTests($apiToCall, $params);
		}
	}

	public function getOutputPrefix()
	{
		return 'reportLimiting';
	}
	
	protected static function setUpConfigOptions()
	{
		self::createTestConfig();
		$generalConfig =& Piwik_Config::getInstance()->General;
		$generalConfig['datatable_archiving_maximum_rows_referers'] = 3;
		$generalConfig['datatable_archiving_maximum_rows_subtable_referers'] = 2;
		$generalConfig['datatable_archiving_maximum_rows_actions'] = 3;
		$generalConfig['datatable_archiving_maximum_rows_subtable_actions'] = 2;
		$generalConfig['datatable_archiving_maximum_rows_standard'] = 3;
		$generalConfig['archiving_ranking_query_row_limit'] = 50000;
	}

	protected static function setUpWebsitesAndGoals()
	{
		self::createWebsite(self::$dateTime);
	}

	protected static function trackVisits()
	{
		$linuxFirefoxA = "Mozilla/5.0 (X11; Linux i686; rv:6.0) Gecko/20100101 Firefox/6.0";
		$win7FirefoxA = "Mozilla/5.0 (Windows; U; Windows NT 6.1; fr; rv:1.9.1.6) Gecko/20100101 Firefox/6.0";
		$win7ChromeA = "Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/532.0 (KHTML, like Gecko) Chrome/3.0.195.38 Safari/532.0";
		$linuxChromeA = "Mozilla/5.0 (X11; Linux i686; rv:6.0) AppleWebKit/532.0 (KHTML, like Gecko) Chrome/3.0.195.38 Safari/532.0";
		$linuxSafariA = "Mozilla/5.0 (X11; U; Linux x86_64; en-us) AppleWebKit/531.2+ (KHTML, like Gecko) Version/5.0 Safari/531.2+";
		$iPadSafariA = "Mozilla/5.0 (iPad; CPU OS 6_0 like Mac OS X) AppleWebKit/531.2+ (KHTML, like Gecko) Version/5.0 Safari/531.2+";
		$iPadFirefoxB = "Mozilla/5.0 (iPad; CPU OS 6_0 like Mac OS X) Gecko/20100101 Firefox/14.0.1";
		$androidFirefoxB = "Mozilla/5.0 (Linux; U; Android 4.0.3; ko-kr; LG-L160L Build/IML74K) Gecko/20100101 Firefox/14.0.1";
		$androidChromeB = "Mozilla/5.0 (Linux; U; Android 4.0.3; ko-kr; LG-L160L Build/IML74K) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/22.0.1207.1 Safari/537.1";
		$androidIEA = "Mozilla/5.0 (compatible; MSIE 10.6; Linux; U; Android 4.0.3; ko-kr; LG-L160L Build/IML74K; Trident/5.0; InfoPath.2; SLCC1; .NET CLR 3.0.4506.2152; .NET CLR 3.5.30729; .NET CLR 2.0.50727) 3gpp-gba UNTRUSTED/1.0";
		$iPhoneOperaA = "Opera/9.80 (iPod; U; CPU iPhone OS 4_3_3 like Mac OS X; ja-jp) Presto/2.9.181 Version/12.00";
		$win8IEB = "Mozilla/5.0 (compatible; MSIE 10.0; Windows 8; Trident/5.0)";
		$winVistaIEB = "Mozilla/5.0 (compatible; MSIE 10.0; Windows Vista; Trident/5.0)";
		$osxOperaB = "Opera/9.80 (Macintosh; Intel Mac OS X 10.6.8; U; fr) Presto/2.9.168 Version/11.52";
		$userAgents = array(
			$linuxFirefoxA, $linuxFirefoxA, $win7FirefoxA, $win7ChromeA, $linuxChromeA, $linuxSafariA,
			$iPadSafariA, $iPadFirefoxB, $androidFirefoxB, $androidChromeB, $androidIEA, $iPhoneOperaA,
			$win8IEB, $winVistaIEB, $osxOperaB
		);
		
		$resolutions = array(
			"1920x1080", "1920x1080", "1920x1080", "1920x1080", "1366x768", "1366x768", "1366x768",
			"1280x1024", "1280x1024", "1280x1024", "1680x1050", "1680x1050", "1024x768", "800x600",
			"320x480"
		);
		
		$referrers = array(
			// website referrers (8)
			'http://whatever0.com/0', 'http://whatever0.com/0', 'http://whatever0.com/1', 'http://whatever0.com/2',
			'http://whatever1.com/0', 'http://whatever.com1/1', 'http://whatever1.com/2', 'http://whatever3.com/3',
			
			// search engines w/ keyword (12)
			'http://www.google.com/search?q=this+search+term',
			'http://www.google.com/search?q=that+search+term',
			'http://search.yahoo.com/search?p=this+search+term',
			'http://search.yahoo.com/search?p=that+search+term',
			'http://www.ask.com/web?q=this+search+term',
			'http://www.bing.com/search?q=search+term+1',
			'http://search.babylon.com/?q=search+term+2',
			'http://alexa.com/search?q=search+term+2',
			'http://www.google.com/search?q=search+term+3',
			'http://search.yahoo.com/search?p=search+term+4',
			'http://www.ask.com/web?q=search+term+3',
			'http://www.bing.com/search?q=search+term+4',
		);
		
		$customVars = array(
			'name' => array('thing0', 'thing1', 'thing2', 'thing3', 'thing4', 'thing5', 'thing6', 'thing7',
							'thing8', 'thing9', 'thing10', 'thing11', 'thing12', 'thing13', 'thing14',
							'thing15', 'thing16', 'thing17', 'thing18', 'thing19'),
			'rating' => array(1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 1, 2, 3, 4, 5, 6, 7, 8, 9, 20),
			'tweeted' => array('y', 'n', 'm', 'n', 'y', 'n', 'y', 'n', 'y', 'n', 'y', 'n', 'y', 'n', 'y', 'n',
							   'm', 'n', 'm', 'n'),
			'liked' => array('yes', 'y', 'y', 'no', 'y', 'y', 'y', 'y', 'y', 'y', 'y', 'y', 'y', 'y', 'y', 'y',
							 'y', 'y', 'no', 'n'),
		);
		$downloadCustomVars = array(
			'size' => array(1024, 1024, 1024, 2048, 2048, 3072, 3072, 3072, 3072, 4096, 4096, 4096,
							512, 512, 256, 128, 64, 32, 48, 48)
		);
		
		$visitorCounter = 0;
		$t = self::getTracker(self::$idSite, self::$dateTime, $defaultInit = true, $useLocal = true);
		
		// track regular actions
		self::trackActions($t, $visitorCounter, 'pageview', $userAgents, $resolutions, $referrers, $customVars);
		
		// track downloads
		self::trackActions($t, $visitorCounter, 'download', $userAgents, $resolutions, null, $downloadCustomVars);
		
		// track outlinks
		self::trackActions($t, $visitorCounter, 'outlink', $userAgents, $resolutions);
	}
	
	private static function trackActions($t, &$visitorCounter, $actionType, $userAgents, $resolutions,
											$referrers = null, $customVars = null)
	{
		for ($i = 0; $i != 5; ++$i, ++$visitorCounter)
		{
			$visitDate = Piwik_Date::factory(self::$dateTime);
			
			$t->setNewVisitorId();
			$t->setIp("156.5.3.$visitorCounter");
			
			$t->setUserAgent($userAgents[$visitorCounter]);
			list($w, $h) = explode('x', $resolutions[$visitorCounter]);
			$t->setResolution((int)$w, (int)$h);
			
			// one visit to root url
			$t->setUrl("http://piwik.net/$visitorCounter/");
			$t->setUrlReferrer(null);
			$t->setForceVisitDateTime($visitDate->getDatetime());
			self::trackAction($t, $actionType, $visitorCounter, null);
			
			for ($j = 0; $j != 4; ++$j)
			{
				// NOTE: to test referers w/o creating too many visits, we don't actually track 4 actions, but
				//	   4 separate visits
				$actionDate = $visitDate->addHour($j + 1);
				
				$actionIdx = $i * 4 + $j;
				$actionNum = $visitorCounter * 4 + $j;
				
				$t->setUrl("http://piwik.net/$visitorCounter/$actionNum");
				$t->setForceVisitDateTime($actionDate->getDatetime());
				
				if (!is_null($referrers))
				{
					$t->setUrlReferrer($referrers[$actionIdx]);
				}
				else
				{
					$t->setUrlReferrer(null);
				}
				
				if (!is_null($customVars))
				{
					$k = 1;
					foreach ($customVars as $name => $values)
					{
						$value = $values[$actionIdx];
						$t->setCustomVariable($k, $name, $value, $scope = 'page');
						
						++$k;
					}
				}
				
				self::trackAction($t, $actionType, $visitorCounter, $actionNum);
			}
		}
	}
	
	private static function trackAction($t, $actionType, $visitorCounter, $actionNum)
	{
		if ($actionType == 'pageview')
		{
			self::checkResponse($t->doTrackPageView(
				is_null($actionNum) ? "title_$visitorCounter" : "title_$visitorCounter / title_$actionNum"));
		}
		else if ($actionType == 'download')
		{
			$root = is_null($actionNum) ? "http://cloudsite$visitorCounter.com"
				: "http://cloudsite$visitorCounter.com/$actionNum";
			
			self::checkResponse($t->doTrackAction("$root/download", 'download'));
		}
		else if ($actionType == 'outlink')
		{
			self::checkResponse($t->doTrackAction(is_null($actionNum) ? "http://othersite$visitorCounter.com/"
				: "http://othersite$visitorCounter.com/$actionNum/", 'link'));
		}
	}
	
	public static function setMockLocationProvider()
	{
		Piwik_UserCountry_LocationProvider::setCurrentProvider('mock_provider');
		Piwik_UserCountry_LocationProvider::getCurrentProvider()->setLocations(array(
			self::makeLocation('Toronto', 'ON', 'CA'),
			
			self::makeLocation('Nice', 'B8', 'FR'),

			self::makeLocation('Melbourne', '07', 'AU'),

			self::makeLocation('Yokohama', '19', 'JP'),
		));
	}
	
	public static function unsetMockLocationProvider()
	{
		Piwik_UserCountry_LocationProvider::setCurrentProvider('default');
	}
	
	public static function makeLocation( $city, $region, $country )
	{
		return array(Piwik_UserCountry_LocationProvider::CITY_NAME_KEY => $city,
					  Piwik_UserCountry_LocationProvider::REGION_CODE_KEY => $region,
					  Piwik_UserCountry_LocationProvider::COUNTRY_CODE_KEY => $country);
	}
}
