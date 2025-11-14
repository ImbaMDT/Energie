<?php
// Automatische Zähler-Visualisierung
//  - erkennt alle Zähler-Variablen automatisch (beginnend mit "Z")
//  - erstellt Charts mit gültigen Dateinamen
//  - zeigt sie nebeneinander im HTML-Grid-Dashboard

// Logging sicherstellen
$archiveID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];
foreach ($vars as $vid) {
    AC_SetLoggingStatus($archiveID, $vid, true);
    AC_SetAggregationType($archiveID, $vid, 1);
}
IPS_ApplyChanges($archiveID);

$catName = "Messkonzept_Skalierung_Historie";
$chartCatName = "Zähler-Charts";

// Kategorie prüfen
$catID = @IPS_GetObjectIDByName($catName, 0);
if ($catID === false) {
    echo "Kategorie '$catName' nicht gefunden!\n";
    return;
}

// Alle Zähler-Variablen automatisch finden (beginnt mit "Z")
$vars = [];
foreach (IPS_GetChildrenIDs($catID) as $cid) {
    $obj = IPS_GetObject($cid);
    if ($obj['ObjectType'] == 2) { // Variable
        $name = $obj['ObjectName'];
        if (preg_match('/^Z\d+_/', $name)) {
            $vars[$name] = $cid;
        }
    }
}

if (empty($vars)) {
    echo "Keine Zähler-Variablen gefunden! (Erwarte Namen wie 'Z1_', 'Z2_', ...)\n";
    return;
}

// Kategorie für Charts prüfen/erstellen
$chartCatID = @IPS_GetObjectIDByName($chartCatName, $catID);
if ($chartCatID === false) {
    $chartCatID = IPS_CreateCategory();
    IPS_SetParent($chartCatID, $catID);
    IPS_SetName($chartCatID, $chartCatName);
}

// Archiv-Instanz-ID holen
$archiveID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];

// Charts erzeugen oder aktualisieren
foreach ($vars as $name => $vid) {
    $chartName = "Chart_" . $name;
    $chartID = @IPS_GetObjectIDByName($chartName, $chartCatID);

    if ($chartID === false) {
        $chartID = IPS_CreateMedia(1); // Chart
        IPS_SetParent($chartID, $chartCatID);
        IPS_SetName($chartID, $chartName);
        IPS_SetMediaFile($chartID, preg_replace('/[^A-Za-z0-9_\-]/', '_', $chartName) . ".json", false);
    }

    $config = [
        "type" => "Line",
        "title" => $name,
        "axes" => [
            [
                "id" => 0,
                "unit" => "kW",
                "caption" => "Leistung",
                "minValue" => 0
            ]
        ],
        "datasets" => [
            [
                "variableID" => $vid,
                "color" => sprintf("#%06X", mt_rand(0, 0xFFFFFF)),
                "axis" => 0
            ]
        ],
        "timeRange" => 3600 * 24, // 24h
        "showLegend" => false,
        "showGrid" => true
    ];

    IPS_SetMediaContent($chartID, json_encode($config));
    IPS_SetMediaCached($chartID, true);
}

// Grid-Dashboard erzeugen
$htmlName = "Zähler_Grid_Dashboard";
$htmlID = @IPS_GetVariableIDByName($htmlName, $catID);
if ($htmlID === false) {
    $htmlID = IPS_CreateVariable(3); // String
    IPS_SetParent($htmlID, $catID);
    IPS_SetName($htmlID, $htmlName);
    IPS_SetVariableCustomProfile($htmlID, "~HTMLBox");
}

// Charts einsammeln
$charts = [];
foreach (IPS_GetChildrenIDs($chartCatID) as $cid) {
    $obj = IPS_GetObject($cid);
    if ($obj['ObjectType'] == 8) { // Media
        $charts[] = [
            'name' => $obj['ObjectName'],
            'id' => $cid
        ];
    }
}

if (empty($charts)) {
    echo "⚠️ Keine Charts gefunden!\n";
    return;
}

// HTML-GRID erstellen
$html  = '<div style="font-family:Segoe UI, sans-serif; padding:10px;">';
$html .= '<h2 style="margin-bottom:10px;">Zähler-Visualisierung (24h)</h2>';
$html .= '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:10px;">';

foreach ($charts as $chart) {
    $mid = $chart['id'];
    $name = htmlspecialchars($chart['name']);
    $chartHTML = "<iframe src='media/$mid' width='100%' height='200' frameborder='0'></iframe>";

    $html .= "<div style='border:1px solid #ccc; border-radius:8px; padding:8px; background:#f9f9f9; box-shadow:2px 2px 6px rgba(0,0,0,0.1);'>
                <div style='font-weight:bold; margin-bottom:4px;'>$name</div>
                $chartHTML
              </div>";
}

$html .= '</div>';
$html .= '<div style="margin-top:10px; font-size:11px; color:gray;">Stand: '.date("d.m.Y H:i:s").'</div>';
$html .= '</div>';

SetValueString($htmlID, $html);

echo "Alle Zählercharts und Grid-Dashboard erfolgreich erstellt!\n";
?>
