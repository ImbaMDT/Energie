<?php
/*
    Energiemonitoring mit Verlauf
    - Verbrauchsberechnung
    - Prüfungen
    - Verlauf mit Statuswechsel‑Erkennung oder geänderten Details
*/

// ------------------ Konfiguration ------------------
$vid_batt     = 53609;
$vid_headpump = 42994;
$vid_house    = 37163;
$vid_wb1      = 17849;
$vid_wb2      = 12904;
$vid_grid     = 55216;
$vid_pv       = 37381;
$vid_wb1_conn = 11167;
$vid_wb2_conn = 31450;
$vid_result   = 56622;

$anschluss_max = 60.0;
$toleranz      = 0.01;
$max_entries   = 50;

$var_log       = "Energie-Verlauf";
$stateVarName  = "Energie_StatusIntern";

// ------------------ Initialisierung ------------------
$selfID   = $_IPS['SELF'];
$parentID = IPS_GetObject($selfID)['ParentID'];

// Unterkategorie für Systemvariablen
$monitorCatName = "Energie-Monitoring";
$monitorCatID   = @IPS_GetObjectIDByName($monitorCatName, $parentID);
if ($monitorCatID === false) {
    $monitorCatID = IPS_CreateCategory();
    IPS_SetName($monitorCatID, $monitorCatName);
    IPS_SetParent($monitorCatID, $parentID);
}

// ------------------ Variablen korrekt verwalten ------------------
function getOrCreateVariable(string $name, int $type, int $parentID, string $profile = "") {
    foreach (IPS_GetChildrenIDs($parentID) as $cid) {
        if (IPS_VariableExists($cid) && IPS_GetName($cid) === $name) {
            return $cid;
        }
    }
    $vid = IPS_CreateVariable($type);
    IPS_SetName($vid, $name);
    IPS_SetParent($vid, $parentID);
    if ($profile !== "") {
        IPS_SetVariableCustomProfile($vid, $profile);
    }
    return $vid;
}

$stateID = getOrCreateVariable($stateVarName, 1, $monitorCatID);
$vid_log = getOrCreateVariable($var_log, 3, $monitorCatID, "~HTMLBox");

// ------------------ Werte einlesen ------------------
$batterie  = GetValueFloat($vid_batt);
$headpump  = GetValueFloat($vid_headpump);
$house     = GetValueFloat($vid_house);
$wallbox1  = GetValueFloat($vid_wb1);
$wallbox2  = GetValueFloat($vid_wb2);
$grid      = GetValueFloat($vid_grid);
$pv        = GetValueFloat($vid_pv);
$wb1_conn  = GetValueBoolean($vid_wb1_conn);
$wb2_conn  = GetValueBoolean($vid_wb2_conn);

// Verbrauch berechnen
$verbrauch = $batterie + $headpump + $house + $wallbox1 + $wallbox2 - $pv;
SetValueFloat($vid_result, $verbrauch);

$delta        = $grid - $verbrauch;
$gesamt_last  = $house + $headpump + $wallbox1 + $wallbox2;
$f_verbrauch  = number_format($verbrauch, 2, ',', '.') . ' kW';
$f_grid       = number_format($grid, 2, ',', '.') . ' kW';
$f_delta      = number_format(abs($delta), 2, ',', '.') . ' kW';
$aktZeit      = date("d.m.Y H:i:s");

// ------------------ Statusbestimmung ------------------
if (abs($delta) <= $toleranz) {
    $shortStatus = "OK";
    $color       = "green";
    $currentState = 0;
} else {
    $richtung     = ($delta > 0) ? "Weniger Verbrauch als Netzbezug" : "Mehr Verbrauch als Netzbezug";
    $shortStatus  = "Fehler";
    $color        = "red";
    $currentState  = 1;
}

$delta_html = "<span style='color:$color;'>Δ = $f_delta" . ($shortStatus === "Fehler" ? " ($richtung)" : "") . "</span>";

// ------------------ Prüfungen ------------------
$checks = [];
if ($headpump < 0 || $house < 0 || $wallbox1 < 0 || $wallbox2 < 0) {
    $checks[] = "Negative Leistungswerte erkannt";
}
if ($pv > 0.5 && $batterie <= 0 && $grid <= 0) {
    $checks[] = "PV aktiv, aber weder Netz- noch Batteriespeisung";
}
if ($house < 0.1) {
    $checks[] = "Hausverbrauch extrem niedrig";
}
if ($gesamt_last > $anschluss_max) {
    $checks[] = "Gesamtlast überschreitet $anschluss_max kW";
}
if (!$wb1_conn && $wallbox1 > 0.1) {
    $checks[] = "WB1 liefert Leistung, obwohl nicht verbunden";
}
if (!$wb2_conn && $wallbox2 > 0.1) {
    $checks[] = "WB2 liefert Leistung, obwohl nicht verbunden";
}
if (empty($checks) && $currentState === 1) {
    $checks[] = "Differenz (Netzbezug minus Gesamtverbrauch) überschreitet Toleranz (Δ = $f_delta)";
}

$checkText = implode("<br>", $checks ?: ["✓"]);

// ------------------ Verlauf bei Statuswechsel oder geänderten Details ------------------
$logHTML = GetValueString($vid_log);
$prevState = GetValueInteger($stateID);
$updateNeeded = ($currentState !== $prevState);

// Prüfe letzten Eintrag: Status oder Detail geändert?
if (!$updateNeeded) {
    if (strpos($logHTML, '<table') === false) {
        $updateNeeded = true;
    } elseif (preg_match('/<tr><td>.*?<\/td><td.*?>(.*?)<\/td><td>(.*?)<\/td><\/tr>/', $logHTML, $match)) {
        $lastStatus  = strip_tags($match[1]);
        $lastDetails = strip_tags($match[2]);
        if ($lastStatus !== $shortStatus || $lastDetails !== strip_tags($checkText)) {
            $updateNeeded = true;
        }
    }
}

if ($updateNeeded) {
    SetValueInteger($stateID, $currentState);
    $entry = "<tr><td>$aktZeit</td><td style='color:$color;'><b>$shortStatus</b></td><td>$checkText</td></tr>";

    $rows = [];
    if (strpos($logHTML, '<table') !== false) {
        preg_match_all('/<tr><td>(.*?)<\/td><td.*?>(.*?)<\/td><td>(.*?)<\/td><\/tr>/', $logHTML, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (count($match) >= 4) {
                $rows[] = "<tr><td>{$match[1]}</td><td style='color:" . (strpos($match[2], "Fehler") !== false ? "red" : "green") . ";'><b>{$match[2]}</b></td><td>{$match[3]}</td></tr>";
            }
        }
    }

    array_unshift($rows, $entry);
    $rows     = array_slice($rows, 0, $max_entries);
    $tableRows = implode('', $rows);

    $logHTML = <<<HTML
<div style="font-family:Segoe UI, sans-serif; padding:10px;">
    <table style="width:100%; border-collapse:collapse; table‑layout:auto;">
        $tableRows
    </table>
    <div style="font‑size:11px; color:gray;">Stand: $aktZeit</div>
</div>
HTML;

    SetValueString($vid_log, $logHTML);
}
?>
