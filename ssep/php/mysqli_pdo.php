<?php
define('CONN_MOD', 'mysqli');     // sets default connection method: 'pdo', or 'mysqli'

// class to connect to MySQL, perform SQL queries, easily and safe
class mysqli_pdo {
  protected $conn_mod ='';    // 'pdo', or 'mysqli'
  protected $conn = false;    // stores the connection to mysql
  protected $conn_data = [];  // to store data for connecting to database
  public $mysql_version ='';  // mysql server version
  public $fetch ='';         // 'assoc' - columns with named index, 'num' - columns numerically indexed, Else - both
  public $group ='';         //If 'cols': returns data grouped by columns; Otherwise, return Select results grouped by Rows. 
  public $affected_rows =0;  // number affected rows for Insert, Update, Delete
  public $num_rows =0;       // number of rows from Select /Show results
  public $num_cols =0;       // number of columns from Select /Show results
  public $last_insertid;     // stores the last ID in an AUTO_INCREMENT column, after Insert query
  public $nr_queries =0;     // to store number of sql queries
  public $error = false;     // to store and check for errors

  function __construct($conn_data){
    $this->conn_data = $conn_data;   //stores connection data to MySQL database
  }

  // to set the connection to mysql, with PDO, or MySQLi
  protected function setConn($conn_data) {
    // sets the connection method, check if can use pdo or mysqli
    if(CONN_MOD == 'mysqli') {
      if(extension_loaded('mysqli') === true && function_exists('mysqli_fetch_all')) $this->conn_mod ='mysqli';
      else if(extension_loaded('PDO') === true) $this->conn_mod ='pdo';
    }
    else if(CONN_MOD == 'pdo') {
      if(extension_loaded('PDO') === true) $this->conn_mod ='pdo';
      else if(extension_loaded('mysqli') === true && function_exists('mysqli_fetch_all')) $this->conn_mod ='mysqli';
    }

    if($this->conn_mod == 'mysqli') $this->connMySQLi($conn_data);
    else if($this->conn_mod == 'pdo') $this->connPDO($conn_data);
    else $this->setSqlError('Unable to use PDO or MySQLi');

    //if error, output it
    if($this->error !== false) echo 'Error: '. $this->error;
  }

  // for connecting to mysql with PDO
  protected function connPDO($conn_data) {
    try {
      // Connect and create the PDO object
      $this->conn = new PDO("mysql:host=".$conn_data['host']."; dbname=".$conn_data['bdname'], $conn_data['user'], $conn_data['pass']);

      // Sets to handle the errors in the ERRMODE_EXCEPTION mode
      $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $this->mysql_version = str_replace('.', '', $this->conn->getAttribute(PDO::ATTR_SERVER_VERSION));

      // Sets transfer with encoding UTF-8
      $this->conn->exec('SET character_set_client="utf8",character_set_connection="utf8",character_set_results="utf8";');
    }
    catch(PDOException $e) {
      $this->setSqlError($e->getMessage());
    }
  }

  // method that create the connection to mysql with MySQLi
  protected function connMySQLi($conn_data) {
    // if the connection is successfully established
    $this->conn = new mysqli($conn_data['host'], $conn_data['user'], $conn_data['pass'], $conn_data['bdname']);
    if(mysqli_connect_errno()){
      $this->setSqlError('MySQL connection failed: '. mysqli_connect_error());
      $this->conn = false;
    }
    else {
      $this->mysql_version = strval($this->conn->server_version);
      $this->conn->query('SET character_set_client="utf8",character_set_connection="utf8",character_set_results="utf8";');
    }
  }

  // Performs SQL queries
  // $sql - SQL query with prepared statement
  // $param - array of values for SQL query
  public function sqlExec($sql, $param=[]) {
    if($this->conn === false || $this->conn === NULL) $this->setConn($this->conn_data);      // sets the connection to mysql

    // resets previous regstered data
    $this->affected_rows = 0;
    $this->num_rows = 0;
    $this->num_cols = 0;

    // if there is a connection set ($conn property not false)
    if($this->conn !== false){
      // gets the first word in $sql, to determine when SELECT query
      $ar_mode = explode(' ', trim(preg_replace('/[^A-z ]+/i', '', $sql)), 2);
      $mode = strtolower($ar_mode[0]);
      $this->error = false;   // to can perform current $sql if previous has error
      $nr_p = count($param);  // number of elements for placeholders

      // code to replace ":placeholder" with "?" (for MySQLi)
      if($this->conn_mod == 'mysqli') {
        // check if number of :placeholders match number of items in $param. If they match, replace :placeholder with ? (for MySQLi)
        // else, replace :placeholder with its value, and empty $param
        if(preg_match_all('/:[^,|"|\'|;|\)\} ]*/i', $sql, $mt)) {
          $nr_m = count($mt[0]);
          if($nr_p == $nr_m) $sql = preg_replace('/:[^,|"|\'|;|\)\} ]*/i','?', $sql);
          else {
            foreach($param AS $k => $v) {
              if(is_string($v)) $v = "'". str_replace("'", "\\'", $v) ."'";
              $sql = str_ireplace(':'. $k, $v, $sql);
            }
            $param = [];  $nr_p = 0;
          }
        }
      }

      $sqlre = $this->conn->prepare($sql);  // prepares statement

      // if successfully prepared execute query
      if(is_object($sqlre)){
        if($this->conn_mod == 'mysqli'){
          // if values in $param, sets to use "bind_param" before execute()
          if($nr_p >0){
            // stores in $args[0] the type of the value of each value in $param, the rest of items in $args are the values
            $args = [''];
            foreach($param AS $k=>$v) {
              if(is_int($v)) $args[0] .= 'i';
              else if(is_double($v)) $args[0] .= 'd';
              else $args[0] .= 's';
              $args[] = &$param[$k];
            }

            // binds the values with their types in prepared statement
            call_user_func_array([$sqlre,'bind_param'], $args);
          }

          if(!$sqlre->execute()) {
            if(isset($this->conn->error_list[0]['error'])) $this->setSqlError($this->conn->error_list[0]['error']);
            else $this->setSqlError('Unable to execute the SQL query, check if values are passed to sqlExec()');
          }
        }
        else if($this->conn_mod == 'pdo'){
          try { $sqlre->execute($param); }
          catch(PDOException $e) { $this->setSqlError($e->getMessage()); }
        }

        $this->nr_queries++;    // to know number of sql queries
      }
      else {
        if(isset($this->conn->error_list[0]['error'])) $this->setSqlError($this->conn->error_list[0]['error']);
        else $this->setSqlError('Unable to prepare the SQL query, check if SQL query data are correctly');
      }

      // if no error
      if($this->error === false) {
        // if $mode is 'select' or 'show', gets the result_set to return
        if($mode == 'select' || $mode == 'show') {
          $re = ($this->conn_mod == 'mysqli') ? $this->getSelectMySQLi($sqlre) : $this->getSelectPDO($sqlre);  // gets select results

          //set number of rows and items
          $nr_i = count($re);  // number of items in results
          if(isset($re[0])) { $nr_i2 = ($this->fetch != 'assoc' && $this->fetch != 'num') ? count($re[0]) /2 :  count($re[0]); }
          else $nr_i2 =0;
          if($this->group !='cols') {$this->num_rows = $nr_i; $this->num_cols = $nr_i2;}
          else {$this->num_rows = $nr_i2; $this->num_cols = $nr_i;}
        }
        else { $re = true; $this->affected_rows = ($this->conn_mod == 'mysqli') ? $sqlre->affected_rows : $sqlre->rowCount(); }  // affected rows for Insert, Update, Delete

        // if Insert query, stores the last insert ID
        if($mode == 'insert') $this->last_insertid = ($this->conn_mod == 'mysqli') ? $this->conn->insert_id : $this->conn->lastInsertId();
      }
    }

    // sets to return false in case of error
    if($this->error !== false) $re = false;
    return $re;
  }

  // gets and returns Select results performed with PDO
  // receives the object created with prepare() statement
  protected function getSelectPDO($sqlre) {
    $re = [];

    // if fetch() returns at least one row (not false), adds the rows in $re, according to $fetch property
    $fetch = $this->fetch =='assoc' ? PDO::FETCH_ASSOC :($this->fetch =='num' ? PDO::FETCH_NUM : PDO::FETCH_BOTH);
    if($row = $sqlre->fetch($fetch)){
      do {
        // check each column if it has numeric value, to convert it from "string"
        foreach($row AS $k=>$v){
          if(is_numeric($v)) $row[$k] = $v + 0;
          if($this->group =='cols'){  //store by cols
            if(!isset($re[$k])) $re[$k] =[];
            $re[$k][] = $v;
          }
        }
        if($this->group !='cols') $re[] = $row;  //store by rows
      }
      while($row = $sqlre->fetch($fetch));
    }
    return $re;
  }

  // gets and returns Select results performed with MySQLi
  // receives the object created with prepare() statement
  protected function getSelectMySQLi($sqlre) {
    $re = [];
    $result = $sqlre->get_result();  //get result set from a prepared /execute statement

    // gets array with results according to $fetch
    $fetch = $this->fetch =='assoc' ? MYSQLI_ASSOC :($this->fetch =='num' ? MYSQLI_NUM : MYSQLI_BOTH);
    while($row = $result->fetch_array($fetch)){
      if($this->group !='cols') $re[] = $row;  //get by rows
      else {  //get by columns
        foreach($row AS $k=>$v){
          if(!isset($re[$k])) $re[$k] =[];
          $re[$k][] = $v;
        }
      }
    }
    return $re;
  }

  // set sql error in $error
  protected function setSqlError($err) {
    $this->error = $err ;
  }
}