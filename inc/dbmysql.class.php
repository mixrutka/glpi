<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2017 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 *  Database class for Mysql
**/
class DBmysql {

   //! Database Host - string or Array of string (round robin)
   public $dbhost             = "";
   //! Database User
   public $dbuser             = "";
   //! Database Password
   public $dbpassword         = "";
   //! Default Database
   public $dbdefault          = "";
   //! Database Handler
   public $dbh;
   //! Database Error
   public $error              = 0;

   // Slave management
   public $slave              = false;
   /** Is it a first connection ?
    * Indicates if the first connection attempt is successful or not
    * if first attempt fail -> display a warning which indicates that glpi is in readonly
   **/
   public $first_connection   = true;
   // Is connected to the DB ?
   public $connected          = false;


   /**
    * Constructor / Connect to the MySQL Database
    *
    * @param integer $choice host number (default NULL)
    *
    * @return void
    */
   function __construct($choice=NULL) {
      $this->connect($choice);
   }

   /**
    * Connect using current database settings
    * Use dbhost, dbuser, dbpassword and dbdefault
    *
    * @param integer $choice host number (default NULL)
    *
    * @return void
    */
   function connect($choice=NULL) {
      $this->connected = false;

      if (is_array($this->dbhost)) {
         // Round robin choice
         $i    = (isset($choice) ? $choice : mt_rand(0, count($this->dbhost)-1));
         $host = $this->dbhost[$i];

      } else {
         $host = $this->dbhost;
      }

      $hostport = explode(":", $host);
      if (count($hostport) < 2) {
         // Host
         $this->dbh = @new mysqli($host, $this->dbuser, rawurldecode($this->dbpassword),
                                  $this->dbdefault);

      } else if (intval($hostport[1])>0) {
         // Host:port
         $this->dbh = @new mysqli($hostport[0], $this->dbuser, rawurldecode($this->dbpassword),
                                  $this->dbdefault, $hostport[1]);
      } else {
         // :Socket
         $this->dbh = @new mysqli($hostport[0], $this->dbuser, rawurldecode($this->dbpassword),
                                  $this->dbdefault, ini_get('mysqli.default_port'), $hostport[1]);
      }

      if ($this->dbh->connect_error) {
         $this->connected = false;
         $this->error     = 1;
      } else {
         $this->dbh->set_charset(isset($this->dbenc) ? $this->dbenc : "utf8");

         if (GLPI_FORCE_EMPTY_SQL_MODE) {
            $this->dbh->query("SET SESSION sql_mode = ''");
         }
         $this->connected = true;
      }
   }

   /**
    * Escapes special characters in a string for use in an SQL statement,
    * taking into account the current charset of the connection
    *
    * @since 0.84
    *
    * @param string $string String to escape
    *
    * @return string escaped string
    */
   function escape($string) {
      return $this->dbh->real_escape_string($string);
   }

   /**
    * Execute a MySQL query
    *
    * @param string $query Query to execute
    *
    * @var array   $CFG_GLPI
    * @var array   $DEBUG_SQL
    * @var integer $SQL_TOTAL_REQUEST
    *
    * @return mysqli_result|boolean Query result handler
    *
    * @throws GlpitestSQLError
    */
   function query($query) {
      global $CFG_GLPI, $DEBUG_SQL, $SQL_TOTAL_REQUEST;

      if (($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE)
          && $CFG_GLPI["debug_sql"]) {
         $SQL_TOTAL_REQUEST++;
         $DEBUG_SQL["queries"][$SQL_TOTAL_REQUEST] = $query;
         $TIMER                                    = new Timer();
         $TIMER->start();
      }

      $res = @$this->dbh->query($query);
      if (!$res) {
         // no translation for error logs
         $error = "  *** MySQL query error:\n  SQL: ".addslashes($query)."\n  Error: ".
                   $this->dbh->error."\n";
         $error .= Toolbox::backtrace(false, 'DBmysql->query()', array('Toolbox::backtrace()'));

         Toolbox::logInFile("sql-errors", $error);
         if (class_exists('GlpitestSQLError')) { // For unit test
            throw new GlpitestSQLError($error);
         }

         if (($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE)
             && $CFG_GLPI["debug_sql"]) {
            $DEBUG_SQL["errors"][$SQL_TOTAL_REQUEST] = $this->error();
         }
      }

      if (($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE)
          && $CFG_GLPI["debug_sql"]) {
         $TIME                                   = $TIMER->getTime();
         $DEBUG_SQL["times"][$SQL_TOTAL_REQUEST] = $TIME;
      }
      return $res;
   }

   /**
    * Execute a MySQL query
    *
    * @since 0.84
    *
    * @param string $query   Query to execute
    * @param string $message Explaination of query (default '')
    *
    * @return mysqli_result Query result handler
    */
   function queryOrDie($query, $message='') {
      //TRANS: %1$s is the description, %2$s is the query, %3$s is the error message
      $res = $this->query($query)
             or die(sprintf(__('%1$s - Error during the database query: %2$s - Error is %3$s'),
                            $message, $query, $this->error()));
      return $res;
   }

   /**
    * Prepare a MySQL query
    *
    * @param string $query Query to prepare
    *
    * @return mysqli_stmt|boolean statement object or FALSE if an error occurred.
    *
    * @throws GlpitestSQLError
    */
   function prepare($query) {
      $res = @$this->dbh->prepare($query);
      if (!$res) {
         // no translation for error logs
         $error = "  *** MySQL prepare error:\n  SQL: ".addslashes($query)."\n  Error: ".
                   $this->dbh->error."\n";
         $error .= Toolbox::backtrace(false, 'DBmysql->prepare()', array('Toolbox::backtrace()'));

         Toolbox::logInFile("sql-errors", $error);
         if (class_exists('GlpitestSQLError')) { // For unit test
            throw new GlpitestSQLError($error);
         }

         if (($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE)
             && $CFG_GLPI["debug_sql"]) {
            $DEBUG_SQL["errors"][$SQL_TOTAL_REQUEST] = $this->error();
         }
      }
      return $res;
   }

   /**
    * Give result from a sql result
    *
    * @param mysqli_result $result MySQL result handler
    * @param int           $i      Row offset to give
    * @param type          $field  Field to give
    *
    * @return mixed Value of the Row $i and the Field $field of the Mysql $result
    */
   function result($result, $i, $field) {
      if ($result && ($result->data_seek($i))
          && ($data = $result->fetch_array())
          && isset($data[$field])) {
         return $data[$field];
      }
      return NULL;
   }

   /**
    * Number of rows
    *
    * @param mysqli_result $result MySQL result handler
    *
    * @return integer number of rows
    */
   function numrows($result) {
      return $result->num_rows;
   }

   /**
    * Fetch array of the next row of a Mysql query
    * Please prefer fetch_row or fetch_assoc
    *
    * @param mysqli_result $result MySQL result handler
    *
    * @return string[]|null array results
    */
   function fetch_array($result) {
      return $result->fetch_array();
   }

   /**
    * Fetch row of the next row of a Mysql query
    *
    * @param mysqli_result $result MySQL result handler
    *
    * @return mixed|null result row
    */
   function fetch_row($result) {
      return $result->fetch_row();
   }

   /**
    * Fetch assoc of the next row of a Mysql query
    *
    * @param mysqli_result $result MySQL result handler
    *
    * @return string[]|null result associative array
    */
   function fetch_assoc($result) {
      return $result->fetch_assoc();
   }

   /**
    * Fetch object of the next row of an SQL query
    *
    * @param mysqli_result $result MySQL result handler
    *
    * @return object|null
    */
   function fetch_object($result) {
      return $result->fetch_object();
   }

   /**
    * Move current pointer of a Mysql result to the specific row
    *
    * @param mysqli_result $result MySQL result handler
    * @param integer       $num    Row to move current pointer
    *
    * @return boolean
    */
   function data_seek($result, $num) {
      return $result->data_seek($num);
   }

   /**
    * Give ID of the last inserted item by Mysql
    *
    * @return mixed
    */
   function insert_id() {
      return $this->dbh->insert_id;
   }

   /**
    * Give number of fields of a Mysql result
    *
    * @param mysqli_result $result MySQL result handler
    *
    * @return int number of fields
    */
   function num_fields($result) {
      return $result->field_count;
   }

   /**
    * Give name of a field of a Mysql result
    *
    * @param mysqli_result $result MySQL result handler
    * @param integer       $nb     ID of the field
    *
    * @return string name of the field
    */
   function field_name($result, $nb) {
      $finfo = $result->fetch_fields();
      return $finfo[$nb]->name;
   }

   /**
    * Get flags of a field of a mysql result
    *
    * @deprecated BUGGY FUNCTION : param $field isn't used. Consider with precaution results... !
    *
    * @param mysqli_result $result MySQL result handler
    * @param string        $field  Field name
    *
    * @return mixed flags of the field
    */
   function field_flags($result, $field) {
      $finfo = $result->fetch_fields();
      return $finfo[$nb]->flags;
   }

   /**
    * List tables in database
    *
    * @param string $table table name condition (glpi_% as default to retrieve only glpi tables)
    *
    * @return mysqli_result list of tables
    */
   function list_tables($table="glpi_%") {
      return $this->query(
         "SELECT TABLE_NAME FROM information_schema.`TABLES`
             WHERE TABLE_SCHEMA = '{$this->dbdefault}'
                AND TABLE_TYPE = 'BASE TABLE'
                AND TABLE_NAME LIKE '$table'"
      );
   }

   /**
    * List fields of a table
    *
    * @param string  $table    Table name condition
    * @param boolean $usecache If use field list cache (default true)
    *
    * @return mixed list of fields
    */
   function list_fields($table, $usecache=true) {
      static $cache = array();

      if ($usecache && isset($cache[$table])) {
         return $cache[$table];
      }
      $result = $this->query("SHOW COLUMNS FROM `$table`");
      if ($result) {
         if ($this->numrows($result) > 0) {
            $cache[$table] = array();
            while ($data = $result->fetch_assoc()) {
               $cache[$table][$data["Field"]] = $data;
            }
            return $cache[$table];
         }
         return array();
      }
      return false;
   }

   /**
    * Get number of affected rows in previous MySQL operation
    *
    * @return int number of affected rows on success, and -1 if the last query failed.
    */
   function affected_rows() {
      return $this->dbh->affected_rows;
   }

   /**
    * Free result memory
    *
    * @param mysqli_result $result MySQL result handler
    *
    * @return boolean TRUE on success or FALSE on failure.
    */
   function free_result($result) {
      return $result->free();
   }

   /**
    * Returns the numerical value of the error message from previous MySQL operation
    *
    * @return int error number from the last MySQL function, or 0 (zero) if no error occurred.
    */
   function errno() {
      return $this->dbh->errno;
   }

   /**
    * Returns the text of the error message from previous MySQL operation
    *
    * @return string error text from the last MySQL function, or '' (empty string) if no error occurred.
    */
   function error() {
      return $this->dbh->error;
   }

   /**
    * Close MySQL connection
    *
    * @return boolean TRUE on success or FALSE on failure.
    */
   function close() {
      if ($this->dbh) {
         return $this->dbh->close();
      }
      return false;
   }

   /**
    * is a slave database ?
    *
    * @return boolean
    */
   function isSlave() {
      return $this->slave;
   }

   /**
    * Execute all the request in a file
    *
    * @param string $path with file full path
    *
    * @return boolean true if all query are successfull
    */
   function runFile($path) {
      $DBf_handle = fopen($path, "rt");
      if (!$DBf_handle) {
         return false;
      }

      $formattedQuery = "";
      $lastresult     = false;
      while (!feof($DBf_handle)) {
         // specify read length to be able to read long lines
         $buffer = fgets($DBf_handle, 102400);

         // do not strip comments due to problems when # in begin of a data line
         $formattedQuery .= $buffer;
         if ((substr(rtrim($formattedQuery), -1) == ";")
             && (substr(rtrim($formattedQuery), -4) != "&gt;")
             && (substr(rtrim($formattedQuery), -4) != "160;")) {

            $formattedQuerytorun = $formattedQuery;

            // Do not use the $DB->query
            if ($this->query($formattedQuerytorun)) { //if no success continue to concatenate
               $formattedQuery = "";
               $lastresult     = true;
            } else {
               $lastresult = false;
            }
         }
      }

      return $lastresult;
   }

   /**
    * Instanciate a Simple DBIterator
    *
    * Examples =
    *  foreach ($DB->request("select * from glpi_states") as $data) { ... }
    *  foreach ($DB->request("glpi_states") as $ID => $data) { ... }
    *  foreach ($DB->request("glpi_states", "ID=1") as $ID => $data) { ... }
    *  foreach ($DB->request("glpi_states", "", "name") as $ID => $data) { ... }
    *  foreach ($DB->request("glpi_computers",array("name"=>"SBEI003W","entities_id"=>1),array("serial","otherserial")) { ... }
    *
    * Examples =
    *   array("id"=>NULL)
    *   array("OR"=>array("id"=>1, "NOT"=>array("state"=>3)));
    *   array("AND"=>array("id"=>1, array("NOT"=>array("state"=>array(3,4,5),"toto"=>2))))
    *
    * FIELDS name or array of field names
    * ORDER name or array of field names
    * LIMIT max of row to retrieve
    * START first row to retrieve
    *
    * @param string|string[] $tableorsql Table name, array of names or SQL query
    * @param string|string[] $crit       String or array of filed/values, ex array("id"=>1), if empty => all rows
    *                                    (default '')
    * @param boolean         $debug      To log the request (default false)
    *
    * @return DBmysqlIterator
    */
   public function request ($tableorsql, $crit="", $debug=false) {
      return new DBmysqlIterator($this, $tableorsql, $crit, $debug);
   }

    /**
     *  Optimize sql table
     *
     * @var DB $DB
     *
     * @param mixed   $migration Migration class (default NULL)
     * @param boolean $cron      To know if optimize must be done (false by default)
     *
     * @return int number of tables
     */
   static function optimize_tables($migration=NULL, $cron=false) {
      global $DB;

      $crashed_tables = self::checkForCrashedTables();
      if (!empty($crashed_tables)) {
         Toolbox::logDebug("Cannot launch automatic action : crashed tables detected");
         return -1;
      }

      if (!is_null($migration) && method_exists($migration, 'displayMessage')) {
         $migration->displayTitle(__('Optimizing tables'));
         $migration->addNewMessageArea('optimize_table'); // to force new ajax zone
         $migration->displayMessage(sprintf(__('%1$s - %2$s'), __('optimize'), __('Start')));
      }
      $result = $DB->list_tables();
      $nb     = 0;

      while ($line = $DB->fetch_row($result)) {
         $table = $line[0];

         // For big database to reduce delay of migration
         if ($cron
             || (countElementsInTable($table) < 15000000)) {

            if (!is_null($migration) && method_exists($migration, 'displayMessage')) {
               $migration->displayMessage(sprintf(__('%1$s - %2$s'), __('optimize'), $table));
            }

            $query = "OPTIMIZE TABLE `".$table."`;";
            $DB->query($query);
            $nb++;
         }
      }
      $DB->free_result($result);

      if (!is_null($migration)
          && method_exists($migration, 'displayMessage') ) {
         $migration->displayMessage(sprintf(__('%1$s - %2$s'), __('optimize'), __('End')));
      }

      return $nb;
   }

   /**
    * Get information about DB connection for showSystemInformations
    *
    * @since 0.84
    *
    * @return string[] Array of label / value
    */
   public function getInfo() {
      // No translation, used in sysinfo
      $ret = array();
      $req = $this->request("SELECT @@sql_mode as mode, @@version AS vers, @@version_comment AS stype");

      if (($data = $req->next())) {
         if ($data['stype']) {
            $ret['Server Software'] = $data['stype'];
         }
         if ($data['vers']) {
            $ret['Server Version'] = $data['vers'];
         } else {
            $ret['Server Version'] = $this->dbh->server_info;
         }
         if ($data['mode']) {
            $ret['Server SQL Mode'] = $data['mode'];
         } else {
            $ret['Server SQL Mode'] = '';
         }
      }
      $ret['Parameters'] = $this->dbuser."@".$this->dbhost."/".$this->dbdefault;
      $ret['Host info']  = $this->dbh->host_info;

      return $ret;
   }

   /**
    * Is MySQL strict mode ?
    * @since 0.90
    *
    * @var DB $DB
    *
    * @param string $msg Mode
    *
    * @return boolean
    */
   static function isMySQLStrictMode(&$msg) {
      global $DB;

      $msg = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,NO_AUTO_CREATE_USER';
      $req = $DB->request("SELECT @@sql_mode as mode");
      if (($data = $req->next())) {
         return (preg_match("/STRICT_TRANS/", $data['mode'])
                 && preg_match("/NO_ZERO_/", $data['mode'])
                 && preg_match("/ONLY_FULL_GROUP_BY/", $data['mode']));
      }
      return false;
   }

   /**
    * Get a global DB lock
    *
    * @since 0.84
    *
    * @param string $name lock's name
    *
    * @return boolean
    */
   public function getLock($name) {
      $name          = addslashes($this->dbdefault.'.'.$name);
      $query         = "SELECT GET_LOCK('$name', 0)";
      $result        = $this->query($query);
      list($lock_ok) = $this->fetch_row($result);

      return $lock_ok;
   }

   /**
    * Release a global DB lock
    *
    * @since 0.84
    *
    * @param string $name lock's name
    *
    * @return boolean
    */
   public function releaseLock($name) {
      $name          = addslashes($this->dbdefault.'.'.$name);
      $query         = "SELECT RELEASE_LOCK('$name')";
      $result        = $this->query($query);
      list($lock_ok) = $this->fetch_row($result);

      return $lock_ok;
   }

   /**
   * Check for crashed MySQL Tables
   *
   * @since 0.90.2
   *
   * @var DB $DB
    *
   * @return string[] array with supposed crashed table and check message
   */
   static public function checkForCrashedTables() {
      global $DB;
      $crashed_tables = array();

      $result_tables = $DB->list_tables();

      while ($line = $DB->fetch_row($result_tables)) {
         $query  = "CHECK TABLE `".$line[0]."` FAST";
         $result  = $DB->query($query);
         if ($DB->numrows($result) > 0) {
            $row = $DB->fetch_array($result);
            if ($row['Msg_type'] != 'status' && $row['Msg_type'] != 'note') {
               $crashed_tables[] = array('table'    => $row[0],
                                         'Msg_type' => $row['Msg_type'],
                                         'Msg_text' => $row['Msg_text']);
            }
         }
      }
      return $crashed_tables;
   }
}


/**
 * Helper for simple query
 * @todo Create a separate file for this class
 */
class DBmysqlIterator implements Iterator {
   /**
    * DBmysql object
    * @var DBmysql
    */
   private $conn;
   // Current SQL query
   private $sql;
   // Current result
   private $res = false;
   // Current row
   private $row;

   /**
    * Constructor
    *
    * @param DBmysql      $dbconnexion Database Connnexion (must be a CommonDBTM object)
    * @param string|array $table       Table name (optional when $crit have FROM entry)
    * @param string|array $crit        Fields/values, ex array("id"=>1), if empty => all rows (default '')
    * @param boolean      $debug       To log the request (default false)
    *
    * @return void
    */
   function __construct ($dbconnexion, $table, $crit="", $debug=false) {
      $this->conn = $dbconnexion;
      if (is_string($table) && strpos($table, " ")) {
         //if ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
         //   trigger_error("Deprecated usage of SQL in DB/request (full query)", E_USER_DEPRECATED);
         //}
         $this->sql = $table;
      } else {
         // Modern way
         if (is_array($table) && isset($table['FROM'])) {
            // Shift the args
            $debug = $crit;
            $crit  = $table;
            $table = $crit['FROM'];
            unset($crit['FROM']);
         }

         // Check field, orderby, limit, start in criterias
         $field    = "";
         $orderby  = "";
         $limit    = 0;
         $start    = 0;
         $distinct = '';
         $where    = '';
         $count    = '';
         $join     = '';
         if (is_array($crit) && count($crit)) {
            foreach ($crit as $key => $val) {
               switch ((string)$key) {
                  case 'SELECT' :
                  case 'FIELDS' :
                     $field = $val;
                     unset($crit[$key]);
                     break;

                  case 'SELECT DISTINCT' :
                  case 'DISTINCT FIELDS' :
                     $field = $val;
                     $distinct = "DISTINCT";
                     unset($crit[$key]);
                     break;

                  case 'COUNT' :
                     $count = $val;
                     unset($crit[$key]);
                     break;

                  case 'ORDER' :
                     $orderby = $val;
                     unset($crit[$key]);
                     break;

                  case 'LIMIT' :
                     $limit = $val;
                     unset($crit[$key]);
                     break;

                  case 'START' :
                     $start = $val;
                     unset($crit[$key]);
                     break;

                  case 'WHERE' :
                     $where = $val;
                     unset($crit[$key]);
                     break;

                  case 'JOIN' :
                     if (is_array($val)) {
                        foreach ($val as $jointable => $joincrit) {
                           $join .= " LEFT JOIN " .  self::quoteName($jointable) . " ON (" . $this->analyseCrit($joincrit) . ")";
                        }
                     } else {
                        trigger_error("BAD JOIN, value sould be [ table => criteria ]", E_USER_ERROR);
                     }
                     unset($crit[$key]);
                     break;
               }
            }
         }

         // SELECT field list
         if ($count) {
            $this->sql = "SELECT COUNT(*) AS $count";
         } else if (is_array($field)) {
            $this->sql = "";
            foreach ($field as $t => $f) {
               if (is_numeric($t)) {
                  $this->sql .= (empty($this->sql) ? 'SELECT ' : ', ') . self::quoteName($f);
               } else if (is_array($f)) {
                  $t = self::quoteName($t);
                  $f = array_map([__CLASS__, 'quoteName'], $f);
                  $this->sql .= (empty($this->sql) ? "SELECT $t." : ",$t.") . implode(", $t.", $f);
               } else {
                  $t = self::quoteName($t);
                  $f = ($f == '*' ? $f : self::quoteName($f));
                  $this->sql .= (empty($this->sql) ? 'SELECT ' : ', ') . "$t.$f";
               }
            }
         } else if (empty($field)) {
            $this->sql = "SELECT *";
         } else {
            $this->sql = "SELECT $distinct " . self::quoteName($field);
         }

         // FROM table list
         if (is_array($table)) {
            if (count($table)) {
               $table = array_map([__CLASS__, 'quoteName'], $table);
               $this->sql .= ' FROM '.implode(", ", $table);
            } else {
               trigger_error("Missing table name", E_USER_ERROR);
            }
         } else if ($table) {
            $table = self::quoteName($table);
            $this->sql .= " FROM $table";
         } else {
            /*
             * TODO filter with if ($where || !empty($crit)) {
             * but not usefull for now, as we CANNOT write somthing like "SELECT NOW()"
             */
            trigger_error("Missing table name", E_USER_ERROR);
         }

         // JOIN
         $this->sql .= $join;

         // WHERE criteria list
         if (!empty($crit)) {
            $this->sql .= " WHERE ".$this->analyseCrit($crit);
         } else if ($where) {
            $this->sql .= " WHERE ".$this->analyseCrit($where);
         }

         // ORDER BY
         if (is_array($orderby)) {
            $cleanorderby = array();
            foreach ($orderby as $o) {
               $new = '';
               $tmp = explode(' ', $o);
               $new .= self::quoteName($tmp[0]);
               // ASC OR DESC added
               if (isset($tmp[1]) && in_array($tmp[1], array('ASC', 'DESC'))) {
                  $new .= ' '.$tmp[1];
               }
               $cleanorderby[] = $new;
            }

            $this->sql .= " ORDER BY ".implode(", ", $cleanorderby);
         } else if (!empty($orderby)) {
            $this->sql .= " ORDER BY ";
            $tmp = explode(' ', $orderby);
            $this->sql .= self::quoteName($tmp[0]);
            // ASC OR DESC added
            if (isset($tmp[1]) && in_array($tmp[1], array('ASC', 'DESC'))) {
               $this->sql .= ' '.$tmp[1];
            }
         }

         if (is_numeric($limit) && ($limit > 0)) {
            $this->sql .= " LIMIT $limit";
            if (is_numeric($start) && ($start > 0)) {
               $this->sql .= " OFFSET $start";
            }
         }
      }
      if ($debug) {
         Toolbox::logDebug("Generated query:", $this->getSql());
      }
      $this->res = ($this->conn ? $this->conn->query($this->sql) : false);
   }


   /**
    * Quote field name
    *
    * @since 9.1
    *
    * @param string $name of field to quote (or table.field)
    *
    * @return string
    */
   private static function quoteName($name) {
      if (strpos($name, '.')) {
         $n = explode('.', $name, 2);
         return self::quoteName($n[0]) . '.' . self::quoteName($n[1]);
      }
      return ($name[0]=='`' ? $name : "`$name`");
   }


   /**
    * Retrieve the SQL statement
    *
    * @since 9.1
    *
    * @return string
    */
   public function getSql() {
      return preg_replace('/ +/', ' ', $this->sql);
   }

   /**
    * Destructor
    *
    * @return void
    */
   function __destruct () {
      if ($this->res) {
         $this->conn->free_result($this->res);
      }
   }

   /**
    * Generate the SQL statement for a array of criteria
    *
    * @param string[] $crit Criteria
    * @param string   $bool Boolean operator (default AND)
    *
    * @return string
    */
   private function analyseCrit ($crit, $bool="AND") {
      static $operators = ['=', '<', '<=', '>', '>=', 'LIKE', 'REGEXP', 'NOT LIKE', 'NOT REGEX'];

      if (!is_array($crit)) {
         //if ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
         //  trigger_error("Deprecated usage of SQL in DB/request (criteria)", E_USER_DEPRECATED);
         //}
         return $crit;
      }
      $ret = "";
      foreach ($crit as $name => $value) {
         if (!empty($ret)) {
            $ret .= " $bool ";
         }
         if (is_numeric($name)) {
            // No Key case => recurse.
            $ret .= "(" . $this->analyseCrit($value, $bool) . ")";

         } else if (($name === "OR") || ($name === "AND")) {
            // Binary logical operator
            $ret .= "(" . $this->analyseCrit($value, $name) . ")";

         } else if ($name === "NOT") {
            // Uninary logicial operator
            $ret .= " NOT (" . $this->analyseCrit($value, "AND") . ")";

         } else if ($name === "FKEY") {
            // Foreign Key condition
            if (is_array($value) && (count($value) == 2)) {
               reset($value);
               list($t1,$f1) = each($value);
               list($t2,$f2) = each($value);
               $ret .= (is_numeric($t1) ? self::quoteName($f1) : self::quoteName($t1) . '.' . self::quoteName($f1)) . ' = ' .
                       (is_numeric($t2) ? self::quoteName($f2) : self::quoteName($t2) . '.' . self::quoteName($f2));
            } else {
               trigger_error("BAD FOREIGN KEY, should be [ key1, key2 ]", E_USER_ERROR);
            }

         } else if (is_array($value)) {
            if (count($value) == 2 && in_array($value[0], $operators)) {
               if (is_numeric($value[1]) || preg_match("/^`.*?`$/", $value[1])) {
                  $ret .= self::quoteName($name) . " {$value[0]} {$value[1]}";
               } else {
                  $ret .= self::quoteName($name) . " {$value[0]} '{$value[1]}'";
               }
            } else {
               // Array of Values
               foreach ($value as $k => $v) {
                  if (!is_numeric($v)) {
                     $value[$k] = "'$v'";
                  }
               }
               $ret .= self::quoteName($name) . ' IN (' . implode(', ', $value) . ')';
            }
         } else if (is_null($value)) {
            // NULL condition
            $ret .= self::quoteName($name) . " IS NULL";

         } else if (is_numeric($value) || preg_match("/^`.*?`$/", $value)) {
            // Integer or field name
            $ret .= self::quoteName($name) . " = $value";

         } else {
            // String
            $ret .= self::quoteName($name) . " = '$value'";
         }
      }
      return $ret;
   }

   /**
    * Reset rows parsing (go to first offset) & provide first row
    *
    * @return string[]|null fetch_assoc() of first results row
    */
   public function rewind() {
      if ($this->res && $this->conn->numrows($this->res)) {
         $this->conn->data_seek($this->res, 0);
      }
      return $this->next();
   }

   /**
    * Provide actual row
    *
    * @return mixed
    */
   public function current() {
      return $this->row;
   }

   /**
    * Get current key value
    *
    * @return mixed
    */
   public function key() {
      return (isset($this->row["id"]) ? $this->row["id"] : 0);
   }

   /**
    * Return next row of query results [FETCH_ASSOC]
    *
    * @return string[]|null fetch_assoc() of first results row
    */
   public function nextAssoc() {
      if (!$this->res) {
         return false;
      }
      $this->row = $this->conn->fetch_assoc($this->res);
      return $this->row;
   }

   /**
    * Get next result
    *
    * @deprecated
    *
    * @see nextAssoc()
    *
    * @return mixed
    */
   public function next() {
      return $this->nextAssoc();
   }

   /**
    * OOP - Next row of query result
    *
    * @return object
   */
   public function nextObject() {
      if (!$this->res) {
         return false;
      }
      $this->row = $this->conn->fetch_object($this->res);
      return $this->row;
   }

   /**
    * @todo phpdoc...
    *
    * @return boolean
    */
   public function valid() {
      return $this->res && $this->row;
   }

   /**
    * Number of rows on a result
    *
    * @return int
    */
   public function numrows() {
      return ($this->res ? $this->conn->numrows($this->res) : 0);
   }
}
