<?php
/*
    Archivierer
    - Archiviert automatisch alle Großverbraucher
    Programmierer: Mike Dorr
    Projekt: HVG241 Meisterprüfung
*/

// Konfiguration
$logVars = [
    21926, // Wallbox1_Ladeleistung
    24750, // Wallbox2_Ladeleistung
    47195, // Wärmepumpe
    57932, // PV-Leistung
    20250, // Hausverbrauch
    99999  // Heizstab (hier bitte echte ID eintragen)
];

// Archiv-Instanz ermitteln
$archiveID = @IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];
if (!$archiveID) {
    echo "Archiv-Instanz nicht gefunden!\n";
    return;
}

// Logging aktivieren
foreach ($logVars as $vid) {
    if (IPS_VariableExists($vid)) {
        AC_SetLoggingStatus($archiveID, $vid, true);
        AC_SetAggregationType($archiveID, $vid, 1); // Durchschnitt
        echo "Logging aktiviert für ID $vid\n";
    } else {
        echo "Variable mit ID $vid existiert nicht\n";
    }
}

// Änderungen anwenden
IPS_ApplyChanges($archiveID);
