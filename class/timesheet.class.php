<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Timesheet
{
    private $host = 'db.metmi.lan';  
    private $user = 'metmi';  
    private $pass = 'iXtTQfeB4PPBW4Q';
    
    /*
    private $host = 'localhost';
    private $user = 'root';
    private $pass = '';
    */
	private $dbname = 'timesheetdev';
	private $stmt;

	private $dbh;  
	private $error;
	protected $log;
	
	public function __construct(){  
		// Set DSN 
		
		$dsn = 'mysql:host=' . $this->host . ';dbname=' . $this->dbname;  
		
		// Set options  
		$options = array(  
			PDO::ATTR_PERSISTENT => true,  
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false
		);
		
		// Inizializza il logger
		$this->log = new Logger('Timesheet');
		$this->log->pushHandler(new StreamHandler('timesheet.log', Logger::DEBUG, true, 0644, true, "a"));
		
		// Create a new PDO instanace  
		try{  
			$this->dbh = new PDO($dsn, $this->user, $this->pass, $options);  
			#$this->log->info("Connessione al database Timesheet stabilita."); // Log INFO
			if (!$this->dbh) {
			    $this->log->critical("Failed to connect to the database.");
			    throw new PDOException("Failed to connect to the database.");
			}
		}  
		// Catch any errors  
		catch(PDOException $e){  
		    $this->error = $e->getMessage();
		    $this->log->error("Database connection error: " . $e->getMessage(), [
		        'error' => $e->getMessage()
		    ]);
		    // Puoi rilanciare l'eccezione o gestirla come preferisci
		    throw $e;
		}
		// Aggiungi questo codice per il debug
		#$this->log->debug("Timesheet class instantiated. Connection established.");
	} 

	/*
	The query method introduces the $stmt variable to hold the statement.

	The query method also introduces the PDO::prepare function.

	The prepare function allows you to bind values into your SQL statements. This is important because it takes away the threat of SQL Injection because you are no longer having to manually include the parameters into the query string.

	Using the prepare function will also improve performance when running the same query with different parameters multiple times.
	*/
	public function query($query){
	    #$this->log->info("Preparing query", ['sql' => $query]);
	    try {
	        $this->stmt = $this->dbh->prepare($query);
	    } catch (PDOException $e) {
	        $this->log->error("Error preparing query", ['sql' => $query, 'error' => $e->getMessage()]);
	        throw $e;
	    }
	}  

	/*
	The next method we will be looking at is the bind method. In order to prepare our SQL queries, we need to bind the inputs with the placeholders we put in place. This is what the Bind method is used for.
	The main part of this method is based upon the PDOStatement::bindValue PDO method.
	Firstly, we create our bind method and pass it three arguments.

	Param is the placeholder value that we will be using in our SQL statement, example :name.

	Value is the actual value that we want to bind to the placeholder, example “John Smith.

	Type is the datatype of the parameter, example string.

	Next we use a switch statement to set the datatype of the parameter:
	*/
	public function bind($param, $value, $type = null){
	    #$this->log->debug("Binding parameter", ['param' => $param, 'value' => $value, 'type' => $type]);
	    
		if (is_null($type)) {  
			switch (true) {  
				case is_int($value):  
					$type = PDO::PARAM_INT;  
					break;  

				case is_bool($value):  
					$type = PDO::PARAM_BOOL;  
					break;  

				case is_null($value):  
					$type = PDO::PARAM_NULL;  
					break;  

				default:  
				$type = PDO::PARAM_STR;  
			}  
		}
		$this->stmt->bindValue($param, $value, $type);   
	}

	/*
	The execute method executes the prepared statement.
	*/
	public function execute(){  
	    #$this->log->info("Executing statement", ['query' => $this->debugDumpParams()]);
	    try {
	        return $this->stmt->execute();
	    } catch (PDOException $e) {
	        $this->log->error("Error executing statement", ['query' => $this->debugDumpParams(), 'error' => $e->getMessage()]);
	        throw $e;
	    }
	} 

	/*
	The Result Set function returns an array of the result set rows. It uses the PDOStatement::fetchAll PDO method. First we run the execute method, then we return the results.
	*/
	public function resultset(){  
	    #$this->log->info("Fetching result set", ['query' => $this->debugDumpParams()]);
	    try {
	        $this->execute();
	        return $this->stmt->fetchAll(PDO::FETCH_ASSOC);
	    } catch (PDOException $e) {
	        $this->log->error("Error fetching result set", ['query' => $this->debugDumpParams(), 'error' => $e->getMessage()]);
	        throw $e;
	    }
	} 

	/*
	Very similar to the previous method, the Single method simply returns a single record from the database. Again, first we run the execute method, then we return the single result. This method uses the PDO method PDOStatement::fetch.
	*/
	public function single(){  
	    #$this->log->info("Fetching single result", ['query' => $this->debugDumpParams()]);
	    try {
	        $this->execute();
	        return $this->stmt->fetch(PDO::FETCH_ASSOC);
	    } catch (PDOException $e) {
	        $this->log->error("Error fetching single result", ['query' => $this->debugDumpParams(), 'error' => $e->getMessage()]);
	        throw $e;
	    }
	}  

	/*
	The next method simply returns the number of effected rows from the previous delete, update or insert statement. This method use the PDO method PDOStatement::rowCount.
	*/
	public function rowCount(){  
	    #$this->log->debug("Getting row count", ['query' => $this->debugDumpParams()]);
	    try {
	        return $this->stmt->rowCount();
	    } catch (PDOException $e) {
	        $this->log->error("Error getting row count", ['query' => $this->debugDumpParams(), 'error' => $e->getMessage()]);
	        return 0; // Or handle as needed
	    }
	} 

	/*
	The Last Insert Id method returns the last inserted Id as a string. This method uses the PDO method PDO::lastInsertId.
	*/
	public function lastInsertId(){  
	    #$this->log->info("Getting last insert ID");
	    try {
	        return $this->dbh->lastInsertId();
	    } catch (PDOException $e) {
	        $this->log->error("Error getting last insert ID", ['error' => $e->getMessage()]);
	        return 0; // Or handle as needed
	    }
	}  

	public function beginTransaction(){  
	    #$this->log->info("Beginning transaction");
	    try {
	        return $this->dbh->beginTransaction();
	    } catch (PDOException $e) {
	        $this->log->error("Error beginning transaction", ['error' => $e->getMessage()]);
	        return false; // Or handle as needed
	    }
	}

	public function endTransaction(){  
	    #$this->log->info("Committing transaction");
	    try {
	        return $this->dbh->commit();
	    } catch (PDOException $e) {
	        $this->log->error("Error committing transaction", ['error' => $e->getMessage()]);
	        return false; // Or handle as needed
	    }
	}   

	public function cancelTransaction(){  
	    #$this->log->info("Rolling back transaction");
	    try {
	        return $this->dbh->rollBack();
	    } catch (PDOException $e) {
	        $this->log->error("Error rolling back transaction", ['error' => $e->getMessage()]);
	        return false; // Or handle as needed
	    }
	} 

	public function debugDumpParams(){  
	    return $this->stmt ? $this->stmt->queryString : '';
	}
}
?>

