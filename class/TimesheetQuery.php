<?php
include 'timesheet.class.php';
include_once 'manager.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class TimesheetQuery extends Timesheet
{
    // Metodi specifici per Timesheet

    /**
     * Ottiene i clienti, i progetti e i DDI attivi per una specifica business unit e azienda.
     *
     * @param int $businessUnitId
     *            L'ID della business unit.
     * @param int $companyId
     *            L'ID dell'azienda.
     * @return array Un array di risultati contenente le informazioni richieste.
     */
    public function getActiveClientsProjectsDdis()
    {
        // Initialize logger for TimesheetQuery
        $log = new Logger('TimesheetQuery');
        $log->pushHandler(new StreamHandler('timesheet.log', Logger::DEBUG, true, 0644, true, "a"));
        
        $this->log->info("Esecuzione query: getActiveClientsProjectsDdis");
        $sql = "SELECT clients.id, clients.name AS client_name, ddis.name, ddis.ddi_in, clients.company_id "
            . ", clients.contacts, ts.slots, CASE WHEN clients.contacts IS NULL THEN 1 ELSE 0 END AS nocontacts  "
            . "FROM clients "
            . "JOIN projects ON clients.id = projects.client_id "
            . "JOIN ddis ON ddis.client_id = clients.id "
            . "JOIN time_slots AS ts ON clients.time_slot_id = ts.id "    
            . "WHERE projects.business_unit_id = 6 " // condizione per DDI 
            . "AND projects.ends_at >= NOW() "
            . "AND clients.contacts IS NOT NULL " // aggiunta condizione per configurazione DDI anomala
            . "AND projects.value > 0";
        
            manager::stamp("SQL: [".__CLASS__."][".__FUNCTION__."]",$sql);
            
             try {
                 $this->query($sql);
                 $this->execute();
                 $result = $this->resultset();
                 
                 // Log dei clienti senza time slot
                 foreach ($result as $row) {
                     if ($row['nocontacts'] == 1) {
                         $this->log->warning(
                             "Numero contatti non valorizzato",
                             ['client_id' => $row['id'], 'client_name' => $row['client_name']]
                             );
                     }
                 }
                 
                 $log->info(
                     "getActiveClientsProjectsDdis",
                     [
                         'result' => $result,
                         'sql' => $sql
                     ]
                     );
                 
                 return $result;
             } catch (PDOException $e) {
                 $this->log->error("Errore durante l'esecuzione di getActiveClientsProjectsDdis", [ // Log ERROR
                     'error' => $e->getMessage(),
                     'sql' => $sql // Log della query
                 ]);
                 throw $e;
             }
    }
    
    /**
     * Calcola il numero di contatti.
     *
     * @param int   $durata
     * @param mixed $fasciaOraria
     *
     * @return float|int
     */
    public function calcolaNumeroContatti(int $durata, mixed $fasciaOraria): float|int
    {
        // Implementa qui la logica per calcolare il numero di contatti
        // utilizzando $durata e $fasciaOraria.
        // Assicurati che questa logica sia corretta e gestisca i vari casi.
        // Esempio (da adattare alla tua logica reale):
        if ($fasciaOraria == 8) {
            $contatti = $durata / 8;
        } else {
            $contatti = $durata / 4;
        }
        
        return $contatti;
    }
    
    /** Crea mappa configurazione Timesheet [DDI => Config]. */
    public function creaMappaConfigurazioneDaTimesheet(array $timesheetData): array
    {
        $configMapByDdi = [];
        $this->log->info("Creazione mappa configurazione Timesheet per DDI.");
        foreach ($timesheetData as $client) {
            $ddi = $client['ddi_in'] ?? null;
            if (!empty($ddi)) {
                // Forziamo la chiave a stringa per coerenza
                $ddiKey = strval($ddi);
                
                if (isset($configMapByDdi[$ddiKey])) {
                    $this->log->warning("DDI duplicato TimeSheet: {$ddiKey}. Usata ultima config.", ['id' => $client['id']]);
                    //manager::stamp("configMapByDdi",$configMapByDdi[$ddiKey]);
                }
                
                $configMapByDdi[$ddiKey] = [
                    'client_id' => $client['id'],
                    'client_name' => $client['client_name'] ?? $client['name'],
                    'project_name' => $client['name'],
                    'slots' => $client['slots'],
                    'contacts' => $client['contacts']
                ];
            } else {
                $this->log->debug("Cliente TSheet senza DDI.", ['id' => $client['id']]);
            }
        }
        $this->log->info("Mappa config TSheet creata.", ['count' => count($configMapByDdi)]);
        return $configMapByDdi;
    }
}
?>