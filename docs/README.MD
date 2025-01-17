# Sources

* PHP OCI functions: https://www.php.net/manual/en/ref.oci8.php
* PDO-via-oci8: https://github.com/yajra/pdo-via-oci8/blob/3.0/src/Pdo/
* PEAR MDB2: https://github.com/pear/MDB2/tree/master

## Usage

### Initialization
```
use Eisodos\Connectors\ConnectorOCI8;

Eisodos::$dbConnectors->registerDBConnector(new ConnectorOCI8(),0);
```
### Connection
```
Eisodos::$dbConnectors->db()->connect();
```

### Disconnect
```
Eisodos::$dbConnectors->db()->disconnect();
```

### Running query
```
$back=[];
Eisodos::$dbConnectors->db()->query(RT_FIRST_ROW,"select * from tablename",$back);
```

