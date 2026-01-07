<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: POST, OPTIONS");
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(0);

$rawPost = file_get_contents('php://input');
$postData = json_decode($rawPost, true);

if (!is_array($postData)) {
    echo json_encode(['estatus' => '0', 'mensaje' => 'Payload inválido']);
    exit();
}

$Salid = isset($postData['salidEnt']) ? intval($postData['salidEnt']) : 0;
$empleadoIDDB = isset($postData['empleadoID']) ? intval($postData['empleadoID']) : 0;
$evidencias = isset($postData['evidencias']) && is_array($postData['evidencias']) ? $postData['evidencias'] : array();
$notifyJefe = isset($postData['notifyJefe']) ? boolval($postData['notifyJefe']) : true;
$motivo = isset($postData['motivo']) ? $postData['motivo'] : '';

if ($Salid <= 0 || $empleadoIDDB <= 0) {
    echo json_encode(['estatus' => '0', 'mensaje' => 'salidEnt y empleadoID son requeridos']);
    exit();
}

// Usar macros ScriptCase para guardar evidencias
try {
    $guardadas = 0;
    $detalles = array();

    foreach ($evidencias as $ev) {
        $ev_filename = isset($ev['filename']) ? addslashes($ev['filename']) : 'evidence_' . time() . '.jpg';
        $ev_base64 = isset($ev['base64']) ? $ev['base64'] : '';
        $ev_mimetype = isset($ev['mimetype']) ? addslashes($ev['mimetype']) : 'image/jpeg';
        if (empty($ev_base64)) continue;

        $ev_filesize = strlen(base64_decode($ev_base64));
        $ev_hash = hash('sha256', $ev_base64);

        // Evitar duplicados
        sc_lookup(check_rs, "SELECT evidenciaID FROM tb_evidencias WHERE hash = '$ev_hash' LIMIT 1");
        if (!empty($check_rs[0][0])) {
            $existing = $check_rs[0][0];
            $detalles[] = array('evidenceID' => $existing, 'filename' => $ev_filename, 'status' => 'duplicate');
            continue;
        }

        $sql_evidencia = "INSERT INTO tb_evidencias (salidEnt, empleadoID, filename, mimetype, filesize, storage_type, payload_base64, hash, creado) VALUES ($Salid, $empleadoIDDB, '$ev_filename', '$ev_mimetype', $ev_filesize, 'db', '" . addslashes($ev_base64) . "', '$ev_hash', NOW())";
        sc_exec_sql($sql_evidencia);

        sc_lookup(last_rs, "SELECT last_insert_id()");
        $inserted_id = !empty($last_rs[0][0]) ? $last_rs[0][0] : null;
        $detalles[] = array('evidenceID' => $inserted_id, 'filename' => $ev_filename, 'status' => 'saved');
        $guardadas++;
    }

    $response = ['estatus' => '1', 'mensaje' => 'Evidencias guardadas', 'guardadas' => $guardadas, 'details' => $detalles];
    echo json_encode($response);
    exit();

} catch (Exception $ex) {
    echo json_encode(['estatus' => '0', 'mensaje' => 'Error interno al guardar', 'detalle' => $ex->getMessage()]);
    exit();
}
