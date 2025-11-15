<?php
/*
Lademanagement mit Fair-Share-Prinzip + Priorisierungszeitraum + Farbige Anzeige
Programmierer: Mike Dorr
Projekt: HVG241 Meisterprüfung
*/

// Änderungen aus WebFront
if ($_IPS['SENDER'] == "WebFront") {
    SetValue($_IPS['VARIABLE'], $_IPS['VALUE']);
    IPS_RunScript($_IPS['SELF']);
    return;
}

// IDs deiner Variablen
$VID_PV       = 57932; // PV-Leistung [kW]
$VID_HOUSE    = 20250; // Hausverbrauch [kW]
$VID_BATT_SOC = 47364; // Batterie-SOC [%]
$VID_WB1_RD   = 35569; // Wallbox1 read [kW]
$VID_WB2_RD   = 45392; // Wallbox2 read [kW]
$VID_WB1_CONNECTED = 12345; // Neue Variable: Wallbox 1 verbunden (bool)
$VID_WB2_CONNECTED = 23456; // Neue Variable: Wallbox 2 verbunden (bool)


$VID_WB1_WR   = 21926; // Wallbox1 write
$VID_WB2_WR   = 24750; // Wallbox2 write
$VID_HEATPUMP = 47195; // Wärmepumpe write

$MAX_HAUS_KW = 60.0;
$WB_MAX = ['wb1'=>11.0,'wb2'=>11.0];
$PRIO_START = 8;
$PRIO_END   = 18;

// Kategorie + Variablen prüfen/erstellen
$catID = @IPS_GetObjectIDByName("Lademanagement", 0);
if ($catID === false) {
    $catID = IPS_CreateCategory();
    IPS_SetName($catID, "Lademanagement");
    IPS_SetParent($catID, 0);
}

function ensureVariable($name, $type, $catID, $profile = "", $actionScript = null) {
    $vid = @IPS_GetVariableIDByName($name, $catID);
    if ($vid === false) {
        $vid = IPS_CreateVariable($type);
        IPS_SetParent($vid, $catID);
        IPS_SetName($vid, $name);
        if ($profile !== "") {
            IPS_SetVariableCustomProfile($vid, $profile);
        }
        if (!is_null($actionScript)) {
            IPS_SetVariableCustomAction($vid, $actionScript);
        }
        IPS_LogMessage("Lademanagement", "Variable '$name' wurde automatisch erstellt (ID: $vid)");
    }
    return $vid;
}

$selfID  = $_IPS['SELF'];
$varMode = ensureVariable("Lademodus", 1, $catID, "~Switch", $selfID);
$varZeit = ensureVariable("Zeitfenster aktiv", 0, $catID, "~Switch", $selfID);
$varHTML = ensureVariable("Dashboard_HTML", 3, $catID, "~HTMLBox");

// Werte einlesen
$pv     = GetValueFloat($VID_PV);
$house  = GetValueFloat($VID_HOUSE);
$batt   = GetValueFloat($VID_BATT_SOC);
$wb1_rd = GetValueFloat($VID_WB1_RD);
$wb2_rd = GetValueFloat($VID_WB2_RD);
$hour = (int)date("G"); // Holt die aktuelle Stunde (0–23) direkt vom System
$wb1_connected = GetValueBoolean($VID_WB1_CONNECTED); // $wb1_connected = (GetValueInteger($VID_WB1_CONNECTED) === 1);
$wb2_connected = GetValueBoolean($VID_WB2_CONNECTED);

$mode   = GetValueInteger($varMode);
$zeitfensterAktiv = GetValueBoolean($varZeit);

// Ladeleistung bestimmen
$available_kW = 0.0;

switch ($mode) {
    case 1:
        if ($batt >= 80) $available_kW = max(0, $pv - $house);
        break;
    case 2:
        if ($batt >= 20) $available_kW = max(0, $pv - $house + 5);
        break;
    case 3:
        $available_kW = $MAX_HAUS_KW - $house;
        break;
}
$available_kW = max(0, min($available_kW, $MAX_HAUS_KW - $house));

// Wärmepumpe
$PV_MIN_UEBERSCHUSS = 1.0;  
$BATT_FULL = 95;            
$HEATPUMP_POWER = 3.0;      

$pv_ueberschuss = $pv - $house - $wb1_rd - $wb2_rd;
if ($pv_ueberschuss > $PV_MIN_UEBERSCHUSS && $batt >= $BATT_FULL) {
    RequestAction($VID_HEATPUMP, $HEATPUMP_POWER);
    $heatpump_state = "Ein (".round($HEATPUMP_POWER,1)." kW)";
} else {
    $heatpump_state = "Aus";
}

// Fair-Share-Verteilung
$wb_shares = ['wb1'=>0.0,'wb2'=>0.0];

$activeWB = 0;

if ($wb1_connected) $activeWB++; // $activeWB = ($wb1_connected ? 1 : 0) + ($wb2_connected ? 1 : 0); 
if ($wb2_connected) $activeWB++;

if ($activeWB>0) {
    $share = $available_kW / $activeWB;

    if ($zeitfensterAktiv && $hour >= $PRIO_START && $hour < $PRIO_END) {
        $prioFactor = 1.5;
        $wb1_share = $share * $prioFactor;
        $wb2_share = $share * (2 - $prioFactor);
    } else {
        $wb1_share = $wb2_share = $share;
    }

    $wb1_share = min($wb1_share, $WB_MAX['wb1']);
    $wb2_share = min($wb2_share, $WB_MAX['wb2']);

    $wb_shares['wb1'] = $wb1_share;
    $wb_shares['wb2'] = $wb2_share;
} else {
    RequestAction($VID_WB1_WR, 0);
    RequestAction($VID_WB2_WR, 0);
    IPS_LogMessage("Lademanagement", "Keine Wallbox verbunden – kein Ladevorgang.");
}

// Hausanschluss absichern
$totalPower = $house + $wb_shares['wb1'] + $wb_shares['wb2'] + ($heatpump_state=="Aus"?0:$HEATPUMP_POWER);
if ($totalPower > $MAX_HAUS_KW) {
    $factor = $MAX_HAUS_KW / $totalPower;
    foreach ($wb_shares as $k=>$v) $wb_shares[$k]=$v*$factor;
    if ($wb1_connected) RequestAction($VID_WB1_WR,$wb_shares['wb1']);
    if ($wb2_connected) RequestAction($VID_WB2_WR,$wb_shares['wb2']);
}

// Modusbeschreibung
switch ($mode) {
    case 1: $modeText = "PV nur (≥80 %)";        $modeColor = "#4CAF50"; break;
    case 2: $modeText = "PV + Speicher (≥20 %)"; $modeColor = "#FFD700"; break;
    case 3: $modeText = "Volllast (Netz erlaubt)"; $modeColor = "#FF8C00"; break;
    default: $modeText = "Unbekannt"; $modeColor = "#999999"; break;
}

// Dashboard erzeugen
$html  = '<div style="font-family:Segoe UI, sans-serif; padding:10px;">';
$html .= '<h2 style="margin-bottom:10px;">⚡ Lademanagement Übersicht (Fair-Share)</h2>';
$html .= '<table style="width:100%; border-collapse:collapse;">';
$html .= "<tr><td>Systemzeit:</td><td><b>".date("H:i")." Uhr</b></td></tr>";
$html .= "<tr><td>PV-Leistung:</td><td><b>".round($pv,1)." kW</b></td></tr>";
$html .= "<tr><td>Hausverbrauch:</td><td><b>".round($house,1)." kW</b></td></tr>";
$html .= "<tr><td>Batterie-SOC:</td><td><b>".round($batt,1)."%</b></td></tr>";
$html .= "<tr><td>Wärmepumpe:</td><td><b>".$heatpump_state."</b></td></tr>";
$html .= "<tr><td>Wallbox 1 (Kundenparkplatz):</td><td><b>".round($wb_shares['wb1'],1)." kW</b></td></tr>";
$html .= "<tr><td>Wallbox 2:</td><td><b>".round($wb_shares['wb2'],1)." kW</b></td></tr>";
$html .= "<tr><td>Gesamtleistung:</td><td><b>".round($totalPower,1)." kW</b></td></tr>";
$html .= "<tr><td>Lademodus:</td><td><b style='color:$modeColor;'>".$modeText."</b></td></tr>";
$html .= "<tr><td>Zeitfenster:</td><td><b>".($zeitfensterAktiv?($hour>=$PRIO_START&&$hour<$PRIO_END?'Aktiv (08–18 Uhr)':'Aktiv (außerhalb)'):'Deaktiviert')."</b></td></tr>";
$html .= "</table>";
$html .= '<div style="margin-top:6px; font-size:11px; color:gray;">Stand: '.date("d.m.Y H:i").' Uhr</div>';
$html .= '</div>';

SetValueString($varHTML, $html);
?>
