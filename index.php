<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('vendor/autoload.php');

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

include_once 'class/TimesheetQuery.php';
include_once 'class/SynthesysQuery.php';
include_once 'class/DdiRelator.php';
include_once 'class/TapiQuery.php';
require_once 'class/manager.php';

manager::cleanLogs();

$log = new Logger('MonitorVolumi');
$log->pushHandler(new StreamHandler('file.log', Logger::DEBUG, true, 0644));

try {
    $log->info("=============================================");
    $log->info("Inizio procedure MONITOR VOLUMI (Approccio Originale)");
    manager::stamp('Inizio procedure MONITOR VOLUMI (Approccio Originale)');
    
    // --- PREPARAZIONE DATI E MAPPATURE ---
    $idMeTMi = 1;
    $idMeTBa = 2;
    $idMeTKla = 3;
    $idMarmeris = 4;
    
    // 1. Dati Timesheet
    $log->info("Recupero dati Timesheet...");
    $timesheetQuery = new TimesheetQuery();
    $timesheetData = $timesheetQuery->getActiveClientsProjectsDdis();
    $configMapByDdi = $timesheetQuery->creaMappaConfigurazioneDaTimesheet($timesheetData);
    // manager::stamp("",$configMapByDdi);
    // 2. Dati Synthesys
    $log->info("Recupero dati Synthesys...");
    $synthesysQuery = new SynthesysQuery();
    $synthesysDdis = $synthesysQuery->getSynthesysDdi();
    
    // verifica DDI
    $ddiRelator = new DdiRelator();
    $ddiRelator->verificaDdi($synthesysDdis, $timesheetData);
    
    // aggregazioni per controlli
    $ddisByAccountId = $synthesysQuery->getAccountToDdisMapping(); // duplicato
    
    $activities = $synthesysQuery->getDailyActivitiesWithAccount();
    
    // AGGREGAZIONI PER DEBUG
    $elaboratedActivities = $ddiRelator->elaborateActivitiesSimplified($activities, $ddisByAccountId, $configMapByDdi);
    $aggregatedData = $ddiRelator->aggregaContattiPerAccountId($elaboratedActivities);
    //manager::stamp("Dati Aggregati (Alternativo):", $aggregatedData);

    // --- ELABORAZIONE PER NAZIONE ---
    
    // Ottieni gli operatori per ogni nazione
    $log->info("Recupero operatori da Tapi...");
    $tapiQueryIT = new TapiQuery('IT');
    $operatori_it = $tapiQueryIT->getOperatoriPerNazione();
    //manager::stamp("operatori_it", $operatori_it);
    //die();
    
    /*
    $tapiQueryFR = new TapiQuery('FR');
    $operatori_fr = $tapiQueryFR->getOperatoriPerNazione();
    //manager::stamp("operatori_fr", $operatori_fr);
    
    $tapiQueryES = new TapiQuery('ES');
    $operatori_es = $tapiQueryES->getOperatoriPerNazione();
    //manager::stamp("operatori_es");
    
    $tapiQueryAT = new TapiQuery('AT');
    $operatori_at = $tapiQueryAT->getOperatoriPerNazione();
    //manager::stamp("operatori_at",$operatori_at);
    //die();
    */
    // Elabora i dati per ogni nazione
    $log->info("Elaborazione dati per nazione...");
    /*   
    manager::stamp("Verifica Dati Input per AT:");
    manager::stamp("timesheetData Count:", count($timesheetData));
    manager::stamp("activities Count:", count($activities));
    manager::stamp("synthesysDdis Count:", count($synthesysDdis));
    manager::stamp("operatori_it['AT'] Count:", isset($operatori_at['AT']) ? count($operatori_at['AT']) : 'Non impostato');
    die();
    */
    
    $metmi = $ddiRelator->getLavorazioniPerNazione($timesheetData, $activities, $synthesysDdis, $operatori_it['IT'], $idMeTMi, 'IT');
    //$marmeris = $ddiRelator->getLavorazioniPerNazione($timesheetData, $activities, $synthesysDdis, $operatori_fr['FR'], $idMarmeris, 'FR');
    //$metba = $ddiRelator->getLavorazioniPerNazione($timesheetData, $activities, $synthesysDdis, $operatori_es['ES'], $idMeTBa, 'ES');
    //$metkla = $ddiRelator->getLavorazioniPerNazione($timesheetData, $activities, $synthesysDdis, $operatori_at['AT'], $idMeTKla, 'AT');
    //manager::stamp("metkla",$metkla);   
    //die();
    
    // Formatta i dati per l'inserimento
    $log->info("Formattazione dati per inserimento...");
    
    $datiFormattatiIT = $ddiRelator->formattaDatiPerInserimento($metmi, 'MeTMi');
    //$datiFormattatiFR = $ddiRelator->formattaDatiPerInserimento($marmeris, 'Marmeris');
    //$datiFormattatiES = $ddiRelator->formattaDatiPerInserimento($metba, 'MeTBa');
    //$datiFormattatiAT = $ddiRelator->formattaDatiPerInserimento($metkla, 'MeTKla');
    //manager::stamp("datiFormattatiAT",$datiFormattatiIT);
    //die();
    
    // Inserisci i dati in Tapi
    $log->info("Inserimento dati in Tapi IT");
    $tapiQueryIT = new TapiQuery('IT');
    $tapiQueryIT->truncateTable();
    $tapiQueryIT->inserisciDatiInTapi($datiFormattatiIT);
    /*
    $tapiQueryIT->inserisciDatiInTapi($datiFormattatiFR);
    $tapiQueryIT->inserisciDatiInTapi($datiFormattatiES);
    $tapiQueryIT->inserisciDatiInTapi($datiFormattatiAT);
    */
    /*
    $log->info("Inserimento dati in Tapi AT");
    $tapiQueryAT = new TapiQuery('AT');
    $tapiQueryAT->truncateTable();
    $tapiQueryAT->inserisciDatiInTapi($datiFormattatiIT);
    $tapiQueryAT->inserisciDatiInTapi($datiFormattatiFR);
    $tapiQueryAT->inserisciDatiInTapi($datiFormattatiES);
    $tapiQueryAT->inserisciDatiInTapi($datiFormattatiAT);
    
    $log->info("Inserimento dati in Tapi FR");
    $tapiQueryFR = new TapiQuery('FR');
    $tapiQueryFR->truncateTable();
    $tapiQueryFR->inserisciDatiInTapi($datiFormattatiIT);
    $tapiQueryFR->inserisciDatiInTapi($datiFormattatiFR);
    $tapiQueryFR->inserisciDatiInTapi($datiFormattatiES);
    $tapiQueryFR->inserisciDatiInTapi($datiFormattatiAT);
    
    $log->info("Inserimento dati in Tapi FR");
    $tapiQueryES = new TapiQuery('ES');
    $tapiQueryES->truncateTable();
    $tapiQueryES->inserisciDatiInTapi($datiFormattatiIT);
    $tapiQueryES->inserisciDatiInTapi($datiFormattatiFR);
    $tapiQueryES->inserisciDatiInTapi($datiFormattatiES);
    $tapiQueryES->inserisciDatiInTapi($datiFormattatiAT);
    */
    // --- FINE ---
    $log->info("Procedure MONITOR VOLUMI completate (Approccio Originale).");
    manager::stamp('Procedure MONITOR VOLUMI completate (Approccio Originale).');
    
} catch (PDOException $e) {
    $log->critical("ERRORE PDO: " . $e->getMessage(), ['code' => $e->getCode(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    manager::stamp("!!! ERRORE DATABASE !!!", $e->getMessage());
} catch (Exception $e) {
    $log->critical("ERRORE CRITICO: " . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    manager::stamp("!!! ERRORE CRITICO !!!", $e->getMessage());
} finally {
    $log->info("=============================================\n");
}

?>