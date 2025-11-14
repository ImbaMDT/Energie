<?php
// Verbrauchsberechnung
$batterie = GetValueFloat(36586);
$grid = GetValueFloat(17862);
$headpump = GetValueFloat(59020);
$house = GetValueFloat(20250);
$wallbox0 = GetValueFloat(35569);
$wallbox1 = GetValueFloat(13709);
$wallbox2 = GetValueFloat(45392);

$pv = GetValueFloat(57932);

$verbrauch = $batterie + $grid + $headpump + $house + $wallbox0 + $wallbox1 + $wallbox2 - $pv;

SetValueFloat(42211, $verbrauch);