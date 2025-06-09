<?php
include_once 'tapi.class.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class TapiQuery extends Tapi
{
    protected $log;
    private $nazione;  // Memorizza la nazione
    
    public function __construct(string $nazione)
    {
        parent::__construct($nazione); // Passa la nazione al costruttore di Tapi
        $this->nazione = $nazione;
        $this->log = new Logger('TapiQuery');
        $this->log->pushHandler(new StreamHandler('tapi_query.log', Logger::DEBUG));
        
    }
    
    /**
     * Ottiene un array degli operatori con i loro interni, indicizzato per nazione.
     *
     * @return array Un array in cui le chiavi sono le nazioni e i valori sono array
     * associativi contenenti 'full_name' e 'extension' per ogni operatore.
     */
    public function getOperatoriPerNazione(): array
    {
        try {
            $this->query("SELECT full_name, extension FROM agents WHERE deleted_at IS NULL AND LENGTH(extension)=3");
            $results = $this->resultset();
            
            $operatori =[];
            foreach ($results as $row) {
                $operatori[]= [
                    'full_name' => $row['full_name'],
                    'extension' => $row['extension'],
                ];
            }
            
            $this->log->info("Operatori {$this->nazione}: ",$operatori);
            return [
                $this->nazione => $operatori,  // Usa la nazione memorizzata
            ];
        } catch (PDOException $e) {
            $this->log->error("Errore durante l'esecuzione della query per la nazione {$this->nazione}: " . $e->getMessage(), [
                'sql' => "SELECT full_name, extension FROM agents WHERE deleted_at IS NULL",
                'nazione' => $this->nazione
            ]);
            return false; // Restituisce un array vuoto in caso di errore
        }
    }
    
    /**
     * Inserisce i dati formattati nella tabella Tapi.
     *
     * @param array $datiPerInserimento I dati da inserire nel database.
     * @return void
     */
    public function inserisciDatiInTapi(array $datiPerInserimento): void
    {
        $this->log->info("Inizio inserimento dati in Tapi");
        
        try {            
            // Definisci la query di inserimento
            $sql = "INSERT INTO `contacts` (company_name, client_name, project_name, total_contacts, created_at, updated_at) VALUES (:company_name, :client_name, :project_name, :total_contacts, now(), now() )";
            
            // Prepara la query
            $this->query($sql);
            
            // Itera sui dati e li inserisce
            foreach ($datiPerInserimento as $row) {
                $this->bind(':company_name', $row['company_name']);
                $this->bind(':client_name', $row['client_name']);
                $this->bind(':project_name', $row['project_name']);
                $this->bind(':total_contacts', $row['total_contacts']);
                $this->execute();
            }
            
            $this->log->info("Dati inseriti correttamente in Tapi.");
            
        } catch (PDOException $e) {
            $this->log->error("Errore durante l'inserimento dati in Tapi: " . $e->getMessage(), [
                'dati' => $datiPerInserimento,
                'sql' => $sql,
            ]);
            // Gestisci l'errore come preferisci (es. lancia un'eccezione, restituisci false, ecc.)
            throw $e;
        }
    }
    
    /**
     * Esegue un'operazione TRUNCATE su una tabella del database TAPI.
     *
     * @param string $tableName Il nome della tabella da troncare.
     * @return bool True se l'operazione ha successo, false altrimenti.
     */
    //public function truncateTable(string $tableName): bool
    public function truncateTable(): bool
    {
        try {
            #$this->beginTransaction(); // Inizia la transazione
            
            // $sql = "TRUNCATE TABLE " . $tableName;
            $sql = "TRUNCATE TABLE `contacts`";
            $this->query($sql);
            $this->execute();
            
            #$this->endTransaction(); // Conferma la transazione
            return true;
            
        } catch (PDOException $e) {
            $this->cancelTransaction(); // Annulla la transazione in caso di errore
            $this->log->error("Errore durante il TRUNCATE della tabella {$tableName}: " . $e->getMessage(), [
                'sql' => $sql,
            ]);
            return false;
        }
    }
}
?>