<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
class ArchiveProcessingTest extends DatabaseTestCase
{
    public function setUp()
    {
        parent::setUp();

        // setup the access layer
        $pseudoMockAccess = new FakeAccess;
        FakeAccess::$superUser = true;
        Zend_Registry::set('access', $pseudoMockAccess);
    }

    /**
     * Creates a new website
     * 
     * @param string $timezone
     * @return Piwik_Site
     */
    private function _createWebsite($timezone = 'UTC')
    {
        $idSite = Piwik_SitesManager_API::getInstance()->addSite(
                                                "site1",
                                                array("http://piwik.net"), 
                                                $ecommerce=0,
										        $siteSearch = 1, $searchKeywordParameters = null, $searchCategoryParameters = null,
                                                $excludedIps = "",
                                                $excludedQueryParameters = "",
                                                $timezone);
                                                
        Piwik_Site::clearCache();
        return new Piwik_Site($idSite);
    }

    /**
     * Creates a new ArchiveProcessing object
     * 
     * @param string $periodLabel
     * @param string $dateLabel
     * @param string $siteTimezone
     * @return Piwik_ArchiveProcessing
     */
    private function _createArchiveProcessing($periodLabel, $dateLabel, $siteTimezone)
    {
        $site = $this->_createWebsite($siteTimezone);
        $date = Piwik_Date::factory($dateLabel);
        $period = Piwik_Period::factory($periodLabel, $date);
        
        $archiveProcessing = Piwik_ArchiveProcessing::factory($periodLabel);
        $archiveProcessing->setSite($site);
        $archiveProcessing->setPeriod($period);
        $archiveProcessing->setSegment(new Piwik_Segment('', $site->getId()));
        $archiveProcessing->init();
        return $archiveProcessing;
    }

    /**
     * test of validity of an archive, for a month not finished
     * @group Core
     * @group ArchiveProcessing
     */
    public function testInitCurrentMonth()
    {
        $siteTimezone = 'UTC+10';
        $now = time();
        
        $dateLabel = date('Y-m-d', $now);
        $archiveProcessing = $this->_createArchiveProcessing('month', $dateLabel, $siteTimezone);
        $archiveProcessing->time = $now;
        
        // min finished timestamp considered when looking at archive timestamp 
        $timeout = Piwik_ArchiveProcessing::getTodayArchiveTimeToLive();
        $this->assertTrue($timeout >= 10);
        $dateMinArchived = $now - $timeout;

        $minTimestamp = $archiveProcessing->getMinTimeArchivedProcessed();
        $this->assertEquals($minTimestamp, $dateMinArchived, Piwik_Date::factory($minTimestamp)->getDatetime() . " != " . Piwik_Date::factory($dateMinArchived)->getDatetime());
        $this->assertTrue($archiveProcessing->isArchiveTemporary());
    }
    
    /**
     * test of validity of an archive, for a month in the past
     * @group Core
     * @group ArchiveProcessing
     */
    public function testInitDayInPast()
    {
        $archiveProcessing = $this->_createArchiveProcessing('day', '2010-01-01', 'UTC');
        
        // min finished timestamp considered when looking at archive timestamp 
        $dateMinArchived = Piwik_Date::factory('2010-01-02')->getTimestamp();
        $this->assertEquals($archiveProcessing->getMinTimeArchivedProcessed() + 1, $dateMinArchived);
        
        $this->assertEquals('2010-01-01 00:00:00', $archiveProcessing->getStartDatetimeUTC());
        $this->assertEquals('2010-01-01 23:59:59', $archiveProcessing->getEndDatetimeUTC());
        $this->assertFalse($archiveProcessing->isArchiveTemporary());
    }

    /**
     * test of validity of an archive, for a non UTC date in the past
     * @group Core
     * @group ArchiveProcessing
     */
    public function testInitDayInPastNonUTCWebsite()
    {
        $timezone = 'UTC+5.5';
        $archiveProcessing = $this->_createArchiveProcessing('day', '2010-01-01', $timezone);
        // min finished timestamp considered when looking at archive timestamp 
        $dateMinArchived = Piwik_Date::factory('2010-01-01 18:30:00');
        $this->assertEquals($archiveProcessing->getMinTimeArchivedProcessed() + 1, $dateMinArchived->getTimestamp());
        
        $this->assertEquals('2009-12-31 18:30:00', $archiveProcessing->getStartDatetimeUTC());
        $this->assertEquals('2010-01-01 18:29:59', $archiveProcessing->getEndDatetimeUTC());
        $this->assertFalse($archiveProcessing->isArchiveTemporary());
    }

    /**
     * test of validity of an archive, for a non UTC month in the past
     * @group Core
     * @group ArchiveProcessing
     */
    public function testInitMonthInPastNonUTCWebsite()
    {
        $timezone = 'UTC-5.5';
        $archiveProcessing = $this->_createArchiveProcessing('month', '2010-01-02', $timezone);
        // min finished timestamp considered when looking at archive timestamp 
        $dateMinArchived = Piwik_Date::factory('2010-02-01 05:30:00');
        $this->assertEquals($archiveProcessing->getMinTimeArchivedProcessed() + 1, $dateMinArchived->getTimestamp());
        
        $this->assertEquals('2010-01-01 05:30:00', $archiveProcessing->getStartDatetimeUTC());
        $this->assertEquals('2010-02-01 05:29:59', $archiveProcessing->getEndDatetimeUTC());
        $this->assertFalse($archiveProcessing->isArchiveTemporary());
    }
    
    /**
     * test of validity of an archive, for today's archive
     * @group Core
     * @group ArchiveProcessing
     */
    public function testInitToday()
    {
        $now = time();
        $siteTimezone = 'UTC-1';
        $timestamp = Piwik_Date::factory('now', $siteTimezone)->getTimestamp();
        $dateLabel = date('Y-m-d', $timestamp);

        Piwik_ArchiveProcessing::setBrowserTriggerArchiving(true);
        
        $archiveProcessing = $this->_createArchiveProcessing('day', $dateLabel, $siteTimezone);
        $archiveProcessing->time = $now;
        
        // we look at anything processed within the time to live range
        $dateMinArchived = $now - Piwik_ArchiveProcessing::getTodayArchiveTimeToLive();
        $this->assertEquals($dateMinArchived, $archiveProcessing->getMinTimeArchivedProcessed());
        $this->assertTrue($archiveProcessing->isArchiveTemporary());

        // when browsers don't trigger archives, we force ArchiveProcessing 
        // to fetch any of the most recent archive
        Piwik_ArchiveProcessing::setBrowserTriggerArchiving(false);
        // see isArchivingDisabled()
        // Running in CLI doesn't impact the time to live today's archive we are loading
        // From CLI, we will not return data that is 'stale' 
        if(!Piwik_Common::isPhpCliMode())
        {
            $dateMinArchived = 0;
        }
        $this->assertEquals($archiveProcessing->getMinTimeArchivedProcessed(), $dateMinArchived);
        
        $this->assertEquals(date('Y-m-d', $timestamp).' 01:00:00', $archiveProcessing->getStartDatetimeUTC());
        $this->assertEquals(date('Y-m-d', $timestamp+86400).' 00:59:59', $archiveProcessing->getEndDatetimeUTC());
        $this->assertTrue($archiveProcessing->isArchiveTemporary());
    }

    /**
     * test of validity of an archive, for today's archive with european timezone
     * @group Core
     * @group ArchiveProcessing
     */
    public function testInitTodayEurope()
    {
        if(!Piwik::isTimezoneSupportEnabled())
        {
            $this->markTestSkipped('timezones needs to be supported');
        }

        $now = time();
        $siteTimezone = 'Europe/Paris';
        $timestamp = Piwik_Date::factory('now', $siteTimezone)->getTimestamp();
        $dateLabel = date('Y-m-d', $timestamp);

        Piwik_ArchiveProcessing::setBrowserTriggerArchiving(true);

        $archiveProcessing = $this->_createArchiveProcessing('day', $dateLabel, $siteTimezone);
        $archiveProcessing->time = $now;

        // we look at anything processed within the time to live range
        $dateMinArchived = $now - Piwik_ArchiveProcessing::getTodayArchiveTimeToLive();
        $this->assertEquals($archiveProcessing->getMinTimeArchivedProcessed(), $dateMinArchived);
        $this->assertTrue($archiveProcessing->isArchiveTemporary());

        // when browsers don't trigger archives, we force ArchiveProcessing
        // to fetch any of the most recent archive
        Piwik_ArchiveProcessing::setBrowserTriggerArchiving(false);
        // see isArchivingDisabled()
        // Running in CLI doesn't impact the time to live today's archive we are loading
        // From CLI, we will not return data that is 'stale'
        if(!Piwik_Common::isPhpCliMode())
        {
            $dateMinArchived = 0;
        }
        $this->assertEquals($archiveProcessing->getMinTimeArchivedProcessed(), $dateMinArchived);

        // this test varies with DST
        $this->assertTrue($archiveProcessing->getStartDatetimeUTC() == date('Y-m-d', $timestamp-86400).' 22:00:00' ||
            $archiveProcessing->getStartDatetimeUTC() == date('Y-m-d', $timestamp-86400).' 23:00:00');
        $this->assertTrue($archiveProcessing->getEndDatetimeUTC() == date('Y-m-d', $timestamp).' 21:59:59' ||
            $archiveProcessing->getEndDatetimeUTC() == date('Y-m-d', $timestamp).' 22:59:59');

        $this->assertTrue($archiveProcessing->isArchiveTemporary());
    }

    /**
     * test of validity of an archive, for today's archive with toronto's timezone
     * @group Core
     * @group ArchiveProcessing
     */
    public function testInitTodayToronto()
    {
        if(!Piwik::isTimezoneSupportEnabled())
        {
            $this->markTestSkipped('timezones needs to be supported');
        }

        $now = time();
        $siteTimezone = 'America/Toronto';
        $timestamp = Piwik_Date::factory('now', $siteTimezone)->getTimestamp();
        $dateLabel = date('Y-m-d', $timestamp);

        Piwik_ArchiveProcessing::setBrowserTriggerArchiving(true);

        $archiveProcessing = $this->_createArchiveProcessing('day', $dateLabel, $siteTimezone);
        $archiveProcessing->time = $now;

        // we look at anything processed within the time to live range
        $dateMinArchived = $now - Piwik_ArchiveProcessing::getTodayArchiveTimeToLive();
        $this->assertEquals($archiveProcessing->getMinTimeArchivedProcessed(), $dateMinArchived);
        $this->assertTrue($archiveProcessing->isArchiveTemporary());

        // when browsers don't trigger archives, we force ArchiveProcessing
        // to fetch any of the most recent archive
        Piwik_ArchiveProcessing::setBrowserTriggerArchiving(false);
        // see isArchivingDisabled()
        // Running in CLI doesn't impact the time to live today's archive we are loading
        // From CLI, we will not return data that is 'stale'
        if(!Piwik_Common::isPhpCliMode())
        {
            $dateMinArchived = 0;
        }
        $this->assertEquals($archiveProcessing->getMinTimeArchivedProcessed(), $dateMinArchived);

        // this test varies with DST
        $this->assertTrue($archiveProcessing->getStartDatetimeUTC() == date('Y-m-d', $timestamp).' 04:00:00' ||
            $archiveProcessing->getStartDatetimeUTC() == date('Y-m-d', $timestamp).' 05:00:00');
        $this->assertTrue($archiveProcessing->getEndDatetimeUTC() == date('Y-m-d', $timestamp+86400).' 03:59:59' ||
            $archiveProcessing->getEndDatetimeUTC() == date('Y-m-d', $timestamp+86400).' 04:59:59');

        $this->assertTrue($archiveProcessing->isArchiveTemporary());
    }

    /**
     * Testing batch insert
     * @group Core
     * @group ArchiveProcessing
     */
    public function testTableInsertBatch()
    {
        $table        = Piwik_Common::prefixTable('site_url');
        $data         = $this->_getDataInsert();
        $didWeUseBulk = Piwik::tableInsertBatch($table, array ('idsite', 'url'), $data);
        if ((version_compare(PHP_VERSION, '5.2.9') < 0 ||
                version_compare(PHP_VERSION, '5.3.7') >= 0 ||
                Piwik_Config::getInstance()->database['adapter'] != 'PDO_MYSQL' )
                && Piwik_Config::getInstance()->database['adapter'] != 'ORACLE')
        {
            $this->assertTrue($didWeUseBulk, "The test didn't LOAD DATA INFILE but fallbacked to plain INSERT, but we must unit test this function!");
        }
        $this->_checkTableIsExpected($table, $data);

        // INSERT again the bulk. Because we use keyword LOCAL the data will be REPLACED automatically (see mysql doc) 
        Piwik::tableInsertBatch($table, array ('idsite', 'url'), $data);
        $this->_checkTableIsExpected($table, $data);
    }

    /**
     * Testing plain inserts
     * @group Core
     * @group ArchiveProcessing
     */
    public function testTableInsertBatchIterate()
    {
        $table = Piwik_Common::prefixTable('site_url');
        $data = $this->_getDataInsert();
        Piwik::tableInsertBatchIterate($table, array('idsite', 'url'), $data);
        $this->_checkTableIsExpected($table, $data);

        // If we insert AGAIN, expect to throw an error because the primary key already exists
        try {
            Piwik::tableInsertBatchIterate($table, array('idsite', 'url'), $data, $ignoreWhenDuplicate = false);    
        } catch (Exception $e) {
            // However if we insert with keyword REPLACE, then the new data should be saved
            Piwik::tableInsertBatchIterate($table, array('idsite', 'url'), $data, $ignoreWhenDuplicate = true );
            $this->_checkTableIsExpected($table, $data);
            return;
        }
        $this->fail('Exception expected');
    }
    
    /**
     * Testing batch insert (BLOB)
     * @group Core
     * @group ArchiveProcessing
     */
    public function testTableInsertBatchBlob()
    {
        $siteTimezone      = 'America/Toronto';
        $dateLabel         = '2011-03-31';
        $archiveProcessing = $this->_createArchiveProcessing('day', $dateLabel, $siteTimezone);

        $table = $archiveProcessing->getTableArchiveBlobName();

        $data         = $this->_getBlobDataInsert();
        $didWeUseBulk = Piwik::tableInsertBatch($table, array ('idarchive', 'name', 'idsite', 'date1', 'date2', 'period', 'ts_archived', 'value'), $data, true);
        if ((version_compare(PHP_VERSION, '5.2.9') < 0 ||
                version_compare(PHP_VERSION, '5.3.7') >= 0 ||
                Piwik_Config::getInstance()->database['adapter'] != 'PDO_MYSQL') && Piwik_Config::getInstance()->database['adapter'] != "ORACLE")
        {
            $this->assertTrue($didWeUseBulk, "The test didn't LOAD DATA INFILE but fallbacked to plain INSERT, but we must unit test this function!");
        }
        $this->_checkTableIsExpectedBlob($table, $data);

        // INSERT again the bulk. Because we use keyword LOCAL the data will be REPLACED automatically (see mysql doc) 
        Piwik::tableInsertBatch($table, array ('idarchive', 'name', 'idsite', 'date1', 'date2', 'period', 'ts_archived', 'value'), $data, true);
        $this->_checkTableIsExpectedBlob($table, $data);
    }

    /**
     * Testing plain inserts (BLOB)
     * @group Core
     * @group ArchiveProcessing
     */
    public function testTableInsertBatchIterateBlob()
    {
        $siteTimezone = 'America/Toronto';
        $dateLabel = '2011-03-31';
        $archiveProcessing = $this->_createArchiveProcessing('day', $dateLabel, $siteTimezone);

        $table = $archiveProcessing->getTableArchiveBlobName();

        $data = $this->_getBlobDataInsert();
        Piwik::tableInsertBatchIterate($table, array('idarchive', 'name', 'idsite', 'date1', 'date2', 'period', 'ts_archived', 'value'), $data, true);
        $this->_checkTableIsExpectedBlob($table, $data);

        // If we insert AGAIN, expect to throw an error because the primary key already exist
        try {
            Piwik::tableInsertBatchIterate($table, array('idarchive', 'name', 'idsite', 'date1', 'date2', 'period', 'ts_archived', 'value'), $data, $ignoreWhenDuplicate = false);    
        } catch (Exception $e) {
            // However if we insert with keyword REPLACE, then the new data should be saved
            Piwik::tableInsertBatchIterate($table, array('idarchive', 'name', 'idsite', 'date1', 'date2', 'period', 'ts_archived', 'value'), $data, $ignoreWhenDuplicate = true );
            $this->_checkTableIsExpectedBlob($table, $data);
            return;
        }
        $this->fail('Exception expected');
    }
    
    
    protected function _checkTableIsExpected($table, $data)
    {
        $fetched = Piwik_FetchAll('SELECT * FROM '.$table);
        foreach($data as $id => $row) {
            $this->assertEquals($fetched[$id]['idsite'], $data[$id][0], "record $id is not {$data[$id][0]}");
            $this->assertEquals($fetched[$id]['url'], $data[$id][1], "Record $id bug, not {$data[$id][1]} BUT {$fetched[$id]['url']}");
        }
    }

    protected function _checkTableIsExpectedBlob($table, $data)
    {
        $fetched = Piwik_FetchAll('SELECT * FROM '.$table);
        foreach($data as $id => $row) {
            $this->assertEquals($fetched[$id]['idarchive'], $data[$id][0], "record $id idarchive is not '{$data[$id][0]}'");
            $this->assertEquals($fetched[$id]['name'], $data[$id][1], "record $id name is not '{$data[$id][1]}'");
            $this->assertEquals($fetched[$id]['idsite'], $data[$id][2], "record $id idsite is not '{$data[$id][2]}'");
            $this->assertEquals($fetched[$id]['date1'], $data[$id][3], "record $id date1 is not '{$data[$id][3]}'");
            $this->assertEquals($fetched[$id]['date2'], $data[$id][4], "record $id date2 is not '{$data[$id][4]}'");
            $this->assertEquals($fetched[$id]['period'], $data[$id][5], "record $id period is not '{$data[$id][5]}'");
            $this->assertEquals($fetched[$id]['ts_archived'], $data[$id][6], "record $id ts_archived is not '{$data[$id][6]}'");
            $this->assertEquals($fetched[$id]['value'], $data[$id][7], "record $id value is unexpected");
        }
    }

    /*
     * Schema for site_url table:
     *    site_url (
     *        idsite INTEGER(10) UNSIGNED NOT NULL,
     *        url VARCHAR(255) NOT NULL,
     *        PRIMARY KEY(idsite, url)
     *    )
     */
    protected function _getDataInsert()
    {
        return array(
            array(1, 'test'),
            array(2, 'te" \n st2'),
            array(3, " \n \r \t test"),

            // these aren't expected to work on a column of datatype VARCHAR
//            array(4, gzcompress( " \n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942")),
//            array(5, gzcompress('test4')),

            array(6, 'test5'),
            array(7, '简体中文'),
            array(8, '"'),
            array(9, "'"),
            array(10, '\\'),
            array(11, '\\"'),
            array(12, '\\\''),
            array(13, "\t"),
            array(14, "test \x00 null"),
            array(15, "\x00\x01\x02\0x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f"),
        );
    }

    /**
     * see archive_blob table
     */
    protected function _getBlobDataInsert()
    {
        $ts = '2011-03-31 17:48:00';
		
        if (Piwik_Common::isOracle())
		{
			$ts .= ".000000"; // Ancud-IT NLS timestamp format ORACLE
		}
        
        $str   = '';
        $array = array ();
		
        for ($i = 0; $i < 256; $i++)
        {
            $str .= chr($i);
        }
        $array[] = array (1, 'bytes 0-255', 1, '2011-03-31', '2011-03-31', Piwik::$idPeriods['day'], $ts, $str);

        $array[] = array (2, 'compressed string', 1, '2011-03-31', '2011-03-31', Piwik::$idPeriods['day'], $ts, gzcompress(" \n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942\n \r \t teste eigaj oegheao geaoh guoea98742983 2 342942"));

        $str     = file_get_contents(PIWIK_PATH_TEST_TO_ROOT . '/tests/core/Piwik/lipsum.txt');
        $array[] = array (3, 'lorem ipsum', 1, '2011-03-31', '2011-03-31', Piwik::$idPeriods['day'], $ts, $str);

        $array[] = array (4, 'lorem ipsum compressed', 1, '2011-03-31', '2011-03-31', Piwik::$idPeriods['day'], $ts, gzcompress($str));

		// Ancud-IT GmbH
		
        if (Zend_Registry::get('db') instanceof Piwik_Db_Adapter_Oracle)
		{
            foreach ($array as $key => $val)
			{
                $array[$key][7] = bin2hex($val[7]);
			}
		}
		
        return $array;
    }
}
