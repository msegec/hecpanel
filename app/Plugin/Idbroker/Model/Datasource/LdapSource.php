<?php
/**
 *  HE cPanel -- Hosting Engineers Control Panel
 *  Copyright (C) 2015  Dynamictivity LLC (http://www.hecpanel.com)
 *
 *   This program is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License as
 *   published by the Free Software Foundation, either version 3 of the
 *   License, or (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU Affero General Public License for more details.
 *
 *   You should have received a copy of the GNU Affero General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * LDAP Datasource
 *
 * Connect to LDAPv3 style datasource with full CRUD support.
 * Still needs HABTM support
 * Discussion at http://www.analogrithems.com/rant/2009/06/12/cakephp-with-full-crud-a-living-example/
 * Tested with OpenLDAP, Netscape Style LDAP {iPlanet, Fedora, RedhatDS} Active Directory.
 * Supports TLS, multiple ldap servers (Failover not, mirroring), Scheme Detection
 */

/**
 * Ldap Datasource
 *
 * @package datasources
 * @subpackage datasources.models.datasources
 */
class LdapSource extends DataSource {

    /**
     * Datasource description
     *
     * @public string
     * @access public
     */
    public $description = "Ldap Data Source";

    /**
     * Cache Sources
     *
     * @public boolean
     * @access public
     */
    public $cacheSources = true;

    /**
     * Schema Results
     *
     * @public boolean
     * @access public
     */
    public $SchemaResults = false;

    /**
     * Database
     *
     * @public mixed
     * @access public
     */
    public $database = false;

    /**
     * Number of Rows Returned
     *
     * @public integer
     * @access public
     */
    public $numRows = 0;

    /**
     * String to hold how many rows were affected by the last LDAP operation.
     *
     * @public string
     */
    public $affected = null;

    /**
     * Model
     *
     * @public mixed
     * @access public
     */
    public $model;

    /**
     * Operational Attributes
     *
     * @public mixed
     * @access public
     */
    public $OperationalAttributes;

    /**
     * Schema DN
     *
     * @public string
     * @access public
     */
    public $SchemaDN;

    /**
     * Schema Attributes
     *
     * @public string
     * @access public
     */
    public $SchemaAtributes;

    /**
     * Schema Filter
     *
     * @public string
     * @access public
     */
    public $SchemaFilter = '(objectClass=subschema)';

    /**
     * Result for formal queries
     *
     * @public mixed
     * @access protected
     */
    public $_result = false;

    /**
     * Base configuration
     *
     * @public array
     * @access protected
     */
    public $_baseConfig = array(
        'host' => 'localhost',
        'port' => 389,
        'version' => 3
    );

    /**
     * MultiMaster Use
     *
     * @public integer
     * @access protected
     */
    public $_multiMasterUse = 0;

    /**
     * Query Time
     *
     * @public integer
     * @access protected
     */
    public $_queriesTime = 0;

    /**
     * Query cnt
     *
     * @public integer
     * @access public
     */
    public $_queriesCnt = 0;

    /**
     * Query Logging
     *
     * @public array
     * @access public
     */
    public $_queriesLog = array();

    /**
     * Query Log Max
     *
     * @public array
     * @access public
     */
    public $_queriesLogMax = 200;

    /**
     * Descriptions
     *
     * @public array
     * @access private
     */
    public $__descriptions = array();

    /**
     * Constructor
     *
     * @param array $config Configuration 
     * @access public
     */
    public function __construct($config = null) {
        $this->debug = Configure::read('debug') > 0;
        $this->fullDebug = Configure::read('debug') > 1;
        parent::__construct($config);
        $link = $this->connect();

        // People Have been asking for this forever.
        if (isset($config['type']) && !empty($config['type'])) {
            switch ($config['type']) {
                case 'Netscape':
                    $this->setNetscapeEnv();
                    break;
                case 'OpenLDAP':
                    $this->setOpenLDAPEnv();
                    break;
                case 'ActiveDirectory':
                    $this->setActiveDirectoryEnv();
                    break;
                default:
                    $this->setNetscapeEnv();
                    break;
            }
        }

        $this->setSchemaPath();
        return $link;
    }

    /**
     * Destructor
     *
     * Closes connection to the server
     *
     * @return void
     * @access public
     */
    public function __destruct() {
        $this->close();
        parent::__destruct();
    }

    /**
     * Field name
     *
     * This looks weird, but for LDAP we just return the name of the field thats passed as an argument.
     *
     * @param string $field Field name
     * @return string Field name
     * @author Graham Weldon
     */
    public function name($field) {
        return $field;
    }

    /**
     * connect([$bindDN], [$passwd])  create the actual connection to the ldap server
     * This public function supports failover, so if your config['host'] is an array it will try the first one, if it fails,
     * jumps to the next and attempts to connect and so on.  If will also check try to setup any special connection options
     * needed like referal chasing and tls support
     *
     * @param string the users dn to bind with
     * @param string the password for the previously state bindDN
     * @return boolean the status of the connection
     */
    public function connect($bindDN = null, $passwd = null) {
        $config = am($this->_baseConfig, $this->config);
        $this->connected = false;
        $hasFailover = false;
        if (isset($config['host']) && is_array($config['host'])) {
            $config['host'] = $config['host'][$this->_multiMasterUse];
            if (count($this->config['host']) > (1 + $this->_multiMasterUse)) {
                $hasFailOver = true;
            }
        }
        $bindDN = (empty($bindDN)) ? $config['login'] : $bindDN;
        $bindPasswd = (empty($passwd)) ? $config['password'] : $passwd;
        if (!function_exists('ldap_connect')) {
            $this->log("LDAP not configured on this server.", 'ldap.error');
            die("LDAP not configured on this server. The PHP-LDAP extension is probably missing!");
        }
        $this->database = @ldap_connect($config['host']);
        if (!$this->database) {
            //Try Next Server Listed
            if ($hasFailover) {
                $this->log('Trying Next LDAP Server in list:' . $this->config['host'][$this->_multiMasterUse], 'ldap.error');
                $this->_multiMasterUse++;
                $this->connect($bindDN, $passwd);
                if ($this->connected) {
                    return $this->connected;
                }
            }
        }

        //Set our protocol version usually version 3
        ldap_set_option($this->database, LDAP_OPT_PROTOCOL_VERSION, $config['version']);

        if ($config['tls']) {
            if (!ldap_start_tls($this->database)) {
                $this->log("Ldap_start_tls failed", 'ldap.error');
                fatal_error("Ldap_start_tls failed");
            }
        }
        //So little known fact, if your php-ldap lib is built against openldap like pretty much every linux
        //distro out their like redhat, suse etc. The connect doesn't acutally happen when you call ldap_connect
        //it happens when you call ldap_bind.  So if you are using failover then you have to test here also.
        $bind_result = @ldap_bind($this->database, $bindDN, $bindPasswd);
        if (!$bind_result) {
            if (ldap_errno($this->database) == 49) {
                $this->log("Auth failed for '$bindDN'!", 'ldap.error');
            } else {
                $this->log('Trying Next LDAP Server in list:' . $this->config['host'][$this->_multiMasterUse], 'ldap.error');
                $this->_multiMasterUse++;
                $this->connect($bindDN, $passwd);
                if ($this->connected) {
                    return $this->connected;
                }
            }
        } else {
            $this->connected = true;
        }
        return $this->connected;
    }

    /**
     * auth($dn, $passwd)
     * Test if the dn/passwd combo is valid
     * This may actually belong in the component code, will look into that
     *
     * @param string bindDN to connect as
     * @param string password for the bindDN
     * @param boolean or string on error
     */
    public function auth($dn, $passwd) {
        $this->connect($dn, $passwd);
        if ($this->connected) {
            return true;
        } else {
            $this->log("Auth Error: for '$dn': " . $this->lastError(), 'ldap.error');
            return $this->lastError();
        }
    }

    /**
     * Disconnects database, kills the connection and says the connection is closed,
     * and if DEBUG is turned on, the log for this object is shown.
     *
     */
    public function close() {
        if ($this->fullDebug) {
            $this->showLog();
        }
        $this->disconnect();
    }

    /**
     * disconnect  close connection and release any remaining results in the buffer
     *
     */
    public function disconnect() {
        @ldap_free_result($this->results);
        @ldap_unbind($this->database);
        $this->connected = false;
        return $this->connected;
    }

    /**
     * Checks if it's connected to the database
     *
     * @return boolean True if the database is connected, else false
     */
    public function isConnected() {
        return $this->connected;
    }

    /**
     * Reconnects to database server with optional new settings
     *
     * @param array $config An array defining the new configuration settings
     * @return boolean True on success, false on failure
     */
    public function reconnect($config = null) {
        $this->disconnect();
        if ($config != null) {
            $this->config = array_merge($this->_baseConfig, $this->config, $config);
        }
        return $this->connect();
    }

    /**
     * Check whether the LDAP extension is installed/loaded
     *
     * @return boolean
     */
    public function enabled() {
        return function_exists('ldap_connect');
    }

    /**
     * The "C" in CRUD
     *
     * @param Model $model
     * @param array $fields containing the field names
     * @param array $values containing the fields' values
     * @return true on success, false on error
     */
    public function create(Model $Model, $fields = NULL, $values = NULL) {
        $basedn = $this->config['basedn'];
        $key = $Model->primaryKey;
        $table = $Model->useTable;
        $fieldsData = array();
        $id = null;
        $objectclasses = null;

        if ($fields == null) {
            unset($fields, $values);
            $fields = array_keys($Model->data);
            $values = array_values($Model->data);
        }

        $count = count($fields);

        for ($i = 0; $i < $count; $i++) {
            if ($fields[$i] == $key) {
                $id = $values[$i];
            } elseif ($fields[$i] == 'cn') {
                $cn = $values[$i];
            }
            $fieldsData[$fields[$i]] = $values[$i];
        }

        //Lets make our DN, this is made from the useTable & basedn + primary key. Logically this corelate to LDAP

        if (isset($table) && preg_match('/=/', $table)) {
            $table = $table . ', ';
        } else {
            $table = '';
        }
        if (isset($key) && !empty($key)) {
            $key = "$key=$id, ";
        } else {
            //Almost everything has a cn, this is a good fall back.
            $key = "cn=$cn, ";
        }
        $dn = $key . $table . $basedn;

        $res = @ ldap_add($this->database, $dn, $fieldsData);
        // Add the entry
        if ($res) {
            $Model->setInsertID($id);
            $Model->id = $id;
            return true;
        } else {
            $this->log("Failed to add ldap entry: dn:$dn\nData:" . print_r($fieldsData, true) . "\n" . ldap_error($this->database), 'ldap.error');
            $Model->onError();
            return false;
        }
    }

    /**
     * Returns the query
     *
     */
    public function query($find, $query = null, $model) {
        if (isset($query[0]) && is_array($query[0])) {
            $query = $query[0];
        }

        if (isset($find)) {
            switch ($find) {
                case 'auth':
                    return $this->auth($query['dn'], $query['password']);
                case 'findSchema':
                    $query = $this->__getLDAPschema();
                    //$this->findSchema($query);
                    break;
                case 'findConfig':
                    return $this->config;
                    break;
                default:
                    $query = $this->read($model, $query);
                    break;
            }
        }
        return $query;
    }

    /**
     * The "R" in CRUD
     *
     * @param Model $model
     * @param array $queryData
     * @param integer $recursive Number of levels of association
     * @return unknown
     */
    public function read(Model $Model, $queryData = array(), $recursive = NULL) {
        $this->model = $Model;
        $this->__scrubQueryData($queryData);
        if (!is_null($recursive)) {
            $_recursive = $Model->recursive;
            $Model->recursive = $recursive;
        }

        // Check if we are doing a 'count' .. this is kinda ugly but i couldn't find a better way to do this, yet
        if (is_string($queryData['fields']) && $queryData['fields'] == 'COUNT(*) AS ' . $this->name('count')) {
            $queryData['fields'] = array();
        }

        // Prepare query data ------------------------ 
        $queryData['conditions'] = $this->_conditions($queryData['conditions']);
        if (empty($queryData['targetDn'])) {
            $queryData['targetDn'] = $Model->useTable;
        }
        $queryData['type'] = 'search';

        if (empty($queryData['order']))
            $queryData['order'] = array($Model->primaryKey);

        // Associations links --------------------------
        if (isset($Model->__associations)) {
            foreach ($Model->__associations as $type) {
                foreach ($Model->{$type} as $assoc => $assocData) {
                    if ($Model->recursive > -1) {
                        $linkModel = & $Model->{$assoc};
                        $linkedModels[] = $type . '/' . $assoc;
                    }
                }
            }
        }

        // Execute search query ------------------------
        $res = $this->_executeQuery($queryData);

        if ($this->lastNumRows() == 0)
            return false;

        // Format results  -----------------------------
        ldap_sort($this->database, $res, $queryData['order'][0]);
        $resultSet = ldap_get_entries($this->database, $res);
        $resultSet = $this->_ldapFormat($Model, $resultSet);

        // Query on linked models  ----------------------
        if (($Model->recursive > 0) && isset($Model->__associations)) {
            foreach ($Model->__associations as $type) {
                foreach ($Model->{$type} as $assoc => $assocData) {
                    $db = null;
                    $linkModel = & $Model->{$assoc};

                    if ($Model->useDbConfig == $linkModel->useDbConfig) {
                        $db = & $this;
                    } else {
                        $db = & ConnectionManager::getDataSource($linkModel->useDbConfig);
                    }

                    if (isset($db) && $db != null) {
                        $stack = array($assoc);
                        $array = array();
                        $db->queryAssociation($Model, $linkModel, $type, $assoc, $assocData, $array, true, $resultSet, $Model->recursive - 1, $stack);
                        unset($db);
                    }
                }
            }
        }

        if (!is_null($recursive)) {
            $Model->recursive = $_recursive;
        }

        // Add the count field to the resultSet (needed by find() to work out how many entries we got back .. used when $Model->exists() is called)
        $resultSet[0][0]['count'] = $this->lastNumRows();
        return $resultSet;
    }

    /**
     * The "U" in CRUD
     */
    public function update(Model $Model, $fields = NULL, $values = NULL, $conditions = NULL) {
        $fieldsData = array();

        if ($fields == null) {
            unset($fields, $values);
            $fields = array_keys($Model->data);
            $values = array_values($Model->data);
        }

        for ($i = 0; $i < count($fields); $i++) {
            $fieldsData[$fields[$i]] = $values[$i];
        }

        //set our scope
        $queryData['scope'] = 'base';
        if ($Model->primaryKey == 'dn') {
            $queryData['targetDn'] = $Model->id;
        } elseif (isset($Model->useTable) && !empty($Model->useTable)) {
            $queryData['targetDn'] = $Model->primaryKey . '=' . $Model->id . ', ' . $Model->useTable;
        }

        // fetch the record
        // Find the user we will update as we need their dn
        $resultSet = $this->read($Model, $queryData, $Model->recursive);

        //now we need to find out what's different about the old entry and the new one and only changes those parts
        $current = $resultSet[0][$Model->alias];
        $update = $Model->data[$Model->alias];

        foreach ($update as $attr => $value) {
            if (isset($update[$attr]) && !empty($update[$attr])) {
                $entry[$attr] = $update[$attr];
            } elseif (!empty($current[$attr]) && (isset($update[$attr]) && empty($update[$attr]))) {
                $entry[$attr] = array();
            }
        }

        //if this isn't a password reset, then remove the password field to avoid constraint violations...
        if (!$this->in_arrayi('userpassword', $update)) {
            unset($entry['userpassword']);
        }
        unset($entry['count']);
        unset($entry['dn']);

        if ($resultSet) {
            $_dn = $resultSet[0][$Model->alias]['dn'];

            if (@ldap_modify($this->database, $_dn, $entry)) {
                return true;
            } else {
                $this->log("Error updating $_dn: " . ldap_error($this->database) . "\nHere is what I sent: " . print_r($entry, true), 'ldap.error');
                return false;
            }
        }

        // If we get this far, something went horribly wrong ..
        $Model->onError();
        return false;
    }

    /**
     * The "D" in CRUD
     */
    public function delete(Model $Model, $conditions = NULL) {
        // Boolean to determine if we want to recursively delete or not
        //$recursive = true;
        $recursive = false;

        if (preg_match('/dn/i', $Model->primaryKey)) {
            $dn = $Model->id;
        } else {
            // Find the user we will update as we need their dn
            if ($Model->defaultObjectClass) {
                $options['conditions'] = sprintf('(&(objectclass=%s)(%s=%s))', $Model->defaultObjectClass, $Model->primaryKey, $Model->id);
            } else {
                $options['conditions'] = sprintf('%s=%s', $Model->primaryKey, $Model->id);
            }
            $options['targetDn'] = $Model->useTable;
            $options['scope'] = 'sub';

            $entry = $this->read($Model, $options, $Model->recursive);
            $dn = $entry[0][$Model->name]['dn'];
        }

        if ($dn) {
            if ($recursive === true) {
                // Recursively delete LDAP entries
                if ($this->__deleteRecursively($dn)) {
                    return true;
                }
            } else {
                // Single entry delete
                if (@ldap_delete($this->database, $dn)) {
                    return true;
                }
            }
        }

        $Model->onError();
        $errMsg = ldap_error($this->database);
        $this->log("Failed Trying to delete: $dn \nLdap Erro:$errMsg", 'ldap.error');
        return false;
    }

    /* Courtesy of gabriel at hrz dot uni-marburg dot de @ http://ar.php.net/ldap_delete */

    public function __deleteRecursively($_dn) {
        // Search for sub entries
        $subentries = ldap_list($this->database, $_dn, "objectClass=*", array());
        $info = ldap_get_entries($this->database, $subentries);
        for ($i = 0; $i < $info['count']; $i++) {
            // deleting recursively sub entries
            $result = $this->__deleteRecursively($info[$i]['dn']);
            if (!$result) {
                return false;
            }
        }

        return( @ldap_delete($this->database, $_dn) );
    }

    //Here are the functions that try to do model associations
    public function generateAssociationQuery(& $model, & $linkModel, $type, $association = null, $assocData = array(), & $queryData, $external = false, & $resultSet) {
        $this->__scrubQueryData($queryData);

        switch ($type) {
            case 'hasOne' :
                $id = $resultSet[$model->name][$model->primaryKey];
                $queryData['conditions'] = trim($assocData['foreignKey']) . '=' . trim($id);
                $queryData['targetDn'] = $linkModel->useTable;
                $queryData['type'] = 'search';
                $queryData['limit'] = 1;
                return $queryData;

            case 'belongsTo' :
                $id = $resultSet[$model->name][$assocData['foreignKey']];
                $queryData['conditions'] = trim($linkModel->primaryKey) . '=' . trim($id);
                $queryData['targetDn'] = $linkModel->useTable;
                $queryData['type'] = 'search';
                $queryData['limit'] = 1;

                return $queryData;

            case 'hasMany' :
                $id = $resultSet[$model->name][$model->primaryKey];
                $queryData['conditions'] = trim($assocData['foreignKey']) . '=' . trim($id);
                $queryData['targetDn'] = $linkModel->useTable;
                $queryData['type'] = 'search';
                $queryData['limit'] = $assocData['limit'];

                return $queryData;

            case 'hasAndBelongsToMany' :
                return null;
        }
        return null;
    }

    public function queryAssociation(& $model, & $linkModel, $type, $association, $assocData, & $queryData, $external = false, & $resultSet, $recursive, $stack) {

        if (!isset($resultSet) || !is_array($resultSet)) {
            if (Configure::read('debug') > 0) {
                echo '<div style = "font: Verdana bold 12px; color: #FF0000">SQL Error in model ' . $model->name . ': ';
                if (isset($this->error) && $this->error != null) {
                    echo $this->error;
                }
                echo '</div>';
            }
            return null;
        }

        $count = count($resultSet);
        for ($i = 0; $i < $count; $i++) {

            $row = & $resultSet[$i];
            $queryData = $this->generateAssociationQuery($model, $linkModel, $type, $association, $assocData, $queryData, $external, $row);
            $fetch = $this->_executeQuery($queryData);
            $fetch = ldap_get_entries($this->database, $fetch);
            $fetch = $this->_ldapFormat($linkModel, $fetch);

            if (!empty($fetch) && is_array($fetch)) {
                if ($recursive > 0) {
                    foreach ($linkModel->__associations as $type1) {
                        foreach ($linkModel->{$type1 } as $assoc1 => $assocData1) {
                            $deepModel = & $linkModel->{$assocData1['className']};
                            if ($deepModel->alias != $model->name) {
                                $tmpStack = $stack;
                                $tmpStack[] = $assoc1;
                                if ($linkModel->useDbConfig == $deepModel->useDbConfig) {
                                    $db = & $this;
                                } else {
                                    $db = & ConnectionManager::getDataSource($deepModel->useDbConfig);
                                }
                                $queryData = array();
                                $db->queryAssociation($linkModel, $deepModel, $type1, $assoc1, $assocData1, $queryData, true, $fetch, $recursive - 1, $tmpStack);
                            }
                        }
                    }
                }
                $this->__mergeAssociation($resultSet[$i], $fetch, $association, $type);
            } else {
                $tempArray[0][$association] = false;
                $this->__mergeAssociation($resultSet[$i], $tempArray, $association, $type);
            }
        }
    }

    /**
     * Returns a formatted error message from previous database operation.
     *
     * @return string Error message with error number
     */
    public function lastError() {
        if (ldap_errno($this->database)) {
            return ldap_errno($this->database) . ': ' . ldap_error($this->database);
        }
        return null;
    }

    /**
     * Returns number of rows in previous resultset. If no previous resultset exists,
     * this returns false.
     *
     * @return int Number of rows in resultset
     */
    public function lastNumRows($source = NULL) {
        if ($this->_result and is_resource($this->_result)) {
            return @ ldap_count_entries($this->database, $this->_result);
        }
        return null;
    }

    // Usefull public (static) functions--------------------------------------------	
    /**
     * Convert Active Directory timestamps to unix ones
     * 
     * @param integer $ad_timestamp Active directory timestamp
     * @return integer Unix timestamp
     */
    public function convertTimestamp_ADToUnix($ad_timestamp) {
        $epoch_diff = 11644473600; // difference 1601<>1970 in seconds. see reference URL
        $date_timestamp = $ad_timestamp * 0.0000001;
        $unix_timestamp = $date_timestamp - $epoch_diff;
        return $unix_timestamp;
    }

// convertTimestamp_ADToUnix

    /* The following was kindly "borrowed" from the excellent phpldapadmin project */

    public function __getLDAPschema() {
        $schemaTypes = array('objectclasses', 'attributetypes');
        $this->results = @ldap_read($this->database, $this->SchemaDN, $this->SchemaFilter, $schemaTypes, 0, 0, 0, LDAP_DEREF_ALWAYS);
        if (is_null($this->results)) {
            $this->log("LDAP schema filter $schema_filter is invalid!", 'ldap.error');
            return;
        }

        $schema_entries = @ldap_get_entries($this->database, $this->results);

        $return = array();
        if ($schema_entries) {
            $return = array();
            foreach ($schemaTypes as $n) {
                $schemaTypeEntries = $schema_entries[0][$n];
                for ($x = 0; $x < $schemaTypeEntries['count']; $x++) {
                    $entry = array();
                    $strings = preg_split('/[\s,]+/', $schemaTypeEntries[$x], -1, PREG_SPLIT_DELIM_CAPTURE);
                    $str_count = count($strings);
                    for ($i = 0; $i < $str_count; $i++) {
                        switch ($strings[$i]) {
                            case '(':
                                break;
                            case 'NAME':
                                if ($strings[$i + 1] != '(') {
                                    do {
                                        $i++;
                                        if (!isset($entry['name']) || strlen($entry['name']) == 0)
                                            $entry['name'] = $strings[$i];
                                        else
                                            $entry['name'] .= ' ' . $strings[$i];
                                    } while (!preg_match('/\'$/s', $strings[$i]));
                                } else {
                                    $i++;
                                    do {
                                        $i++;
                                        if (!isset($entry['name']) || strlen($entry['name']) == 0)
                                            $entry['name'] = $strings[$i];
                                        else
                                            $entry['name'] .= ' ' . $strings[$i];
                                    } while (!preg_match('/\'$/s', $strings[$i]));
                                    do {
                                        $i++;
                                    } while (!preg_match('/\)+\)?/', $strings[$i]));
                                }

                                $entry['name'] = preg_replace('/^\'/', '', $entry['name']);
                                $entry['name'] = preg_replace('/\'$/', '', $entry['name']);
                                break;
                            case 'DESC':
                                do {
                                    $i++;
                                    if (!isset($entry['description']) || strlen($entry['description']) == 0)
                                        $entry['description'] = $strings[$i];
                                    else
                                        $entry['description'] .= ' ' . $strings[$i];
                                } while (!preg_match('/\'$/s', $strings[$i]));
                                break;
                            case 'OBSOLETE':
                                $entry['is_obsolete'] = TRUE;
                                break;
                            case 'SUP':
                                $entry['sup_classes'] = array();
                                if ($strings[$i + 1] != '(') {
                                    $i++;
                                    array_push($entry['sup_classes'], preg_replace("/'/", '', $strings[$i]));
                                } else {
                                    $i++;
                                    do {
                                        $i++;
                                        if ($strings[$i] != '$')
                                            array_push($entry['sup_classes'], preg_replace("/'/", '', $strings[$i]));
                                    } while (!preg_match('/\)+\)?/', $strings[$i + 1]));
                                }
                                break;
                            case 'ABSTRACT':
                                $entry['type'] = 'abstract';
                                break;
                            case 'STRUCTURAL':
                                $entry['type'] = 'structural';
                                break;
                            case 'SINGLE-VALUE':
                                $entry['multiValue'] = 'false';
                                break;
                            case 'AUXILIARY':
                                $entry['type'] = 'auxiliary';
                                break;
                            case 'MUST':
                                $entry['must'] = array();
                                $i = $this->_parse_list(++$i, $strings, $entry['must']);

                                break;

                            case 'MAY':
                                $entry['may'] = array();
                                $i = $this->_parse_list(++$i, $strings, $entry['may']);

                                break;
                            default:
                                if (preg_match('/[\d\.]+/i', $strings[$i]) && $i == 1) {
                                    $entry['oid'] = $strings[$i];
                                }
                                break;
                        }
                    }
                    if (!isset($return[$n]) || !is_array($return[$n])) {
                        $return[$n] = array();
                    }
                    //make lowercase for consistency
                    $return[strtolower($n)][strtolower($entry['name'])] = $entry;
                    //array_push( $return[$n][$entry['name']], $entry );
                }
            }
        }

        return $return;
    }

    public function _parse_list($i, $strings, &$attrs) {
        /**
         * * A list starts with a ( followed by a list of attributes separated by $ terminated by )
         * * The first token can therefore be a ( or a (NAME or a (NAME)
         * * The last token can therefore be a ) or NAME)
         * * The last token may be terminate by more than one bracket
         */
        $string = $strings[$i];
        if (!preg_match('/^\(/', $string)) {
            // A bareword only - can be terminated by a ) if the last item
            if (preg_match('/\)+$/', $string))
                $string = preg_replace('/\)+$/', '', $string);

            array_push($attrs, $string);
        } elseif (preg_match('/^\(.*\)$/', $string)) {
            $string = preg_replace('/^\(/', '', $string);
            $string = preg_replace('/\)+$/', '', $string);
            array_push($attrs, $string);
        } else {
            // Handle the opening cases first
            if ($string == '(') {
                $i++;
            } elseif (preg_match('/^\(./', $string)) {
                $string = preg_replace('/^\(/', '', $string);
                array_push($attrs, $string);
                $i++;
            }

            // Token is either a name, a $ or a ')'
            // NAME can be terminated by one or more ')'
            while (!preg_match('/\)+$/', $strings[$i])) {
                $string = $strings[$i];
                if ($string == '$') {
                    $i++;
                    continue;
                }

                if (preg_match('/\)$/', $string)) {
                    $string = preg_replace('/\)+$/', '', $string);
                } else {
                    $i++;
                }
                array_push($attrs, $string);
            }
        }
        sort($attrs);

        return $i;
    }

    /**
     * Function to actually query LDAP
     */
    public function execute($query, $options = array(), $params = array()) {
        $options += array('log' => $this->fullDebug);

        $t = microtime(true);
        $this->_result = $this->_executeQuery($query, $params);

        if ($options['log']) {
            $this->took = round((microtime(true) - $t) * 1000, 0);
            $this->numRows = $this->affected = $this->lastAffected();
            $this->logQuery($query);
        }

        return $this->_result;
    }

    /**
     * Function not supported
     */
    public function fetchAll($query, $cache = true) {
        return array();
    }

    /**
     * Log given LDAP query.
     *
     * @param string $query LDAP statement
     * @todo: Add hook to log errors instead of returning false
     */
    public function logQuery($query) {
        $this->_queriesCnt++;
        $this->_queriesTime += $this->took;
        $this->_queriesLog[] = array(
            'query' => $query,
            'affected' => $this->affected,
            'numRows' => $this->numRows,
            'took' => $this->took
        );
        if (count($this->_queriesLog) > $this->_queriesLogMax) {
            array_pop($this->_queriesLog);
        }
    }

    /**
     * Get the query log as an array.
     *
     * @param boolean $sorted Get the queries sorted by time taken, defaults to false.
     * @param boolean $clear If True the existing log will cleared.
     * @return array Array of queries run as an array
     */
    public function getLog($sorted = false, $clear = true) {
        if ($sorted) {
            $log = sortByKey($this->_queriesLog, 'took', 'desc', SORT_NUMERIC);
        } else {
            $log = $this->_queriesLog;
        }
        if ($clear) {
            $this->_queriesLog = array();
        }
        return array('log' => $log, 'count' => $this->_queriesCnt, 'time' => $this->_queriesTime);
    }

    /**
     * Outputs the contents of the queries log. If in a non-CLI environment the sql_log element
     * will be rendered and output.  If in a CLI environment, a plain text log is generated.
     *
     * @param boolean $sorted Get the queries sorted by time taken, defaults to false.
     * @return void
     */
    public function showLog($sorted = false) {
        $log = $this->getLog($sorted, false);
        if (empty($log['log'])) {
            return;
        }

        if (PHP_SAPI != 'cli') {
            $controller = null;
            $View = new View($controller, false);
            $View->set('logs', array($this->configKeyName => $log));
            echo $View->element('ldap_dump', array('_forced_from_ldap_' => true));
        } else {
            foreach ($log['log'] as $k => $i) {
                print (($k + 1) . ". {$i['query']}\n");
            }
        }
    }

    /**
     * Output information about a LDAP query. The query, number of rows in resultset,
     * and execution time in microseconds. If the query fails, an error is output instead.
     *
     * @param string $query Query to show information on.
     */
    public function showQuery($query) {
        $error = $this->error;
        if (strlen($query) > 200 && !$this->fullDebug) {
            $query = substr($query, 0, 200) . '[...]';
        }

        if ($this->debug || $error) {
            print ("<p style = \"text-align:left\"><b>Query:</b> {$query} <small>[ Num:{$this->numRows} Took:{$this->took}ms]</small>");
            if ($error) {
                print ("<br /><span style = \"color:Red;text-align:left\"><b>ERROR:</b> {$this->error}</span>");
            }
            print ('</p>');
        }
    }

    public function _conditions($conditions) {
        $str = '';
        if (empty($conditions)) {
            return '(objectClass=*)';
        }

        if (is_array($conditions)) {
            $str = '';
            //Lets parse the types of operands that cakephp wil use and even add a few LDAP specific ones
            //fuzy & approximate  are unique to ldap
            $operands = array('and', 'or', 'not', 'fuzy', 'approximate');
            foreach ($conditions as $key => $value) {
                $fuzy = false;
                //TODO  add in code to strip models if people try to use model notation like 'User.cn'=>'foobar' etc.
                if (is_array($value) && in_array(strtolower($key), $operands)) {
                    switch (strtolower($key)) {
                        case 'and':
                            $str .= '(&';
                            break;
                        case 'or':
                            $str .= '(|';
                            break;
                        case 'not':
                            $str .= '(!';
                            break;
                        case 'fuzy':
                        case 'approximate':
                            $fuzy = true;
                        default:
                            $str .= '(';
                            break;
                    }
                    foreach ($value as $attr => $assignment) {
                        if (is_array($assignment))
                            $str .= $this->_conditions(array($attr => $assignment));
                        else if ($fuzy === true) {
                            $str .= '(' . $attr . '~=' . $assignment . ')';
                            $fuzy = false;
                        } else {
                            $str .= '(' . $attr . '=' . $assignment . ')';
                            $fuzy = false;
                        }
                    }
                    $str .= ')';
                } elseif (is_string($key) && is_string($value)) {
                    if (preg_match('/ like/i', $key) > 0) { //Here we support the Like caluse
                        $key = preg_replace('/ like/i', '', $key);
                        $value = preg_replace('/\%/', '*', $value);
                        $str .= '(' . $key . '=' . $value . ')';
                    } else if (preg_match('/ <=/', $key)) { //Less than or equal
                        $key = preg_replace('/ <=/i', '', $key);
                        $str .= '(' . $key . '<=' . $value . ')';
                    } else if (preg_match('/ >=/', $key)) { //Greator than or equal
                        $key = preg_replace('/ >=/i', '', $key);
                        $str .= '(' . $key . '>=' . $value . ')';
                    } else {// generic match
                        $str .= '(' . $key . '=' . $value . ')';
                    }
                }
            }
        } elseif (is_string($conditions)) {
            $str = $conditions;
        }
        return $str;
    }

    public function checkBaseDn($targetDN) {
        $parts = preg_split('/,\s*/', $this->config['basedn']);
        $pattern = '/' . implode(',\s*', $parts) . '/i';
        return(preg_match($pattern, $targetDN));
    }

    public function _executeQuery($queryData = array(), $cache = true) {
        $options = array('log' => $this->fullDebug);
        $t = microtime(true);

        $pattern = '/,[ \t]+(\w+)=/';
        $queryData['targetDn'] = preg_replace($pattern, ',$1=', $queryData['targetDn']);
        if ($this->checkBaseDn($queryData['targetDn']) == 0) {

            if ($queryData['targetDn'] != null) {
                $seperator = (substr($queryData['targetDn'], -1) == ',') ? '' : ',';
                if ((strpos($queryData['targetDn'], '=') === false) && (isset($this->model) && !empty($this->model))) {
                    //Fix TargetDN here 
                    $key = $this->model->primaryKey;
                    $table = $this->model->useTable;
                    $queryData['targetDn'] = $key . '=' . $queryData['targetDn'] . ', ' . $table . $seperator . $this->config['basedn'];
                } else {
                    $queryData['targetDn'] = $queryData['targetDn'] . $seperator . $this->config['basedn'];
                }
            } else {
                $queryData['targetDn'] = $this->config['basedn'];
            }
        }

        $query = $this->_queryToString($queryData);
        if ($cache && isset($this->_queryCache[$query])) {
            if (strpos(trim(strtolower($query)), $queryData['type']) !== false) {
                $res = $this->_queryCache[$query];
            }
        } else {

            switch ($queryData['type']) {
                case 'search':
                    // TODO pb ldap_search & $queryData['limit']
                    if (empty($queryData['fields'])) {
                        $queryData['fields'] = $this->defaultNSAttributes();
                    }

                    //Handle LDAP Scope
                    if (isset($queryData['scope']) && $queryData['scope'] == 'base') {
                        $res = @ ldap_read($this->database, $queryData['targetDn'], $queryData['conditions'], $queryData['fields']);
                    } elseif (isset($queryData['scope']) && $queryData['scope'] == 'one') {
                        $res = @ ldap_list($this->database, $queryData['targetDn'], $queryData['conditions'], $queryData['fields']);
                    } else {
                        if ($queryData['fields'] == 1)
                            $queryData['fields'] = array();
                        $res = @ ldap_search($this->database, $queryData['targetDn'], $queryData['conditions'], $queryData['fields'], 0, $queryData['limit']);
                    }

                    if (!$res) {
                        $res = false;
                        $errMsg = ldap_error($this->database);
                        $this->log("Query Params Failed:" . print_r($queryData, true) . ' Error: ' . $errMsg, 'ldap.error');
                    }

                    if ($cache) {
                        if (strpos(trim(strtolower($query)), $queryData['type']) !== false) {
                            $this->_queryCache[$query] = $res;
                        }
                    }
                    break;
                case 'delete':
                    $res = @ ldap_delete($this->database, $queryData['targetDn'] . ',' . $this->config['basedn']);
                    break;
                default:
                    $res = false;
                    break;
            }
        }

        $this->_result = $res;

        if ($options['log']) {
            $this->took = round((microtime(true) - $t) * 1000, 0);
            $this->error = $this->lastError();
            $this->numRows = $this->lastNumRows();
            $this->logQuery($query);
        }

        return $this->_result;
    }

    public function _queryToString($queryData) {
        $tmp = '';
        if (!empty($queryData['scope']))
            $tmp .= ' | scope: ' . $queryData['scope'] . ' ';

        if (!empty($queryData['conditions']))
            $tmp .= ' | cond: ' . $queryData['conditions'] . ' ';

        if (!empty($queryData['targetDn']))
            $tmp .= ' | targetDn: ' . $queryData['targetDn'] . ' ';

        $fields = '';
        if (!empty($queryData['fields']) && is_array($queryData['fields'])) {
            $fields = implode(', ', $queryData['fields']);
            $tmp .= ' |fields: ' . $fields . ' ';
        }

        if (!empty($queryData['order']))
            $tmp .= ' | order: ' . $queryData['order'][0] . ' ';

        if (!empty($queryData['limit']))
            $tmp .= ' | limit: ' . $queryData['limit'];

        return $queryData['type'] . $tmp;
    }

    public function _ldapFormat(& $model, $data) {
        $res = array();

        foreach ($data as $key => $row) {
            if ($key === 'count')
                continue;

            foreach ($row as $key1 => $param) {
                if ($key1 === 'dn') {
                    $res[$key][$model->name][$key1] = $param;
                    continue;
                }
                if (!is_numeric($key1))
                    continue;
                if ($row[$param]['count'] === 1)
                    $res[$key][$model->name][$param] = $row[$param][0];
                else {
                    foreach ($row[$param] as $key2 => $item) {
                        if ($key2 === 'count')
                            continue;
                        $res[$key][$model->name][$param][] = $item;
                    }
                }
            }
        }
        return $res;
    }

    public function _ldapQuote($str) {
        return str_replace(
                array('\\', ' ', '*', '(', ')'), array('\\5c', '\\20', '\\2a', '\\28', '\\29'), $str
        );
    }

    // __ -----------------------------------------------------
    public function __mergeAssociation(& $data, $merge, $association, $type) {

        if (isset($merge[0]) && !isset($merge[0][$association])) {
            $association = Inflector::pluralize($association);
        }

        if ($type == 'belongsTo' || $type == 'hasOne') {
            if (isset($merge[$association])) {
                $data[$association] = $merge[$association][0];
            } else {
                if (count($merge[0][$association]) > 1) {
                    foreach ($merge[0] as $assoc => $data2) {
                        if ($assoc != $association) {
                            $merge[0][$association][$assoc] = $data2;
                        }
                    }
                }
                if (!isset($data[$association])) {
                    $data[$association] = $merge[0][$association];
                } else {
                    if (is_array($merge[0][$association])) {
                        $data[$association] = array_merge($merge[0][$association], $data[$association]);
                    }
                }
            }
        } else {
            if ($merge[0][$association] === false) {
                if (!isset($data[$association])) {
                    $data[$association] = array();
                }
            } else {
                foreach ($merge as $i => $row) {
                    if (count($row) == 1) {
                        $data[$association][] = $row[$association];
                    } else {
                        $tmp = array_merge($row[$association], $row);
                        unset($tmp[$association]);
                        $data[$association][] = $tmp;
                    }
                }
            }
        }
    }

    /**
     * Private helper method to remove query metadata in given data array.
     *
     * @param array $data
     */
    public function __scrubQueryData(& $data) {
        if (!isset($data['type']))
            $data['type'] = 'default';

        if (!isset($data['conditions']))
            $data['conditions'] = array();

        if (!isset($data['targetDn']))
            $data['targetDn'] = null;

        if (!isset($data['fields']) && empty($data['fields']))
            $data['fields'] = array();

        if (!isset($data['order']) && empty($data['order']))
            $data['order'] = array();

        if (!isset($data['limit']))
            $data['limit'] = null;
    }

    public function __getObjectclasses() {
        $cache = null;
        if ($this->cacheSources !== false) {
            if (isset($this->__descriptions['ldap_objectclasses'])) {
                $cache = $this->__descriptions['ldap_objectclasses'];
            } else {
                $cache = $this->__cacheDescription('objectclasses');
            }
        }

        if ($cache != null) {
            return $cache;
        }

        // If we get this far, then we haven't cached the attribute types, yet!
        $ldapschema = $this->__getLDAPschema();
        $objectclasses = $ldapschema['objectclasses'];

        // Cache away
        $this->__cacheDescription('objectclasses', $objectclasses);

        return $objectclasses;
    }

    public function boolean() {
        return null;
    }

    /**
     * Returns the count of records
     *
     * @param model $model
     * @param string $func Lowercase name of SQL function, i.e. 'count' or 'max'
     * @param array $params Function parameters (any values must be quoted manually)
     * @return string	   entry count
     * @access public
     */
    public function calculate(&$model, $func, $params = array()) {
        $params = (array) $params;

        switch (strtolower($func)) {
            case 'count':
                if (empty($params) && $model->id) {
                    //quick search to make sure it exsits
                    $queryData['targetDn'] = $model->id;
                    $queryData['conditions'] = 'objectClass=*';
                    $queryData['scope'] = 'base';
                    $query = $this->read($model, $queryData);
                }
                return $this->numRows;
                break;
            case 'max':
            case 'min':
                break;
        }
    }

    public function describe($model) {
        $schemas = $this->__getLDAPschema();
        $attrs = $schemas['attributetypes'];
        ksort($attrs);
        if (!empty($field)) {
            return($attrs[strtolower($field)]);
        } else {
            return $attrs;
        }
    }

    public function in_arrayi($needle, $haystack) {
        $found = false;
        foreach ($haystack as $attr => $value) {
            if (strtolower($attr) == strtolower($needle)) {
                $found = true;
            } elseif (strtolower($value) == strtolower($needle)) {
                $found = true;
            }
        }
        return $found;
    }

    public function defaultNSAttributes() {
        $fields = '* ' . $this->OperationalAttributes;
        return(explode(' ', $fields));
    }

    /**
     * debugLDAPConnection debugs the current connection to check the settings
     *
     */
    public function debugLDAPConnection() {
        $opts = array('LDAP_OPT_DEREF', 'LDAP_OPT_SIZELIMIT', 'LDAP_OPT_TIMELIMIT', 'LDAP_OPT_NETWORK_TIMEOUT', 'LDAP_OPT_PROTOCOL_VERSION', 'LDAP_OPT_ERROR_NUMBER', 'LDAP_OPT_REFERRALS', 'LDAP_OPT_RESTART', 'LDAP_OPT_HOST_NAME', 'LDAP_OPT_ERROR_STRING', 'LDAP_OPT_MATCHED_DN', 'LDAP_OPT_SERVER_CONTROLS', 'LDAP_OPT_CLIENT_CONTROLS');
        foreach ($opts as $opt) {
            $ve = '';
            ldap_get_option($this->database, constant($opt), $ve);
            $this->log("Option={$opt}, Value=" . print_r($ve, 1), 'debug');
        }
    }

    /**
     * If you want to pull everything from a netscape stype ldap server 
     * iPlanet, Redhat-DS, Project-389 etc you need to ask for specific 
     * attributes like so.  Other wise the attributes listed below wont
     * show up
     */
    public function setNetscapeEnv() {
        $this->OperationalAttributes = 'accountUnlockTime aci copiedFrom copyingFrom createTimestamp creatorsName dncomp entrydn entryid hasSubordinates ldapSchemas ldapSyntaxes modifiersName modifyTimestamp nsAccountLock nsAIMStatusGraphic nsAIMStatusText nsBackendSuffix nscpEntryDN nsds5ReplConflict nsICQStatusGraphic nsICQStatusText nsIdleTimeout nsLookThroughLimit nsRole nsRoleDN nsSchemaCSN nsSizeLimit nsTimeLimit nsUniqueId nsYIMStatusGraphic nsYIMStatusText numSubordinates parentid passwordAllowChangeTime passwordExpirationTime passwordExpWarned passwordGraceUserTime passwordHistory passwordRetryCount pwdExpirationWarned pwdGraceUserTime pwdHistory pwdpolicysubentry retryCountResetTime subschemaSubentry';
        $this->SchemaAttributes = 'objectClasses attributeTypes ldapSyntaxes matchingRules matchingRuleUse createTimestamp modifyTimestamp';
    }

    public function setActiveDirectoryEnv() {
        //Need to disable referals for AD
        ldap_set_option($this->database, LDAP_OPT_REFERRALS, 0);
        $this->OperationalAttributes = ' + ';
        $this->SchemaAttributes = 'objectClasses attributeTypes ldapSyntaxes matchingRules matchingRuleUse createTimestamp modifyTimestamp subschemaSubentry';
    }

    public function setOpenLDAPEnv() {
        $this->OperationalAttributes = ' + ';
    }

    public function setSchemaPath() {
        $checkDN = ldap_read($this->database, '', 'objectClass=*', array('subschemaSubentry'));
        $schemaEntry = ldap_get_entries($this->database, $checkDN);
        $this->SchemaDN = $schemaEntry[0]['subschemasubentry'][0];
    }

    /**
     * Returns an array of sources (tables) in the database.
     *
     * @param mixed $data
     * @return array Array of tablenames in the database
     */
    public function listSources($data = null) {
        $cache = parent::listSources();
        if ($cache !== null) {
            return $cache;
        }
    }

}
