<?php /** @noinspection DuplicatedCode SpellCheckingInspection PhpUnusedFunctionInspection NotOptimalIfConditionsInspection */
  
  namespace Eisodos\Connectors;
  
  use Eisodos\Eisodos;
  use Eisodos\Interfaces\DBConnectorInterface;
  use Exception;
  use RuntimeException;
  
  /**
   * Eisodos OCI8 Connector class
   *
   * https://www.oracle.com/technical-resources/articles/fuecks-lobs.html
   * https://www.phptutorial.info/?oci-bind-by-name
   *
   * Config values:
   * [Database]
   * connectMode=cached|persistent|empty(default)
   * username=
   * password=
   * connection=tnsnev|server:port/SID|tns description
   * characterSet=if empty NLS_LANG environment variable will be used, ex: AL32UTF8
   * autoCommit=true|false(default)
   * connectSQL=list of query run after connection separated by ;
   */
  class ConnectorOCI8 implements DBConnectorInterface {
    
    /** @var string DB Syntax */
    private string $_dbSyntax = 'oci8';
    
    /** @var resource Connection resource */
    private $_connection;
    
    /** @var bool In transaction flag */
    private bool $_inTransaction;
    
    /** @var array Last query column names */
    private array $_lastQueryColumnNames = [];
    
    /** @var int Last query total rows */
    private int $_lastQueryTotalRows = 0;
    
    /** @var bool Auto commit enabled */
    private bool $_autoCommit = false;
    
    /** @var int Switch case of response objects name */
    private int $_caseQuery = CASE_UPPER;
    
    /** @var int Switch case of response objects name */
    private int $_caseStoredProcedure = CASE_UPPER;
    
    public function __destruct() {
      $this->disconnect();
    }
    
    /** Convert user data type to OCI type
     * @param string $dataType_
     * @param mixed $value_
     * @return int
     */
    private function _convertType(string $dataType_, mixed &$value_): int {
      
      $dataType_ = strtolower($dataType_);
      
      if ($dataType_ === '' || $value_ == '') {
        return SQLT_CHR;
      }
      
      $type = match ($dataType_) {
        'clob' => OCI_B_CLOB,
        'rowid' => SQLT_RDD,
        'cursor' => SQLT_RSET,
        default => SQLT_CHR,
      };
      
      switch ($type) {
        case SQLT_INT:
          $value_ = (int)$value_;
          break;
        case SQLT_FLT:
          $value_ = (float)$value_;
          break;
      }
      
      return $type;
    }
    
    /** Calculate parameter length by user data type
     * @param string $bindType_
     * @return int
     */
    private function _convertLength(string $bindType_): int {
      return (!in_array($bindType_, ['clob', 'rowid', 'cursor']) ? (32766 / 2) : -1);
    }
    
    /** Calling OCI bind based on bound variables
     * @param resource $statement_ OCI statement resource
     * @param array $boundVariables_ Bound variables array structure
     * @return void
     */
    private function _bindVariables($statement_, array &$boundVariables_): void {
      
      foreach ($boundVariables_ as $variableName => &$variableProperties) {
        // get OCI constant by incoming data type
        $variableProperties['bindType'] = $this->_convertType($variableProperties['type'], $variableProperties['value']);
        // get bind variable length by data type
        $variableProperties['bindLength'] = $this->_convertLength($variableProperties['type']);
        
        // TODO cursor, rowid, file not yet supported
        // boundValue will be overwritten in case of OUT, IN_OUT parameters
        if ($variableProperties['bindType'] === OCI_B_CLOB) {
          $variableProperties['descriptor'] = oci_new_descriptor($this->_connection, OCI_D_LOB);
          $variableProperties['boundValue'] = &$variableProperties['descriptor']; // important to assign as reference
          $variableProperties['bindLength'] = -1;
        } else {
          $variableProperties['descriptor'] = false;
          $variableProperties['boundValue'] = $variableProperties['value'];
          $variableProperties['value'] = '';
        }
        
        Eisodos::$logger->trace(
          'Binding variable: ' .
          $variableName . ' - ' .
          ($variableProperties['descriptor'] !== false ? "OCILob object" : ((strlen($variableProperties['boundValue']) > 255) ? ("(" . mb_strlen($variableProperties['boundValue']) . " bytes of data)") : $variableProperties['boundValue'])) . " - " .
          $variableProperties['bindType'] . ' - ' .
          $variableProperties['bindLength']);
        
        // bindig OCI to variables
        oci_bind_by_name(
          $statement_,
          ':' . $variableName,
          $variableProperties['boundValue'], // reference, value will be overwritten
          $variableProperties['bindLength'],
          $variableProperties['bindType']
        );
        
        // In case of descriptors, value is written after binding
        if ($variableProperties['descriptor'] !== false && str_contains($variableProperties['mode_'], 'IN')) {
          if ($variableProperties['descriptor']->writeTemporary($variableProperties['value'])) {
            Eisodos::$logger->trace('  OCI LOB descriptor for parameter ' . $variableName . ' written with ' . $variableProperties['descriptor']->size() . " bytes\n");
          } else {
            throw new RuntimeException('Could not write temporary LOB for parameter ' . $variableName);
          }
        }
        
      }
    }
    
    /** Free up descriptors, fill value with values given back in out parameters
     * @param array $boundVariables_ Bound variables
     * @return void
     */
    private function _freeVariables(array $boundVariables_, array &$resultVariables_): void {
      
      foreach ($boundVariables_ as $variableName => &$variableProperties) {
        
        // free OCILob objects
        if ($variableProperties['descriptor'] !== false) {
          oci_free_descriptor($variableProperties['descriptor']);
          $variableProperties['descriptor'] = false;
        }
        
        $resultVariables_[$variableName] = $variableProperties['boundValue'];
        
        // value is overwritten by outgoing values in case of OUT, IN_OUT parameters
        if (str_contains($variableProperties['mode_'], 'OUT')) {
          $variableProperties['boundValue'] = '';
        }
      }
      unset($variableProperties);
      
    }
    
    /** OCI execute routine
     * @param resource $statement_ OCI statement resource
     * @param string $exceptionMessage_ Exception message, if filled exception threw in case of error
     * @return bool|mixed
     */
    private function &_execute($statement_, $exceptionMessage_ = ''): mixed {
      $transactionMode = $this->inTransaction() ? OCI_NO_AUTO_COMMIT : OCI_COMMIT_ON_SUCCESS;
      $result = oci_execute($statement_, $transactionMode);
      if ($result === false) {
        $e = oci_error($statement_);
        if ($statement_) {
          oci_free_statement($statement_);
        }
        // Eisodos::$logger->writeErrorLog(NULL, 'Could not execute statement - ' . $e['code'] . ' - ' . $e['message'] . "\n" . $e['sqltext']);
        Eisodos::$parameterHandler->setParam('DBError', 'Could not execute statement - ' . $e['code'] . ' - ' . $e['message'] . "\n" . $e['sqltext']);
        if (!$exceptionMessage_) {
          $_POST["__EISODOS_extendedError"] = 'Could not execute statement - ' . $e['code'] . ' - ' . $e['message'] . "\n" . $e['sqltext'];
          throw new RuntimeException('Could not execute statement');
        }
      }
      
      return $result;
    }
    
    /** OCI parse given SQL command
     * @param string $SQL_ SQL command
     * @param string $exceptionMessage_ Exception message, if filled exception threw in case of error
     * @return false|mixed|resource
     */
    private function &_parse(string $SQL_, string $exceptionMessage_ = '') {
      
      $SQL_ = str_replace("\r\n", "\n", $SQL_); // for fixing end-of-line character in the PL/SQL in windows
      $statement = oci_parse($this->_connection, $SQL_);
      if ($statement === false) {
        $e = oci_error();
        Eisodos::$parameterHandler->setParam('DBError', 'Could not parse query - ' . $e['code'] . ' - ' . $e['message'] . "\n" . $SQL_);
        if (!$exceptionMessage_) {
          $_POST['__EISODOS_extendedError'] = 'Could not parse query - ' . $e['code'] . ' - ' . $e['message'] . "\n" . $SQL_;
          throw new RuntimeException('Could not parse query');
        }
      }
      
      return $statement;
    }
    
    /** Check connection, throws exception when lost
     * @return void
     * @throws RuntimeException
     */
    private function _checkConnection(): void {
      if (!$this->connected()) {
        throw new RuntimeException('Database connection not established!');
      }
    }
    
    /**
     * @inheritDoc
     */
    public function connected(): bool {
      return !empty($this->_connection);
    }
    
    /**
     * @inheritDoc
     * @throws RuntimeException|Exception
     */
    public function connect($databaseConfigSection_ = 'Database', $connectParameters_ = [], $persistent_ = false): void {
      if (!isset($this->_connection)) {
        $databaseConfig = array_change_key_case(Eisodos::$configLoader->importConfigSection($databaseConfigSection_, '', false));
        if ($persistent_ ||
          Eisodos::$utils->safe_array_value($databaseConfig, 'connectmode') === 'persistent'
        ) {
          $connection = oci_pconnect(
            Eisodos::$utils->safe_array_value($databaseConfig, 'username'),
            Eisodos::$utils->safe_array_value($databaseConfig, 'password'),
            Eisodos::$utils->safe_array_value($databaseConfig, 'connection'),
            Eisodos::$utils->safe_array_value($databaseConfig, 'characterset'),
            OCI_DEFAULT
          );
        } elseif (Eisodos::$utils->safe_array_value($databaseConfig, 'connectmode') === 'cached') {
          $connection = oci_connect(
            Eisodos::$utils->safe_array_value($databaseConfig, 'username'),
            Eisodos::$utils->safe_array_value($databaseConfig, 'password'),
            Eisodos::$utils->safe_array_value($databaseConfig, 'connection'),
            Eisodos::$utils->safe_array_value($databaseConfig, 'characterset'),
            OCI_DEFAULT
          );
        } else {
          $connection = oci_new_connect(
            Eisodos::$utils->safe_array_value($databaseConfig, 'username'),
            Eisodos::$utils->safe_array_value($databaseConfig, 'password'),
            Eisodos::$utils->safe_array_value($databaseConfig, 'connection'),
            Eisodos::$utils->safe_array_value($databaseConfig, 'characterset'),
            OCI_DEFAULT
          );
        }
        
        if (!$connection) {
          $e = oci_error();
          Eisodos::$parameterHandler->setParam('DBError', $e['code'] . ' - ' . $e['message'] . "\n" . $e['sqltext']);
          throw new RuntimeException('Database Open Error!');
        }
        
        $this->_connection = $connection;
        
        $this->_autoCommit = (Eisodos::$utils->safe_array_value($databaseConfig, 'autocommit') === 'true');
        
        $this->_caseQuery = (Eisodos::$utils->safe_array_value($databaseConfig, 'casequery') === 'lower') ? CASE_LOWER : CASE_UPPER;
        $this->_caseStoredProcedure = (Eisodos::$utils->safe_array_value($databaseConfig, 'casestoredprocedure') === 'lower') ? CASE_LOWER : CASE_UPPER;
        
        if (!$this->_autoCommit) {
          $this->_inTransaction = true;
        }
        
        Eisodos::$logger->trace('Database connected - ' . oci_server_version($this->_connection) . ' - ' . Eisodos::$utils->safe_array_value($databaseConfig, 'connection'));
        
        $connectSQL = Eisodos::$utils->safe_array_value($databaseConfig, 'connectsql');
        if (stripos($connectSQL, 'nls_date_format') === false) {
          $connectSQL = "'ALTER SESSION SET NLS_DATE_FORMAT='YYYY-MM-DD HH24:MI:SS';'" . $connectSQL;
        }
        
        foreach (explode(';', $connectSQL) as $sql) {
          if ($sql !== '') {
            $this->query(RT_FIRST_ROW_FIRST_COLUMN, $sql);
          }
        }
        
      }
      
    }
    
    /**
     * @inheritDoc
     */
    public function disconnect($force_ = false): void {
      if ($this->connected()) {
        if ($this->inTransaction()) {
          oci_rollback($this->_connection);
        }
        oci_close($this->_connection);
        $this->_connection = NULL;
        Eisodos::$logger->trace('Database disconnected');
      }
    }
    
    /**
     * @inheritDoc
     */
    public function startTransaction(string|null $savePoint_ = NULL): void {
      $this->_checkConnection();
      
      if (!$this->inTransaction()) {
        if ($savePoint_ !== NULL && $savePoint_ !== '') {
          $this->query(RT_RAW, 'SAVEPOINT ' . $savePoint_);
        }
        $this->_inTransaction = true;
      }
    }
    
    /**
     * @inheritDoc
     */
    public function commit(): void {
      $this->_checkConnection();
      
      if ($this->inTransaction()) {
        oci_commit($this->_connection);
        Eisodos::$logger->trace('Transaction committed');
        $this->_inTransaction = true;
      }
    }
    
    /**
     * @inheritDoc
     * @throws Exception
     */
    public function rollback(string|null $savePoint_ = NULL): void {
      $this->_checkConnection();
      
      if ($this->inTransaction()) {
        if ($savePoint_ !== NULL) {
          $this->query(RT_RAW, 'ROLLBACK TO SAVEPOINT ' . $savePoint_);
          Eisodos::$logger->trace('Transaction rolled back to savepoint: ' . $savePoint_);
        } else {
          oci_rollback($this->_connection);
          Eisodos::$logger->trace('Transaction rolled back');
        }
        $this->_inTransaction = true;
      }
    }
    
    /**
     * @inheritDoc
     */
    public function inTransaction(): bool {
      $this->_checkConnection();
      
      return !$this->_autoCommit && $this->_inTransaction;
    }
    
    /**
     * @inheritDoc
     */
    public function query(int $resultTransformation_, string $SQL_, &$queryResult_ = NULL, $getOptions_ = [], $exceptionMessage_ = ''): mixed {
      $this->_lastQueryColumnNames = [];
      $this->_lastQueryTotalRows = 0;
      
      $this->_checkConnection();
      
      Eisodos::$logger->trace("Running query: \n" . $SQL_);
      
      $statement = $this->_parse($SQL_, $exceptionMessage_);
      if ($statement === false) {
        
        return false;
      }
      
      $executeResult = $this->_execute($statement, $exceptionMessage_);
      if ($executeResult === false) {
        if ($statement) {
          oci_free_statement($statement);
        }
        
        return false;
      }
      
      
      for ($i = 1; $i <= oci_num_fields($statement); $i++) {
        $this->_lastQueryColumnNames[] = ($this->_caseQuery===CASE_LOWER)?strtolower(oci_field_name($statement, $i)):strtoupper(oci_field_name($statement, $i));
      }
      
      $rows = [];
      
      if ($resultTransformation_ === RT_RAW) {
        oci_fetch_all($statement, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC);
        if ($statement) {
          oci_free_statement($statement);
        }
        if (!$rows) {
          return false;
        }
        
        foreach ($rows as &$row) {
          $row = array_change_key_case($row, $this->_caseQuery);
        }
        unset($row);
        $queryResult_ = $rows;
        $this->_lastQueryTotalRows = count($rows);
        
        return true;
      }
      
      if ($resultTransformation_ === RT_FIRST_ROW) {
        oci_fetch_all($statement, $rows, 0, 1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC);
        if ($statement) {
          oci_free_statement($statement);
        }
        if (!$rows || count($rows) === 0) {
          return false;
        }
        
        $queryResult_ = array_change_key_case($rows[0], $this->_caseQuery);;
        $this->_lastQueryTotalRows = 1;
        
        return true;
      }
      
      if ($resultTransformation_ === RT_FIRST_ROW_FIRST_COLUMN) {
        oci_fetch_all($statement, $rows, 0, 1, OCI_FETCHSTATEMENT_BY_ROW + OCI_NUM);
        if ($statement) {
          oci_free_statement($statement);
        }
        if (!$rows || count($rows) === 0) {
          return '';
        }
        
        $this->_lastQueryTotalRows = 1;
        
        return $rows[0][0];
      }
      
      if ($resultTransformation_ === RT_ALL_KEY_VALUE_PAIRS
        || $resultTransformation_ === RT_ALL_FIRST_COLUMN_VALUES
        || $resultTransformation_ === RT_ALL_ROWS
        || $resultTransformation_ === RT_ALL_ROWS_ASSOC) {
        
        if ($resultTransformation_ === RT_ALL_KEY_VALUE_PAIRS) {
          while ($row = oci_fetch_array($statement, OCI_NUM + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) {
            $queryResult_[$row[0]] = $row[1];
          }
        } else if ($resultTransformation_ === RT_ALL_FIRST_COLUMN_VALUES) {
          while ($row = oci_fetch_array($statement, OCI_NUM + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) {
            $queryResult_[] = $row[0];
          }
        } else if ($resultTransformation_ === RT_ALL_ROWS) {
          oci_fetch_all($statement, $queryResult_, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC);
          foreach ($queryResult_ as &$row) {
            $row = array_change_key_case($row, $this->_caseQuery);
          }
          unset($row);
        } else if ($resultTransformation_ === RT_ALL_ROWS_ASSOC) {
          $indexFieldName = Eisodos::$utils->safe_array_value($getOptions_, 'indexFieldName', false);
          if (!$indexFieldName) {
            throw new RuntimeException('Index field name is mandatory on RT_ALL_ROWS_ASSOC result type');
          }
          switch ($this->_caseQuery) {
            case CASE_LOWER:
              $indexFieldName = strtolower($indexFieldName);
              break;
            default:
              $indexFieldName = strtoupper($indexFieldName);
              break;
          }
          while ($row = oci_fetch_assoc($statement)) {
            $row = array_change_key_case($row, $this->_caseQuery);
            $queryResult_[$row[$indexFieldName]] = $row;
          }
        }
        
        if ($statement) {
          oci_free_statement($statement);
        }
        
        $this->_lastQueryTotalRows = count($queryResult_);
        
        return true;
      }
      
      throw new RuntimeException('Unknown query result type');
      
    }
    
    /**
     * @inheritDoc
     * @throws Exception
     */
    public function executeDML(string $SQL_, $throwException_ = true): int|bool {
      $this->_checkConnection();
      
      Eisodos::$logger->trace("Executing DML: \n" . $SQL_);
      
      $statement = false;
      
      try {
        
        $statement = $this->_parse($SQL_, $throwException_ ? 'DML Exception' : '');
        if ($statement === false) {
          
          return false;
        }
        $executeResult = $this->_execute($statement, $throwException_ ? 'DML Exception' : '');
        if ($executeResult === false) {
          if ($statement) {
            oci_free_statement($statement);
          }
          
          return false;
        }
        
      } catch (Exception $e) {
        if ($statement) {
          oci_free_statement($statement);
        }
        throw $e;
      }
      
      $numRows = oci_num_rows($statement);
      Eisodos::$logger->trace('Number of rows modified: ' . $numRows);
      if ($statement) {
        oci_free_statement($statement);
      }
      
      return $numRows;
    }
    
    /**
     * @inheritDoc
     */
    public function bind(array &$boundVariables_, string $variableName_, string $dataType_, string $value_, $inOut_ = 'IN'): void {
      $boundVariables_[$variableName_] = array();
      if ($dataType_ === 'clob' && $value_ === '') // Empty CLOB bug / invalid LOB locator specified, force type to text
      {
        $boundVariables_[$variableName_]['type'] = 'text';
      } else {
        $boundVariables_[$variableName_]['type'] = $dataType_;
      }
      $boundVariables_[$variableName_]['value'] = $value_;
      // TODO rename to mode
      $boundVariables_[$variableName_]['mode_'] = $inOut_;
    }
    
    /**
     * @inheritDoc
     */
    public function bindParam(array &$boundVariables_, string $parameterName_, string $dataType_): void {
      $this->bind($boundVariables_, $parameterName_, $dataType_, Eisodos::$parameterHandler->getParam($parameterName_));
    }
    
    /**
     * @deprecated Execute prepared DML not supported! Use executePreparedDML2()
     * @inheritDoc
     */
    public function executePreparedDML(string $SQL_, array $dataTypes_ = [], array &$data_ = [], bool $throwException_ = true): int|bool {
      throw new RuntimeException('Execute prepared DML not supported! Use executePreparedDML2()!');
    }
    
    /**
     * @inheritDoc
     * Bind variables: ['variableName'=>['value'=>'','type'=>'','lenght'=>'','mode_'=>'IN|IN_OUT|OUT']]
     * SQL: insert into tablename (id,column1,column2) values (:variableName,:variableName2,:variableName3)
     * @throws Exception
     */
    public function executePreparedDML2(string $SQL_, array $boundVariables_, $throwException_ = true): int|bool {
      $this->_checkConnection();
      
      Eisodos::$logger->trace("Executing DML: \n" . $SQL_);
      
      $statement = $this->_parse($SQL_, $throwException_ ? 'DML Exception' : '');
      if ($statement === false) {
        
        return false;
      }
      
      /* bindig variables */
      $this->_bindVariables($statement, $boundVariables_);
      
      try {
        $executeResult = $this->_execute($statement, $throwException_ ? 'DML Exception' : '');
        if ($executeResult === false) {
          $this->_freeVariables($boundVariables_);
          if ($statement) {
            oci_free_statement($statement);
          }
          
          return false;
        }
      } catch (Exception $e) {
        $this->_freeVariables($boundVariables_);
        if ($statement) {
          oci_free_statement($statement);
        }
        throw $e;
      }
      
      $numRows = oci_num_rows($statement);
      Eisodos::$logger->trace('Number of rows modified: ' . $numRows);
      $this->_freeVariables($boundVariables_);
      if ($statement) {
        oci_free_statement($statement);
      }
      
      return $numRows;
    }
    
    /**
     * @inheritDoc
     * @throws Exception
     */
    public function executeStoredProcedure(string $procedureName_, array $inputVariables_, array &$resultVariables_, $throwException_ = true, $case_ = CASE_UPPER): bool {
      $this->_checkConnection();
      
      // keep input variables in the result array
      // $resultVariables_ = $inputVariables_;
      
      // generate stored procedure sql command from incoming parameters
      $sql = '';
      foreach ($inputVariables_ as $parameterName => $parameterProperties) {
        $sql .= ($sql ? ',' : '') . $parameterName . ' => :' . $parameterName;
      }
      $sql = 'BEGIN ' . $procedureName_ . '(' . $sql . '); END; ';
      
      Eisodos::$logger->trace("Executing stored procedure: \n" . $sql);
      
      $statement = $this->_parse($sql, $throwException_ ? 'Stored Procedure Exception' : '');
      if ($statement === false) {
        
        return false;
      }
      
      $this->_bindVariables($statement, $inputVariables_);
      
      try {
        $executeResult = $this->_execute($statement, $throwException_ ? 'Stored Procedure Exception' : '');
        
        $this->_freeVariables($inputVariables_, $resultVariables_);
        $resultVariables_ = array_change_key_case($resultVariables_, $this->_caseStoredProcedure);
        if ($executeResult === false) {
          return false;
        }
        if ($statement) {
          oci_free_statement($statement);
        }
        
      } catch (Exception $e) {
        try {
          $this->_freeVariables($inputVariables_);
          if ($statement) {
            oci_free_statement($statement);
          }
        } catch (Exception $e2) {
        }
        throw $e;
      }
      
      return true;
    }
    
    /**
     * @inheritDoc
     */
    public function getLastQueryColumns(): array {
      return $this->_lastQueryColumnNames;
    }
    
    /**
     * @inheritDoc
     */
    public function getLastQueryTotalRows(): int {
      return $this->_lastQueryTotalRows;
    }
    
    /**
     * @inheritDoc
     */
    public function getConnection(): mixed {
      return $this->_connection;
    }
    
    /**
     * @inheritDoc
     */
    public function emptySQLField($value_, $isString_ = true, $maxLength_ = 0, $exception_ = '', $withComma_ = false, $keyword_ = 'NULL'): string {
      if ($value_ === '') {
        if ($withComma_) {
          return 'NULL, ';
        }
        
        return 'NULL';
      }
      if ($isString_) {
        if ($maxLength_ > 0 && mb_strlen($value_, 'UTF-8') > $maxLength_) {
          if ($exception_) {
            throw new RuntimeException($exception_);
          }
          
          $value_ = substr($value_, 0, $maxLength_);
        }
        $result = "'" . Eisodos::$utils->replace_all($value_, "'", "''") . "'";
      } else {
        $result = $value_;
      }
      if ($withComma_) {
        $result .= ', ';
      }
      
      return $result;
    }
    
    /**
     * @inheritDoc
     */
    public function nullStr($value_, $isString_ = true, $maxLength_ = 0, $exception_ = '', $withComma_ = false): string {
      return $this->emptySQLField($value_, $isString_, $maxLength_, $exception_, $withComma_);
    }
    
    /**
     * @inheritDoc
     */
    public function defaultStr($value_, $isString_ = true, $maxLength_ = 0, $exception_ = '', $withComma_ = false): string {
      return $this->emptySQLField($value_, $isString_, $maxLength_, $exception_, $withComma_, 'DEFAULT');
    }
    
    /**
     * @inheritDoc
     */
    public function nullStrParam(string $parameterName_, $isString_ = true, $maxLength_ = 0, $exception_ = '', $withComma_ = false): string {
      return $this->emptySQLField(Eisodos::$parameterHandler->getParam($parameterName_), $isString_, $maxLength_, $exception_, $withComma_);
    }
    
    /**
     * @inheritDoc
     */
    public function defaultStrParam(string $parameterName_, $isString_ = true, $maxLength_ = 0, $exception_ = '', $withComma_ = false): string {
      return $this->emptySQLField(Eisodos::$parameterHandler->getParam($parameterName_), $isString_, $maxLength_, $exception_, $withComma_, 'DEFAULT');
    }
    
    /**
     * @inheritDoc
     */
    public function DBSyntax(): string {
      return $this->_dbSyntax;
    }
    
    /**
     * @inheritDoc
     */
    public function toList(mixed $value_, bool $isString_ = true, int $maxLength_ = 0, string $exception_ = '', bool $withComma_ = false): string {
      $result = '';
      foreach (explode(',', $value_) as $value) {
        $result .= ($result === '' ? '' : ',') . $this->nullStr(trim($value), $isString_, $maxLength_, $exception_);
      }
      $result = '(' . $result . ')';
      if ($withComma_) {
        $result .= ", ";
      }
      
      return $result;
    }
  }