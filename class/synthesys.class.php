<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Synthesys
{
    protected $connection = null;
    protected $maps=null;
    
    protected $db;
    protected $username;
    protected $password;
    protected $host;
    protected $port;
    protected $conn;
    protected $stmt;
    protected $log;
    
    public function __construct()
    {
        // Inizializza il logger
        $this->log = new Logger('Synthesys');
        $this->log->pushHandler(new StreamHandler('synthesys.log', Logger::DEBUG, true, 0644, true, "a")); // File di log specifico per Synthesys
        
        $this->db = 'Synthesys_General';
        $this->username = "sa";
        $this->password = "Wie@q&OxfePH";
        $this->host = "192.168.10.43";
        $this->port = "1433";
        try {
            
            $this->conn = new PDO("sqlsrv:Server=192.168.10.43;Database=".$this->db, "sa", "c4p1t4n.bl4tt4", array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ));
            
            /*
            $this->connection = new PDO("dblib:host=$this->host:$this->port;dbname=$this->db", "$this->username", "$this->password", array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            */
            
            /*
            $this->log->info("Connected to Synthesys database", [
                'host' => $this->host,
                'database' => $this->db
            ]);
            */
        } catch (Exception $e) {
            $this->log->critical("Error connecting to Synthesys database", [
                'error' => $e->getMessage(),
                'host' => $this->host,
                'database' => $this->db
            ]);
            throw new PDOException($e->getMessage()); // Throws PDOException
        }
    }
    
    public function disconnect() {
        #$this->log->info("Disconnessione dal database Synthesys"); // Log INFO
        $this->conn = null;
    }
    
    public function query($sql)
    {
        #$this->log->info("Executing Synthesys query", ['sql' => $sql]);
        try {
            $this->stmt = $this->conn->prepare($sql);
            $this->stmt->execute();
            #$this->log->info("Query executed", ['sql' => $sql]);
            return $this->stmt;
        } catch (PDOException $e) {
            $this->log->error("Synthesys query failed", ['sql' => $sql, 'error' => $e->getMessage()]);
            throw $e; // Rethrow the exception
        }
    }
    
    public function prepare($sql)
    {
        #$this->log->info("Preparing Synthesys query", ['sql' => $sql]);
        try {
            $this->stmt = $this->conn->prepare($sql);
            #$this->log->info("Query prepared", ['sql' => $sql]);
            return $this->stmt;
        } catch (PDOException $e) {
            $this->log->error("Synthesys prepare failed", ['sql' => $sql, 'error' => $e->getMessage()]);
            throw $e; // Rethrow the exception
        }
    }
    
    public function execute($params = array())
    {
        #$this->log->info("Executing prepared Synthesys query", ['params' => $params]);
        
        try {
            $this->stmt->execute($params);
            #$this->log->info("Statement executed", ['params' => json_encode($params)]);
            return $this->stmt;
        } catch (PDOException $e) {
            $this->log->error("Execute failed: " . $e->getMessage(), ['error' => $e->getMessage()]);
            die("Execute failed: " . $e->getMessage());
        }
    }
    
    public function fetch($fetchStyle = PDO::FETCH_ASSOC)
    {
        #$this->log->debug("Fetching one row");
        try {
            $result = $this->stmt->fetch($fetchStyle);
            #$this->log->info("Fetched a row", ['result' => json_encode($result)]);
            return $result;
        } catch (PDOException $e) {
            $this->log->error("Error fetching one row", ['error' => $e->getMessage()]);
            return false; // Or handle as needed
        }
    }
    
    // public function fetchAll($fetchStyle = PDO::FETCH_ASSOC)
    public function fetchAll()
    {
        #$this->log->debug("Fetching all rows");
        try {
            $result = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            #$this->log->info("Fetched all rows", ['result' => json_encode($result)]);
            return $result;
        } catch (PDOException $e) {
            $this->log->error("Error fetching all rows", ['error' => $e->getMessage()]);
            return; // Or handle as needed
        }

    }
    
    public function lastInsertId() {
        #$this->log->info("Getting last insert ID");
        try {
            return $this->conn->lastInsertId();
        } catch (PDOException $e) {
            $this->log->error("Error getting last insert ID", ['error' => $e->getMessage()]);
            return 0; // Or handle as needed
        }
    }
    
    public function beginTransaction() {
        #$this->log->info("Beginning transaction");
        try {
            return $this->conn->beginTransaction();
        } catch (PDOException $e) {
            $this->log->error("Error beginning transaction", ['error' => $e->getMessage()]);
            return false; // Or handle as needed
        }
    }
    
    public function commitTransaction() {
        #$this->log->info("Committing transaction");
        try {
            return $this->conn->commit();
        } catch (PDOException $e) {
            $this->log->error("Error committing transaction", ['error' => $e->getMessage()]);
            return false; // Or handle as needed
        }
    }
    
    public function rollbackTransaction() {
        #$this->log->info("Rolling back transaction");
        try {
            return $this->conn->rollBack();
        } catch (PDOException $e) {
            $this->log->error("Error rolling back transaction", ['error' => $e->getMessage()]);
            return false; // Or handle as needed
        }
    }
    
    public function debugDumpParams()
    {
        return $this->stmt->debugDumpParams();
    }
}
?>