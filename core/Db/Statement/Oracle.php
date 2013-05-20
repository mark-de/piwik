<?php

/**
 */
class Piwik_Db_Statement_Oracle extends Zend_Db_Statement_Oracle
{

    private $reservedWords = array ('access', 'group');
    private $sqlStrippedQuoted;
    private $sqlString;
    private $types = array (
        'CHAR' => 'VARCHAR',
        'VARCHAR' => 'VARCHAR2',
        'UNSIGNED' => '',
        'FLOAT\(' => 'NUMBER(',
        'FLOAT\w+\( ' => 'NUMBER(',
        'FLOAT' => 'NUMBER(12,2)',
        'DOUBLE' => 'NUMBER(12,2)',
        'TEXT' => 'VARCHAR2(4000) ',
        'VARBINARY' => 'VARCHAR2',
        'DATETIME' => 'TIMESTAMP ',
        'TIME' => 'INTERVAL DAY(1) TO SECOND(0)',
        'MEDIUMBLOB' => 'BLOB',
        'LONGTEXT' => ' VARCHAR(4000)'
    );
    private $numericTypes = array (
        'BINARY' => 'VARCHAR2(16)',
        'INTEGER' => 'NUMBER(11,0)',
        'TINYINT' => 'NUMBER(3,0)',
        'SMALLINT' => 'NUMBER(11,0)',
        'INT' => 'NUMBER(11,0)'
    );  // SMALLINT translates to Number(7,0) according to Oracle-Docs, but it doesn't seem so,
    // we got "to low precision"-errors with Number(7,0) in some update/insert-statements!
    // Ancud-IT GmbH 2012
    private $showTables = array (
        "SHOW TABLES LIKE " => "SELECT TABLE_NAME FROM USER_TABLES WHERE REGEXP_LIKE( TABLE_NAME, "
    );
    private $trigger;
    private $sequence;
    private $index;


    public function getSqlString()
    {
        return $this->sqlString;
    }


    /**
     *
     * @param mixed $adapter
     * @param String $sql 
     */
    public function __construct($adapter, $sql)
    {
        $this->_adapter = $adapter;
        $sql = $this->filterMysql($sql);
        $this->sqlString = $sql;

        if ($sql instanceof Zend_Db_Select)
        {
            $sql = $sql->assemble();
        }

        $this->_parseParameters($sql);
        $this->_prepare($sql);

        $this->_queryId = $this->_adapter->getProfiler()->queryStart($sql);
    }


    /**
     * @param	string	$sql
     * @return	string	$sql 
     */
    private function filterMysql($sql)
    {
        $sql = $this->_adapter->convertPositionalParameters($sql);
        $sql = $this->quoteReservedWords($sql);

        $this->sqlStrippedQuoted = $this->_stripQuoted($sql);

        if (stripos($this->sqlStrippedQuoted, "select") !== false)
            return str_replace('`', '"', $sql);

        if (preg_match('/\bCREATE\b\s+\bTABLE\b\s+|\bALTER\b\s+\bTABLE\b\s+/i', $this->sqlStrippedQuoted) == 1)
        {
            $sql = str_replace('`', '', $sql);
            return $this->convertDDLSql($sql);
        }

        if (preg_match('/\bSHOW\s+TABLES\s+LIKE\s+/i', $this->sqlStrippedQuoted) == 1)
        {
            foreach ($this->showTables as $key => $val)
            {
                $sql = str_replace($key, $val, $sql); // Ancud-IT GmbH
                $sql = str_replace('%', '.*', $sql);  // use regexp syntax
                $sql = str_replace('\_', '_', $sql);  // not LIKE-operator syntax!
                $sql .= ",'i') ";
            }

            return $sql;
        }

        return str_replace('`', '"', $sql);
        ;
    }


    /**
     * Executes a prepared statement.
     *
     * @param array $params OPTIONAL Values to bind to parameter placeholders.
     * @return bool
     */
    public function execute(array $params = null)
    {
        $retval = parent::execute($params);

        if (isset($this->trigger))
        {
            $retval = $this->sequence->execute();
            $retval = $this->trigger->execute();

            unset($this->sequence, $this->trigger);
        }

        if (isset($this->index))
        {
            {
                foreach ($this->index as $in)
                    $retval = $in->execute();
            }

            unset($this->index);
        }

        return $retval;
    }


    /**
     *
     * @param array $params
     * @throws Zend_Db_Statement_Oracle_Exception 
     */
    public function _execute(array $params = null)
    {

        if ($params !== null)
        {
            if (!is_array($params))
            {
                $params = array ($params);
            }

            $params = $this->_prepareBind($params);

            foreach (array_keys($params) as $name)
            {
                if (is_int($params[$name]))
                {
                    $this->_bindParam($name, $params[$name], SQLT_INT, 13);
                } else
                {
                    //  @oci_bind_by_name($this->_stmt, $name, $params[$name], -1);
                    $this->_bindParam($name, $params[$name], SQLT_CHR, -1);
                }
            }
        }

        return parent::_execute(); // Ancud-IT GmbH  take not to forget the return!
    }


    /**
     *
     * @param String $sql 
     */
    protected function _parseParameters($sql)
    {
        $this->_sqlSplit = preg_split('/(\:[a-zA-Z0-9_]+)/', $this->sqlStrippedQuoted, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        // map params
        $this->_sqlParam = array ();
        foreach ($this->_sqlSplit as $key => $val)
            $this->_sqlParam[] = $val;


        // set up for binding
        $this->_bindParam = array ();

        unset($this->sqlStrippedQuoted);
    }


    /**
     * @param String $sql
     * @return String 
     */
    private function quoteReservedWords($sql)
    {
        // Don't examine any 'group by'-clause
        $gpexcluded = preg_split('/\bgroup\b\s+\bby\b\s+/i', $sql, 6);
        $countparts = count($gpexcluded);

        for ($i = 0; $i < $countparts; $i++)
        {
            $sqlParts = explode('"', $gpexcluded[$i]);

            // Don't examine string elements that has been quoted already  
            for ($n = 0; $n < count($sqlParts); $n+=2)
            {
                foreach ($this->reservedWords as $reservedWord)
                {
                    $pattern      = "/\b" . $reservedWord . "\b/i";
                    $sqlParts[$n] = preg_replace($pattern, ' "' . $reservedWord . '" ', $sqlParts[$n]);
                }
            }

            $gpexcluded[$i] = implode('"', $sqlParts);
        }

        $sql = implode(' group by ', $gpexcluded);

        return $sql;
    }


    /**
     *
     * @param array $bind
     * @return array $bindPrepared 
     */
    public function _prepareBind($bind = null)
    {
        if (!isset($bind))
            return array ();

        $bindPrepared = array ();

        if (!is_array($bind))
            $bind = array ($bind);

        foreach ($bind as $key => $value)
        {
            $tmpKey                = is_int($key) ? ":p" . $key : $key;
            // $value = $value === '' ? ' ' : $value;
            $value                 = $value === false ? (int) 0 : $value;
            $bindPrepared[$tmpKey] = $value;
        }

        return $bindPrepared;
    }


    /**
     * Ancud-IT
     * 
     * Implementation of smarter CASE_FOLDING 
     * in Piwik_Db_Adapter_Oracle!
     * All keys are changed to lower case unless a 
     * field identifier is specified in 
     * camelcase style
     * 
     * @param integer $style
     * @param null $cursor
     * @param null $offset
     * @return array 
     */
    public function fetch($style = null, $cursor = null, $offset = null)
    {
        $row = parent::fetch($style, $cursor, $offset);

        if (!is_array($row))
        {
            return $row;
        }

        $resultSet = array ();
        foreach (array_keys($row) as $key)
        {
            $resultSet[$this->_adapter->foldCase($key)] = $row[$key];
        }

        return $resultSet;
    }


    /**
     * Ancud-IT
     * 
     * Implementation of smarter CASE_FOLDING 
     * in Piwik_Db_Adapter_Oracle!
     * All keys are changed to lower case unless a 
     * field identifier is specified in 
     * camelcase style
     * 
     * @param integer $style
     * @param integer $col
     * @return array
     */
    public function fetchAll($style = null, $col = 0)
    {
        $result = parent::fetchAll($style, $col);

        if ($style == Zend_Db::FETCH_COLUMN)
        {
            return $result;
        }

        $length    = sizeof($result);
        $resultSet = array ();
        $singleRow = array ();

        for ($i = 0; $i < $length; $i++)
        {
            foreach (array_keys($result[$i]) as $key)
            {
                $singleRow[$this->_adapter->foldCase($key)] = $result[$i][$key];
            }

            $resultSet[] = $singleRow;
        }

        return $resultSet;
    }


    /**
     *
     * @return array
     */
    public function getReservedWords()
    {
        return $this->reservedWords;
    }


    /**
     * Ancud-IT GmbH
     * helper method to rewrite mysql-statements
     * @param string $sql
     * @return string $sql 
     */
    private function convertDDLSql($sql)
    {
        /**
         * replace data types 
         */
        foreach ($this->types as $mysql => $ora)
            $sql = preg_replace("/\b" . $mysql . "\b/i", $ora, $sql);

        /**
         * replace numeric datatypes with parameters, often mysql parameters 
         * mean zerofill, while oracle parameters means number of digits
         */
        foreach ($this->numericTypes as $mysql => $ora)
        {
            $pattern = "/\b" . $mysql . "(\(\d{1,4}\)|\s)/i"; //[\(+\d+\)+]/i
            $sql     = preg_replace($pattern, $ora . " ", $sql);
        }

        /**
         * DEFAULT CHARSET definitions are not known in Oracle SQL,
         * so cut them off. 
         */
        $charsetDef = stripos($sql, 'DEFAULT CHARSET');

        if ($charsetDef !== false)
            $sql = substr($sql, 0, $charsetDef - 1);


        /**
         * Oracle SQL needs "CONSTRAINT" (but no "KEY") key word for named 
         * UNIQUE-constraints and "UNIQUE" must be followed by the 
         * field list!
         */
        if (stripos($sql, 'UNIQUE') !== false)
        {
            $pattern = "/(UNIQUE)(\s+KEY)([^\(]+)/i";
            $sql     = preg_replace($pattern, "CONSTRAINT $3 $1", $sql);
            $sql     = preg_replace("/(UNIQUE\(\w+)(\(\d+\))(\))/i", "$1 $3", $sql);
        }

        /**
         * Default values must be defined before (NOT) NULL constraints 
         * or 
         */
        if (stripos($sql, 'DEFAULT') !== false)
        {
            $pattern = "/(\s*[\w{3}]*\s+NULL)(\s+default\s+[^,]{1,15})/i";
            $sql     = preg_replace($pattern, "$2$1", $sql);
        }

        /**
         * eliminate any "AUTO_INCREMENT" definition (not known by Oracle)
         */
        $count = 0;
        $sql   = str_ireplace('AUTO_INCREMENT', '', $sql, $count);

        if ($count > 0)
            $this->createSequence($sql);

        /**
         * create explicit INDEX definitions (for indexes not related to any 
         * primary key / unique key constraint
         */
        if (preg_match("/\bINDEX\b/i", $sql) !== 0)
            $sql = $this->createIndex($sql);


        return $sql;
    }


    /**
     * Ancud-IT GmbH
     * helper method to create indices 
     * on fields without contraints
     * @param String $sql
     * @return String
     */
    private function createIndex($sql)
    {
        $pattern = "/(INDEX\s+[^\(]+)(\(.*\))(.*)/i";

        $matches = array ();
        preg_match_all($pattern, $sql, $matches);

        $sql = preg_replace($pattern, '', $sql);

        $sqlParts = explode(',', $sql);

        $pos2 = count($sqlParts) - 1;
        $pos1 = $pos2 - 1;

        $sqlEnd          = $sqlParts[$pos1] . " " . $sqlParts[$pos2];
        $sqlParts[$pos1] = $sqlEnd;
        unset($sqlParts[$pos2]);

        $sql = implode(',', $sqlParts);

        $tableName = $this->extractTableName($sql);

        for ($i = 0; $i < count($matches[2]); $i++)
        {
            $indexName   = str_replace('`', '', $tableName . "_" . ($i + 1));
            $indexName   = $this->checkIdentifierLength($indexName);
            $createIndex = "CREATE INDEX "
                    . $indexName
                    . " ON "
                    . $tableName
                    . $matches[2][$i];

            $this->index[] = new self($this->_adapter, $createIndex);
        }

        return $sql;
    }


    /**
     * Ancud-IT GmbH:
     * get the tableName from create-Statements
     * @param type $sql
     * @return string 
     */
    private function extractTableName($sql)
    {
        $sqlparts  = explode(' ', $sql);
        $tableName = '';

        $i = 0;
        foreach ($sqlparts as $part)
        {
            if (strcasecmp($part, 'TABLE') === 0)
                $tableName = $sqlparts[$i + 1];
            $i++;
        }

        return $tableName;
    }


    /**
     * Ancud-IT GmbH;
     * helper method
     * provides a sequence for autonumbering PK-fields
     * @param String $sql 
     */
    private function createSequence($sql)
    {
        $matches = array ();
        if (preg_match('/PRIMARY KEY\((.+)\)/', $sql, $matches) == 1)
        {
            $primaryKey = $matches[1];

            $suffixTrg = "_TRG";
            $suffixSeq = "_SEQ";

            $tableName = strtoupper($this->extractTableName($sql));

            $seqName = strtoupper($this->checkIdentifierLength($tableName . $suffixSeq));
            $trgName = strtoupper($this->checkIdentifierLength($tableName . $suffixTrg));

            $createSeq = "CREATE SEQUENCE {$seqName} INCREMENT BY 1 START WITH 1 NOCACHE";
            $createTrg = "CREATE OR REPLACE TRIGGER {$trgName}" .
                    " BEFORE INSERT ON {$tableName}" .
                    " FOR EACH ROW" .
                    " BEGIN" .
                    " SELECT {$seqName}.NEXTVAL INTO :NEW.{$primaryKey} FROM DUAL;" .
                    " END;";

            $this->sequence = new self($this->_adapter, $createSeq);
            $this->trigger = new self($this->_adapter, $createTrg);
        }
    }


    /*     * Ancud-IT GmbH
     * TODO:	Testing ...
     * Oracle doesn't allow identifier names to be longer than 30 chars
     * @param String $identifier
     * @return String 
     */


    private function checkIdentifierLength($identifier)
    {
        if (strlen($identifier) > 30)
        {
            $identifier = substr_replace($identifier, '', 1, strpos($identifier, '_') - 1);
        }

        return $identifier;
    }


    /**
     * We rewrite ? to named parameters
     * 
     * @param String $type
     * @return boolean 
     */
    public function supportsParameters($type)
    {
        return true;
    }


    public function getStmt()
    {
        return $this->_stmt;
    }


}

