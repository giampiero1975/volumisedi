<?php
// Include necessari
include_once 'manager.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class DdiRelator
{
    private $log;
    public $timesheetDdis;
    public $synthesysDdis;
    
    public function __construct()
    {
        $this->log = new Logger('DdiRelator');
        $this->log->pushHandler(new StreamHandler('ddi_relator_DEBUG_LOOKUP.log', Logger::DEBUG));
    }
    
    /**
     * Elabora le attività basandosi sull'assunzione
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
        $this->log->info("Inizio {elaborateActivitiesSimplified} semplificata per " . count($activities) . " attività (DEBUG LOOKUP ATTIVO).");
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
            }
            
            $this->log->debug("Chiavi presenti in configMapByDdi:", ['keys' => $debugKeys]);
            
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
                $this->log->error("Attività saltata: Account ID non trovato.", ['CRMPrefix' => $activity['CRMPrefix'] ?? 'N/A', 'EventTime' => $activity['EventTime'] ?? 'N/A', 'SequenceID' => $sequenceId]);
                $skippedCount++;
                continue;
            }
            
            if ($nomeAccountSynthesys === null) {
                $nomeAccountSynthesys = 'Account ' . $accountId;
                $this->log->error("Nome Account non trovato.", ['CRMPrefix' => $activity['CRMPrefix'] ?? 'N/A', 'EventTime' => $activity['EventTime'] ?? 'N/A', 'SequenceID' => $sequenceId]);
            }
            
            $ncontatti = 0;
            $config = null;
            $primoDDIValido = null;
            $durataMinuti = $durata_ms / 60000.0;
            $timesheetClientName = null; // Inizializza la variabile
            
            // Aggiunto controllo per saltare se non ci sono DDI mappati per l'account
            if (empty($ddisByAccountId[$accountId])) {
                $this->log->error("1. Nessun DDI associato trovato per Account ID {$accountId}. Assegnati 0 contatti.", ['SequenceID' => $sequenceId]);
                // Andiamo comunque a memorizzare l'attività con 0 contatti
            } else {
                $listaDdiAccount = $ddisByAccountId[$accountId];
                $primoDDIValido = $listaDdiAccount[0]; // Prendi il primo
                //manager::stamp("listaDdiAccount",$listaDdiAccount);
                //die();
                $config = $configMapByDdi[$primoDDIValido] ?? null; // Lookup
                
                if ($config === null) {
                    $this->log->error("Nessuna config Timesheet trovata per il DDI '{$primoDDIValido}' (Account ID {$accountId}). Assegnati 0 contatti.", ['SequenceID' => $sequenceId]);
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
    
    /**
     * Aggrega i contatti calcolati e il numero di ticket per combinazione cliente/progetto.
     * Prende l'output di elaborateActivitiesSimplified.
     *
     * @param array $elaboratedActivities Lista di singole attività processate. Ogni item ha:
     * ['sequenceId', 'account_id', 'durata', 'ncontatti' (calcolati per il ticket),
     * 'synthesys_nome_account', 'ticket_count' (solitamente 1), 'timesheet_client_name']
     * @return array Lista piatta di dati aggregati. Ogni item ha:
     * ['client_name', 'project_name', 'total_contacts' (somma ncontatti), 'total_tickets' (somma ticket_count)]
     */
    public function aggregaContattiPerAccountId(array $elaboratedActivities): array
    {
        // manager::stamp("",$elaboratedActivities); // Stamp originale (output a schermo)
        
        $aggregato = []; // Array per i risultati aggregati
        $this->log->info("Inizio aggregazione contatti e ticket.");
        $totalTicketsAggregatedOverall = 0; // Contatore generale ticket (opzionale)
        
        // Itera su ogni singola attività elaborata
        foreach ($elaboratedActivities as $activity) {
            $accountId = $activity['account_id'] ?? null;
            // Contatti calcolati per *questo* specifico ticket
            $contattiSingoloTicket = $activity['ncontatti'] ?? 0;
            // Conteggio per *questo* specifico ticket (dovrebbe essere 1)
            $ticketCountSingolo = $activity['ticket_count'] ?? 1; // Default a 1 se non presente
            
            // Log di debug per l'attività corrente
            $this->log->debug("Aggregazione: Processo Attività", [
                'sequenceId' => $activity['sequenceId'] ?? 'N/A',
                'account_id' => $accountId,
                'project_name_activity' => $activity['synthesys_nome_account'] ?? 'N/D',
                'client_name_activity' => $activity['timesheet_client_name'] ?? 'N/D',
                'contatti_activity' => $contattiSingoloTicket,
                'ticket_count_activity' => $ticketCountSingolo
            ]);
            
            // Salta se manca l'account ID, essenziale per l'aggregazione
            if ($accountId === null) {
                $this->log->warning("Record saltato in aggregazione: Account ID mancante.", ['activity' => $activity]);
                continue;
            }
            
            // Determina le chiavi per l'aggregazione
            $clientName = $activity['timesheet_client_name'] ?? 'Nome Mancante';
            $projectName = $activity['synthesys_nome_account'] ?? ('Account ' . $accountId);
            
            // Usa una chiave combinata per raggruppare per cliente E progetto
            $aggregationKey = $clientName . '|' . $projectName;
            
            // Se è il primo ticket per questa combinazione cliente/progetto...
            if (!isset($aggregato[$aggregationKey])) {
                // ...inizializza l'array per questo gruppo
                $aggregato[$aggregationKey] = [
                    'client_name' => $clientName,
                    'project_name' => $projectName,
                    'total_contacts' => 0, // Inizializza somma contatti calcolati
                    'total_tickets' => 0  // Inizializza somma ticket (conteggio)
                ];
                $this->log->debug("Nuovo gruppo di aggregazione creato", ['key' => $aggregationKey]);
            }
            
            // Aggiorna i totali per questo gruppo
            $aggregato[$aggregationKey]['total_contacts'] += $contattiSingoloTicket; // SOMMA i contatti calcolati
            $aggregato[$aggregationKey]['total_tickets']  += $ticketCountSingolo;    // SOMMA i ticket count (essenzialmente conta i ticket)
            
            $totalTicketsAggregatedOverall += $ticketCountSingolo; // Aggiorna contatore generale (opzionale)
            
            // Log di debug dopo l'aggiornamento del gruppo
            $this->log->debug("Gruppo di aggregazione aggiornato", [
                'key' => $aggregationKey,
                'current_total_contacts' => $aggregato[$aggregationKey]['total_contacts'],
                'current_total_tickets' => $aggregato[$aggregationKey]['total_tickets']
            ]);
            
        } // Fine foreach $elaboratedActivities
        
        // Log finale di riepilogo
        $this->log->info("Fine aggregazione.", [
            'groups_aggregated' => count($aggregato), // Numero di gruppi cliente/progetto unici
            'total_tickets_sum_overall' => $totalTicketsAggregatedOverall, // Totale ticket su tutti i gruppi
        ]);
        
        // Log dettagliato dei risultati finali aggregati (più leggibile)
        $this->log->info("--- Risultati Aggregati Dettagliati ---");
        if (empty($aggregato)) {
            $this->log->info("Nessun dato aggregato prodotto.");
        } else {
            $itemNum = 0;
            // Itera sui risultati aggregati PRIMA di array_values per avere info complete
            foreach ($aggregato as $key => $item) {
                $itemNum++;
                // Logga chiaramente i totali per ogni gruppo cliente/progetto
                $this->log->info("Aggregato #{$itemNum} (Key: {$key})", [
                    'Cliente' => $item['client_name'] ?? 'N/D',
                    'Progetto' => $item['project_name'] ?? 'N/D',
                    'Conteggio Ticket Totale' => $item['total_tickets'] ?? 'N/D',      // <-- CONTEGGIO TICKET
                    'Somma Contatti Calcolati Totale' => $item['total_contacts'] ?? 'N/D' // <-- SOMMA CONTATTI CALCOLATI
                ]);
            }
        }
        $this->log->info("-------------------------------------");
        
        // Restituisce l'array aggregato come lista semplice (senza le chiavi usate per raggruppare)
        return array_values($aggregato);
    }
    
    /**
 * Formatta i dati per l'inserimento in Tapi, leggendo la struttura nidificata.
 * @param array $lavorazioniPerNazione Dati strutturati per [Nazione][Cliente][Progetto]
 * @param string $company Nome dell'azienda (es. 'MeTMi')
 * @return array Lista piatta di record pronti per l'inserimento
 */
public function formattaDatiPerInserimento(array $lavorazioniPerNazione, string $company): array
{
    $datiPerInserimento = [];
    $this->log->info("Inizio formattazione dati per TAPI da struttura nidificata", [
        'company' => $company,
        'input_keys_example' => !empty($lavorazioniPerNazione) ? array_keys($lavorazioniPerNazione) : 'Input vuoto'
    ]);

    // 1. Ciclo sulle Nazioni (es. 'IT', 'ES', ...)
    foreach ($lavorazioniPerNazione as $nazione => $clienti) {
        // $nazione contiene la sigla della nazione (es. 'IT')
        // $clienti è l'array dei clienti per quella nazione

        // 2. Ciclo sui Clienti Timesheet all'interno della nazione
        foreach ($clienti as $nomeClienteTimesheet => $progetti) {
            // $nomeClienteTimesheet contiene il nome del cliente (es. 'MCZ GROUP SPA')
            // $progetti è l'array dei progetti per quel cliente

            // 3. Ciclo sui Progetti Synthesys all'interno del cliente
            foreach ($progetti as $nomeProgettoSynthesys => $datiAggregati) {
                // $nomeProgettoSynthesys contiene il nome del progetto
                // $datiAggregati è l'array finale con ['ncontatti' => ..., 'durata' => ...]

                // Estrai il numero di contatti
                $totalContacts = $datiAggregati['total_contacts'] ?? 0;

                // Crea la riga per l'array di output piatto
                $datiPerInserimento[] = [
                    'company_name' => $company,                 // Dall'argomento della funzione
                    'client_name' => $nomeClienteTimesheet,     // Dalla chiave del ciclo clienti
                    'project_name' => $nomeProgettoSynthesys,   // Dalla chiave del ciclo progetti
                    'total_contacts' => $totalContacts          // Dal valore 'ncontatti'
                    // Potresti aggiungere anche la nazione se ti serve nella tabella TAPI:
                    // 'nazione' => $nazione
                ];
            }
        }
        //manager::stamp("",$datiPerInserimento);
    }

    $this->log->info("Formattazione completata.", ['rows_to_insert' => count($datiPerInserimento)]);
    return $datiPerInserimento;
}
    
/**
 * Ottiene un array delle lavorazioni aggregate per nazione, cliente Timesheet e progetto Synthesys,
 * calcolando la SOMMA dei contatti basata su durata e slots, filtrando per azienda e operatori della nazione.
 *
 * @param array $timesheetDdis Dati da Timesheet (array di clienti/progetti con ddi_in, client_name, slots, company_id).
 * @param array $activitiesByProjects Attività giornaliere da Synthesys (array di attività con account_id, durata_ms, addressline1).
 * @param array $synthesysDdis Dati DDI da Synthesys (per lookup DDI -> Account).
 * @param array $operatori Array degli operatori per la nazione specifica (array di operatori con 'extension').
 * @param int $targetCompanyId ID dell'azienda target (es. $idMeTMi, $idMarmeris).
 * @param string $nazione Sigla della nazione che si sta processando (es. 'IT', 'FR').
 * @return array Array strutturato [Nazione][ClienteTimesheet][ProgettoSynthesys] => ['total_contacts' => ..., 'total_duration_ms' => ..., 'ticket_count' => ...]
 */
public function getLavorazioniPerNazione(
    array $timesheetDdis,
    array $activitiesByProjects,
    array $synthesysDdis,
    array $operatori, // Questo è l'array degli operatori SOLO per la nazione corrente
    int $targetCompanyId,
    string $nazione // Sigla nazione (IT, FR, ES, AT)
    ): array {
        $lavorazioniPerNazione = []; // Inizializza array risultato vuoto
        $this->log->info("Inizio elaborazione getLavorazioniPerNazione", [
            'nazione' => $nazione,
            'target_company_id' => $targetCompanyId,
            'num_timesheet_configs' => count($timesheetDdis),
            'num_activities' => count($activitiesByProjects),
            'num_operatori' => count($operatori)
        ]);
        
        // 1. Crea mappe di lookup per efficienza
        $ddiLookup = []; // [ddi => ['account_id' => ..., 'account_name' => ...]]
        foreach ($synthesysDdis as $ddiItem) {
            $ddiKey = $ddiItem['DDI'] ?? null;
            if ($ddiKey !== null) {
                $ddiLookup[$ddiKey] = [
                    'account_id' => $ddiItem['AccountId'] ?? null,
                    'account_name' => $ddiItem['Name'] ?? null // Nome account/progetto da Synthesys
                ];
            }
        }
        
        $operatoriLookup = []; // [extension => true] per la nazione corrente
        foreach ($operatori as $operatore) {
            if (!empty($operatore['extension'])) {
                $operatoriLookup[$operatore['extension']] = true;
            }
        }
        $this->log->debug("Lookup operatori creata per nazione {$nazione}", ['num_operatori_lookup' => count($operatoriLookup)]);
        if(empty($operatoriLookup)) {
            $this->log->warning("Lookup operatori VUOTA per nazione {$nazione}. Nessuna attività verrà associata.");
            // Potresti voler uscire qui se non ci sono operatori, dipende dai requisiti
            // return [];
        }
        
        // 2. Itera sulle configurazioni Timesheet (clienti/progetti)
        foreach ($timesheetDdis as $timesheetItem) {
            // Controlli preliminari sull'item Timesheet
            /*
            if (empty($timesheetItem['company_id']) || empty($timesheetItem['ddi_in']) || empty($timesheetItem['client_name']) || !isset($timesheetItem['slots'])) {
                $this->log->warning("Saltato record Timesheet malformato o senza slots.", [
                    'client_id_tms' => $timesheetItem['id'] ?? 'N/A', // Aggiungi ID se disponibile
                    'item_keys' => array_keys($timesheetItem)
                ]);
                continue;
            }
            */
            
            $companyId = $timesheetItem['company_id'];
            $ddiIn = $timesheetItem['ddi_in'];
            $clienteTimesheet = $timesheetItem['client_name']; // Nome cliente da Timesheet
            $slotsJson = $timesheetItem['slots']; // Slots JSON per calcolo contatti
            
            // Filtro per azienda target
            /*
            if ($companyId !== $targetCompanyId) {
                continue; // Salta record di altre aziende, questo è normale
            }
            */
            // Decodifica gli slots JSON
            $slotsArray = json_decode($slotsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($slotsArray) || empty($slotsArray)) {
                $this->log->warning("Slots JSON non validi o vuoti. Impossibile calcolare contatti.", [
                    'nazione' => $nazione,
                    'company_id' => $companyId,
                    'ddi_tms' => $ddiIn,
                    'cliente_tms' => $clienteTimesheet,
                    'json_error' => json_last_error_msg(),
                    'json_content' => $slotsJson
                ]);
                continue; // Non possiamo calcolare contatti senza slots validi
            }
            
            // Cerca l'account_id corrispondente al DDI nel lookup Synthesys
            if (isset($ddiLookup[$ddiIn])) {
                $accountInfo = $ddiLookup[$ddiIn];
                $accountId = $accountInfo['account_id'];
                $nomeProgettoSynthesys = $accountInfo['account_name'] ?? ('Account ' . $accountId); // Nome progetto da Synthesys
                
                if ($accountId === null) {
                    $this->log->warning("Account ID nullo nel lookup DDI.", [
                        'nazione' => $nazione,
                        'company_id' => $companyId,
                        'ddi_tms' => $ddiIn,
                        'cliente_tms' => $clienteTimesheet
                    ]);
                    continue; // Non possiamo correlare attività senza Account ID
                }
                
                // 3. Itera sulle attività Synthesys per trovare corrispondenze con l'account_id
                foreach ($activitiesByProjects as $activity) {
                    // Controlli sull'attività Synthesys
                    if (empty($activity['account_id']) || empty($activity['addressline1']) || !isset($activity['durata_ms'])) {
                        // Logga solo una volta o a livello DEBUG se sono tante
                        // $this->log->debug("Attività Synthesys saltata per dati mancanti.", ['activity_keys' => array_keys($activity), 'sequenceid' => $activity['sequenceid'] ?? 'N/A']);
                        continue;
                    }
                    
                    // Filtro 1: Corrispondenza Account ID
                    if ($activity['account_id'] == $accountId) {
                        $operatoreExtension = $activity['addressline1'];
                        $durataMs = $activity['durata_ms'];
                        
                        // Filtro 2: L'operatore appartiene alla nazione corrente?
                        if (isset($operatoriLookup[$operatoreExtension])) {
                            
                            // Calcola i contatti per questa specifica attività
                            $contattiCalcolati = 0;
                            if ($durataMs > 0) {
                                $durataMinuti = $durataMs / 60000.0;
                                // Chiama il metodo privato per il calcolo
                                $contattiCalcolati = $this->calcolaContatti($slotsArray, $durataMinuti);
                                $this->log->debug("Contatti calcolati per attività", [
                                    'sequenceid' => $activity['sequenceid'] ?? 'N/A',
                                    'durata_ms' => $durataMs,
                                    'durata_min' => round($durataMinuti, 2),
                                    'contatti' => $contattiCalcolati,
                                    'slots_usati' => $slotsArray
                                ]);
                            } else {
                                $this->log->debug("Durata 0 o negativa per attività, 0 contatti calcolati.", [
                                    'sequenceid' => $activity['sequenceid'] ?? 'N/A',
                                    'durata_ms' => $durataMs
                                ]);
                            }
                            
                            // Aggrega i risultati per [Nazione][ClienteTimesheet][ProgettoSynthesys]
                            // Inizializza il gruppo se non esiste
                            if (!isset($lavorazioniPerNazione[$nazione][$clienteTimesheet][$nomeProgettoSynthesys])) {
                                $lavorazioniPerNazione[$nazione][$clienteTimesheet][$nomeProgettoSynthesys] = [
                                    'total_contacts' => 0,      // Somma contatti calcolati
                                    'total_duration_ms' => 0.0, // Somma durata in ms (usare float per somme precise)
                                    'ticket_count' => 0         // Conteggio ticket elaborati per questo gruppo
                                ];
                                $this->log->debug("Nuovo gruppo di aggregazione creato", [
                                    'nazione' => $nazione,
                                    'cliente' => $clienteTimesheet,
                                    'progetto' => $nomeProgettoSynthesys
                                ]);
                            }
                            
                            // Aggiungi i valori calcolati ai totali del gruppo
                            $lavorazioniPerNazione[$nazione][$clienteTimesheet][$nomeProgettoSynthesys]['total_contacts'] += $contattiCalcolati;
                            $lavorazioniPerNazione[$nazione][$clienteTimesheet][$nomeProgettoSynthesys]['total_duration_ms'] += (float)$durataMs; // Assicura float per somma
                            $lavorazioniPerNazione[$nazione][$clienteTimesheet][$nomeProgettoSynthesys]['ticket_count']++;
                            
                        } // Fine filtro operatore
                    } // Fine filtro account_id
                } // Fine ciclo activitiesByProjects
            } else {
                $this->log->debug("DDI Timesheet non trovato nel lookup Synthesys.", [
                    'nazione' => $nazione,
                    'company_id' => $companyId,
                    'ddi_tms' => $ddiIn
                ]);
            }
        } // Fine ciclo timesheetDdis
        
        $this->log->info("Fine elaborazione getLavorazioniPerNazione", [
            'nazione' => $nazione,
            'target_company_id' => $targetCompanyId,
            'num_groups_aggregated' => count($lavorazioniPerNazione[$nazione] ?? []) // Conta i gruppi per la nazione specifica
        ]);
        
        // Restituisce l'array completo, la formattazione sceglierà cosa usare
        return $lavorazioniPerNazione;
}
    
    public function formattaDatiAggregatiPerOutputFinale(array $aggregatedData, string $company): array
    {
        $datiPerInserimento = [];
        $this->log->info("Inizio formattazione dati aggregati per Output Finale", [
            'company' => $company,
            'input_records' => count($aggregatedData)
        ]);
        
        foreach ($aggregatedData as $item) {
            // PRENDE LA SOMMA DEI CONTATTI CALCOLATI
            $calculatedContacts = $item['total_contacts'] ?? 0;
            
            $datiPerInserimento[] = [
                'company_name' => $company,
                'client_name' => $item['client_name'] ?? 'Cliente Mancante',
                'project_name' => $item['project_name'] ?? 'Progetto Mancante', // Nome progetto originale
                // USA LA SOMMA DEI CONTATTI CALCOLATI
                'total_contacts' => $calculatedContacts
            ];
        }
        
        $this->log->info("Formattazione per Output Finale completata.", ['rows_to_insert' => count($datiPerInserimento)]);
        return $datiPerInserimento;
    }
    
    /**
     * Verifica e confronta i DDI tra Timesheet e un array di riferimento.
     *
     * @param array $synthesysDdis Un array di DDI provenienti da Synthesys.
     * @return void
     */
    public function verificaDdi(array $synthesysDdis, array $timesheetDdis): void
    {
        $this->log->info("Inizio verifica DDI Timesheet");
        $this->timesheetDdis = $timesheetDdis;
        $this->synthesysDdis = $synthesysDdis;
        
        // 2. Estrai i DDI da Synthesys
        $ddiSynthesys = array_column($this->synthesysDdis, 'DDI');
        
        // Inizializza array per tenere traccia delle discrepanze
        $clientiSenzaDDI =[];
        $ddiMancantiInSynthesys =[];
        
        // 3. Verifica i DDI e confronta con Synthesys
        foreach ($this->timesheetDdis as $client) {
            if (empty($client['ddi_in'])) {
                // 3.1 Log dei clienti senza DDI in Timesheet
                $this->log->warning(
                    "Cliente senza DDI associati in Timesheet",
                    ['client_id' => $client['id'], 'client_name' => $client['client_service_name']]
                    );
                $clientiSenzaDDI= $client['id']; // Traccia i clienti senza DDI
            } else {
                // 3.2 Verifica se il DDI è presente in Synthesys
                if (!in_array($client['ddi_in'], $ddiSynthesys)) {
                    $ddiMancantiInSynthesys= $client['ddi_in'];
                    $clienteMancantiInSynthesys= $client['client_name'];
                }
            }
        }
        
        // 4. Log dei risultati del confronto
        
        // 4.1. Log dei DDI mancanti in Synthesys
        if (!empty($ddiMancantiInSynthesys)) {
            $this->log->error(
                "DDI presenti in Timesheet ma non in Synthesys",
                ['ddi_mancanti' => $ddiMancantiInSynthesys, 'cliente' => $clienteMancantiInSynthesys]
                );
        }
        
        // 4.2. Log di riepilogo (opzionale)
        if (empty($ddiMancantiInSynthesys) && empty($clientiSenzaDDI)) {
            $this->log->info("Tutti i DDI di Timesheet sono presenti anche in Synthesys e tutti i clienti hanno DDI associati");
        } else {
            $this->log->info("Verifica DDI Timesheet completata con discrepanze.");
        }
        
        $this->log->info("Fine verifica DDI Timesheet");
    }
    
} // Fine classe DdiRelator
?>