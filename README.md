# ConnectorOCI8

Eisodos Framework ORACLE Database Connector

## Prerequisites
- PHP 8.x
  - Tested with PHP 8.3
- Installed ext-oci8
  - Tested with ORACLE InstantClient 21.13
  - Tested with oci8-3.4.0 extension
- Eisodos framework
  - Minimum version 1.0.14 (DBConnector Interface changed)

## Installation
Installation via composer:
```
composer install "offsite-solutions/eisodos-db-connector-oci8"
```

## Configuration
Default configuration:
```
[Database]
connectMode=
username={username}
password={password}
connection={TNS name}
characterSet=
autoCommit=false
connectSQL=ALTER SESSION SET NLS_DATE_FORMAT='YYYY-MM-DD HH24:MI:SS';ALTER SESSION SET NLS_TIMESTAMP_FORMAT='YYYY-MM-DD HH24:MI:SS';ALTER SESSION SET NLS_SORT='hungarian';
```

### ConnectMode
OCI ConnectMode can be **cached, persistent, empty**(default) according to OCI8 connection options.
See PHP documentation for details: https://www.php.net/manual/en/function.oci-connect.php

### Authentication
Currently only username, password based authentication implemented. 
Connection string can be anything (tns name|server:port/SID|tns description), see the documentation: https://www.php.net/manual/en/function.oci-connect.php

### CharacterSet
Default character set is AL32UTF8.

### AutoCommit
If it's **true**, every command will be auto-committed (not recommended). Default is **false**.

### ConnectSQL
Series of SQLs which will be executed right after successful connection.

## Initialization
```
  use Eisodos\Connectors\ConnectorOCI8;
  use Eisodos\Eisodos;
  
  Eisodos::$dbConnectors->registerDBConnector(new ConnectorOCI8(), 0);
  Eisodos::$dbConnectors->db()->connect();
  
  Eisodos::$dbConnectors->db()->disconnect();
```

## Methods
See Eisodos DBConnector Interface documentation: https://github.com/offsite-solutions/Eisodos