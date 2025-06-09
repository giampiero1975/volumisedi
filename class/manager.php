<?php

class manager
{
    public static function cleanLogs()
    {
        if (file_exists('file.log'))
            unlink('file.log');

        if (file_exists('timesheet.log'))
            unlink('timesheet.log');
            
        if (file_exists('synthesys.log'))
            unlink('synthesys.log');
        
        if (file_exists('ddi_relator_DEBUG_LOOKUP.log'))
            unlink('ddi_relator_DEBUG_LOOKUP.log');
        
        if (file_exists('tapi_query.log'))
            unlink('tapi_query.log');
    }
    
    public static function stamp($titolo=null, $text=null){
        echo "<pre>";
        if(!empty($titolo)){
            echo "<strong>".$titolo."</strong>";
        }
        if(!empty($titolo) && !empty($text))
            echo "<strong>: </strong>";
        
        if (is_array($text)) {
            print_r($text); // Stampa l'array in modo leggibile
        } else {
            echo $text; // Stampa il dato se non è un array
        }
        echo "</pre>"; 
    }
}
?>