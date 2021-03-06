<?php

namespace PHPixie\DB\PDOV;


use App\Exception\SQLException;
use App\Pixie;
use VulnModule\VulnerableField;

/**
 * PDO Database implementation.
 * @package Database
 * @method exec
 */
class Connection extends \PHPixie\DB\Connection {

    /**
     * @var Pixie
     */
    public $pixie;

    /**
     * Connection object
     * @var \PDO
     * @link http://php.net/manual/en/class.pdo.php
     */
    public $conn;

    /**
     * Type of the database, e.g. mysql, pgsql etc.
     * @var string
     */
    public $db_type;

    /**
     * @var array
     */
    protected $dispErrorStates;

    /**
     * Vulnerability settings for current controller.
     * @var string
     */
    private $_settings;

    protected $isBlinded = null;

    protected $vulnerable = null;

    /**
     * Initializes database connection
     *
     * @param $pixie
     * @param string $config Name of the connection to initialize
     * @return \PHPixie\DB\PDOV\Connection
     */
    public function __construct($pixie, $config) {
        parent::__construct($pixie, $config);

        $this->conn = new \PDO(
            $pixie->config->get("db.{$config}.connection"),
            $pixie->config->get("db.{$config}.user", ''),
            $pixie->config->get("db.{$config}.password", '')
        );
        $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db_type = strtolower(str_replace('PDO_', '', $this->conn->getAttribute(\PDO::ATTR_DRIVER_NAME)));
        if ($this->db_type != 'sqlite') {
            $this->conn->exec("SET NAMES utf8");
        }
    }

    /**
     * Builds a new Query implementation
     *
     * @param string $type Query type. Available types: select,update,insert,delete,count
     * @param array $settings
     * @return Query Returns a PDO implementation of a Query.
     * @see Query_Database
     */
    public function query($type, $settings = array()) {
        /** @var Query $query */
        $query = $this->pixie->db->query_driver('PDOV', $this, $type);
        $query->settings($this->_settings);
        return $query;
    }

    public function settings($val = null) {
        if ($val != null) {
            $this->_settings = $val;
        }
        return $this->_settings;
    }

    /**
     * Gets the id of the last inserted row.
     *
     * @return mixed Row id
     */
    public function insert_id() {
        if ($this->db_type == 'pgsql') {
            return $this->execute('SELECT lastval() as id')->current()->id;
        }
        return $this->conn->lastInsertId();
    }

    /**
     * Gets column names for the specified table
     *
     * @param string $table Name of the table to get columns from
     * @return array Array of column names
     */
    public function list_columns($table) {
        $columns = array();
        if ($this->db_type == 'mysql') {
            $table_desc = $this->execute("DESCRIBE `$table`");
            foreach ($table_desc as $column) {
                $columns[] = $column->Field;
            }
        }
        if ($this->db_type == 'pgsql') {
            $table_desc = $this->execute("select column_name from information_schema.columns where table_name = '{$table}' and table_catalog=current_database();");
            foreach ($table_desc as $column) {
                $columns[] = $column->column_name;
            }
        }
        if ($this->db_type == 'sqlite') {
            $table_desc = $this->execute("PRAGMA table_info('$table')");
            foreach ($table_desc as $column) {
                $columns[] = $column->name;
            }
        }
        return $columns;
    }

    /**
     * Executes a prepared statement query
     *
     * @param string $query A prepared statement query
     * @param array $params Parameters for the query
     * @throws \Exception
     * @return Result   PDO implementation of a database result
     * @see Database_Result
     */
    public function execute($query, $params = array()) {
        //return $this->pixie->db->result_driver('PDOV', $this->conn->query($query));

        $vulnFields = null;
        if (array_key_exists('vuln_fields', $params)) {
            $vulnFields = $params['vuln_fields'];
            unset($params['vuln_fields']);
        }

        $this->startBlindness($vulnFields);

        $stmt = $this->conn->prepare($query);
        try {
            if (!$stmt->execute($params)) {
                $error = $stmt->errorInfo();
                throw new \Exception("Database error:\n" . $error[2] . " \n in query:\n{$query}");
            }
            $result = $this->pixie->db->result_driver('PDOV', $stmt);

        } catch (\Exception $e) {
            $isVulnerable = $this->vulnerable;
            $isBlind = $this->isBlinded;
            $this->stopBlindness($vulnFields);
            $error = $stmt->errorInfo();
            throw new SQLException("Database error:\n" . $error[2] . " \n in query:\n{$query}", 0, $e, $isVulnerable, $isBlind);
        }

        $this->stopBlindness($vulnFields);

        return $result;
    }

    /**
     * @param mixed|VulnerableField|VulnerableField[] $vulnFields
     */
    public function startBlindness($vulnFields)
    {
        if (!$vulnFields || $this->vulnerable) {
            return;
        }

        if (!is_array($vulnFields) && !($vulnFields instanceof \ArrayObject)) {
            $vulnFields = [$vulnFields];
        }

        $this->vulnerable = false;

        foreach ($vulnFields as $field) {
            if (!($field instanceof VulnerableField)) {
                continue;
            }

            if ($field->isVulnerableTo('SQL')) {
                $this->vulnerable = true;
                if ($field->getVulnerability('SQL')->isBlind()) {
                    $this->dispErrorStates[] = $this->pixie->debug->display_errors;
                    $this->pixie->debug->display_errors = false;
                    $this->isBlinded = true;
                    return;
                }
            }
        }

        $this->isBlinded = false;
    }

    public function stopBlindness()
    {
        if ($this->isBlinded === true) {
            $this->dispErrorStates[] = $this->pixie->debug->display_errors;
            $this->pixie->debug->display_errors = array_pop($this->dispErrorStates);
        }
        $this->isBlinded = null;
        $this->vulnerable = null;
    }
}
