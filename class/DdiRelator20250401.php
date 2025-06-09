<?php
// Include necessari
include_once 'manager.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class DdiRelator
{
    private $log;
    
    public function __construct()
    {
        $this->log = new Logger('DdiRelator');
        $this->log->pushHandler(new StreamHandler('ddi_relator_DEBUG_LOOKUP.log', Logger::DEBUG));
    }
    
    /**
     * NUOVO (Approccio Semplificato): Elabora le attività basandosi sull'assunzione
     * che tutti i DDI di un account usino la stessa configurazione Timesheet.
     * VERSIONE CON DEBUG AGGIUNTIVO PER LOOKUP CONFIG
     *
     * @param array $activities Risultato di SynthesysQuery::getDailyActivitiesWithAccount()
     * @param array $ddisByAccountId Mappa [account_id => \[ddi1, ddi2,...]] da SynthesysQuery::getAccountToDdisMapping()
     * @param array $configMapByDdi Mappa \[DDI => Config] da creaMappaConfigurazioneDaTimesheet()
     * @return array Array di attività elaborate \[ {account_id, ncontatti, synthesys_nome_account, ticket_count}, ... ]
     */
    /**
     * NUOVO (Approccio Semplificato): Elabora le attività basandosi sull'assunzione
     * che tutti i DDI di un account usino la stessa configurazione Timesheet.
     * VERSIONE CON DEBUG AGGIUNTIVO PER LOOKUP CONFIG
     *
     * @param array $activities Risultato di SynthesysQuery::getDailyActivitiesWithAccount()
     * @param array $ddisByAccountId Mappa [account_id => [ddi1, ddi2,...]] da SynthesysQuery::getAccountToDdisMapping()
     * @param array $configMapByDdi Mappa [DDI => Config] da creaMappaConfigurazioneDaTimesheet()
     * @return array Array di attività elaborate [ {account_id, ncontatti, synthesys_nome_account, ticket_count}, ... ]
     */
    public function elaborateActivitiesSimplified(array $activities, array $ddisByAccountId, array $configMapByDdi): array
    {
        $this->log->info("Inizio elaborazione semplificata per " . count($activities) . " attività (DEBUG LOOKUP ATTIVO).");
        $elaboratedActivities = [];
        $skippedCount = 0;
        $durataMinuti = 0; // Inizializza fuori
        
        // --- DEBUG: Logga chiavi mappa config ---
        if (count($activities) > 0 && !empty($configMapByDdi)) {
            $debugKeys = [];
            $count = 0;
            foreach ($configMapByDdi as $key => $value) {
                $debugKeys[] = $key . ' (' . gettype($key) . ')';
                $count++;
                if ($count >= 100) {
                    break;
                }
            }
            
            $this->log->debug("Chiavi presenti in configMapByDdi (max 100):", ['keys' => $debugKeys]);
            
            // Log anche usando array_key_exists per sicurezza
            /*
            $specificKey = '432'; // Chiave che ci interessa
            $specificKeyExists = isset($configMapByDdi[$specificKey]);
            $specificKeyExistsFn = array_key_exists($specificKey, $configMapByDdi);
            $this->log->debug("Controllo specifico per chiave '{$specificKey}' in configMapByDdi", [
                'chiave_cercata' => $specificKey,
                'tipo_chiave_cercata' => gettype($specificKey),
                'chiave_esiste_isset' => $specificKeyExists ? 'SI' : 'NO',
                'chiave_esiste_fn' => $specificKeyExistsFn ? 'SI' : 'NO'
            ]);
            */
        } elseif (empty($configMapByDdi)) {
            $this->log->warning("La mappa configMapByDdi è VUOTA!");
        }
        // --- FINE DEBUG ---
        
        foreach ($activities as $activity) {
            $accountId = $activity['account_id'] ?? null;
            $durata_ms = $activity['durata_ms'] ?? 0;
            $sequenceId = $activity['sequenceid'] ?? null;
            $nomeAccountSynthesys = $activity['progetto_name'] ?? null;
            
            if ($accountId === null) {
                $this->log->warning("Attività saltata: Account ID non trovato.", ['CRMPrefix' => $activity['CRMPrefix'] ?? 'N/A', 'EventTime' => $activity['EventTime'] ?? 'N/A', 'SequenceID' => $sequenceId]);
                $skippedCount++;
                continue;
            }
            if ($nomeAccountSynthesys === null) {
                $nomeAccountSynthesys = 'Account ' . $accountId;
            }
            
            $ncontatti = 0;
            $config = null;
            $primoDDIValido = null;
            $durataMinuti = $durata_ms / 60000.0;
            $timesheetClientName = null; // Inizializza la variabile
            
            // Aggiunto controllo per saltare se non ci sono DDI mappati per l'account
            if (empty($ddisByAccountId[$accountId])) {
                $this->log->warning("Nessun DDI associato trovato per Account ID {$accountId}. Assegnati 0 contatti.", ['SequenceID' => $sequenceId]);
                // Andiamo comunque a memorizzare l'attività con 0 contatti
            } else {
                $listaDdiAccount = $ddisByAccountId[$accountId];
                $primoDDIValido = $listaDdiAccount[0]; // Prendi il primo
                
                $config = $configMapByDdi[$primoDDIValido] ?? null; // Lookup
                
                if ($config === null) {
                    $this->log->warning("Nessuna config Timesheet trovata per il DDI '{$primoDDIValido}' (Account ID {$accountId}). Assegnati 0 contatti.", ['SequenceID' => $sequenceId]);
                } elseif ($durata_ms > 0 && isset($config['slots'])) {
                    // Calcolo contatti (come prima)
                    $slotsJson = $config['slots'];
                    if (!empty($slotsJson)) {
                        $slotsArray = json_decode($slotsJson, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($slotsArray) && !empty($slotsArray)) {
                            $ncontatti = $this->calcolaContatti($slotsArray, $durataMinuti);
                            // Rimosso log INFO ridondante
                            $this->log->debug("Contatti calcolati OK", ['accountId' => $accountId, 'ddiUsato' => $primoDDIValido, 'durataMin' => round($durataMinuti, 2), 'nContatti' => $ncontatti, 'SequenceID' => $sequenceId]);
                            $timesheetClientName = $config['client_name'] ?? null; // Ottieni il nome del cliente da Timesheet
                        } else {
                            $this->log->warning("JSON slots non valido DDI {$primoDDIValido}", ['json' => $slotsJson, 'SequenceID' => $sequenceId]);
                        }
                    } else {
                        $this->log->warning("Config slots vuota DDI {$primoDDIValido}", ['accountId' => $accountId, 'SequenceID' => $sequenceId]);
                    }
                } else {
                    $this->log->debug("Config trovata DDI {$primoDDIValido} ma durata 0 o slots mancanti, 0 contatti.", ['accountId' => $accountId, 'SequenceID' => $sequenceId]);
                }
            } // Fine blocco if/else DDI trovato
            
            // Memorizza Risultato
            $elaboratedActivities[] = [
                'sequenceId' => $sequenceId, // Ripristinato
                'account_id' => $accountId,
                'durata' => round($durataMinuti, 5),
                'ncontatti' => $ncontatti,
                'synthesys_nome_account' => $nomeAccountSynthesys,
                'ticket_count' => 1,
                'timesheet_client_name' => $timesheetClientName
            ];
        } // Fine foreach
        
        $this->log->info("Fine elaborazione semplificata.", ['skipped' => $skippedCount, 'ready_for_aggregation' => count($elaboratedActivities)]);
        return $elaboratedActivities;
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
                    $this->log->warning("DDI duplicato TSheet: {$ddiKey}. Usata ultima config.", ['id' => $client['id']]);
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
    
    /** Calcola numero contatti basato su durata e slots. */
    private function calcolaContatti(array $timeSlots, float $durataMinutes): int
    {
        $nuovoArray = [];
        $indiceContatto = 1;
        foreach ($timeSlots as $minutiSlot) {
            if (is_numeric($minutiSlot)) {
                $nuovoArray[$indiceContatto] = floatval($minutiSlot);
                $indiceContatto++;
            } else {
                $this->log->warning("Valore non numerico negli slots", ['valore' => $minutiSlot]);
            }
        }
        $timeSlotsMappato = $nuovoArray;
        if (empty($timeSlotsMappato)) {
            $this->log->error("Array slots vuoto/non valido", ['slots' => $timeSlots]);
            return 1;
        }
        $durataSeconds = $durataMinutes * 60;
        $ncontatti = 0;
        if ($durataSeconds > 0) {
            $chiaveAssegnata = 0;
            foreach ($timeSlotsMappato as $numContatto => $minutiMassimiSlot) {
                $secondiMassimiSlot = $minutiMassimiSlot * 60;
                if ($durataSeconds <= $secondiMassimiSlot) {
                    $chiaveAssegnata = $numContatto;
                    break;
                }
            }
            if ($chiaveAssegnata === 0) {
                if (function_exists('array_key_last')) {
                    $chiaveAssegnata = array_key_last($timeSlotsMappato);
                } else {
                    end($timeSlotsMappato);
                    $chiaveAssegnata = key($timeSlotsMappato);
                }
            }
            $ncontatti = $chiaveAssegnata;
        } else {
            $this->log->debug("Durata <= 0 ({$durataMinutes} min), 0 contatti.");
        }
        return max(0, (int) $ncontatti);
    }
    
    /** Formatta minuti decimali in MM:SS. */
    function formattaMinuti(float $minutiDecimali): string
    {
        $minuti = floor($minutiDecimali);
        $secondi = round(($minutiDecimali - $minuti) * 60);
        if ($secondi >= 60) {
            $minuti += 1;
            $secondi = 0;
        }
        return sprintf("%02d:%02d", $minuti, $secondi);
    }
    
    /** Aggrega i contatti e il numero di ticket per account_id usando il nome da Synthesys. */
    public function aggregaContattiPerAccountId(array $elaboratedActivities): array
    {
        $aggregato = [];
        $this->log->info("Inizio aggregazione contatti e ticket per Account ID.");
        $totalTicketsAggregated = 0;
        foreach ($elaboratedActivities as $activity) {
            $accountId = $activity['account_id'] ?? null;
            $contattiTicket = $activity['ncontatti'] ?? 0;
            $ticketCount = $activity['ticket_count'] ?? 0;
            if ($accountId === null) {
                $this->log->warning("Record elaborato senza account_id durante aggregazione.", ['activity' => $activity]);
                continue;
            }
            
            // Usa direttamente client_name e project_name se presenti
            $clientName = $activity['timesheet_client_name'] ?? 'Nome Mancante';
            $projectName = $activity['synthesys_nome_account'] ?? ('Account ' . $accountId);
            
            if (!isset($aggregato[$accountId])) {
                $aggregato[$accountId] = [
                    'client_name' => $clientName,
                    'project_name' => $projectName,
                    'total_contacts' => 0,
                    'total_tickets' => 0
                ];
                $this->log->debug("Nuovo Account ID aggregazione: {$accountId}", ['nome' => $clientName]);
            }
            $aggregato[$accountId]['total_contacts'] += $contattiTicket;
            $aggregato[$accountId]['total_tickets']  += $ticketCount;
            $totalTicketsAggregated += $ticketCount;
        }
        $this->log->info("Fine aggregazione.", ['accounts_aggregated' => count($aggregato), 'total_tickets_sum' => $totalTicketsAggregated]);
        return array_values($aggregato);
    }
    
    /** Formatta i dati per l'inserimento in Tapi. */
    public function formattaDatiPerInserimento(array $aggregatedData, string $company): array
    {
        $datiPerInserimento = [];
        $this->log->info("Formattazione dati per TAPI", ['company' => $company, 'records' => count($aggregatedData)]);
        foreach ($aggregatedData as $item) {
            $clientName = $item['client_name'] ?? 'Nome Mancante';
            $projectName = $item['project_name'] ?? $clientName;
            $totalContacts = $item['total_contacts'] ?? 0;
            $datiPerInserimento[] = ['company_name' => $company, 'client_name' => $clientName, 'project_name' => $projectName, 'total_contacts' => $totalContacts];
        }
        $this->log->info("Formattazione completata.", ['rows_to_insert' => count($datiPerInserimento)]);
        return $datiPerInserimento;
    }
    
    // --- METODI VECCHI RIMOSSI/COMMENTATI ---
    /* ... */
    
} // Fine classe DdiRelator
?>