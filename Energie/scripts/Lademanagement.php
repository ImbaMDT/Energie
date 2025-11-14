<?php
// Lademanagement mit Fair-Share-Prinzip + Priorisierungszeitraum + Farbige Anzeige

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
$VID_HOUR     = 36160; // Zeitvariable (Stunde 0–23)

$VID_WB1_WR   = 21926; // Wallbox1 write
$VID_WB2_WR   = 24750; // Wallbox2 write
$VID_HEATPUMP = 47195; // Wärmepumpe write

// Parameter
$MAX_HAUS_KW = 60.0;                     // Hausanschlusslimit
$WB_MAX = ['wb1'=>11.0,'wb2'=>11.0];     // Max-Leistung je Wallbox [kW]
$PRIO_START = 8;                         // Priorisierungszeitraum Start
$PRIO_END   = 18;                        // Ende

// Kategorie + Steuer-Variablen prüfen
$catID = @IPS_GetObjectIDByName("Lademanagement", 0);
if ($catID === false) { IPS_LogMessage("Lademanagement","Kategorie fehlt!"); return; }
$varMode = @IPS_GetVariableIDByName("Lademodus", $catID);
$varZeit = @IPS_GetVariableIDByName("Zeitfenster aktiv", $catID);
if ($varMode===false || $varZeit===false){ IPS_LogMessage("Lademanagement","Variablen fehlen!"); return; }

if (IPS_GetVariable($varMode)['VariableCustomAction'] != $_IPS['SELF'])
    IPS_SetVariableCustomAction($varMode, $_IPS['SELF']);
if (IPS_GetVariable($varZeit)['VariableCustomAction'] != $_IPS['SELF'])
    IPS_SetVariableCustomAction($varZeit, $_IPS['SELF']);

// Werte einlesen
$pv     = GetValueFloat($VID_PV);
$house  = GetValueFloat($VID_HOUSE);
$batt   = GetValueFloat($VID_BATT_SOC);
$wb1_rd = GetValueFloat($VID_WB1_RD);
$wb2_rd = GetValueFloat($VID_WB2_RD);
$hour   = (int)GetValue($VID_HOUR);

$mode   = GetValueInteger($varMode);
$zeitfensterAktiv = GetValueBoolean($varZeit);

// Ladeleistung bestimmen (je nach Modus)
$available_kW = 0.0;

switch ($mode) {
    case 1: // PV nur (>=80%)
        if ($batt >= 80) $available_kW = max(0, $pv - $house);
        break;
    case 2: // PV + Speicher (>=20%)
        if ($batt >= 20) $available_kW = max(0, $pv - $house + 5); // Speicherbeitrag 5 kW
        break;
    case 3: // Volllast (Netz erlaubt)
        $available_kW = $MAX_HAUS_KW - $house;
        break;
}
$available_kW = max(0, min($available_kW, $MAX_HAUS_KW - $house));

// Wärmepumpe-Steuerung
$PV_MIN_UEBERSCHUSS = 1.0;  
$BATT_FULL = 95;            
$HEATPUMP_POWER = 3.0;      

$pv_ueberschuss = $pv - $house - $wb1_rd - $wb2_rd;
if ($pv_ueberschuss > $PV_MIN_UEBERSCHUSS && $batt >= $BATT_FULL) {
    RequestAction($VID_HEATPUMP, $HEATPUMP_POWER);
    $heatpump_state = "Ein (".round($HEATPUMP_POWER,1)." kW)";
} else {
    RequestAction($VID_HEATPUMP, 0);
    $heatpump_state = "Aus";
}

// Fair-Share-Verteilung---
$wb_shares = ['wb1'=>0.0,'wb2'=>0.0];
$activeWB = 2; // beide aktiv
if ($activeWB>0) {
    $share = $available_kW / $activeWB;

    // Priorisierung (Kundenparkplatz bevorzugt 08–18 Uhr)
    if ($zeitfensterAktiv && $hour >= $PRIO_START && $hour < $PRIO_END) {
        $prioFactor = 1.5; // 50 % mehr für Kundenparkplatz
        $wb1_share = $share * $prioFactor;
        $wb2_share = $share * (2 - $prioFactor);
    } else {
        $wb1_share = $wb2_share = $share;
    }

    // Begrenzen auf Max-Leistung
    $wb1_share = min($wb1_share, $WB_MAX['wb1']);
    $wb2_share = min($wb2_share, $WB_MAX['wb2']);

    $wb_shares['wb1'] = $wb1_share;
    $wb_shares['wb2'] = $wb2_share;

    // Schreiben
    RequestAction($VID_WB1_WR, $wb1_share);
    RequestAction($VID_WB2_WR, $wb2_share);
}

// Hausanschluss-Limit absichern-----
$totalPower = $house + $wb_shares['wb1'] + $wb_shares['wb2'] + ($heatpump_state=="Aus"?0:$HEATPUMP_POWER);
if ($totalPower > $MAX_HAUS_KW) {
    $factor = $MAX_HAUS_KW / $totalPower;
    foreach ($wb_shares as $k=>$v) $wb_shares[$k]=$v*$factor;
    RequestAction($VID_WB1_WR,$wb_shares['wb1']);
    RequestAction($VID_WB2_WR,$wb_shares['wb2']);
}

// Modusnamen & Farben
switch ($mode) {
    case 1: $modeText = "PV nur (≥80 %)";        $modeColor = "#4CAF50"; break;
    case 2: $modeText = "PV + Speicher (≥20 %)"; $modeColor = "#FFD700"; break;
    case 3: $modeText = "Volllast (Netz erlaubt)"; $modeColor = "#FF8C00"; break;
    default: $modeText = "Unbekannt"; $modeColor = "#999999"; break;
}

//Dashboard-HTML----------
$html  = '<div style="font-family:Segoe UI, sans-serif; padding:10px;">';
$html .= '<h2 style="margin-bottom:10px;">Lademanagement Übersicht (Fair-Share)</h2>';
$html .= '<table style="width:100%; border-collapse:collapse;">';
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

//HTMLBox schreiben-------
$varHTML = @IPS_GetVariableIDByName("Dashboard_HTML", $catID);
if ($varHTML === false) { IPS_LogMessage("Lademanagement","Dashboard_HTML fehlt!"); return; }
SetValueString($varHTML, $html);
?>
