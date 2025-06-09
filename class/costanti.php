<?php

class Costanti
{
    public static function TAPI_IT(): array
    {
        return [
            'host' => '192.168.10.122',
            'user' => 'tapi_it',
            'pass' => '9Gc87nGuHq7M',
            'dbname' => 'tapi_it',
        ];
    }
    
    // In seguito aggiungerai i metodi statici per ES, AT e FR
    public static function TAPI_ES(): array
    {
        return [
            'host' => '192.168.10.250',
            'user' => 'tapi_es',
            'pass' => '9Gc87nGuHq7M',
            'dbname' => 'tapi_es',
        ];
    }
    
    public static function TAPI_AT(): array
    {
        return [
            'host' => '192.168.12.122',
            'user' => 'tapi_at',
            'pass' => '9Gc87nGuHq7M',
            'dbname' => 'tapi_at',
        ];
    }
    
    public static function TAPI_FR(): array
    {
        return [
            'host' => '192.168.12.250',
            'user' => 'tapi_fr',
            'pass' => '9Gc87nGuHq7M',
            'dbname' => 'tapi_fr',
        ];
    }
}
?>