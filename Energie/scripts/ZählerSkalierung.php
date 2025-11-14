<?php
// 	Zähler_Skalierung
//  - verwendet reale Leistungswerte (kW) der Referenzvariable
//  - skaliert anteilig auf Z1–Z8
//  - erzeugt sauberes Dashboard mit HTML-Tabelle

// IDs deiner Variablen
$REF_VAR_ID = 35210;  // Referenz (Messwandler-Leistung gesamt)
$catName = "Messkonzept_Skalierung_Historie";
$parentID = 0;

// Prozentuale Aufteilung (Summe = 100 %)
$distribution = [
    'Meter1'=>25, 'Meter2'=>12, 'Meter3'=>12,
    'Meter4'=>12, 'Meter5'=>12, 'Meter6'=>15
];

$labels = [
    'Meter1'=>'Z1_Mieter1_WB1_WB2 [kW]',
    'Meter2'=>'Z2_Mieter2_1OG [kW]',
    'Meter3'=>'Z3_Mieter3_1OG [kW]',
    'Meter4'=>'Z4_Mieter4_2OG [kW]',
    'Meter5'=>'Z5_Mieter5_2OG [kW]',
    'Meter6'=>'Z6_WP_und_Allgemein [kW]',
    'Meter7'=>'Z7_Zweirichtungs_Bilanz [kW]',
    'Meter8'=>'Z8_Messwandler_Haupt [kW]'
];

// Kategorie prüfen/erstellen
$catID = @IPS_GetObjectIDByName($catName, $parentID);
if ($catID === false) {
    $catID = IPS_CreateCategory();
    IPS_SetName($catID, $catName);
    IPS_SetParent($catID, $parentID);
}

// Variablen prüfen/erstellen
$varIDs = [];
foreach ($labels as $key => $label) {
    $vid = @IPS_GetVariableIDByName($label, $catID);
    if ($vid === false) {
        $vid = IPS_CreateVariable(2); // Float
        IPS_SetParent($vid, $catID);
        IPS_SetName($vid, $label);
        IPS_SetVariableCustomProfile($vid, "~Power"); // kW
    }
    $varIDs[$key] = $vid;
}

// HTMLBox anlegen
$htmlID = @IPS_GetVariableIDByName("Dashboard_HTML", $catID);
if ($htmlID === false) {
    $htmlID = IPS_CreateVariable(3); // String
    IPS_SetParent($htmlID, $catID);
    IPS_SetName($htmlID, "Dashboard_HTML");
    IPS_SetVariableCustomProfile($htmlID, "~HTMLBox");
}

// Archiv holen
$archiveID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];
foreach ($varIDs as $id) {
    AC_SetLoggingStatus($archiveID, $id, true);
    AC_SetAggregationType($archiveID, $id, 1);
}
IPS_ApplyChanges($archiveID);

// Skalierung
$val = GetValueFloat($REF_VAR_ID);
if ($val > 0) {
    $sumSub = 0;
    foreach ($distribution as $key => $pct) {
        $scaled = $val * ($pct / 100);
        SetValueFloat($varIDs[$key], $scaled);
        $sumSub += $scaled;
    }
    $bal = $val - $sumSub;
    SetValueFloat($varIDs['Meter7'], $bal);
    SetValueFloat($varIDs['Meter8'], $val);
} else {
    IPS_LogMessage("Lademanagement", "Referenzwert = 0 kW (ID $REF_VAR_ID)");
}

// Dashboard aufbauen
$html  = '<div style="font-family:Segoe UI, sans-serif; padding:10px;">';
$html .= '<h2>Zählerübersicht (live)</h2>';
$html .= '<table style="width:100%; border-collapse:collapse;">';
foreach ($labels as $key => $name) {
    $html .= sprintf(
        '<tr><td style="padding:4px 8px;">%s</td><td style="text-align:right;"><b>%.2f kW</b></td></tr>',
        $name, GetValueFloat($varIDs[$key])
    );
}
$html .= '</table>';
$html .= '<div style="margin-top:6px; font-size:11px; color:gray;">Stand: '.date("d.m.Y H:i:s").'</div>';
$html .= '</div>';

SetValueString($htmlID, $html);

// Ausgabe
echo "Zählerdaten aktualisiert um ".date("H:i:s")." (Referenz: $val kW)\n";
?>
