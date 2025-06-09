<?php
include 'synthesys.class.php'; // Include classe base PDO per SQL Server
include_once 'manager.php';     // Per manager::stamp (debug)
use Monolog\Logger;           // Necessario per costanti es. Logger::INFO
use Monolog\Handler\StreamHandler;
// RIMOSSO: use Monolog\Level;  // Non serve per Monolog 2.x

class SynthesysQuery extends Synthesys
{
    // Logger ereditato da Synthesys as $this->log
    
    /**
     * Recupera le attività giornaliere valide
     * e cerca di associarle all'Account ID affidabile tramite CRMPrefix.
     * Restituisce account_id (può essere NULL), durata_ms, progetto_name.
     *
     * @return array Array [ { account_id, durata_ms, progetto_name }, ... ]
     */
    public function getDailyActivitiesWithAccount(): array
    {
        $this->log->info("Esecuzione getDailyActivitiesWithAccount (via CRMPrefix)");
        $sql="SELECT PS.id AS sequenceid, Acc.AccountId AS account_id, CAST(REPLACE(PS.Duration, ',', '.') AS DECIMAL(18, 6)) * 1000 AS durata_ms, "
            ."Acc.Name AS progetto_name, PS.CRMPrefix, PS.EventTime, PS.Result, Users.addressline1 "
            ."FROM Phoenix.dbo.Phoenix_Statistics AS PS "
            ."JOIN Synthesys_General_Admin.dbo.Accounts AS Acc ON PS.CRMPrefix = CAST(Acc.additional AS XML).value('(/Additional/Attribute/@EntityPrefix)[1]', 'VARCHAR(100)') "
            ."join Synthesys_General_Admin.dbo.Users AS Users ON PS.operator_id = Users.UserID "
            //."WHERE CAST(PS.EventTime AS DATE) = CAST(GETDATE() AS DATE) "
            ."WHERE CAST(PS.EventTime AS DATE) >= '2025-01-01' "
            ."AND PS.Result NOT IN ('Training', 'Application Closed', 'Disconnection') "
            ."AND (PS.Duration IS NOT NULL AND PS.Duration > 0) "
            ."AND CAST(Acc.additional AS XML).exist('/Additional/Attribute/@EntityPrefix') = 1 "
            ."AND Acc.AccountId IS NOT NULL AND ISNUMERIC(REPLACE(PS.Duration, ',', '.')) = 1 "
            ."ORDER BY Acc.AccountId";
        
        $this->log->debug("SQL getDailyActivitiesWithAccount:", ['sql' => $sql]);
        manager::stamp("SQL [getDailyActivitiesWithAccount]", $sql);
        
        try {
            $stmt = $this->query($sql);
            $results = $this->fetchAll($stmt);
            $this->log->info("Recuperate attività giornaliere.", ['count' => count($results)]);
            return $results;
        } catch (PDOException $e) {
            $this->log->error("Errore in getDailyActivitiesWithAccount: " . $e->getMessage(), ['sql' => $sql]);
            throw $e; // Rilancia
        }
        
        manager::stamp("getDailyActivitiesWithAccount",$results);
    }
    
    /**
     * NUOVO (Approccio Semplificato): Recupera il mapping Account ID -> Lista DDI (3 cifre).
     * @return array Mappa PHP [ account_id => [ddi1, ddi2, ...], ... ]
     */
    public function getAccountToDdisMapping(): array
    {
        $this->log->info("Esecuzione getAccountToDdisMapping (solo DDI 3 cifre)");
        // Query SQL SENZA COMMENTI INTERNI
        $sql = "SELECT Accounts.AccountId, DDI.DDI, Accounts.Name, DDI.WebflowUri, Accounts.Prefix, "
              ."CAST(Accounts.additional AS XML).value('(/Additional/Attribute/@EntityPrefix)[1]', 'VARCHAR(100)') AS EntityPrefix "
              ."FROM Synthesys_General_Admin.dbo.DDI "
              ."JOIN Synthesys_General_Admin.dbo.Webflows ON Webflows.WebflowId = DDI.WebflowId "
              ."JOIN Synthesys_General_Admin.dbo.Accounts ON Accounts.AccountId = Webflows.AccountId "
              ."WHERE DDI.webflowid IS NOT NULL "
              ."AND LEN(DDI) = 3 AND Accounts.additional IS NOT NULL";
        
               $this->log->debug("SQL getAccountToDdisMapping:", ['sql' => $sql]);
               manager::stamp("SQL [getAccountToDdisMapping]", $sql);
               
               $map = [];
               try {
                   $stmt = $this->query($sql);
                   $results = $this->fetchAll($stmt);
                   foreach ($results as $row) {
                       if (!empty($row['AccountId']) && !empty($row['DDI'])) {
                           $accountId = strval($row['AccountId']);
                           $ddi = strval($row['DDI']);
                           if (!isset($map[$accountId])) {
                               $map[$accountId] = [];
                           }
                           if (!in_array($ddi, $map[$accountId])) {
                               $map[$accountId][] = $ddi;
                           }
                       }
                   }
                   $this->log->info("Mapping Account ID -> Lista DDI creato.", ['accounts_count' => count($map)]);
                   return $map;
               } catch (PDOException $e) {
                   $this->log->error("Errore in getAccountToDdisMapping: " . $e->getMessage(), ['sql' => $sql]);
                   throw $e; // Rilancia
               }
    }
    
    public function getSynthesysDdi() {
        $this->log->info("Synthesys > getSynthesysDdi");
        // Query SQL SENZA COMMENTI INTERNI
        $sql = "SELECT Accounts.AccountId, DDI.DDI, Accounts.Name, DDI.WebflowUri, Accounts.Prefix, "
              ."CAST(Accounts.additional AS XML).value('(/Additional/Attribute/@EntityPrefix)[1]', 'VARCHAR(100)') AS EntityPrefix "
              ."FROM Synthesys_General_Admin.dbo.DDI "
              ."JOIN Synthesys_General_Admin.dbo.Webflows ON Webflows.WebflowId = DDI.WebflowId "
              ."JOIN Synthesys_General_Admin.dbo.Accounts ON Accounts.AccountId = Webflows.AccountId "
              ."WHERE DDI.webflowid IS NOT NULL "
              ."AND LEN(DDI) = 3 AND Accounts.additional IS NOT NULL";
                                
              $this->log->debug("getSynthesysDdi:", ['sql' => $sql]);
              manager::stamp("getSynthesysDdi]", $sql);
              try {
                $stmt = $this->query($sql);
                $results = $this->fetchAll($stmt);
                
                $this->log->info(
                    "getSynthesysDdi",
                    [
                        'result' => $results,
                        'sql' => $sql
                    ]
                    );
                
              return $results;
              
              } catch (PDOException $e) {
                $this->log->error("Errore in getAccountToDdisMapping: " . $e->getMessage(), ['sql' => $sql]);
                throw $e; // Rilancia
              }
    }
} // Fine classe SynthesysQuery
?>