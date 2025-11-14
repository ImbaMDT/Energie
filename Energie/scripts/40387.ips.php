<?php
// ============================================================================
// ⚡ Lademanagement All-In-One (manuelle Variablen + Dummy-kompatibel)
// ============================================================================
/*
// --- Änderungen aus WebFront ------------------------------------------------
if ($_IPS['SENDER'] == "WebFront") {
    SetValue($_IPS['VARIABLE'], $_IPS['VALUE']);
    IPS_RunScript($_IPS['SELF']);
    return;
}

// ============================================================================
// --- IDs deiner Variablen ---------------------------------------------------
$VID_PV       = 57932; // PV-Leistung [kW]
$VID_HOUSE    = 20250; // Hausverbrauch [kW]
$VID_BATT_SOC = 47364; // Batterie-SOC [%]
$VID_WB1_RD   = 35569; // Wallbox1 read [kW]
$VID_WB2_RD   = 45392; // Wallbox2 read [kW]
$VID_HOUR     = 36160; // Zeitvariable (Stunde 0–23)

// Schreibende Variablen (Simulation)
$VID_WB1_WR   = 21926; // Wallbox1 write
$VID_WB2_WR   = 24750; // Wallbox2 write
$VID_HEATPUMP = 47195; // Wärmepumpe write

// Max. Gesamtleistung
$MAX_HAUS = 60.0;

// ============================================================================
// --- Kategorie finden -------------------------------------------------------
$catID = @IPS_GetObjectIDByName("Lademanagement", 0);
if ($catID === false) {
    IPS_LogMessage("Lademanagement", "⚠️ Kategorie 'Lademanagement' nicht gefunden!");
    return;
}

// ============================================================================
// --- Variablen prüfen (keine automatische Erstellung!) ----------------------
$varMode = @IPS_GetVariableIDByName("Lademodus", $catID);
if ($varMode === false) {
    IPS_LogMessage("Lademanagement", "⚠️ Variable 'Lademodus' fehlt!");
    return;
}
$varZeit = @IPS_GetVariableIDByName("Zeitfenster aktiv", $catID);
if ($varZeit === false) {
    IPS_LogMessage("Lademanagement", "⚠️ Variable 'Zeitfenster aktiv' fehlt!");
    return;
}

// --- Eigene Aktion sicherstellen (z. B. bei Dummy-Modulen) ------------------
if (IPS_GetVariable($varMode)['VariableCustomAction'] != $_IPS['SELF']) {
    IPS_SetVariableCustomAction($varMode, $_IPS['SELF']);
}
if (IPS_GetVariable($varZeit)['VariableCustomAction'] != $_IPS['SELF']) {
    IPS_SetVariableCustomAction($varZeit, $_IPS['SELF']);
}

// --- Aktuelle Werte lesen ---------------------------------------------------
$mode = GetValueInteger($varMode);
$zeitfensterAktiv = GetValueBoolean($varZeit);

// ============================================================================
// --- Werte einlesen ---------------------------------------------------------
$pv    = GetValueFloat($VID_PV);
$house = GetValueFloat($VID_HOUSE);
$batt  = GetValueFloat($VID_BATT_SOC);
$wb1_val = GetValueFloat($VID_WB1_RD);
$wb2_val = GetValueFloat($VID_WB2_RD);
$hourRaw = GetValue($VID_HOUR);

// robuste Stunden-Erkennung
if (is_numeric($hourRaw) && $hourRaw >= 0 && $hourRaw < 24) {
    $hour = (int)$hourRaw;
} elseif (is_string($hourRaw) && preg_match('/^(\d{1,2})[:.]/', $hourRaw, $m)) {
    $hour = (int)$m[1];
} elseif (is_numeric($hourRaw) && $hourRaw > 1000000000) {
    $hour = (int)date("G", $hourRaw);
} else {
    $hour = (int)date("G");
}

// ============================================================================
// --- Lademodi ---------------------------------------------------------------
switch ($mode) {
    case 1: // PV nur (≥80%)
        if ($batt >= 80) {
            RequestAction($VID_WB1_WR, 6);
            RequestAction($VID_WB2_WR, 6);
            $wb1_val = 6;
            $wb2_val = 6;
        } else {
            RequestAction($VID_WB1_WR, 0);
            RequestAction($VID_WB2_WR, 0);
            $wb1_val = 0;
            $wb2_val = 0;
        }
        break;

    case 2: // PV+Speicher (≥20%)
        if ($batt >= 20) {
            RequestAction($VID_WB1_WR, 5);
            RequestAction($VID_WB2_WR, 5);
            $wb1_val = 5;
            $wb2_val = 5;
        } else {
            RequestAction($VID_WB1_WR, 0);
            RequestAction($VID_WB2_WR, 0);
            $wb1_val = 0;
            $wb2_val = 0;
        }
        break;

    case 3: // Volllast (Netz ok)
        RequestAction($VID_WB1_WR, 11);
        RequestAction($VID_WB2_WR, 11);
        $wb1_val = 11;
        $wb2_val = 11;
        break;
}

// ============================================================================
// --- Wärmepumpe bei PV-Überschuss & vollem Speicher ------------------------
$PV_MIN_UEBERSCHUSS = 1.0;  
$BATT_FULL = 95;            
$HEATPUMP_POWER = 3.0;      

$pv_ueberschuss = $pv - $house - $wb1_val - $wb2_val;

if ($pv_ueberschuss > $PV_MIN_UEBERSCHUSS && $batt >= $BATT_FULL) {
    RequestAction($VID_HEATPUMP, $HEATPUMP_POWER);
    $heatpump_state = "Ein (" . round($HEATPUMP_POWER,1) . " kW)";
} else {
    RequestAction($VID_HEATPUMP, 0);
    $heatpump_state = "Aus";
}

// ============================================================================
// --- Zeitfenster-Priorisierung ----------------------------------------------
if ($zeitfensterAktiv && $hour >= 8 && $hour < 18) {
    $wb1_val = min($wb1_val + 3, 11);
    $wb2_val = max($wb2_val - 3, 0);
    RequestAction($VID_WB1_WR, $wb1_val);
    RequestAction($VID_WB2_WR, $wb2_val);
    $zeitText = "Aktiv (08–18 Uhr)";
} elseif ($zeitfensterAktiv) {
    $zeitText = "Aktiv (außerhalb Zeitfenster)";
} else {
    $zeitText = "Deaktiviert";
}

// ============================================================================
// --- Gesamtleistung prüfen -------------------------------------------------
$totalPower = $house + $pv + $wb1_val + $wb2_val + ($heatpump_state == "Aus" ? 0 : $HEATPUMP_POWER);
if ($totalPower > $MAX_HAUS) {
    $factor = $MAX_HAUS / $totalPower;
    $wb1_val *= $factor;
    $wb2_val *= $factor;
    RequestAction($VID_WB1_WR, $wb1_val);
    RequestAction($VID_WB2_WR, $wb2_val);
}

// ============================================================================
// --- Dashboard HTML ---------------------------------------------------------
$html  = '<div style="font-family:Segoe UI, sans-serif; padding:10px;">';
$html .= '<h2 style="margin-bottom:10px;">⚡ Lademanagement Übersicht</h2>';
$html .= '<table style="width:100%; border-collapse:collapse;">';
$html .= '<tr><td>PV-Leistung:</td><td><b>'.round($pv,1).' kW</b></td></tr>';
$html .= '<tr><td>Hausverbrauch:</td><td><b>'.round($house,1).' kW</b></td></tr>';
$html .= '<tr><td>Batterie-SOC:</td><td><b>'.round($batt,1).' %</b></td></tr>';
$html .= '<tr><td>Wärmepumpe:</td><td><b>'.$heatpump_state.'</b></td></tr>';
$html .= '<tr><td>Wallbox 1:</td><td><b>'.round($wb1_val,1).' kW</b></td></tr>';
$html .= '<tr><td>Wallbox 2:</td><td><b>'.round($wb2_val,1).' kW</b></td></tr>';
$html .= '<tr><td>Gesamtleistung:</td><td><b>'.round($totalPower,1).' kW</b></td></tr>';
$html .= '<tr><td>Lademodus:</td><td><b>'.$mode.'</b></td></tr>';
$html .= '<tr><td>Zeitfenster:</td><td><b>'.$zeitText.'</b></td></tr>';
$html .= '</table>';
$html .= '<div style="margin-top:6px; font-size:11px; color:gray;">Stand: '.date("d.m.Y H:i").' Uhr</div>';
$html .= '</div>';

// ============================================================================
// --- HTMLBox aktualisieren -------------------------------------------------
$varHTML = @IPS_GetVariableIDByName("Dashboard_HTML", $catID);
if ($varHTML === false) {
    IPS_LogMessage("Lademanagement", "⚠️ Dashboard_HTML fehlt!");
    return;
}
SetValueString($varHTML, $html);
?>
*/