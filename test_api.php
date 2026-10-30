<?php
require 'core/auth.php';
$con = getDbConnection();

$id_sede = 2; 
$start_ts = strtotime("2026-09-01 00:00:00");
$end_ts = strtotime("2026-09-15 23:59:59");

$res = $con->query("SELECT id_negocio FROM lineas_whatsapp WHERE id_sede = $id_sede LIMIT 1");
$row = $res->fetch_assoc();
$waba_id = $row['id_negocio'];

$fields = "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY).dimensions(PRICING_CATEGORY)";
$url = "https://graph.facebook.com/v23.0/$waba_id?fields=$fields";

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $url);
curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . META_GLOBAL_TOKEN, "Content-Type: application/json"]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$raw_exec = curl_exec($curl);
$resp = json_decode($raw_exec, true);
curl_close($curl);

print_r($resp);
