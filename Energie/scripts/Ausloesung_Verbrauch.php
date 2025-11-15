<?php
// ------------------ Konfiguration ------------------
$categoryID     = 32805;  // ID der Kategorie "Verbraucher"
$verbrauchScriptID = 14766;  // ID des Hauptskripts "Verbrauch"
$energiemanagementScriptID = 38851; // ID des Hauptskripts "Energiemanagement"

// ------------------ Alte Ereignisse löschen ------------------
foreach (IPS_GetChildrenIDs($verbrauchScriptID) as $eid) {
    if (IPS_GetObject($eid)['ObjectType'] == 4) {
        IPS_DeleteEvent($eid);
    }
}
foreach (IPS_GetChildrenIDs($energiemanagementScriptID) as $eid) {
    if (IPS_GetObject($eid)['ObjectType'] == 4) {
        IPS_DeleteEvent($eid);
    }
}

// ------------------ Neue Ereignisse anlegen ------------------
$anzahl = 0;
foreach (IPS_GetChildrenIDs($categoryID) as $vid) {
    if (IPS_VariableExists($vid)) {
        $eid = IPS_CreateEvent(0); // 0 = Bei Änderung
        IPS_SetEventTrigger($eid, 0, $vid); // 0 = OnChange
        IPS_SetParent($eid, $verbrauchScriptID);
        IPS_SetEventActive($eid, true);
        $anzahl++;
    }
}
foreach (IPS_GetChildrenIDs($categoryID) as $vid) {
    if (IPS_VariableExists($vid)) {
        $eid = IPS_CreateEvent(0); // 0 = Bei Änderung
        IPS_SetEventTrigger($eid, 0, $vid); // 0 = OnChange
        IPS_SetParent($eid, $energiemanagementScriptID);
        IPS_SetEventActive($eid, true);
        $anzahl++;
    }
}

echo "Ereignisse für $anzahl Variablen unter 'Verbraucher' mit Skript $verbrauchScriptID &  Energiemanagement mit Skript $energiemanagementScriptID verknüpft.\n";
?>
