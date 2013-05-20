<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
require_once PIWIK_INCLUDE_PATH . '/libs/PiwikTracker/PiwikTracker.php';

/**
 * Base class for Integration tests.
 *
 * Provides helpers to track data and then call API get* methods to check outputs automatically.
 *
 */
abstract class IntegrationTestCase extends PHPUnit_Framework_TestCase
{
    /**
     * Identifies the last language used in an API/Controller call.
     *
     * @var string
     */
    protected $lastLanguage;
	
	/**
	 * Creates a config object for use w/ tests.
	 */
	public static function createTestConfig()
	{
        Piwik::createConfigObject();
        Piwik_Config::getInstance()->setTestEnvironment();
	}
	
	/**
	 * Connects to MySQL w/o specifying a database.
     * Ancud-IT ... but keep database name for Oracle,
     * as it is the SID and abolutely necessary
     * for establishing a connection at all!
	 */
	public static function connectWithoutDatabase()
	{
        $dbConfig = Piwik_Config::getInstance()->database;
        $oldDbName = $dbConfig['dbname'];
		$adapter = $dbConfig['adapter'];
        $dbConfig['dbname'] = $adapter == 'ORACLE' ? $oldDbName : null;
        
        Piwik::createDatabaseObject($dbConfig);
        
        $dbConfig['dbname'] = $oldDbName;
	}

    public static function setUpBeforeClass( $dbName = false, $createEmptyDatabase = true, $createConfig = true )
    {
        try {
        	if ($createConfig)
        	{
	        	self::createTestConfig();
        	}
        	
        	if ($dbName === false) // must be after test config is created
        	{
        		$dbName = Piwik_Config::getInstance()->database['dbname'];
        	}
        	
        	self::connectWithoutDatabase();
            if ($createEmptyDatabase)
            {
                Piwik::dropDatabase();
            }
            Piwik::createDatabase($dbName);
            Piwik::disconnectDatabase();
			
			// reconnect once we're sure the database exists
			Piwik_Config::getInstance()->database['dbname'] = $dbName;
            Piwik::createDatabaseObject();
            
            Piwik::createTables();
            Piwik::createLogObject();

            Piwik_PluginsManager::getInstance()->loadPlugins(array());

        } catch(Exception $e) {
            self::fail("TEST INITIALIZATION FAILED: " .$e->getMessage());
        }

        include "DataFiles/SearchEngines.php";
        include "DataFiles/Languages.php";
        include "DataFiles/Countries.php";
        include "DataFiles/Currencies.php";
        include "DataFiles/LanguageToCountry.php";

        Piwik::createAccessObject();
        Piwik_PostEvent('FrontController.initAuthenticationObject');

        // We need to be SU to create websites for tests
        Piwik::setUserIsSuperUser();

        // Load and install plugins
        $pluginsManager = Piwik_PluginsManager::getInstance();
        $plugins = $pluginsManager->readPluginsDirectory();

        $pluginsManager->loadPlugins( $plugins );
        if ($createEmptyDatabase) // only install if database is empty
        {
	        $pluginsManager->installLoadedPlugins();
        }

        $_GET = $_REQUEST = array();
        $_SERVER['HTTP_REFERER'] = '';

        // Make sure translations are loaded to check messages in English
        Piwik_Translate::getInstance()->loadEnglishTranslation();
        Piwik_LanguagesManager_API::getInstance()->setLanguageForUser('superUserLogin', 'en');

        // List of Modules, or Module.Method that should not be called as part of the XML output compare
        // Usually these modules either return random changing data, or are already tested in specific unit tests.
        self::setApiNotToCall(self::$defaultApiNotToCall);
        self::setApiToCall( array());
    }

    public static function tearDownAfterClass($dropDatabase = true)
    {
		$plugins = Piwik_PluginsManager::getInstance()->getLoadedPlugins();
		foreach ($plugins AS $plugin)
		{
			if ($dropDatabase)
			{
				try 
				{
					$plugin->uninstall();
				} catch (Exception $e) {
					echo "\n There was an error uninstalling a plugin: " . $e->getMessage() . "\n";
				}
			}
		}
		Piwik_PluginsManager::getInstance()->unloadPlugins();
       
        if ($dropDatabase)
        {
            Piwik::dropDatabase();
        }
        Piwik_DataTable_Manager::getInstance()->deleteAll();
        Piwik_Option::getInstance()->clearCache();
        Piwik_Site::clearCache();
        Piwik_Common::deleteTrackerCache();
        Piwik_Config::getInstance()->clear();
        Piwik_TablePartitioning::$tablesAlreadyInstalled = null;
        Piwik_PDFReports_API::$cache = array ();
        Zend_Registry::_unsetInstance();

        $_GET     = $_REQUEST = array ();
        Piwik_Translate::getInstance()->unloadEnglishTranslation();

        // re-enable tag cloud shuffling
        Piwik_Visualization_Cloud::$debugDisableShuffle = true;
    }

    public function setUp()
    {
        $this->changeLanguage('en');
    }

    protected static $apiToCall = array();
    protected static $apiNotToCall = array();

    public static $defaultApiNotToCall = array(
        'LanguagesManager',
        'DBStats',
        'UsersManager',
        'SitesManager',
        'ExampleUI',
        'Live',
        'SEO',
        'ExampleAPI',
        'PDFReports',
        'MobileMessaging',
        'Transitions',
        'API',
        'ImageGraph',
    );

    const DEFAULT_USER_PASSWORD = 'nopass';
    
    protected $missingExpectedFiles = array();
    protected $comparisonFailures = array();

    /**
     * Forces the test to only call and fetch XML for the specified plugins,
     * or exact API methods.
     * If not called, all default tests will be executed.
     *
     * @param array $apiToCall array( 'ExampleAPI', 'Plugin.getData' )
     *
     * @throws Exception
     * @return void
     */
    protected static function setApiToCall( $apiToCall )
    {
        if(func_num_args() != 1)
        {
            throw new Exception('setApiToCall expects an array');
        }
        if(!is_array($apiToCall))
        {
            $apiToCall = array($apiToCall);
        }
        self::$apiToCall = $apiToCall;
    }

    /**
     * Sets a list of API methods to not call during the test
     *
     * @param string $apiNotToCall eg. 'ExampleAPI.getPiwikVersion'
     *
     * @return void
     */
    protected static function setApiNotToCall( $apiNotToCall )
    {
        if(!is_array($apiNotToCall))
        {
            $apiNotToCall = array($apiNotToCall);
        }
        self::$apiNotToCall = $apiNotToCall;
    }

    /**
     * Returns a PiwikTracker object that you can then use to track pages or goals.
     *
     * @param         $idSite
     * @param         $dateTime
     * @param boolean $defaultInit If set to true, the tracker object will have default IP, user agent, time, resolution, etc.
     *
     * @return PiwikTracker
     */
    public static function getTracker($idSite, $dateTime, $defaultInit = true, $useLocal = false )
    {
    	if ($useLocal)
    	{
			require_once PIWIK_INCLUDE_PATH . '/tests/LocalTracker.php';
    		$t = new Piwik_LocalTracker($idSite, self::getTrackerUrl());
    	}
    	else
    	{
	        $t = new PiwikTracker( $idSite, self::getTrackerUrl());
        }
        $t->setForceVisitDateTime($dateTime);

        if($defaultInit)
        {
            $t->setIp('156.5.3.2');

            // Optional tracking
            $t->setUserAgent( "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-GB; rv:1.9.2.6) Gecko/20100625 Firefox/3.6.6 (.NET CLR 3.5.30729)");
            $t->setBrowserLanguage('fr');
            $t->setLocalTime( '12:34:06' );
            $t->setResolution( 1024, 768 );
            $t->setBrowserHasCookies(true);
            $t->setPlugins($flash = true, $java = true, $director = false);
        }
        return $t;
    }

    /**
     * Creates a website, then sets its creation date to a day earlier than specified dateTime
     * Useful to create a website now, but force data to be archived back in the past.
     *
     * @param string  $dateTime eg '2010-01-01 12:34:56'
     * @param int     $ecommerce
     * @param string  $siteName
     *
     * @return int    idSite of website created
     */
    public static function createWebsite( $dateTime, $ecommerce = 0, $siteName = 'Piwik test', $siteUrl = false, $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null )
    {
        $idSite = Piwik_SitesManager_API::getInstance()->addSite(
            $siteName,
            $siteUrl === false ? "http://piwik.net/" : $siteUrl,
            $ecommerce,
	        $siteSearch , $searchKeywordParameters, $searchCategoryParameters,
            $ips = null,
            $excludedQueryParameters = null,
            $timezone = null,
            $currency = null
        );

        // Manually set the website creation date to a day earlier than the earliest day we record stats for
        Zend_Registry::get('db')->update(Piwik_Common::prefixTable("site"),
            array('ts_created' => Piwik_Date::factory($dateTime)->subDay(1)->getDatetime()),
            "idsite = $idSite"
        );

        // Clear the memory Website cache
        Piwik_Site::clearCache();

        return $idSite;
    }

	/**
	 * Create one MAIL and two MOBILE scheduled reports
	 *
	 * Reports sent by mail can contain PNG graphs when the user specifies it.
	 * Depending on the system under test, generated images differ slightly.
	 * Because of this discrepancy, PNG graphs are only tested if the system under test
	 * has the characteristics described in 'canImagesBeIncludedInScheduledReports'
	 *
	 * @see canImagesBeIncludedInScheduledReports
	 * @param int $idSite id of website created
	 */
	protected static function setUpScheduledReports($idSite)
	{
		$includeImages = self::canImagesBeIncludedInScheduledReports();

		// fake access is needed so API methods can call Piwik::getCurrentUserLogin(), e.g: 'PDFReports.addReport'
		$pseudoMockAccess = new FakeAccess;
		FakeAccess::$superUser = true;
		Zend_Registry::set('access', $pseudoMockAccess);

		// retrieve available reports
		$availableReportMetadata = Piwik_PDFReports_API::getReportMetadata($idSite, Piwik_PDFReports::EMAIL_TYPE);

		$availableReportIds = array();
		foreach($availableReportMetadata as $reportMetadata)
		{
			$availableReportIds[] = $reportMetadata['uniqueId'];
		}

		//@review should we also test evolution graphs?
		// set-up mail report
		Piwik_PDFReports_API::getInstance()->addReport(
			$idSite,
			'Mail Test report',
			'day', // overridden in getApiForTestingScheduledReports()
			Piwik_PDFReports::EMAIL_TYPE,
			Piwik_ReportRenderer::HTML_FORMAT, // overridden in getApiForTestingScheduledReports()
			$availableReportIds,
			array("displayFormat" => $includeImages ? Piwik_PDFReports::DISPLAY_FORMAT_TABLES_AND_GRAPHS : Piwik_PDFReports::DISPLAY_FORMAT_TABLES_ONLY)
		);

		// set-up sms report for one website
		Piwik_PDFReports_API::getInstance()->addReport(
			$idSite,
			'SMS Test report, one website',
			'day', // overridden in getApiForTestingScheduledReports()
			Piwik_MobileMessaging::MOBILE_TYPE,
			Piwik_MobileMessaging::SMS_FORMAT,
			array("MultiSites_getOne"),
			array("phoneNumbers"=>array())
		);

		// set-up sms report for all websites
		Piwik_PDFReports_API::getInstance()->addReport(
			$idSite,
			'SMS Test report, all websites',
			'day', // overridden in getApiForTestingScheduledReports()
			Piwik_MobileMessaging::MOBILE_TYPE,
			Piwik_MobileMessaging::SMS_FORMAT,
			array("MultiSites_getAll"),
			array("phoneNumbers"=>array())
		);
	}

	protected function alertWhenImagesExcludedFromTests()
	{
		if(!self::canImagesBeIncludedInScheduledReports())
		{
			$this->markTestSkipped(
				'Do take note that scheduled reports are not being tested with images. ' .
					'If images contained in scheduled reports have been altered, tests will fail on the Piwik QA Server. ' .
					'To include images in the test suite, please use a machine with the following specifications :' // TODO update with new Piwik QA Server
			);
		}
	}

	/**
	 * Return true if system under test has the following characteristics :
	 *  - // TODO update doc with new Piwik QA Server technical characteristics
	 *
	 */
	private static function canImagesBeIncludedInScheduledReports()
	{
		// TODO update to match new Piwik QA Server
			$gdInfo = gd_info();
			return $gdInfo['GD Version'] == 'bundled (2.0.34 compatible)';

		//	stristr(php_uname(),'Linux precise32') &&
		//	phpversion() == '5.3.10-1ubuntu3.2' &&
		//  $gdInfo['GD Version'] == '2.0';
	}

	/**
	 * Return 4 Api Urls for testing scheduled reports :
	 * - one in HTML format with all available reports
	 * - one in PDF format with all available reports
	 * - two in SMS (one for each available report: MultiSites.getOne & MultiSites.getAll)
	 *
	 * @param string $dateTime eg '2010-01-01 12:34:56'
	 * @param string $period eg 'day', 'week', 'month', 'year'
	 */
	protected static function getApiForTestingScheduledReports($dateTime, $period)
	{
		$reportContentPostfix = self::canImagesBeIncludedInScheduledReports() ? '_tables_and_graph' : '_tables_only';

		return array(
			// HTML Scheduled Report
			array(
				'PDFReports.generateReport',
				array(
					'testSuffix' => '_scheduled_report_in_html' . $reportContentPostfix,
					'date' => $dateTime,
					'periods' => array($period),
					'format' => 'original',
					'fileExtension' => 'html',
					'otherRequestParameters' => array(
						'idReport' => 1,
						'reportFormat' => Piwik_ReportRenderer::HTML_FORMAT,
						'outputType' => Piwik_PDFReports_API::OUTPUT_RETURN
					)
				)
			),
			// PDF Scheduled Report
			array(
				'PDFReports.generateReport',
				array(
					'testSuffix' => '_scheduled_report_in_pdf' . $reportContentPostfix,
					'date' => $dateTime,
					'periods' => array($period),
					'format' => 'original',
					'fileExtension' => 'pdf',
					'otherRequestParameters' => array(
						'idReport' => 1,
						'reportFormat' => Piwik_ReportRenderer::PDF_FORMAT,
						'outputType' => Piwik_PDFReports_API::OUTPUT_RETURN
					)
				)
			),
			// SMS Scheduled Report, one site
			array(
				'PDFReports.generateReport',
				array(
					'testSuffix' => '_scheduled_report_via_sms_one_site',
					'date' => $dateTime,
					'periods' => array($period),
					'format' => 'original',
					'fileExtension' => 'sms.txt',
					'otherRequestParameters' => array(
						'idReport' => 2,
						'outputType' => Piwik_PDFReports_API::OUTPUT_RETURN
					)
				)
			),
			// SMS Scheduled Report, all sites
			array(
				'PDFReports.generateReport',
				array(
					'testSuffix' => '_scheduled_report_via_sms_all_sites',
					'date' => $dateTime,
					'periods' => array($period),
					'format' => 'original',
					'fileExtension' => 'sms.txt',
					'otherRequestParameters' => array(
						'idReport' => 3,
						'outputType' => Piwik_PDFReports_API::OUTPUT_RETURN
					)
				)
			)
		);
	}

	/**
     * Checks that the response is a GIF image as expected.
     * Will fail the test if the response is not the expected GIF
     *
     * @param $response
     */
    protected static function checkResponse($response)
    {
        $trans_gif_64 = "R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==";
        $expectedResponse = base64_decode($trans_gif_64);
        self::assertEquals($expectedResponse, $response, "Expected GIF beacon, got: <br/>\n"
	        . var_export($response, true)
	        . "\n If you are stuck, you can enable \$GLOBALS['PIWIK_TRACKER_DEBUG']=true; in piwik.php to get more debug info."
//            .base64_encode($response) // uncomment to further debug when the GIF hides the error
        );
    }

	/**
	 * Returns URL to Piwik root.
	 *
	 * @return string
	 */
	protected static function getRootUrl()
	{
		$piwikUrl = Piwik_Url::getCurrentUrlWithoutFileName();

		$pathBeforeRoot = 'tests';
		// Running from a plugin
		if(strpos($piwikUrl, 'plugins/') !== false)
		{
			$pathBeforeRoot = 'plugins';
		}

		$piwikUrl = substr($piwikUrl, 0, strpos($piwikUrl, $pathBeforeRoot.'/'));
		return $piwikUrl;
	}

	/**
	 * Returns URL to the proxy script, used to ensure piwik.php
	 * uses the test environment, and allows variable overwriting
	 *
	 * @return string
	 */
	public static function getTrackerUrl()
	{
		return self::getRootUrl().'tests/PHPUnit/proxy/piwik.php';
	}

	/**
	 * Returns the super user token auth that can be used in tests. Can be used to
	 * do bulk tracking.
	 *
	 * @return string
	 */
	public static function getTokenAuth()
	{
        // get token auth
	    $pwd = Zend_Registry::get('config')->superuser->password;
	    if(strlen($pwd) != 32) $pwd = md5($pwd);

	    return Piwik_UsersManager_API::getInstance()->getTokenAuth(
	        Zend_Registry::get('config')->superuser->login, $pwd);
    }

    /**
     * Given a list of default parameters to set, returns the URLs of APIs to call
     * If any API was specified in setApiToCall() we ensure only these are tested.
     * If any API is set as excluded (see list below) then it will be ignored.
     *
     * @param array       $parametersToSet Parameters to set in api call
     * @param array       $formats         Array of 'format' to fetch from API
     * @param array       $periods         Array of 'period' to query API
     * @param bool        $supertableApi
     * @param bool        $setDateLastN    If set to true, the 'date' parameter will be rewritten to query instead a range of dates, rather than one period only.
     * @param bool|string $language        2 letter language code, defaults to default piwik language
     * @param bool|string $segment
     * @param bool|string $fileExtension
     *
     * @throws Exception
     * @return array of API URLs query strings
     */
    protected function generateUrlsApi( $parametersToSet, $formats, $periods, $supertableApi = false, $setDateLastN = false, $language = false, $segment = false, $fileExtension = false )
    {
        // Get the URLs to query against the API for all functions starting with get*
        $skipped = $requestUrls = array();
        $apiMetadata = new Piwik_API_DocumentationGenerator;
        foreach(Piwik_API_Proxy::getInstance()->getMetadata() as $class => $info)
        {
            $moduleName = Piwik_API_Proxy::getInstance()->getModuleNameFromClassName($class);
            foreach($info as $methodName => $infoMethod)
            {
                $apiId = $moduleName.'.'.$methodName;

                // If Api to test were set, we only test these
                if(!empty(self::$apiToCall)
                    && in_array($moduleName, self::$apiToCall) === false
                    && in_array($apiId, self::$apiToCall) === false)
                {
                    $skipped[] = $apiId;
                    continue;
                }
                // Excluded modules from test
                elseif(
                    ((strpos($methodName, 'get') !== 0 && $methodName != 'generateReport')
                        || in_array($moduleName, self::$apiNotToCall) === true
                        || in_array($apiId, self::$apiNotToCall) === true
                        || $methodName == 'getLogoUrl'
                        || $methodName == 'getHeaderLogoUrl'
                    )
                )
                {
                    $skipped[] = $apiId;
                    continue;
                }

                foreach($periods as $period)
                {
                    $parametersToSet['period'] = $period;

                    // If date must be a date range, we process this date range by adding 6 periods to it
                    if($setDateLastN)
                    {
                        if(!isset($parametersToSet['dateRewriteBackup']))
                        {
                            $parametersToSet['dateRewriteBackup'] = $parametersToSet['date'];
                        }

                        $lastCount = (int)$setDateLastN;
                        if($setDateLastN === true)
                        {
                            $lastCount = 6;
                        }
                        $firstDate = $parametersToSet['dateRewriteBackup'];
                        $secondDate = date('Y-m-d', strtotime("+$lastCount " . $period . "s", strtotime($firstDate)));
                        $parametersToSet['date'] = $firstDate . ',' . $secondDate;
                    }

                    // Set response language
                    if($language !== false)
                    {
                        $parametersToSet['language'] = $language;
                    }

                    // set idSubtable if subtable API is set
                    if ($supertableApi !== false)
                    {
                    	$request = new Piwik_API_Request(array(
                    		'module' => 'API',
                    		'method' => $supertableApi,
                    		'idSite' => $parametersToSet['idSite'],
                    		'period' => $parametersToSet['period'],
                    		'date' => $parametersToSet['date'],
                    		'format' => 'php',
                    		'serialize' => 0,
                    	));

                    	// find first row w/ subtable
                    	foreach ($request->process() as $row)
                    	{
                    		if (isset($row['idsubdatatable']))
                    		{
                    			$parametersToSet['idSubtable'] = $row['idsubdatatable'];
                    			break;
                    		}
                    	}

                    	// if no subtable found, throw
                    	if (!isset($parametersToSet['idSubtable']))
                    	{
	                    	throw new Exception(
	                    		"Cannot find subtable to load for $apiId in $supertableApi.");
                    	}
                    }

                    // Generate for each specified format
                    foreach($formats as $format)
                    {
                        $parametersToSet['format'] = $format;
                        $parametersToSet['hideIdSubDatable'] = 1;
                        $parametersToSet['serialize'] = 1;

                        $exampleUrl = $apiMetadata->getExampleUrl($class, $methodName, $parametersToSet);
                        if($exampleUrl === false)
                        {
                            $skipped[] = $apiId;
                            continue;
                        }

                        // Remove the first ? in the query string
                        $exampleUrl = substr($exampleUrl, 1);
                        $apiRequestId = $apiId;
                        if(strpos($exampleUrl, 'period=') !== false)
                        {
                            $apiRequestId .= '_' . $period;
                        }

                        $apiRequestId .= '.' . $format;

						if($fileExtension)
						{
							$apiRequestId .= '.' . $fileExtension;
						}

                        $requestUrls[$apiRequestId] = $exampleUrl;
                    }
                }
            }
        }
        return $requestUrls;
    }

    /**
     * Will return all api urls for the given data
     *
     * @param string|array      $formats        String or array of formats to fetch from API
     * @param int|bool          $idSite         Id site
     * @param string|bool       $dateTime       Date time string of reports to request
     * @param array|bool|string $periods        String or array of strings of periods (day, week, month, year)
     * @param bool              $setDateLastN   When set to true, 'date' parameter passed to API request will be rewritten to query a range of dates rather than 1 date only
     * @param string|bool       $language       2 letter language code to request data in
     * @param string|bool       $segment        Custom Segment to query the data  for
     * @param string|bool       $visitorId      Only used for Live! API testing
     * @param bool              $abandonedCarts Only used in Goals API testing
     * @param bool              $idGoal
     * @param bool              $apiModule
     * @param bool              $apiAction
     * @param array             $otherRequestParameters
     * @param array|bool        $supertableApi
     * @param array|bool        $fileExtension
     *
     * @return array
     */
    protected function _generateApiUrls($formats = 'xml', $idSite = false, $dateTime = false, $periods = false,
										 $setDateLastN = false, $language = false, $segment = false, $visitorId = false,
										 $abandonedCarts = false, $idGoal = false, $apiModule = false, $apiAction = false,
										 $otherRequestParameters = array(), $supertableApi = false, $fileExtension = false)
    {
        list($pathProcessed, $pathExpected) = $this->getProcessedAndExpectedDirs();

        if($periods === false)
        {
            $periods = 'day';
        }
        if(!is_array($periods))
        {
            $periods = array($periods);
        }
        if(!is_array($formats))
        {
            $formats = array($formats);
        }
        if(!is_writable($pathProcessed))
        {
            $this->fail('To run the tests, you need to give write permissions to the following directory (create it if it doesn\'t exist).<code><br/>mkdir '. $pathProcessed.'<br/>chmod 777 '.$pathProcessed.'</code><br/>');
        }
        $parametersToSet = array(
            'idSite'           => $idSite,
            'date'             => $periods == array('range') ? $dateTime : date('Y-m-d', strtotime($dateTime)),
            'expanded'         => '1',
            'piwikUrl'         => 'http://example.org/piwik/',
            // Used in getKeywordsForPageUrl
            'url'              => 'http://example.org/store/purchase.htm',

            // Used in Actions.getPageUrl, .getDownload, etc.
            // tied to Main.test.php doTest_oneVisitorTwoVisits
            // will need refactoring when these same API functions are tested in a new function
            'downloadUrl'      => 'http://piwik.org/path/again/latest.zip?phpsessid=this is ignored when searching',
            'outlinkUrl'       => 'http://dev.piwik.org/svn',
            'pageUrl'          => 'http://example.org/index.htm?sessionid=this is also ignored by default',
            'pageName'         => ' Checkout / Purchasing... ',

            // do not show the millisec timer in response or tests would always fail as value is changing
            'showTimer'        => 0,

            'language'         => $language ? $language : 'en',
            'abandonedCarts'   => $abandonedCarts ? 1 : 0,
            'idSites'          => $idSite,
        );
        $parametersToSet = array_merge($parametersToSet, $otherRequestParameters);
        if(!empty($visitorId ))
        {
            $parametersToSet['visitorId'] = $visitorId;
        }
        if(!empty($apiModule ))
        {
            $parametersToSet['apiModule'] = $apiModule;
        }
        if(!empty($apiAction))
        {
            $parametersToSet['apiAction'] = $apiAction;
        }
        if(!empty($segment))
        {
            $parametersToSet['segment'] = $segment;
        }
        if($idGoal !== false)
        {
            $parametersToSet['idGoal'] = $idGoal;
        }

        $requestUrls = $this->generateUrlsApi($parametersToSet, $formats, $periods, $supertableApi, $setDateLastN, $language, $segment, $fileExtension);
        return $requestUrls;
    }

    protected function _testApiUrl($testName, $apiId, $requestUrl)
    {
        $isLiveMustDeleteDates = strpos($requestUrl, 'Live.getLastVisits') !== false;
		
        $request               = new Piwik_API_Request($requestUrl);
        $dateTime             = Piwik_Common::getRequestVar('date', '', 'string', Piwik_Common::getArrayFromQueryString($requestUrl));

        list($processedFilePath, $expectedFilePath) = $this->getProcessedAndExpectedPaths($testName, $apiId);

        // Cast as string is important. For example when calling
        // with format=original, objects or php arrays can be returned.
        // we also hide errors to prevent the 'headers already sent' in the ResponseBuilder (which sends Excel headers multiple times eg.)
        $response = (string)$request->process();

        if ($isLiveMustDeleteDates) {
            $response = $this->removeAllLiveDatesFromXml($response);
        }

		// normalize date markups and document ID in pdf files :
		// - /LastModified (D:20120820204023+00'00')
		// - /CreationDate (D:20120820202226+00'00')
		// - /ModDate (D:20120820202226+00'00')
		// - /M (D:20120820202226+00'00')
		// - /ID [ <0f5cc387dc28c0e13e682197f485fe65> <0f5cc387dc28c0e13e682197f485fe65> ]
		$response = preg_replace('/\(D:[0-9]{14}/', '(D:19700101000000', $response);
		$response = preg_replace('/\/ID \[ <.*> ]/', '', $response);

        file_put_contents($processedFilePath, $response);

        $expected = $this->loadExpectedFile($expectedFilePath);
        if (empty($expected)) {
            return;
        }

        // @todo This should not vary between systems AFAIK... "idsubdatatable can differ"
        $expected = $this->removeXmlElement($expected, 'idsubdatatable', $testNotSmallAfter = false);
        $response = $this->removeXmlElement($response, 'idsubdatatable', $testNotSmallAfter = false);

        if ($isLiveMustDeleteDates) {
            $expected = $this->removeAllLiveDatesFromXml($expected);
        } // If date=lastN the <prettyDate> element will change each day, we remove XML element before comparison
        elseif (strpos($dateTime, 'last') !== false
            || strpos($dateTime, 'today') !== false
            || strpos($dateTime, 'now') !== false
        ) {
            if (strpos($requestUrl, 'API.getProcessedReport') !== false) {
                $expected = $this->removePrettyDateFromXml($expected);
                $response = $this->removePrettyDateFromXml($response);
            }
            // avoid build failure when running just before midnight, generating visits in the future
            $expected = $this->removeXmlElement($expected, 'sum_daily_nb_uniq_visitors');
            $response = $this->removeXmlElement($response, 'sum_daily_nb_uniq_visitors');
            $expected = $this->removeXmlElement($expected, 'nb_visits_converted');
            $response = $this->removeXmlElement($response, 'nb_visits_converted');
            
            
             if (strpos($requestUrl, 'date=') !== false)
            {
            	$regex = "/date=[-0-9,%Ca-z]+/"; // need to remove %2C which is encoded ,
            	$expected = preg_replace($regex, 'date=', $expected);
            	$response = preg_replace($regex, 'date=', $response);
            } 
        }

        // if idSubtable is in request URL, make sure idSubtable values are not in any urls
        if (strpos($requestUrl, 'idSubtable=') !== false)
        {
        	$regex = "/idSubtable=[0-9]+/";
        	$expected = preg_replace($regex, 'idSubtable=', $expected);
        	$response = preg_replace($regex, 'idSubtable=', $response);
        }

        // is there a better way to test for the current DB type in use?
        if (Zend_Registry::get('db') instanceof Piwik_Db_Adapter_Mysqli) {
            // Do not test for TRUNCATE(SUM()) returning .00 on mysqli since this is not working
            // http://bugs.php.net/bug.php?id=54508
            $expected = str_replace('.00</revenue>', '</revenue>', $expected);
            $response = str_replace('.00</revenue>', '</revenue>', $response);
            $expected = str_replace('.1</revenue>', '</revenue>', $expected);
            $expected = str_replace('.11</revenue>', '</revenue>', $expected);
            $response = str_replace('.11</revenue>', '</revenue>', $response);
            $response = str_replace('.1</revenue>', '</revenue>', $response);
        }

		try
		{
		    if (strpos($requestUrl, 'format=xml') !== false) {
		        $this->assertXmlStringEqualsXmlString($expected, $response, "Differences with expected in: $processedFilePath");
		    } else {
		        $this->assertEquals(strlen($expected), strlen($response), "Differences with expected in: $processedFilePath");
		        $this->assertEquals($expected, $response, "Differences with expected in: $processedFilePath");
		    }
		    
		    if (trim($response) == trim($expected)) {
		        file_put_contents($processedFilePath, $response);
		    }
	    }
	    catch (Exception $ex)
	    {
	    	$this->comparisonFailures[] = $ex;
	    }
    }

    protected function removeAllLiveDatesFromXml($input)
    {
        $toRemove = array(
            'serverDate',
            'firstActionTimestamp',
            'lastActionTimestamp',
            'lastActionDateTime',
            'serverTimestamp',
            'serverTimePretty',
            'serverDatePretty',
            'serverDatePrettyFirstAction',
            'serverTimePrettyFirstAction',
            'goalTimePretty',
            'serverTimePretty',
            'visitorId'
        );
        foreach($toRemove as $xml) {
            $input = $this->removeXmlElement($input, $xml);
        }
        return $input;
    }

    protected function removePrettyDateFromXml($input)
    {
        return $this->removeXmlElement($input, 'prettyDate');
    }

    protected function removeXmlElement($input, $xmlElement, $testNotSmallAfter = true)
    {
    	// Only raise error if there was some data before
    	$testNotSmallAfter = strlen($input > 100 ) && $testNotSmallAfter;
    	
        $input = preg_replace('/(<'.$xmlElement.'>.+?<\/'.$xmlElement.'>)/', '', $input);
        //check we didn't delete the whole string
        if($testNotSmallAfter)
        {
            $this->assertTrue(strlen($input) > 100);
        }
        return $input;
    }

    private function getProcessedAndExpectedDirs()
    {
        $path = $this->getPathToTestDirectory();
        // Ancud-IT  uncomment the commented code below for testing against adapted output-files
        // create /expected/oracle first and copy all files to it, than 
        // adapt those you need to adapt in order tests to pass.
        // leaving output-files for the default database MySQL untouched
		$expected = /* Piwik_Common::isOracle() ? '/expected/oracle/' : */ '/expected/';
        return array($path . '/processed/', $path . $expected);
    }

    private function getProcessedAndExpectedPaths($testName, $testId, $format = null)
    {
        $filename = $testName . '__' . $testId;
        if ($format)
        {
            $filename .= ".$format";
        }

        list($processedDir, $expectedDir) = $this->getProcessedAndExpectedDirs();

        return array($processedDir . $filename, $expectedDir . $filename);
    }

    private function loadExpectedFile($filePath)
    {
        $result = @file_get_contents($filePath);
        if(empty($result))
        {
            $expectedDir = dirname($filePath);
            $this->missingExpectedFiles[] = $filePath;
            return null;
        }
        return $result;
    }

    /**
     * Returns an array describing the API methods to call & compare with
     * expected output.
     *
     * The returned array must be of the following format:
     * <code>
     * array(
     *     array('SomeAPI.method', array('testOption1' => 'value1', 'testOption2' => 'value2'),
     *     array(array('SomeAPI.method', 'SomeOtherAPI.method'), array(...)),
     *     .
     *     .
     *     .
     * )
     * </code>
     *
     * Valid test options:
     * <ul>
     *   <li><b>testSuffix</b> The suffix added to the test name. Helps determine
     *   the filename of the expected output.</li>
     *   <li><b>format</b> The desired format of the output. Defaults to 'xml'.</li>
     *   <li><b>idSite</b> The id of the website to get data for.</li>
     *   <li><b>date</b> The date to get data for.</li>
     *   <li><b>periods</b> The period or periods to get data for. Can be an array.</li>
     *   <li><b>setDateLastN</b> Flag describing whether to query for a set of
     *   dates or not.</li>
     *   <li><b>language</b> The language to use.</li>
     *   <li><b>segment</b> The segment to use.</li>
     *   <li><b>visitorId</b> The visitor ID to use.</li>
     *   <li><b>abandonedCarts</b> Whether to look for abandoned carts or not.</li>
     *   <li><b>idGoal</b> The goal ID to use.</li>
     *   <li><b>apiModule</b> The value to use in the apiModule request parameter.</li>
     *   <li><b>apiAction</b> The value to use in the apiAction request parameter.</li>
     *   <li><b>otherRequestParameters</b> An array of extra request parameters to use.</li>
     *   <li><b>disableArchiving</b> Disable archiving before running tests.</li>
     * </ul>
     *
     * All test options are optional, except 'idSite' & 'date'.
     */
    public function getApiForTesting() {
        return array();
    }

    /**
     * Gets the string prefix used in the name of the expected/processed output files.
     */
    public function getOutputPrefix()
    {
        return str_replace('Test_Piwik_Integration_', '', get_class($this));
    }

    protected function _setCallableApi($api)
    {
        if ($api == 'all')
        {
            self::setApiToCall(array());
            self::setApiNotToCall(self::$defaultApiNotToCall);
        }
        else
        {
            if (!is_array($api))
            {
                $api = array($api);
            }

            self::setApiToCall($api);
            self::setApiNotToCall(array('API.getPiwikVersion'));
        }
    }

    /**
     * Runs API tests.
     */
    protected function runApiTests($api, $params)
    {
        $testName = 'test_' . $this->getOutputPrefix();
        $this->missingExpectedFiles = array();
        $this->comparisonFailures = array();

        $this->_setCallableApi($api);

        if (isset($params['disableArchiving']) && $params['disableArchiving'] === true)
        {
            Piwik_ArchiveProcessing::$forceDisableArchiving = true;
        }
        else
        {
            Piwik_ArchiveProcessing::$forceDisableArchiving = false;
        }

        if (isset($params['language']))
        {
            $this->changeLanguage($params['language']);
        }

        $testSuffix = isset($params['testSuffix']) ? $params['testSuffix'] : '';

        $requestUrls = $this->_generateApiUrls(
                    isset($params['format']) ? $params['format'] : 'xml',
                    isset($params['idSite']) ? $params['idSite'] : false,
                    isset($params['date']) ? $params['date'] : false,
                    isset($params['periods']) ? $params['periods'] : false,
                    isset($params['setDateLastN']) ? $params['setDateLastN'] : false,
                    isset($params['language']) ? $params['language'] : false,
                    isset($params['segment']) ? $params['segment'] : false,
                    isset($params['visitorId']) ? $params['visitorId'] : false,
                    isset($params['abandonedCarts']) ? $params['abandonedCarts'] : false,
                    isset($params['idGoal']) ? $params['idGoal'] : false,
                    isset($params['apiModule']) ? $params['apiModule'] : false,
                    isset($params['apiAction']) ? $params['apiAction'] : false,
                    isset($params['otherRequestParameters']) ? $params['otherRequestParameters'] : array(),
                    isset($params['supertableApi']) ? $params['supertableApi'] : false,
                    isset($params['fileExtension']) ? $params['fileExtension'] : false);

        foreach($requestUrls as $apiId => $requestUrl)
        {
            $this->_testApiUrl( $testName . $testSuffix, $apiId, $requestUrl);
        }

        // change the language back to en
        if ($this->lastLanguage != 'en')
        {
            $this->changeLanguage('en');
        }
        
        if (!empty($this->missingExpectedFiles))
        {
            $expectedDir = dirname(reset($this->missingExpectedFiles));
            $this->markTestIncomplete(" ERROR: Could not find expected API output '"
            	. implode("', '", $this->missingExpectedFiles)
            	. "'. For new tests, to pass the test, you can copy files from the processed/ directory into"
            	. " $expectedDir  after checking that the output is valid. %s ");
        }
        
        if (!empty($this->comparisonFailures))
        {
        	throw reset($this->comparisonFailures);
        }
    }

    /**
     * changing the language within one request is a bit fancy
     * in order to keep the core clean, we need a little hack here
     *
     * @param string $langId
     */
    protected function changeLanguage( $langId )
    {
        if ($this->lastLanguage != $langId)
        {
            $_GET['language'] = $langId;
            Piwik_Translate::reset();
            Piwik_Translate::getInstance()->reloadLanguage($langId);
        }

        $this->lastLanguage = $langId;
    }

    /**
     * Path where expected/processed output files are stored. Can be overridden.
     */
    public function getPathToTestDirectory()
    {
        return dirname(__FILE__).DIRECTORY_SEPARATOR.'Integration';
    }
    
    /**
     * Returns an array associating table names w/ lists of row data.
     * 
     * @return array
     */
    protected static function getDbTablesWithData()
    {
    	$result = array();
    	foreach (Piwik::getTablesInstalled() as $tableName)
    	{
    		$result[$tableName] = Piwik_FetchAll("SELECT * FROM $tableName");
    	}
    	return $result;
    }
    
    /**
     * Truncates all tables then inserts the data in $tables into each
     * mapped table.
     * 
     * @param array $tables Array mapping table names with arrays of row data.
     */
    protected static function restoreDbTables( $tables )
    {
		// truncate existing tables
    	Piwik::truncateAllTables();
    	
    	// insert data
    	$existingTables = Piwik::getTablesInstalled();
    	foreach ($tables as $table => $rows)
    	{
    		// create table if it's an archive table
    		if (strpos($table, 'archive_') !== false && !in_array($table, $existingTables))
    		{
    			$tableType = strpos($table, 'archive_numeric') !== false ? 'archive_numeric' : 'archive_blob';
    			
    			$createSql = Piwik::getTableCreateSql($tableType);
    			$createSql = str_replace(Piwik_Common::prefixTable($tableType), $table, $createSql);
    			Piwik_Query($createSql);
    		}
    		
    		if (empty($rows))
    		{
    			continue;
    		}
    		
    		$rowsSql = array();
    		foreach ($rows as $row)
    		{
    			$values = array();
    			foreach ($row as $name => $value)
    			{
    				if (is_null($value))
    				{
    					$values[] = 'NULL';
    				}
    				else if (is_numeric($value))
    				{
    					$values[] = $value;
    				}
    				else if (!ctype_print($value))
    				{
    					// leading x for MySQL
						// Ancud-IT GmbH
						if( !Piwik_Common::isOracle())
    					$values[] = "x'".bin2hex(substr($value, 1))."'";
						else
								$values[] = "'".bin2hex(substr($value, 1))."'";		
    				}
    				else
    				{
    					$values[] = "'$value'";
    				}
    			}
    			
    			$rowsSql[] = "(".implode(',', $values).")";
    		}
    		
    		$sql = "INSERT INTO $table VALUES ".implode(',', $rowsSql);
			
			if( Piwik_Common::isOracle())
			{
				$sql = "INSERT ALL " ;
				
				foreach( $rowsSql as $singleRow )
				{
					$sql .= " INTO " . $table . " VALUES " . $singleRow;
				}
				
				$sql .= " SELECT * FROM DUAL";
			}
			
    		Piwik_Query($sql);
    	}
    }
	
	/**
	 * Drops all archive tables.
	 */
	public static function deleteArchiveTables()
	{
		foreach (Piwik::getTablesArchivesInstalled() as $table)
		{
			// Ancud-IT GmbH    should work for all databases ...
			try 
			{
				Piwik_Query("DROP TABLE  $table");
			} catch( Exception $ex ) {}	
		}
		
		Piwik_TablePartitioning::$tablesAlreadyInstalled = Piwik::getTablesInstalled($forceReload = true);
	}
	
	public static $geoIpDbUrl = 'http://piwik-team.s3.amazonaws.com/GeoIP.dat.gz';
	public static $geoLiteCityDbUrl = 'http://piwik-team.s3.amazonaws.com/GeoLiteCity.dat.gz';
	
	public static function downloadGeoIpDbs()
	{
		$geoIpOutputDir = PIWIK_INCLUDE_PATH.'/tests/lib/geoip-files';
		self::downloadAndUnzip(self::$geoIpDbUrl, $geoIpOutputDir, 'GeoIP.dat');
		self::downloadAndUnzip(self::$geoLiteCityDbUrl, $geoIpOutputDir, 'GeoIPCity.dat');
	}
	
	public static function downloadAndUnzip( $url, $outputDir, $filename )
	{
		$bufferSize = 1024 * 1024;
		
		try
		{
			if (!is_dir($outputDir)) 
			{
				mkdir($outputDir);
			}
			
			$deflatedOut = $outputDir.'/'.$filename;
			$outfileName = $deflatedOut.'.gz';
			
			if (file_exists($deflatedOut))
			{
				return;
			}
			
			$dump = fopen($url, 'rb');
			$outfile = fopen($outfileName, 'wb');
			$bytesRead = 0;
			while (!feof($dump))
			{
				fwrite($outfile, fread($dump, $bufferSize), $bufferSize);
				$bytesRead += $bufferSize;
			}
			fclose($dump);
			fclose($outfile);
			
			// unzip the dump
			exec("gunzip -c \"".$outfileName."\" > \"$deflatedOut\"", $output, $return);
			if ($return !== 0)
			{
				throw new Exception("gunzip failed($return): ".implode("\n", $output));
			}
		}
		catch (Exception $ex)
		{
			self::markTestSkipped(
				"Cannot download GeoIp DBs, skipping: ".$ex->getMessage()."\n".$ex->getTraceAsString());
		}
	}
}
