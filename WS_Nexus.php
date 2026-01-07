header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ============================================================
// FUNCIÓN PARA GENERAR URL DE VALIDACIÓN SEGURA (SIN TABLA)
// ============================================================

function generarUrlValidacion($salidEnt, $empleadoID, $jefeID) {
	// Clave secreta para firmar URLs (cambiar en producción)
	$secret_key = 'bsys_geocerca_2025_secret_key';
	
	// Generar hash de seguridad (previene manipulación de parámetros)
	$data_to_hash = $salidEnt . '|' . $empleadoID . '|' . $jefeID . '|' . $secret_key;
	$hash = substr(hash('sha256', $data_to_hash), 0, 16); // 16 caracteres
	
	// Construir URL con parámetros
	$url = "https://dev.bsys.mx/scriptcase/app/Gilneas/validar_geocerca/validar_geocerca.php";
	$url .= "?s=" . $salidEnt;      // salidEnt (ID del registro)
	$url .= "&e=" . $empleadoID;    // empleadoID
	$url .= "&j=" . $jefeID;        // jefeID (quien valida)
	$url .= "&h=" . $hash;          // hash de seguridad
	
	error_log("URL validación generada: $url");
	return $url;
}

// ============================================================
// FUNCIÓN PARA VALIDAR HASH DE SEGURIDAD (en vista de validación)
// ============================================================

function validarHashSeguridad($salidEnt, $empleadoID, $jefeID, $hash) {
	$secret_key = 'bsys_geocerca_2025_secret_key';
	$data_to_hash = $salidEnt . '|' . $empleadoID . '|' . $jefeID . '|' . $secret_key;
	$hash_esperado = substr(hash('sha256', $data_to_hash), 0, 16);
	return ($hash === $hash_esperado);
}

// ============================================================
// FUNCIÓN DE VALIDACIÓN DE GEOCERCAS
// ============================================================

function validarGeocerca($empleadoID, $latitud, $longitud) {
    // Inicializar resultado por defecto
    $resultado = array(
        'geocercaID' => null,
        'distanciaMetros' => null,
        'validacion' => 'Sin_Geocerca',
        'mensaje' => 'Sin zona asignada'
    );
    
    try {
        // Obtener información del empleado
        $sql_empleado = "SELECT empresaID, DepartamentoID FROM tb_empleados WHERE empleadoID = " . intval($empleadoID);
        sc_lookup(emp_info, $sql_empleado);
        
        if (empty({emp_info[0][0]})) {
            return $resultado;
        }
        
        $empresaID = {emp_info[0][0]};
        $departamentoID = {emp_info[0][1]};
        
        // Buscar TODAS las geocercas aplicables al empleado:
        // - Geocercas asignadas directamente al empleado (tipoAsignacion = 'usuario')
        // - Geocercas de su departamento (tipoAsignacion = 'departamento')
        // - Geocercas de empresa (tipoAsignacion = 'empresa')
        $sql_geocercas = "
            SELECT DISTINCT g.geocercaID, g.latitud, g.longitud, g.radio, g.nombre, g.tipoAsignacion
            FROM tb_geocercas g
            WHERE g.empresaID = $empresaID 
            AND g.estatus = 'Activo'
            AND (
                g.tipoAsignacion = 'empresa'
                OR (g.tipoAsignacion = 'departamento' AND EXISTS (
                    SELECT 1 FROM tb_geocercas_departamentos gd 
                    WHERE gd.geocercaID = g.geocercaID AND gd.DepartamentoID = $departamentoID
                ))
                OR (g.tipoAsignacion = 'usuario' AND EXISTS (
                    SELECT 1 FROM tb_geocercas_empleados ge 
                    WHERE ge.geocercaID = g.geocercaID AND ge.empleadoID = $empleadoID
                ))
            )
            ORDER BY 
                CASE g.tipoAsignacion 
                    WHEN 'usuario' THEN 1 
                    WHEN 'departamento' THEN 2 
                    WHEN 'empresa' THEN 3 
                END, 
                g.geocercaID";
        
        sc_lookup(geocercas_aplicables, $sql_geocercas);
        
        if (!isset({geocercas_aplicables}) || empty({geocercas_aplicables}) || empty({geocercas_aplicables[0][0]})) {
            error_log("Sin geocercas para empleado $empleadoID (Empresa: $empresaID, Depto: $departamentoID)");
            return $resultado;
        }
        
        error_log("Geocercas encontradas para empleado $empleadoID: " . count({geocercas_aplicables}));
        
        $geocercaMasCercana = null;
        $distanciaMinima = PHP_INT_MAX;
        $nombreGeocercaCercana = '';
        
        // Evaluar cada geocerca
        foreach ({geocercas_aplicables} as $geocerca) {
            $geocercaID = $geocerca[0];
            $geoLat = floatval($geocerca[1]);
            $geoLng = floatval($geocerca[2]);
            $radio = intval($geocerca[3]);
            $nombre = $geocerca[4];
            $tipo = $geocerca[5];
            
            // Calcular distancia usando la fórmula Haversine
            $distancia = calcularDistanciaHaversine(
                floatval($latitud), 
                floatval($longitud), 
                $geoLat, 
                $geoLng
            );
            
            error_log("Geocerca '$nombre' (ID:$geocercaID, Tipo:$tipo): Empleado a " . round($distancia) . "m, Radio: {$radio}m");
            
            // Si está dentro del radio
            if ($distancia <= $radio) {
                $resultado['geocercaID'] = $geocercaID;
                $resultado['distanciaMetros'] = round($distancia);
                $resultado['validacion'] = 'Dentro';
                $resultado['mensaje'] = 'En zona de trabajo ✓';
                
                error_log("✓ EN ZONA: Empleado $empleadoID dentro de '$nombre' (Tipo: $tipo)");
                return $resultado;
            }
            
            // Rastrear la geocerca más cercana
            if ($distancia < $distanciaMinima) {
                $distanciaMinima = $distancia;
                $geocercaMasCercana = $geocercaID;
                $nombreGeocercaCercana = $nombre;
            }
        }
        
        // Si llegamos aquí, no está dentro de ninguna geocerca
        if ($geocercaMasCercana !== null) {
            $resultado['geocercaID'] = $geocercaMasCercana;
            $resultado['distanciaMetros'] = round($distanciaMinima);
            $resultado['validacion'] = 'Fuera';
            $resultado['mensaje'] = 'Fuera de zona (' . round($distanciaMinima) . 'm de ' . $nombreGeocercaCercana . ')';
            
            error_log("✗ FUERA DE ZONA: Empleado $empleadoID. Más cercana: '$nombreGeocercaCercana' (ID:$geocercaMasCercana) a " . round($distanciaMinima) . "m");
        }
        
    } catch (Exception $e) {
        error_log("Error en validación de geocerca: " . $e->getMessage());
    }
    
    return $resultado;
}

// Fórmula Haversine para calcular distancia entre dos puntos geográficos
// Retorna la distancia en METROS
function calcularDistanciaHaversine($lat1, $lon1, $lat2, $lon2) {
    // Radio de la Tierra en metros
    $earthRadius = 6371000;
    
    // Convertir grados a radianes
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);
    
    // Fórmula Haversine
    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
         cos($lat1Rad) * cos($lat2Rad) *
         sin($deltaLon / 2) * sin($deltaLon / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    $distancia = $earthRadius * $c;
    
    return $distancia;
}

// ============================================================
// FUNCIÓN PARA DETERMINAR TIPO DE REGISTRO Y ESTADO
// ============================================================

function determinarTipoYEstadoRegistro($empleadoID) {
    $resultado = array(
        'tipo' => 1, // Por defecto entrada
        'esPrimerRegistroDelDia' => true,
        'estadoRegistro' => 'Entrada',
        'requierePedirMotivo' => true
    );
    
    try {
        $hoy = date('Y-m-d');
        $empleadoIDNumerico = intval($empleadoID);
        
        // Buscar registros del empleado en el día actual
        $sql_registros_hoy = "
            SELECT COUNT(*) as total, MAX(fechaH) as ultimo_registro, 
                   (SELECT tipo FROM tb_entrada_salida 
                    WHERE empleadoID = $empleadoIDNumerico 
                    AND DATE(fechaH) = '$hoy' 
                    ORDER BY fechaH DESC LIMIT 1) as ultimo_tipo
            FROM tb_entrada_salida 
            WHERE empleadoID = $empleadoIDNumerico 
            AND DATE(fechaH) = '$hoy'";
        
        sc_lookup(registros_hoy, $sql_registros_hoy);
        
        if (!empty({registros_hoy[0][0]}) && {registros_hoy[0][0]} > 0) {
            // Ya hay registros hoy
            $resultado['esPrimerRegistroDelDia'] = false;
            $resultado['requierePedirMotivo'] = false;
            
            // Obtener el último registro para determinar el siguiente tipo
            $ultimo_tipo = {registros_hoy[0][2]};
            
            if ($ultimo_tipo == 1) {
                // Último fue entrada, ahora debe ser salida
                $resultado['tipo'] = 2;
                $resultado['estadoRegistro'] = 'Salida';
            } else {
                // Último fue salida, ahora debe ser entrada
                $resultado['tipo'] = 1;
                $resultado['estadoRegistro'] = 'Entrada';
            }
        }
        
        error_log("Tipo registro determinado - EmpleadoID: $empleadoIDNumerico, Tipo: {$resultado['tipo']}, EsPrimero: " . ($resultado['esPrimerRegistroDelDia'] ? 'SI' : 'NO') . ", Estado: {$resultado['estadoRegistro']}, RequiereMotivo: " . ($resultado['requierePedirMotivo'] ? 'SI' : 'NO'));
        
    } catch (Exception $e) {
        error_log("Error determinando tipo de registro: " . $e->getMessage());
    }
    
    return $resultado;
}

// ============================================================
// INICIO DEL WEBSERVICE CON ESTRUCTURA ORIGINAL
// ============================================================

error_log("WS_Nexus");	

// ============================================================
// HANDLER POST: SubirEvidencias (y helpers)
// - SubirEvidencias: recibe {salidEnt, empleadoID, evidencias: [{filename, base64, mimetype}], notifyJefe:true}
// ============================================================
// Compatibilidad: reenviar solicitudes antiguas de SubirEvidencias al nuevo endpoint
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	$rawPost = file_get_contents('php://input');
	$postData = json_decode($rawPost, true);
	if (is_array($postData) && isset($postData['fn']) && $postData['fn'] === 'SubirEvidencias') {
		// Ejecutar el handler de evidencias dentro del contexto de ScriptCase
		// para que las funciones sc_* estén disponibles. Preparar $postData
		// y luego incluir el script que realiza la inserción.
		try {
			// Intentar varias rutas posibles donde puede estar el handler
			$candidates = array(
				__DIR__ . '/subir_evidencias.php', // mismo directorio
				__DIR__ . '/../subir_evidencias/subir_evidencias.php', // carpeta hermana app/Gilneas/subir_evidencias
			);

			// Añadir ruta basada en DOCUMENT_ROOT si está disponible
			if (!empty($_SERVER['DOCUMENT_ROOT'])) {
				$candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/scriptcase/app/Gilneas/subir_evidencias/subir_evidencias.php';
			}

			$included = false;
			foreach ($candidates as $include_path) {
				if (is_file($include_path)) {
					error_log('WS_Nexus: incluyendo handler local de evidencias en: ' . $include_path);
					// Incluir de forma controlada y evitar que warnings/notices impriman HTML
					define('WS_NEXUS_INCLUDED', true);
					// Registro temporal de warnings/notices para evitar que ScriptCase genere HTML de error
					$prev_error_handler = set_error_handler(function($errno, $errstr, $errfile, $errline) {
						if (in_array($errno, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED])) {
							error_log("WS_Nexus include warning: $errstr in $errfile:$errline");
							return true; // marcar como manejado
						}
						// dejar que PHP maneje errores fatales
						return false;
					});
					// Log incoming postData preview (sin base64) para depuración
					$preview_post = $postData;
					if (isset($preview_post['evidencias']) && is_array($preview_post['evidencias'])) {
						foreach ($preview_post['evidencias'] as $i => $pev) {
							if (isset($pev['base64'])) unset($preview_post['evidencias'][$i]['base64']);
						}
					}
					error_log('WS_Nexus: postData preview before include: ' . substr(json_encode($preview_post),0,1000));
					ob_start();
					include $include_path;
					$inc_buf = ob_get_clean();
					if (!empty($inc_buf)) {
						error_log('WS_Nexus: include buffer fragment: ' . substr($inc_buf,0,1000));
					}
					// Restaurar manejador de errores
					restore_error_handler();

					// Llamar a la función si fue definida por el include
					if (function_exists('process_subir_evidencias')) {
						ob_start();
						process_subir_evidencias($postData);
						$out = ob_get_clean();
					// Log fragment of the process output for debugging
					error_log('WS_Nexus: process output fragment: ' . substr(($out ?? ''), 0, 1000));
						if (!headers_sent()) header('Content-Type: application/json');
						echo $out;
					} else {
						error_log('WS_Nexus: process_subir_evidencias no encontrada tras incluir ' . $include_path);
						echo json_encode(['estatus' => '0', 'mensaje' => 'Handler de evidencias no disponible']);
					}

					$included = true;
					break;
				}
			}

			if (!$included) {
				// Si no se encontró el archivo, volver al proxy HTTP remoto
				$target = 'https://dev.bsys.mx/scriptcase/app/Gilneas/subir_evidencias/subir_evidencias.php';
				$opts = [
					'http' => [
						'method'  => 'POST',
						'header'  => "Content-Type: application/json\r\n",
						'content' => $rawPost,
						'timeout' => 30,
					]
				];
				$context = stream_context_create($opts);
				$result = @file_get_contents($target, false, $context);
				if ($result === false) {
					error_log('WS_Nexus: fallo al reenviar SubirEvidencias a ' . $target . ' (no se encontró handler local)');
					echo json_encode(['estatus' => '0', 'mensaje' => 'Error reenviando evidencias y handler local no encontrado']);
				} else {
					// Si la respuesta no parece JSON, registrar un fragmento para depuración
					$trim = trim($result);
					if (strpos($trim, '{') !== 0 && strpos($trim, '[') !== 0) {
						$snippet = substr($trim, 0, 512);
						error_log('WS_Nexus: respuesta proxy no JSON (fragmento): ' . $snippet);
						if (isset($http_response_header) && is_array($http_response_header)) {
							error_log('WS_Nexus: proxy HTTP headers: ' . implode(' | ', $http_response_header));
						}
					}
					header('Content-Type: application/json');
					echo $result;
				}
			}
		} catch (Exception $ex) {
			error_log('WS_Nexus: excepción procesando SubirEvidencias: ' . $ex->getMessage());
			echo json_encode(['estatus' => '0', 'mensaje' => 'Error interno procesando evidencias']);
		}
		exit();
	}

	// Si llega POST pero no es la función esperada, devolvemos un error genérico
	echo json_encode(['estatus' => '0', 'mensaje' => 'Función POST no reconocida']);
	exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'GET'){

	error_log("entro al primer IF GET nexus  ");	
	$request = file_get_contents('php://input');
		error_log($request);

	//$req_dump = json_encode($request);    
	//$req_array =json_decode($request,true);
	$request = isset($_GET['request']) ? $_GET['request'] : 'empty';
	$latitude = isset($_GET['latitud']) ? $_GET['latitud'] : 'empty';
	$longitude  = isset($_GET['longitud']) ? $_GET['longitud'] : 'empty';
	$address = isset($_GET['direccion']) ? $_GET['direccion'] : 'empty';
	$company = isset($_GET['empresa']) ? $_GET['empresa'] : 'empty';
	$locate = isset($_GET['ubicacionAcc']) ? $_GET['ubicacionAcc'] : 'empty';
	$hadwareB = isset($_GET['tipoHard']) ? $_GET['tipoHard'] : 'empty';
	$puertoEn = 'COM4';
	$puertoSal = 'COM5';
	// Tomar el valor de esPendiente al inicio
	$esPendiente = isset($_GET['esPendiente']) ? $_GET['esPendiente'] : '0';
	error_log('Valor de esPendiente: ' . $esPendiente);

    if(isset($_GET['fn'])) { 
         $funcion = $_GET['fn'];
     	}else{
         $funcion = 'No hay funcion';
    }
    // Redirigir a RegistroEntradaOffline si esPendiente == '1'
    if ($esPendiente == '1' && ($funcion == 'RegistroEntrada' || $funcion == 'RegistroRemoto')) {
        $funcion = 'RegistroEntradaOffline';
        error_log('Redirigiendo a RegistroEntradaOffline por esPendiente=1 (función original: ' . ($funcion == 'RegistroEntrada' ? 'RegistroEntrada' : 'RegistroRemoto') . ')');
    }
    error_log('Función final a ejecutar: ' . $funcion);
	
	error_log($request);	
	 //$funcion = 'RegistroEntrada';
	 switch($funcion){
			 
		case 'NexusDK':
			// Optimización: validación y consulta unificada
			$emailNexus = isset($_GET['email']) ? trim($_GET['email']) : '';
			$phoneNexus = isset($_GET['phone']) ? trim($_GET['phone']) : '';
			$imei = isset($_GET['imei']) ? trim($_GET['imei']) : '';
			$where = '';
			if ($emailNexus !== '' && $phoneNexus === '') {
				$where = "emp.correo = '" . addslashes($emailNexus) . "'";
			} elseif ($phoneNexus !== '' && $emailNexus === '') {
				$where = "emp.telefono = '" . addslashes($phoneNexus) . "'";
			} else {
				echo json_encode(['estatus'=>'0','mensaje'=>'Faltan datos de acceso']);
				exit();
			}
			// Consulta optimizada (solo campos necesarios)
			$sql_accesos = "SELECT emp.empleadoID, emp.acceso, emp.DepartamentoID, emp.puestoID, emp.fotografia, ro.departamento, pu.nombre, 
				CONCAT(emp.nombre, ' ', emp.apellidoP, ' ', emp.apellidoM) as nombreCompleto, empr.nombreComercial, emp.estatus, emp.NSS, 
				emp.comedor, emp.empresaID, emp.correo, qr.QR1, emp.telefono, emp.flg_app, emp.ligado_nexus, emp.archivo 
				FROM tb_empleados emp
				LEFT JOIN cat_roles ro ON ro.DepartamentoID = emp.DepartamentoID
				LEFT JOIN cat_puesto pu ON pu.puestoID = emp.puestoID
				INNER JOIN tb_empresas empr ON empr.empresaID = emp.empresaID
				INNER JOIN tb_crendecial_QR qr ON qr.empleadoID = emp.empleadoID
				WHERE emp.estatus = 'Activo' AND $where LIMIT 1";
			sc_lookup(acc, $sql_accesos);
			if (!empty({acc[0][0]})) {
				// ...asignación de variables igual...
				$idempleado = {acc[0][0]};
				$acceso = {acc[0][1]};
				$departamento = {acc[0][2]};
				$puesto = {acc[0][3]};
				$fotoN = {acc[0][4]};
				$departamentName = {acc[0][5]};
				$puestoName = {acc[0][6]};
				$NameComplet = {acc[0][7]};
				$empresaName = {acc[0][8]};
				$estatusEM = {acc[0][9]};
				$nssINV = {acc[0][10]};
				$comedorflg = {acc[0][11]};
				$empresasI = {acc[0][12]};
				$correoNexus = {acc[0][13]};
				$qremp = {acc[0][14]};
				$telefonoNexus = {acc[0][15]};
				$flgapp = {acc[0][16]};
				$ligadoNexus = {acc[0][17]};
				$archivoM = {acc[0][18]};
				$rutafoto = fnConfsis_v2(2,'N');
				// Procesamiento de imagen optimizado
				if ($fotoN == '') {
					$rutafotoCompleta = !empty($archivoM) ? $archivoM : '/scriptcase/app/Gilneas/_lib/img/_lib/img/grp__NM__bg__NM__userDOMO.png';
				} else {
					$rutafotoCompleta = $rutafoto.'/fotosEmpleados/'.$fotoN;
					// Si la app requiere base64, descomenta la siguiente línea:
					// $rutafotoCompleta = base64_encode(file_get_contents($rutafotoCompleta));
				}
				// Validación de IMEI y registro
				if ($flgapp == 0) {
					if ($emailNexus !== '' || $phoneNexus !== '') {
						$update_nexus = "UPDATE tb_empleados SET flg_app = '1' WHERE empleadoID =" . $idempleado;
						$update_nexusD = "UPDATE tb_empleados SET ligado_nexus = '" . addslashes($imei) . "' WHERE empleadoID =" . $idempleado;
						sc_exec_sql($update_nexus);
						sc_exec_sql($update_nexusD);
					}
					$array = array(
						'empleadoID' => $idempleado,
						'nombre' => $NameComplet,
						'Departamento' => $departamentName,
						'puesto' => $puestoName,
						'empresa' => $empresaName,
						'cadena' => $qremp,
						'estatus' => $estatusEM,
						'fotografia' => $rutafotoCompleta
					);
					$objResponse = array('estatus'=>'1','detalles'=>$array,'mensaje'=>'Datos encontrados se puede generar registro...');
					header("HTTP/1.1 200 OK");
					echo json_encode($objResponse);
				} elseif ($flgapp == 1 && $imei == $ligadoNexus) {
					$array = array(
						'empleadoID' => $idempleado,
						'nombre' => $NameComplet,
						'Departamento' => $departamentName,
						'puesto' => $puestoName,
						'empresa' => $empresaName,
						'cadena' => $qremp,
						'estatus' => $estatusEM,
						'fotografia' => $rutafotoCompleta
					);
					$objResponse = array('estatus'=>'1','detalles'=>$array,'mensaje'=>'Se econtro coincidencia con este equipo, puedes acceder');
					header("HTTP/1.1 200 OK");
					echo json_encode($objResponse);
				} else {
					$array = array(
						'empleadoID' => $idempleado,
						'nombre' => $NameComplet,
						'Departamento' => $departamentName,
						'puesto' => $puestoName,
						'empresa' => $empresaName,
						'cadena' => $qremp,
						'estatus' => $estatusEM,
						'fotografia' => $rutafotoCompleta,
						'flgapp' => $flgapp,
						'registradoC' => $ligadoNexus
					);
					$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'Lo sentimos, ya tienes registrado o instalada la app previamente con el IMEI: '.$ligadoNexus.' ');
					header("HTTP/1.1 200 OK");
					echo json_encode($objResponse);
				}
			} else {
				$array = array(
					'empresa' => 'no exiten datos de empresa',
					'nombre' =>  'no exite nombre',
					'Departamento' =>  'No exite departamento',
					'puesto' =>  'No existe puesto'
				);
				$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'Hola, No se ecnotrarón registro en nuestra base de datos.');
				header("HTTP/1.1 200 OK");
				echo json_encode($objResponse);
			}
			exit();
			break;
			 
		case 'PermisoRemoto':	 
			 
			 $empleadoD= isset($_GET['empleado']) ? $_GET['empleado'] : 'empty';
			 
			 $sql_permiso = "SELECT empleadoID, concat(nombre, ' ', apellidoP, ' ',apellidoM) as nombreCompleto, reg_remoto FROM tb_empleados WHERE empleadoID =".$empleadoD;
				sc_lookup(vrperm, $sql_permiso);
					
					if (!empty({vrperm[0][0]})) {
						 error_log("entro al if del permiso");
					 $idempleado = {vrperm[0][0]};	
					 $name= {vrperm[0][1]};
					 $permisoR= {vrperm[0][2]};
						
						
					$array = array();
					$array = array(
					  'empleadoID' => $idempleado,
					  'nombre' => $name,
					  'permisoR' => $permisoR
					);

					$objResponse = array('estatus'=>'1','detalles'=>$array,'mensaje'=>'Se encontro permiso para registro remoto');
					header("HTTP/1.1 200 OK");
					echo json_encode($objResponse);	
						
					}else{
						$array = array();
						$array = array(
						  'empleadoID' => "sin datos empleado",
						  'nombre' => "sin datos nombre",
						  'permisoR' => "sin datos Permiso"

						);

						$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'sin datos para el permiso remoto');
					 header("HTTP/1.1 200 OK");
					 echo json_encode($objResponse);

					}
			 
			 
		exit(); 
        break;	 

		case 'VerificarGeocerca':
			$empleadoID = isset($_GET['empleadoID']) ? intval($_GET['empleadoID']) : 0;
			$latitud = isset($_GET['latitud']) ? floatval($_GET['latitud']) : 0;
			$longitud = isset($_GET['longitud']) ? floatval($_GET['longitud']) : 0;
			
			error_log("VerificarGeocerca - EmpleadoID: $empleadoID, Lat: $latitud, Lng: $longitud");
			
			if ($empleadoID > 0 && $latitud != 0 && $longitud != 0) {
				$resultado = validarGeocerca($empleadoID, $latitud, $longitud);
				
				error_log("Resultado geocerca: " . json_encode($resultado));
				
				header("HTTP/1.1 200 OK");
				echo json_encode($resultado);
			} else {
				header("HTTP/1.1 400 Bad Request");
				$array = array(
					'geocercaID' => null,
					'distanciaMetros' => null,
					'validacion' => 'Error',
					'mensaje' => 'Parámetros inválidos'
				);
				echo json_encode($array);
			}
			exit();
		break;

		case 'VerificarTipoRegistro':
			$empleadoID = isset($_GET['empleadoID']) ? intval($_GET['empleadoID']) : 0;
			
			error_log("VerificarTipoRegistro - EmpleadoID: $empleadoID");
			
			if ($empleadoID > 0) {
				$resultado = determinarTipoYEstadoRegistro($empleadoID);
				
				error_log("Resultado tipo registro: " . json_encode($resultado));
				
				header("HTTP/1.1 200 OK");
				echo json_encode($resultado);
			} else {
				header("HTTP/1.1 400 Bad Request");
				$array = array(
					'tipo' => 1,
					'esPrimerRegistroDelDia' => true,
					'estadoRegistro' => 'Entrada',
					'requierePedirMotivo' => true,
					'error' => 'EmpleadoID requerido'
				);
				echo json_encode($array);
			}
			exit();
		break;

		case 'RegistroRemoto':
			// Función específica para registro remoto SIN validación de tiempo de código QR
			$request = isset($_GET['request']) ? $_GET['request'] : 'empty';
			$latitude = isset($_GET['latitud']) ? $_GET['latitud'] : 'empty';
			$longitude = isset($_GET['longitud']) ? $_GET['longitud'] : 'empty';
			$address = isset($_GET['direccion']) ? $_GET['direccion'] : 'empty';
			$company = isset($_GET['empresa']) ? $_GET['empresa'] : 'empty';
			$locate = isset($_GET['ubicacionAcc']) ? $_GET['ubicacionAcc'] : 'empty';
			$hadwareB = isset($_GET['tipoHard']) ? $_GET['tipoHard'] : 'empty';
			$esPendiente = isset($_GET['esPendiente']) ? $_GET['esPendiente'] : '0';
			$motivoFueraGeocerca = isset($_GET['motivoFueraGeocerca']) ? $_GET['motivoFueraGeocerca'] : '';
			
			error_log("RegistroRemoto - Request: $request, esPendiente: $esPendiente, motivo: $motivoFueraGeocerca");
			
			if ($request != 'empty') {
				// Parsear el request (cadenaTiempo:cadenaEncriptada)
				$partes = explode(":", $request);
				if (count($partes) >= 2) {
					$cadena_tiempo = $partes[0];
					$cadena_empleado = $partes[1];
					
					// Obtener coordenadas de la cadena de tiempo
					$array = explode(",", $cadena_tiempo);
					if (count($array) >= 6) {
						$latitude = isset($array[4]) ? $array[4] : $latitude;
						$longitude = isset($array[5]) ? $array[5] : $longitude;
					}
					
					// Desencriptar empleado
					$mensaje_decifrado = encryptor('decrypt', $cadena_empleado);
					error_log("Cadena empleado encriptada: $cadena_empleado");
					error_log("Mensaje decifrado: $mensaje_decifrado");
					
					if ($mensaje_decifrado != '') {
						$empleadoF = intval($mensaje_decifrado); // Asegurar que sea numérico
						error_log("EmpleadoID final: $empleadoF");
						
						// Determinar tipo de registro y si requiere motivo
						$infoRegistro = determinarTipoYEstadoRegistro($empleadoF);
						$vtipo = $infoRegistro['tipo'];
						$estadoBase = $infoRegistro['estadoRegistro'];
						$requierePedirMotivo = $infoRegistro['requierePedirMotivo'];
						
						// Validar geocerca si tenemos coordenadas
						$validacionGeocerca = null;
						if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
							$validacionGeocerca = validarGeocerca($empleadoF, floatval($latitude), floatval($longitude));
						}
						
						// Determinar el estado final del registro
						$estadoFinal = $estadoBase;
						$requiereValidacion = false;
						
						// Si está fuera de geocerca
						if ($validacionGeocerca && $validacionGeocerca['validacion'] == 'Fuera') {
							if ($requierePedirMotivo && !empty($motivoFueraGeocerca)) {
								// Es primer registro del día y está fuera de geocerca con motivo
								$estadoFinal .= ' (POR VALIDAR)';
								$requiereValidacion = true;
							} else if (!$requierePedirMotivo) {
								// No es primer registro, determinar estado normal (Puntual, Retardo, etc.)
								// Aquí podrías agregar lógica para determinar puntualidad basada en horarios
								// Por ahora mantenemos el estado base
								$estadoFinal = $estadoBase; // En Rango, Puntual, Retardo, etc.
							}
						}
						
						// Consulta simple para verificar empleado (incluye reg_remoto para validar permiso)
						$sql_acceso = "SELECT empleadoID, nombre, apellidoP, apellidoM, estatus, empresaID, IFNULL(reg_remoto, 0) as reg_remoto FROM tb_empleados WHERE empleadoID = $empleadoF AND estatus = 'Activo'";
						error_log("SQL empleado simplificado: $sql_acceso");
						sc_lookup(acc, $sql_acceso);
						
						if (!empty({acc[0][0]})) {
							$empleadoIDDB = {acc[0][0]};
							$nombreCompleto = {acc[0][1]} . ' ' . {acc[0][2]} . ' ' . {acc[0][3]};
							$empresaID = {acc[0][5]};
							$reg_remoto = intval({acc[0][6]});
							
							// Validar si el empleado tiene permiso de registro remoto
							if ($reg_remoto == 0) {
								error_log("RegistroRemoto BLOQUEADO: Empleado $empleadoIDDB ($nombreCompleto) NO tiene permiso de registro remoto (reg_remoto=0)");
								$objResponse = array(
									'estatus' => '0',
									'mensaje' => 'No tienes permiso para realizar registros remotos. Contacta a Recursos Humanos para solicitar acceso.',
									'codigo' => 'SIN_PERMISO_REMOTO'
								);
								echo json_encode($objResponse);
								exit;
							}
							
							error_log("RegistroRemoto PERMITIDO: Empleado $empleadoIDDB tiene reg_remoto=$reg_remoto");
							
							$dia = date('Y-m-d');
							$fechaH = date('Y-m-d H:i:s');
							$userF = 'Scanner';
							$tipoTarjeta = 'Fisica';
							
							// Obtener el nombre del día en español
							$nombresDias = array('Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado');
							$numeroDia = date('w'); // 0 (Domingo) a 6 (Sábado)
							$nombreDia = $nombresDias[$numeroDia];
							
							// Insert del registro (igual que proceso normal)
							$insert_sql = 'INSERT INTO tb_entrada_salida '
								. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, hadware, latitud, longitud, direccion, compania, ubicacion_Acc)'
								. ' VALUES (' . $empleadoIDDB . ', "' . $fechaH . '", ' . $vtipo . ', "' . $nombreDia . '", "' . $fechaH . '", "' . $userF . '", "' . $tipoTarjeta . '", "' . addslashes($hadwareB) . '", "' . addslashes($latitude) . '", "' . addslashes($longitude) . '", "' . addslashes($address) . '", "' . addslashes($company) . '", "' . addslashes($locate) . '" )';
							
							error_log('RegistroRemoto Insert SQL: ' . $insert_sql);
							sc_exec_sql($insert_sql);
							
							// Obtener el ID insertado usando el mismo patrón que NormalCheck
							$Salid = 0;
							$query_last = "SELECT last_insert_id()";
							sc_lookup(rstl, $query_last);
							$Salid = {rstl[0][0]};
							error_log("RegistroRemoto: ID insertado = $Salid");
							
							// Actualizar con información de geocerca, comentario y estado de validación
							$campos_update = array();
							$estadoValidacion = 'No_Requiere'; // Por defecto no requiere validación
							
							if ($validacionGeocerca) {
								$campos_update[] = "geocercaID = " . intval($validacionGeocerca['geocercaID']);
								$campos_update[] = "distanciaMetros = " . intval($validacionGeocerca['distanciaMetros']);
								$campos_update[] = "validacionGeocerca = '" . addslashes($validacionGeocerca['validacion']) . "'";
								
								// Si está FUERA de geocerca y hay motivo, marcar como Pendiente de validación
								if ($validacionGeocerca['validacion'] === 'Fuera' && !empty($motivoFueraGeocerca)) {
									$estadoValidacion = 'Pendiente';
									error_log("RegistroRemoto: Fuera de geocerca con motivo - Estado: Pendiente de validación");
								}
							}
							
							// Si hay motivo, guardarlo en comentario
							if (!empty($motivoFueraGeocerca)) {
								$campos_update[] = "comentario = '" . addslashes($motivoFueraGeocerca) . "'";
							}
							
						// Agregar estado de validación
						$campos_update[] = "estadoValidacionGeocerca = '$estadoValidacion'";
						
						if (!empty($campos_update)) {
							$update_sql = "UPDATE tb_entrada_salida SET " . implode(', ', $campos_update) . " WHERE salidEnt = " . $Salid;
							error_log('RegistroRemoto Update SQL: ' . $update_sql);
							sc_exec_sql($update_sql);
						}
						
						// Ejecutar procedimientos almacenados (mismo patrón que NormalCheck)
							if ($vtipo == 2) {
								$setencia = "CALL pdHorasTrabajadas_m($Salid,$vtipo,$empleadoF,'$dia','$fechaH')";
								sc_exec_sql($setencia);
								error_log("RegistroRemoto Ejecutado pdHorasTrabajadas_m: $setencia");
							} elseif ($vtipo == 1) {
								$setencia = "CALL pdHorasComedor_m($Salid,$vtipo,$empleadoF,'$dia','$fechaH')";
								sc_exec_sql($setencia);
								error_log("RegistroRemoto Ejecutado pdHorasComedor_m: $setencia");
							}
							
							if ($vtipo == 1 && $empresaID != 2) {
								$setenciaF = "CALL pdHorasFuera($Salid,$vtipo,$empleadoF,'$dia','$fechaH')";
								sc_exec_sql($setenciaF);
								error_log("RegistroRemoto Ejecutado pdHorasFuera: $setenciaF");
							}
							
							// Ejecutar rutina de puntualidad
							if ($vtipo == 1) {
								$setenciaPunt = "CALL pdPuntualidad_m($Salid,$vtipo,$empleadoF,'$dia','$fechaH')";
								sc_exec_sql($setenciaPunt);
								error_log("RegistroRemoto Ejecutado pdPuntualidad_m: $setenciaPunt");
							}
							
							$tipoTexto = ($vtipo == 1) ? 'Entrada' : 'Salida';
							
							// Mensaje personalizado según estado de validación
							if ($estadoValidacion === 'Pendiente') {
								$mensajeFinal = " $estadoFinal registrado - Pendiente de validación por geocerca";
							} else {
								$mensajeFinal = " $estadoFinal registrado exitosamente";
							}
							
							$response = [
								'estatus' => '1',
								'mensaje' => $mensajeFinal,
								'empleado' => $nombreCompleto,
								'empleadoID' => $empleadoF,
								'tipo' => $vtipo,
								'tipoTexto' => $tipoTexto,
								'estado' => $estadoFinal,
								'requiereValidacion' => $requiereValidacion,
								'esPrimerRegistroDelDia' => $requierePedirMotivo,
								'estadoValidacionGeocerca' => $estadoValidacion,
							'salidEnt' => $Salid
						];
				if ($estadoValidacion === 'Pendiente') {
					error_log("RegistroRemoto: Buscando jefe ANTES de responder (macros disponibles)");
					$tiempo_jefe_inicio = microtime(true);
					
					// 1. Buscar jefe directo
					$sql_jefe = "SELECT ei.jefeID FROM empleados_info ei INNER JOIN tb_empleados e ON e.empleadoID = ei.empleadoID WHERE e.empleadoID = $empleadoIDDB LIMIT 1";
					sc_lookup(jefe_rs_pre, $sql_jefe);
					$jefeEmpleadoInfoID = !empty({jefe_rs_pre}) ? {jefe_rs_pre[0][0]} : null;
					
					// Si no hay jefe directo, buscar por departamento
					if (empty($jefeEmpleadoInfoID)) {
						$sql_jefe_dept = "SELECT jefe_empleadoInfoID FROM cat_jefes_departamento WHERE departamento = (SELECT DepartamentoID FROM tb_empleados WHERE empleadoID = $empleadoIDDB) LIMIT 1";
						sc_lookup(jefe_dept_rs_pre, $sql_jefe_dept);
						$jefeEmpleadoInfoID = !empty({jefe_dept_rs_pre}) ? {jefe_dept_rs_pre[0][0]} : null;
					}
					
					// Convertir empleadoInfoID a empleadoID + obtener teléfono y correo
					if (!empty($jefeEmpleadoInfoID)) {
						$sql_jefe_data = "SELECT e.empleadoID, e.telefono, e.nombre, e.apellidoP, e.apellidoM, e.correo FROM tb_empleados e INNER JOIN empleados_info ei ON e.empleadoID = ei.empleadoID WHERE ei.empleadoInfoID = $jefeEmpleadoInfoID LIMIT 1";
						sc_lookup(jefe_data_rs_pre, $sql_jefe_data);
						
						if (!empty({jefe_data_rs_pre})) {
							$jefeID = {jefe_data_rs_pre[0][0]};
							$telefonoJefe = {jefe_data_rs_pre[0][1]};
							$nombreJefe = trim({jefe_data_rs_pre[0][2]} . ' ' . {jefe_data_rs_pre[0][3]} . ' ' . {jefe_data_rs_pre[0][4]});
							$correoJefe = !empty({jefe_data_rs_pre[0][5]}) ? {jefe_data_rs_pre[0][5]} : null;
							
							// GENERAR URL (sin INSERT en BD - instantáneo)
							$urlValidacion = generarUrlValidacion($Salid, $empleadoIDDB, $jefeID);
							
							$tiempo_jefe_fin = microtime(true);
							$tiempo_jefe_ms = round(($tiempo_jefe_fin - $tiempo_jefe_inicio) * 1000, 2);
							error_log("RegistroRemoto PRE-FLUSH: ✓ Jefe encontrado y URL generada en {$tiempo_jefe_ms}ms - ID=$jefeID, Tel=$telefonoJefe");
						}
					}
				}					// ============================================================
					// PASO 1: RESPUESTA INMEDIATA A LA APP
					// ============================================================
					$response_json = json_encode($response);
					error_log('RegistroRemoto: Enviando respuesta inmediata (token ya guardado si aplicaba)');
					header("HTTP/1.1 200 OK");
					header('Content-Type: application/json');
					header('Content-Length: ' . strlen($response_json));
					header('Connection: close');
					echo $response_json;
					
				// Liberar conexión HTTP inmediatamente
				if (ob_get_level() > 0) { ob_end_flush(); }
				flush();
				if (function_exists('fastcgi_finish_request')) {
					fastcgi_finish_request();
					error_log('RegistroRemoto: Conexión cerrada - Enviando SMS en segundo plano');
				} else {
					error_log('RegistroRemoto: flush() OK - Enviando SMS sin bloquear');
				}
				
				// ============================================================
				// PASO 2: ENVÍO DE SMS ASÍNCRONO (URL ya generada arriba)
				// ============================================================
				if (!empty($urlValidacion) && !empty($telefonoJefe)) {
				$tiempo_sms_inicio = microtime(true);
				error_log("RegistroRemoto ASYNC: Enviando SMS (app ya recibió respuesta)");
				
			// Formatear teléfono correctamente para México
			$telefono_intl = $telefonoJefe;
			
			// Si NO tiene código de país, agregar +52
			if (strlen($telefonoJefe) == 10 && substr($telefonoJefe, 0, 1) !== '+') {
				$telefono_intl = '+52' . $telefonoJefe;
			} else if (substr($telefonoJefe, 0, 2) === '52' && substr($telefonoJefe, 0, 1) !== '+') {
				// Si tiene 52 pero sin +, agregarlo
				$telefono_intl = '+' . $telefonoJefe;
			} else if (substr($telefonoJefe, 0, 1) !== '+') {
				// Si no tiene código de país ni +, agregar +52
				$telefono_intl = '+52' . $telefonoJefe;
			}
			
			error_log("RegistroRemoto ASYNC: Teléfono original: $telefonoJefe, Formateado: $telefono_intl");
				// Unificar formato SMS con correo y agregar dos ligas directas
				$secret_key_sms = 'bsys_geocerca_2025_secret_key';
				$data_to_hash_sms = $Salid . '|' . $empleadoIDDB . '|' . $jefeID . '|' . $secret_key_sms;
				$hash_sms = substr(hash('sha256', $data_to_hash_sms), 0, 16);
				$url_base_sms = 'https://dev.bsys.mx/scriptcase/app/Gilneas/validar_geocerca/validar_geocerca.php';
				$url_aprobar_sms = $url_base_sms . "?s=$Salid&e=$empleadoIDDB&j=$jefeID&h=$hash_sms&accion=aprobar";
				$url_rechazar_sms = $url_base_sms . "?s=$Salid&e=$empleadoIDDB&j=$jefeID&h=$hash_sms&accion=rechazar";
				$mensaje_sms = "🔔 Validación de Asistencia\n\n";
				$mensaje_sms .= "Empleado: $nombreCompleto\n";
				$mensaje_sms .= "Fecha: " . date('d/m/Y H:i', strtotime($fechaH)) . "\n";
				$mensaje_sms .= "Ubicación: $address\n";
				// Mostrar el motivo que escribió el empleado (si existe)
				$motivoMostrar = !empty($motivoFueraGeocerca) ? $motivoFueraGeocerca : 'Registro fuera de zona asignada';
				$mensaje_sms .= "Motivo: $motivoMostrar\n\n";
				$mensaje_sms .= "✅ Aprobar: $url_aprobar_sms\n";
				$mensaje_sms .= "🚫 Rechazar: $url_rechazar_sms";
			
			$api_key = 'a06862d8-f452-45a5-b9e5-6da701555901';
			$device_id = '6876663ce7c673140c7ef5e6';
			$url_sms_api = 'https://api.textbee.dev/api/v1/gateway/devices/' . $device_id . '/send-sms';
			error_log("RegistroRemoto ASYNC: URL API SMS: $url_sms_api");
			
			$data_sms = json_encode(['recipients' => [$telefono_intl], 'message' => $mensaje_sms], JSON_UNESCAPED_UNICODE);
			error_log("RegistroRemoto ASYNC: Payload SMS: $data_sms");
				
			$opts_sms = [
				'http' => [
					'header'  => "Content-type: application/json\r\n" .
								 "x-api-key: $api_key\r\n" .
								 "Accept: application/json",
					'method'  => 'POST',
					'content' => $data_sms,
					'timeout' => 15
				]
			];
			
			$context_sms = stream_context_create($opts_sms);
			$result_sms = @file_get_contents($url_sms_api, false, $context_sms);
			$tiempo_sms_fin = microtime(true);
			$tiempo_sms_ms = round(($tiempo_sms_fin - $tiempo_sms_inicio) * 1000, 2);
			
			$http_code_sms = 0;
			if (isset($http_response_header)) {
				foreach ($http_response_header as $hdr) {
					if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $hdr, $m)) {
						$http_code_sms = intval($m[1]);
						break;
					}
				}
			}
			
			// Log completo de respuesta SMS
			error_log("RegistroRemoto ASYNC: Respuesta API SMS (HTTP $http_code_sms, {$tiempo_sms_ms}ms): " . ($result_sms ?: '(sin respuesta)'));
			
			if ($http_code_sms == 200 || $http_code_sms == 201) {
				error_log("RegistroRemoto ASYNC: ✓ SMS enviado OK");
			} else {
				error_log("RegistroRemoto ASYNC: ⚠ SMS falló. Respuesta completa: " . var_export($result_sms, true));
			}
			
			// ============================================================
			// ENVÍO ADICIONAL POR EMAIL (respaldo)
			// ============================================================
			if (!empty($correoJefe)) {
				error_log("RegistroRemoto ASYNC: Enviando email a: $correoJefe");
				
				$mail_smtp_server = 'smtp.gmail.com';
				$mail_smtp_user   = 'helpbinfo@gmail.com';
				$mail_smtp_pass   = 'wovrqesluirppipu';
				$mail_from        = 'helpbinfo@gmail.com';
				$mail_to          = $correoJefe;
				$mail_subject     = '🔔 Validación de Asistencia - ' . $nombreCompleto;
				
			// Generar hash para validación de email (igual al de la URL)
			$secret_key = 'bsys_geocerca_2025_secret_key';
			$data_to_hash = $Salid . '|' . $empleadoIDDB . '|' . $jefeID . '|' . $secret_key;
			$hash_email = substr(hash('sha256', $data_to_hash), 0, 16);
			
			// Generar URLs de acción con hash de seguridad (apuntan a la vista de ScriptCase)
			$url_base = 'https://dev.bsys.mx/scriptcase/app/Gilneas/validar_geocerca/validar_geocerca.php';
			$url_aprobar = $url_base . "?s=$Salid&e=$empleadoIDDB&j=$jefeID&h=$hash_email&accion=aprobar";
			$url_rechazar = $url_base . "?s=$Salid&e=$empleadoIDDB&j=$jefeID&h=$hash_email&accion=rechazar";
			
			$mail_message = '<html><head><meta charset="utf-8"><style>';
			$mail_message .= 'body{background:#f7f8fa;font-family:Arial,sans-serif;color:#222;margin:0;padding:0;}';
			$mail_message .= '.card{background:#fff;border-radius:12px;max-width:600px;margin:30px auto;box-shadow:0 4px 20px rgba(0,0,0,0.1);overflow:hidden;}';
			$mail_message .= '.header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:24px;text-align:center;}';
			$mail_message .= '.content{padding:28px;}';
			$mail_message .= '.info{background:#f9fafb;border-left:4px solid #667eea;padding:14px;margin:16px 0;border-radius:4px;}';
			$mail_message .= '.btn-container{text-align:center;margin:30px 0;}';
			$mail_message .= '.btn{display:inline-block;padding:14px 40px;text-decoration:none;border-radius:8px;margin:8px;font-weight:bold;font-size:16px;transition:transform 0.2s;}';
			$mail_message .= '.btn-aprobar{background:#27ae60;color:#fff;}';
			$mail_message .= '.btn-rechazar{background:#e74c3c;color:#fff;}';
			$mail_message .= '.footer{text-align:center;color:#888;font-size:0.9em;padding:20px;border-top:1px solid #eee;}';
			$mail_message .= '</style></head><body>';
			$mail_message .= '<div class="card">';
			$mail_message .= '<div class="header"><h2 style="margin:0;">⚠️ Validación de Asistencia Requerida</h2></div>';
			$mail_message .= '<div class="content">';
			$mail_message .= '<p>Hola <strong>' . htmlspecialchars($nombreJefe) . '</strong>,</p>';
			$mail_message .= '<p>Se requiere tu validación para el siguiente registro:</p>';
			$mail_message .= '<div class="info">';
			$mail_message .= '<strong>👤 Empleado:</strong> ' . htmlspecialchars($nombreCompleto) . '<br>';
			$mail_message .= '<strong>📅 Fecha:</strong> ' . date('d/m/Y H:i', strtotime($fechaH)) . '<br>';
			$mail_message .= '<strong>📍 Ubicación:</strong> ' . htmlspecialchars($address) . '<br>';
			// Mostrar el motivo que escribió el empleado (si existe)
			$motivoMostrarEmail = !empty($motivoFueraGeocerca) ? $motivoFueraGeocerca : 'Registro fuera de zona asignada';
			$mail_message .= '<strong>💬 Motivo:</strong> ' . htmlspecialchars($motivoMostrarEmail);
			$mail_message .= '</div>';
			$mail_message .= '<div class="btn-container">';
			$mail_message .= '<a href="' . htmlspecialchars($url_aprobar) . '" class="btn btn-aprobar">✅ APROBAR</a>';
			$mail_message .= '<a href="' . htmlspecialchars($url_rechazar) . '" class="btn btn-rechazar">🚫 RECHAZAR</a>';
			$mail_message .= '</div>';
			$mail_message .= '<p style="font-size:0.85em;color:#666;text-align:center;margin-top:20px;">';
			$mail_message .= 'Haz clic en el botón correspondiente para validar el registro.<br>';
			$mail_message .= '<strong>Nota:</strong> Al rechazar, el registro se marcará como Falta automáticamente.';
			$mail_message .= '</p>';
			$mail_message .= '</div>';
			$mail_message .= '<div class="footer">DRT Recursos Humanos &bull; Sistema de Asistencia &bull; ' . date('Y') . '</div>';
			$mail_message .= '</div></body></html>';				$mail_format    = 'H'; // HTML
				$mail_copies    = '';
				$mail_tp_copies = '';
				$mail_port      = '587';
				$mail_security  = 'T'; // TLS
				
				$tiempo_email_inicio = microtime(true);
				sc_mail_send(
					$mail_smtp_server,
					$mail_smtp_user,
					$mail_smtp_pass,
					$mail_from,
					$mail_to,
					$mail_subject,
					$mail_message,
					$mail_format,
					$mail_copies,
					$mail_tp_copies,
					$mail_port,
					$mail_security
				);
				$tiempo_email_fin = microtime(true);
				$tiempo_email_ms = round(($tiempo_email_fin - $tiempo_email_inicio) * 1000, 2);
				
				error_log("RegistroRemoto ASYNC: ✓ Email enviado a $correoJefe ({$tiempo_email_ms}ms)");
			} else {
				error_log("RegistroRemoto ASYNC: ⚠ No se envió email - correo del jefe no disponible");
			}
				} else {
					if ($estadoValidacion === 'Pendiente') {
						error_log("RegistroRemoto ASYNC: ⚠ No se pudo enviar SMS - URL o jefe no disponible");
					}
				}					// Terminar ejecución del webservice
					error_log('RegistroRemoto: Finalizando proceso - Registro ID: ' . $Salid . ', EmpleadoID: ' . $empleadoF);
					exit();
				} else {
					// Empleado no encontrado o inactivo
					$response = ['estatus' => '0', 'mensaje' => 'Empleado no encontrado o inactivo'];
					header("HTTP/1.1 400 Bad Request");
					echo json_encode($response);
					exit();
				}
			} else {
				// Error al desencriptar
				$response = ['estatus' => '0', 'mensaje' => 'Error al desencriptar datos del empleado'];
				header("HTTP/1.1 400 Bad Request");
				echo json_encode($response);
				exit();
			}
		} else {
			// Formato de request inválido
			$response = ['estatus' => '0', 'mensaje' => 'Formato de request inválido'];
			header("HTTP/1.1 400 Bad Request");
			echo json_encode($response);
			exit();
		}
	} else {
		// Request vacío
		$response = ['estatus' => '0', 'mensaje' => 'Request vacío'];
		header("HTTP/1.1 400 Bad Request");
		echo json_encode($response);
		exit();
	}
	break;
	
	// ============================================================
	// ENDPOINT: Listar registros pendientes de validación
	// ============================================================
	case 'ListarRegistrosPendientes':
			error_log("=== ListarRegistrosPendientes ===");
			
			// Parámetros opcionales para filtrado
			$empresaID = isset($_GET['empresaID']) ? intval($_GET['empresaID']) : null;
			$fechaInicio = isset($_GET['fechaInicio']) ? $_GET['fechaInicio'] : date('Y-m-d', strtotime('-30 days'));
			$fechaFin = isset($_GET['fechaFin']) ? $_GET['fechaFin'] : date('Y-m-d');
			$estadoFiltro = isset($_GET['estado']) ? $_GET['estado'] : 'Pendiente'; // Pendiente, En_Revision, todos
			
			// Construir query con filtros
			$where_clauses = ["es.estadoValidacionGeocerca != 'No_Requiere'"];
			
			if ($estadoFiltro !== 'todos') {
				$where_clauses[] = "es.estadoValidacionGeocerca = '$estadoFiltro'";
			}
			
			if ($empresaID) {
				$where_clauses[] = "e.empresaID = $empresaID";
			}
			
			$where_clauses[] = "DATE(es.fechaH) BETWEEN '$fechaInicio' AND '$fechaFin'";
			
			$sql_pendientes = "
				SELECT 
					es.salidEnt,
					es.empleadoID,
					CONCAT(e.nombre, ' ', e.apellidoP, ' ', e.apellidoM) as nombreCompleto,
					emp.nombre as nombreEmpresa,
					d.Departamento as departamento,
					es.fechaH,
					es.tipo,
					es.validacionGeocerca,
					es.geocercaID,
					g.nombre as nombreGeocerca,
					es.distanciaMetros,
					es.comentario,
					es.estadoValidacionGeocerca,
					es.validadoPor,
					es.fechaValidacion,
					es.comentarioValidacion,
					es.direccion,
					es.latitud,
					es.longitud
				FROM tb_entrada_salida es
				INNER JOIN tb_empleados e ON es.empleadoID = e.empleadoID
				LEFT JOIN tb_empresas emp ON e.empresaID = emp.empresaID
				LEFT JOIN tb_departamentos d ON e.DepartamentoID = d.DepartamentoID
				LEFT JOIN tb_geocercas g ON es.geocercaID = g.geocercaID
				WHERE " . implode(' AND ', $where_clauses) . "
				ORDER BY es.fechaH DESC
				LIMIT 100";
			
			error_log("SQL Pendientes: $sql_pendientes");
			sc_lookup(pendientes, $sql_pendientes);
			
			$registros = array();
			if (!empty({pendientes})) {
				foreach ({pendientes} as $row) {
					$registros[] = array(
						'salidEnt' => $row[0],
						'empleadoID' => $row[1],
						'nombreCompleto' => $row[2],
						'empresa' => $row[3],
						'departamento' => $row[4],
						'fechaHora' => $row[5],
						'tipo' => $row[6] == 1 ? 'Entrada' : 'Salida',
						'validacionGeocerca' => $row[7],
						'geocercaID' => $row[8],
						'nombreGeocerca' => $row[9],
						'distanciaMetros' => $row[10],
						'motivoEmpleado' => $row[11],
						'estadoValidacion' => $row[12],
						'validadoPor' => $row[13],
						'fechaValidacion' => $row[14],
						'comentarioSupervisor' => $row[15],
						'direccion' => $row[16],
						'latitud' => $row[17],
						'longitud' => $row[18]
					);
				}
			}
			
			$response = [
				'estatus' => '1',
				'total' => count($registros),
				'registros' => $registros
			];
			
			header("HTTP/1.1 200 OK");
			echo json_encode($response);
			exit();
		break;
		
		// ============================================================
		// ENDPOINT: Validar/Rechazar registro fuera de geocerca
		// ============================================================
		case 'ValidarRegistroGeocerca':
			error_log("=== ValidarRegistroGeocerca ===");
			
			$salidEnt = isset($_GET['salidEnt']) ? intval($_GET['salidEnt']) : 0;
			$accion = isset($_GET['accion']) ? $_GET['accion'] : ''; // validar, rechazar, revisar
			$usuarioValidador = isset($_GET['usuario']) ? $_GET['usuario'] : 'Sistema';
			$comentarioSupervisor = isset($_GET['comentario']) ? $_GET['comentario'] : '';
			
			if (!$salidEnt || !$accion) {
				$response = ['estatus' => '0', 'mensaje' => 'Faltan parámetros: salidEnt y accion son requeridos'];
				header("HTTP/1.1 400 Bad Request");
				echo json_encode($response);
				exit();
			}
			
			// Determinar nuevo estado
			$nuevoEstado = '';
			switch ($accion) {
				case 'revisar':
					$nuevoEstado = 'En_Revision';
					$mensajeAccion = 'marcado como en revisión';
					break;
				case 'validar':
					$nuevoEstado = 'Validado';
					$mensajeAccion = 'validado exitosamente';
					break;
				case 'rechazar':
					$nuevoEstado = 'Rechazado';
					$mensajeAccion = 'rechazado';
					break;
				default:
					$response = ['estatus' => '0', 'mensaje' => 'Acción inválida. Use: revisar, validar o rechazar'];
					header("HTTP/1.1 400 Bad Request");
					echo json_encode($response);
					exit();
			}
			
			// Verificar que el registro existe y requiere validación
			$sql_check = "SELECT salidEnt, estadoValidacionGeocerca FROM tb_entrada_salida WHERE salidEnt = $salidEnt";
			sc_lookup(check_registro, $sql_check);
			
			if (empty({check_registro[0][0]})) {
				$response = ['estatus' => '0', 'mensaje' => 'Registro no encontrado'];
				header("HTTP/1.1 404 Not Found");
				echo json_encode($response);
				exit();
			}
			
			$estadoActual = {check_registro[0][1]};
			if ($estadoActual === 'No_Requiere') {
				$response = ['estatus' => '0', 'mensaje' => 'Este registro no requiere validación'];
				header("HTTP/1.1 400 Bad Request");
				echo json_encode($response);
				exit();
			}
			
			// Actualizar el registro
			$fechaActual = date('Y-m-d H:i:s');
			$update_validacion = "UPDATE tb_entrada_salida SET 
				estadoValidacionGeocerca = '$nuevoEstado',
				validadoPor = '" . addslashes($usuarioValidador) . "',
				fechaValidacion = '$fechaActual',
				comentarioValidacion = '" . addslashes($comentarioSupervisor) . "'
				WHERE salidEnt = $salidEnt";
			
			error_log("Update Validación: $update_validacion");
			sc_exec_sql($update_validacion);
			
			$response = [
				'estatus' => '1',
				'mensaje' => "Registro $mensajeAccion",
				'salidEnt' => $salidEnt,
				'nuevoEstado' => $nuevoEstado,
				'validadoPor' => $usuarioValidador,
				'fechaValidacion' => $fechaActual
			];
			
			header("HTTP/1.1 200 OK");
			echo json_encode($response);
			exit();
		break;
		
		// ============================================================
		// ENDPOINT: Historial de registros de un empleado
		// ============================================================
		case 'HistorialRegistros':
			error_log("=== HistorialRegistros (paginado) ===");
			
			$empleadoID = isset($_GET['empleadoID']) ? intval($_GET['empleadoID']) : 0;
			$limite = isset($_GET['limite']) ? intval($_GET['limite']) : 50;
			$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
			$offset = ($page - 1) * $limite;
			$fechaInicio = isset($_GET['fechaInicio']) ? $_GET['fechaInicio'] : date('Y-m-d', strtotime('-30 days'));
			$fechaFin = isset($_GET['fechaFin']) ? $_GET['fechaFin'] : date('Y-m-d');
			
			if (!$empleadoID) {
				$response = ['estatus' => '0', 'mensaje' => 'Falta empleadoID'];
				header("HTTP/1.1 400 Bad Request");
				echo json_encode($response);
				exit();
			}
			
			// Obtener total real (COUNT) para calcular páginas
			// Filtrar sólo registros Remoto procedentes de hardware 'Nexus'
			$sql_count = "SELECT COUNT(*) as total FROM tb_entrada_salida es WHERE es.empleadoID = $empleadoID AND DATE(es.fechaH) BETWEEN '$fechaInicio' AND '$fechaFin' AND es.hadware = 'Nexus' AND es.ubicacion_Acc = 'Remoto'";
			sc_lookup(countRes, $sql_count);
			$total = 0;
			if (!empty({countRes}) && isset({countRes}[0][0])) {
				$total = intval({countRes}[0][0]);
			}
			
			$sql_historial = "
				SELECT 
					es.salidEnt,
					es.fechaH,
					es.tipo,
					es.direccion,
					es.latitud,
					es.longitud,
					es.validacionGeocerca,
					g.nombre as nombreGeocerca,
					es.comentario,
					es.estadoValidacionGeocerca,
					es.validadoPor,
					es.fechaValidacion,
					es.comentarioValidacion
				FROM tb_entrada_salida es
				LEFT JOIN tb_geocercas g ON es.geocercaID = g.geocercaID
				WHERE es.empleadoID = $empleadoID
				AND DATE(es.fechaH) BETWEEN '$fechaInicio' AND '$fechaFin'
				AND es.hadware = 'Nexus' AND es.ubicacion_Acc = 'Remoto'
				ORDER BY es.fechaH DESC
				LIMIT $offset, $limite";
			
			error_log("SQL Historial (paginado): $sql_historial");
			sc_lookup(historial, $sql_historial);
			
			$registros = array();
			if (!empty({historial})) {
				foreach ({historial} as $row) {
					$fechaHora = $row[1];
					$fechaParts = explode(' ', $fechaHora);
					$fecha = isset($fechaParts[0]) ? date('d/m/Y', strtotime($fechaParts[0])) : '';
					$hora = isset($fechaParts[1]) ? $fechaParts[1] : '';
					
					// Determinar estado de validación amigable
					$estadoRaw = $row[9] ?? 'No_Requiere';
					$estadoAmigable = $estadoRaw;
					if ($estadoRaw === 'Pendiente') {
						$estadoAmigable = 'Pendiente Validación';
					} elseif ($estadoRaw === 'No_Requiere' || $estadoRaw === 'Validado') {
						$estadoAmigable = 'Validado';
					} elseif ($estadoRaw === 'En_Revision') {
						$estadoAmigable = 'En Revisión';
					}
					
					// Determinar si está dentro de geocerca
					$validacionGeocerca = $row[6] ?? 'Dentro';
					$dentroGeocerca = ($validacionGeocerca === 'Dentro' || $validacionGeocerca === 'Sin_Geocerca');
					
					$registros[] = array(
						'fecha' => $fecha,
						'hora' => $hora,
						'tipo' => $row[2] == 1 ? 'Entrada' : 'Salida',
						'ubicacion' => $row[3] ?? '',
						'latitud' => $row[4],
						'longitud' => $row[5],
						'dentroGeocerca' => $dentroGeocerca,
						'nombreGeocerca' => $row[7] ?? '',
						'motivo' => $row[8] ?? '',
						'estado' => $estadoAmigable,
						'validadoPor' => $row[10] ?? '',
						'fechaValidacion' => $row[11] ?? '',
						'comentarioValidacion' => $row[12] ?? ''
					);
				}
			}
			
			$response = [
				'estatus' => '1',
				'total' => $total,
				'registros' => $registros
			];
			
			header("HTTP/1.1 200 OK");
			echo json_encode($response);
			exit();
		break;
			 
		case 'RegistroEntrada' :
			//$request = "26,11,39,21.33443939,-100.32232323:K2Z3SWhGSzUxZDlJc1pGUlpOMXRralFZZkhxejVkdmR4U1B2Q24yczM1d0NkNWVCMHc5aHBGdXEyKzJvZmlwQQ==";
			$flagTime = "";
			$partes = explode(":", $request); // separar por ":"
			$partes2 = explode(",", $partes[1]); // separar la segunda parte por ","

			$cadena_tiempo  = $partes[0];

			$cadena_empleado = $partes[1];

			//$cadena = "19,09,06,21.33443939,-100.32232323";
			$array = explode(",", $cadena_tiempo); // separar la cadena por comas y obtener un array
			$primeros_tres = array_slice($array, 0, 4); // obtener los primeros tres elementos
			$cadena_tiempo = implode(",", $primeros_tres); // unir los elementos con una coma
			//echo $resultado; // imprimir el resultado: "19,09,06"
			$latitude = isset($array[4]) ? $array[4] : 'empty';
			$longitude  = isset($array[5])? $array[5] : 'empty';
			//echo $cadena_tiempo;


			$outputQuery = "SELECT regID FROM cat_config_sis WHERE registroID = 9";
			sc_lookup(rsOutput,$outputQuery);

			if(count({rsOutput}) >0){

				$flagTime = {rsOutput[0][0]}; // minutos

				}else{
				$flagTime = 2;
				}
						// Convertir el valor de $flagTime de minutos a segundos
				$flagTime = $flagTime * 60;
			   // $flagtime = 60; // Rango de 1 minuto en segundos
				//$cadena = "30,18,53,40";
				//$valores = explode(',', $cadena);
				// Obtener la fecha y hora actual
				$fechaActual = date('d');
				$horaActual = date('H');
				$minutoActual = date('i');
				$segundoActual = date('s');     
				// Obtener los valores de día, hora, minuto y segundo de la cadena

				$diaCadena = $array[0];
				$horaCadena = $array[1];
				$minutoCadena = $array[2];
				$segundoCadena = $array[3];

				error_log( "\n flag".$flagTime." \n ");
				error_log( "\n dia cadena".$diaCadena." \n ");
				error_log( "\n dia hora".$horaCadena." \n ");
				error_log( "\n dia minuto".$minutoCadena." \n");
				error_log( "\n dia segundo".$segundoCadena." \n");
				error_log( "\n fecha actual".$fechaActual." \n");
				error_log( "\n segundo actual".$segundoActual." \n");

				// Comprobar si el día y la hora son los mismos


				if ($diaCadena == $fechaActual && $horaCadena == $horaActual) {
					// Convertir los minutos y segundos de la cadena a segundos
					$segundosCadena = ($minutoCadena * 60) + $segundoCadena;
					error_log( "\n segundos cadena ".$segundosCadena." \n");


					// Calcular el límite superior del rango
					error_log("mimite la sumaa de $segundosCadena + $flagTime ");
					$limiteSuperior = $segundosCadena + $flagTime;  
					error_log( "limite superior".$limiteSuperior);


					// Calcular la diferencia de tiempo en segundos
					 $segundosActuales = ($minutoActual * 60) + $segundoActual - $segundoCadena;
					error_log( "\n segundosActuales".$segundosActuales." \n");
					 $diferencia = $segundosActuales - $segundosCadena;

					error_log( "\n diferencia   ".$diferencia."  \n"."\n");

					$flagTime = $flagTime + $segundoCadena;
					// Verificar si la diferencia de tiempo está dentro del rango permitido
					//if ($diferencia >= 0 && $diferencia <= $flagTime && $segundosActuales <= $limiteSuperior) {
				if ($diferencia <= $flagTime && $segundosActuales <= $limiteSuperior) {
					error_log("despues de validacion tiempo  ");

					$request = $cadena_empleado;
					if($request != ''){

						error_log($request);
						$mensaje_decifrado = encryptor('decrypt',$request);
						//echo $mensaje_decifrado;
											error_log("codigo decript");

						error_log($mensaje_decifrado);
						if($mensaje_decifrado != ''){
							//error_log("tercer if TRY desencripto el mensaje");
							try {
								$mensaje_explode = explode(";",$mensaje_decifrado);

								$empleadoF = $mensaje_explode[0];
								$empleadoactiv = $mensaje_explode[3];
								//$empleadoactiv = $mensaje_explode[6];
								$tipoTarjeta = $mensaje_explode[4];
								//$tipoTarjeta = $mensaje_explode[7];

								if(!empty($empleadoF)){
									error_log("entro por el empelado F $empleadoF");
									$acceso='';
									//error_log("cuarto if se aplico explode ");
									$userF = "Scanner";
									//$empresaF = $mensaje_explode[4];

									$dias = array("Domingo","Lunes","Martes","Miercoles","Jueves","Viernes","Sábado");

									$dia = $dias[date('w')];
									$Hoy = date("Y-m-d H:i:s");

									$Hoyd = date("Y-m-d");

									//$sql_acceso = "SELECT empleadoID,acceso,DepartamentoID,puestoID,fotografia FROM tb_empleados WHERE empleadoID = ".$empleadoF;
										$sql_acceso = "SELECT emp.empleadoID,emp.acceso,ifnull(emp.DepartamentoID,0) as DepartamentoID,ifnull(emp.puestoID,0) as puestoID,emp.fotografia,ifnull(ro.departamento,'no tiene') as departamento,ifnull(pu.nombre,'no tiene') as nombre , concat(emp.nombre, ' ', emp.apellidoP, ' ', emp.apellidoM) as nombreCompleto,empr.nombreComercial,emp.estatus,emp.NSS,emp.comedor,emp.empresaID FROM tb_empleados  as emp
			LEFT JOIN cat_roles ro ON ro.DepartamentoID = emp.DepartamentoID
			LEFT JOIN cat_puesto pu ON pu.puestoID = emp.puestoID
			INNER JOIN tb_empresas empr ON empr.empresaID = emp.empresaID
			WHERE emp.estatus ='Activo' AND  emp.empleadoID =".$empleadoF;
										sc_lookup(acc, $sql_acceso);
	error_log($sql_acceso);
							if (!empty({acc[0][0]})) {
							 $acceso = {acc[0][1]};
							 $departamento = {acc[0][2]};
							 $puesto = {acc[0][3]};
							 $fotoN = {acc[0][4]};
							 $departamentName = {acc[0][5]};
							 $puestoName = {acc[0][6]};
							 $NameComplet = {acc[0][7]};
							 $empresaName = {acc[0][8]};
							 $estatusEM = {acc[0][9]};
							 $nssINV = {acc[0][10]};
							 $comedorflg = {acc[0][11]};
							 $empresasI = {acc[0][12]};
							 $rutafoto = fnConfsis_v2(2,'N');
							if($fotoN == ''){
								//$fotoN = $rutafoto'/grp__NM__bg__NM__userDOMO.png';
								// $vrLogo='/scriptcase/app/Gilneas/_lib/img/grp__NM__img__NM__falconlogoMI.png';
								$fotoN = '/scriptcase/app/Gilneas/_lib/img/_lib/img/grp__NM__bg__NM__userDOMO.png';
								$rutafotoCompleta = $fotoN;
							}else{
								//$rutafotoCompleta = $rutafoto.'/fotosEmpleados/'."1".$fotoN;
								$rutafotoCompleta = $rutafoto.'/fotosEmpleados/'.$fotoN;
							}
							//error_log("quinto if se se buscan los datos del empleado  ");
					}else{
										$array = array();
										$array = array(
											'empresa' => 'no exiten datos de empresa',
											'nombre' =>  'no exite nombre',
											'Departamento' =>  'No exite departamento',
											'puesto' =>  'No existe puesto'

										);

										$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'usuario Inactivo o Inexistente');
										header("HTTP/1.1 200 OK");
										echo json_encode($objResponse);

									}

									/*
										$check_sql = "SELECT empleadoID FROM tb_entrada_salida WHERE empleadoID = '"
														.  $empleadoF . "' AND  DATE_FORMAT(fechaH,'%Y-%m-%d') = '". $Hoyd . "'";
										sc_lookup(rs, $check_sql);

										if (!isset({rs[0][0]})) {
											 $acceso = 'Salida';
										}
								*/
									if($departamento == 2 || $departamento == 3 || $departamento == 4 || $departamento == 5 || $departamento == 6 || $departamento == 7 || $departamento == 14 || $departamento == 29 || $empresasI == 2){

										//if( in_array($departamento, array(2,3,4,5,6), true )){

									}else{
										$check_sql = "SELECT empleadoID FROM tb_entrada_salida WHERE empleadoID = '"
											.  $empleadoF . "' AND  DATE_FORMAT(fechaH,'%Y-%m-%d') = '". $Hoyd . "'";
										sc_lookup(rs, $check_sql);

										if (!isset({rs[0][0]})) {
											$acceso = 'Salida';
										}

									}

									if ($acceso == 'Entrada') {
										$update_sql = 'UPDATE tb_empleados SET acceso = "Salida" WHERE empleadoID = ' . $empleadoF;
										$update_sql_p = "UPDATE tb_empleados SET puerto = '$puertoSal' WHERE empleadoID =" . $empleadoF;
										$vtipo=2;
										$mensajeAccion = 'Salida';
									} else {
										$update_sql = 'UPDATE tb_empleados SET acceso = "Entrada" WHERE empleadoID =' . $empleadoF;
										$update_sql_p = "UPDATE tb_empleados SET puerto = '$puertoEn' WHERE empleadoID =" . $empleadoF;
										$vtipo=1;
										$mensajeAccion = 'Entrada';
									}
									sc_exec_sql($update_sql);
									sc_exec_sql($update_sql_p);

									if($nssINV == '88888888888'){
										$check_Acceso_Inv = "SELECT invitadoID,empleadoID,empresaID,nombre,MotivoVisita FROM tb_invitados WHERE empleadoID = ".$empleadoF." AND DATE_FORMAT(fechaRegistro,'%Y-%m-%d') = '". $Hoyd . "' ORDER BY invitadoID  DESC LIMIT 1";
										sc_lookup(invv,$check_Acceso_Inv);
										//echo $check_Acceso_Inv;
										if (!empty({invv[0][0]})){
											$invt={invv[0][0]};
								 $NameComplet={invv[0][3]};


							$insert_sqlINV = 'INSERT INTO tb_entrada_salida'
								. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, IDinvitado, hadware, latitud, longitud, direccion, compania,ubicacion_Acc)'
								. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'", '.$invt.', "'.$hadwareB.'", "'.$latitude.'", "'.$longitude.'", "'.$address.'", "'.$company.'", "'.$locate.'" )';
							sc_exec_sql($insert_sqlINV);
							
							// Validar geocerca para invitados
							$lastInsertID = 0;
							$query_last_id = "SELECT LAST_INSERT_ID()";
							sc_lookup(last_id, $query_last_id);
							if (!empty({last_id[0][0]})) {
								$lastInsertID = {last_id[0][0]};
								
								if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
									$geocercaResult = validarGeocerca($empleadoF, $latitude, $longitude);
									
									$update_geocerca = "UPDATE tb_entrada_salida SET 
										geocercaID = " . ($geocercaResult['geocercaID'] ? $geocercaResult['geocercaID'] : 'NULL') . ",
										distanciaMetros = " . ($geocercaResult['distanciaMetros'] ? $geocercaResult['distanciaMetros'] : 'NULL') . ",
										validacionGeocerca = '" . $geocercaResult['validacion'] . "'
										WHERE salidEnt = $lastInsertID";
									sc_exec_sql($update_geocerca);
								}
							}
							}else{
											$NameComplet="Sin registro de invitado";
										}


									}elseif($nssINV == '99999999999'){
										/*
										$insert_sql = 'INSERT INTO tb_Ac_proveedor_almacen '
											. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso)'
											. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'" )';
										sc_exec_sql($insert_sql);
										*/
										$check_Acceso_Prov = "SELECT ProveedorID,empleadoID,empresaID,nombre,MotivoVisita FROM tb_proveedores_Acc WHERE empleadoID = ".$empleadoF." AND DATE_FORMAT(fechaRegistro,'%Y-%m-%d') = '". $Hoyd . "' ORDER BY ProveedorID  DESC LIMIT 1";
										sc_lookup(prov,$check_Acceso_Prov);
										//echo $check_Acceso_Inv;
										if (!empty({prov[0][0]})){
											$prove={prov[0][0]};
								 $NameComplet={prov[0][3]};


							$insert_sqlProv = 'INSERT INTO tb_entrada_salida'
								. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, IDproveedor, hadware, latitud, longitud, direccion, compania, ubicacion_Acc)'
								. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'", '.$prove.', "'.$hadwareB.'", "'.$latitude.'", "'.$longitude.'", "'.$address.'", "'.$company.'", "'.$locate.'" )';
							sc_exec_sql($insert_sqlProv);
							
							// Validar geocerca para proveedores
							$lastInsertID = 0;
							$query_last_id = "SELECT LAST_INSERT_ID()";
							sc_lookup(last_id, $query_last_id);
							if (!empty({last_id[0][0]})) {
								$lastInsertID = {last_id[0][0]};
								
								if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
									$geocercaResult = validarGeocerca($empleadoF, $latitude, $longitude);
									
									$update_geocerca = "UPDATE tb_entrada_salida SET 
										geocercaID = " . ($geocercaResult['geocercaID'] ? $geocercaResult['geocercaID'] : 'NULL') . ",
										distanciaMetros = " . ($geocercaResult['distanciaMetros'] ? $geocercaResult['distanciaMetros'] : 'NULL') . ",
										validacionGeocerca = '" . $geocercaResult['validacion'] . "'
										WHERE salidEnt = $lastInsertID";
									sc_exec_sql($update_geocerca);
								}
							}
							}else{
											$NameComplet="Sin registro de Proveedor";
										}

									}else{

										$insert_sql = 'INSERT INTO tb_entrada_salida '
											. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, hadware, latitud, longitud, direccion, compania, ubicacion_Acc)'
											. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'", "'.$hadwareB.'", "'.$latitude.'", "'.$longitude.'", "'.$address.'", "'.$company.'", "'.$locate.'" )';
										sc_exec_sql($insert_sql);
										
										// Validar geocerca después del INSERT
										$lastInsertID = 0;
										$query_last_id = "SELECT LAST_INSERT_ID()";
										sc_lookup(last_id, $query_last_id);
										if (!empty({last_id[0][0]})) {
											$lastInsertID = {last_id[0][0]};
											
											// Validar geocerca si tenemos coordenadas
											if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
												$geocercaResult = validarGeocerca($empleadoF, $latitude, $longitude);
												
												// Actualizar el registro con los datos de geocerca
												$update_geocerca = "UPDATE tb_entrada_salida SET 
													geocercaID = " . ($geocercaResult['geocercaID'] ? $geocercaResult['geocercaID'] : 'NULL') . ",
													distanciaMetros = " . ($geocercaResult['distanciaMetros'] ? $geocercaResult['distanciaMetros'] : 'NULL') . ",
													validacionGeocerca = '" . $geocercaResult['validacion'] . "'
													WHERE salidEnt = $lastInsertID";
												sc_exec_sql($update_geocerca);
												
												error_log("Geocerca validada - ID: " . $geocercaResult['geocercaID'] . 
													", Distancia: " . $geocercaResult['distanciaMetros'] . 
													", Validación: " . $geocercaResult['validacion']);
											}
										}
									}

									//error_log("Realiza el insert de la entrada");
									$check_Acceso = 'SELECT empleadoID,acceso FROM tb_empleados WHERE empleadoID ='.$empleadoF;
									sc_lookup(acc,$check_Acceso);
									if (!empty({acc[0][0]})){
										$accesoE={acc[0][1]};
							 $empID = {acc[0][0]};

							$AccesoComedor = "SELECT salidEnt, empleadoID, tipo,ubicacion_Acc,fechaH FROM tb_entrada_salida WHERE empleadoID = '" .  $empID . "' AND ubicacion_Acc = 'Comedor' AND  DATE_FORMAT(fechaH,'%Y-%m-%d')= '". $Hoyd . "' ORDER BY fechaH  DESC limit 1";

							sc_lookup(accA,$AccesoComedor);
							$Salid= 0;
							if (!empty({accA[0][0]})){
											$Ubicc={accA[0][3]};
								 $Salid = {accA[0][0]};
							}
							/*
							 $Salid= 0;
							 $query_last = "SELECT last_insert_id();";
							 sc_lookup(rstl,$query_last);
							 $Salid = {rstl[0][0]};
							 */
						}


									$array = array();
									$array = array(
										'EntSalID' => $Salid,
										'empleadoID' => $empID,
										'empresa' => $empresaName,
										'nombre' => $NameComplet,
										'Departamento' => $departamentName,
										'puesto' => $puestoName,
										'fotografia' => $rutafotoCompleta,
										'Acceso' => $accesoE,
										'tipoCredencial' => $tipoTarjeta,
										'comedorFLG' =>$comedorflg
									);

									$objResponse = array('estatus'=>'1','detalles'=>$array,'mensaje'=>'¡Registro de ' . $mensajeAccion . ' exitoso!');
									header("HTTP/1.1 200 OK");
									echo json_encode($objResponse);


									if($vtipo == 2){

										$Salid= 0;
										$query_last = "SELECT last_insert_id();";
										sc_lookup(rstl,$query_last);
										$Salid = {rstl[0][0]};
							$setencia ="CALL pdHorasTrabajadas_m($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
							sc_exec_sql($setencia);
							//error_log("salida $Salid tipo $vtipo empleado $empleadoF dia $dia hoy $Hoy entra a la validacion del tipo y proceso call");
						}elseif($vtipo == 1){
										$Salid= 0;
										$query_last = "SELECT last_insert_id();";
										sc_lookup(rstl,$query_last);
										$Salid = {rstl[0][0]};
							$setencia ="CALL pdHorasComedor_m($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
							sc_exec_sql($setencia);
							//error_log("salida $Salid tipo $vtipo empleado $empleadoF dia $dia hoy $Hoy entra a la validacion del tipo y proceso call");

						}



									if($vtipo == 1 && $empresasI != 2){
										error_log("entro a la validaccion tiempo fuera ");
										$Salid= 0;
										$query_last = "SELECT last_insert_id();";
										sc_lookup(rstl,$query_last);

										$Salid = {rstl[0][0]};

					$setenciaF ="CALL pdHorasFuera($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
					sc_exec_sql($setenciaF);
					error_log("salida $Salid tipo $vtipo empleado $empleadoF dia $dia hoy $Hoy entra a la validacion del tipo y proceso call");
					//error_log("salida $Salid tipo $vtipo empleado $empleadoF dia $dia hoy $Hoy entra a la validacion del tipo y proceso call");

					}



							//error_log("enntro ws pruba");
							//proceso puntualidad sin tomar comedor
							$rgcomedor = "SELECT salidEnt, empleadoID, tipo,ubicacion_Acc,fechaH FROM tb_entrada_salida WHERE empleadoID = '" .  $empleadoF . "' ORDER BY fechaH  DESC limit 1";
							sc_lookup(accAC,$rgcomedor);
							error_log($rgcomedor);
							$Salid= 0;
							if (!empty({accAC[0][0]})){
								$Ubicacion={accAC[0][3]};
						 if($Ubicacion == "Comedor"){
							 error_log("entro al proceso puntaulidad");

						 }elseif($vtipo == 1 && $empresasI != 2){
							 error_log("paso al vr tipo 1 ");

							 $Salid2= 0;
							 $query_lasts = "SELECT last_insert_id();";
							 sc_lookup(rstl,$query_lasts);
							 $Salid2 = {rstl[0][0]};
							$setencia2 ="CALL pdPuntualidad_m($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
							sc_exec_sql($setencia2);


						 }

							}




								}else{
									$array = array();
									$array = array(
										'empresa' => 'no exiten datos de empresa',
										'nombre' =>  'no exite nombre',
										'Departamento' =>  'No exite departamento',
										'puesto' =>  'No existe puesto'

									);

									$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'No se encontraron datos del Empleado o no esta activo');
									header("HTTP/1.1 200 OK");
									echo json_encode($objResponse);
								}

							}catch (Exception $e) {
								$array = array();
								$array = array(
									'empresa' => 'No se puede decodificar la informacion',
									'nombre' =>  'No se puede decodificar la informacion',
									'Departamento' =>  'No se puede decodificar la informacion',
									'puesto' =>  'No se puede decodificar la informacion'
								);

								$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'No se puede decodificar la informacion');
								header("HTTP/1.1 200 OK");
								echo json_encode($objResponse);
							}

						}else{
							$array = array();
							$array = array(
								'empresa' => 'No existe la cadena',
								'nombre' =>  'No existe la cadena',
								'Departamento' =>  'No existe la cadena',
								'puesto' =>  'No existe la cadena'

							);

							$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'No existe la cadena');
							header("HTTP/1.1 200 OK");
							echo json_encode($objResponse);


						}//tercer if
					}//segundo IF
				} else {
					header("HTTP/1.1 200 OK");
					$array = array();
					$array = array('estatus' => '0', 'mensaje' => "Tu codigo QR expiro pasando los  minutos", 'DEFAULT' => 'ADIOS popo att: raul');
					echo json_encode($array);
				}

			}else{
					header("HTTP/1.1 200 OK");
					$array = array();
					$array = array('estatus' => '0', 'mensaje' => "Tu codigo QR expiro pasando los  dias y  hora", 'DEFAULT' => 'ADIOS popo att: raul');
					echo json_encode($array);
			}

			exit();
			break;
				 		 
			case 'RegistroEntradaOffline':
				// Nuevo case para procesar registros offline (esPendiente == '1')
				error_log('Entrando a RegistroEntradaOffline');
				try {
					$request = isset($_GET['request']) ? $_GET['request'] : 'empty';
					error_log('Request recibido: ' . $request);
					$address = isset($_GET['direccion']) ? $_GET['direccion'] : 'empty';
					$company = isset($_GET['empresa']) ? $_GET['empresa'] : 'empty';
					$locate = isset($_GET['ubicacionAcc']) ? $_GET['ubicacionAcc'] : 'empty';
					$hadwareB = isset($_GET['tipoHard']) ? $_GET['tipoHard'] : 'empty';
					$puertoEn = 'COM4';
					$puertoSal = 'COM5';
	                // Leer el tipo enviado por la app (1=entrada, 2=salida)
	                //$tipo = isset($_GET['tipo']) ? intval($_GET['tipo']) : 1;

					$partes = explode(":", $request);
					error_log('Partes del request: ' . print_r($partes, true));
					if (count($partes) < 2) {
						error_log('Formato de request inválido para registro offline');
						echo json_encode(['estatus' => '0', 'mensaje' => 'Formato de request inválido para registro offline']);
						exit();
					}
					$cadena_empleado = $partes[1];
					error_log('Cadena empleado: ' . $cadena_empleado);
					if ($cadena_empleado != '') {
						$mensaje_decifrado = encryptor('decrypt', $cadena_empleado);
						error_log('Mensaje decifrado: ' . $mensaje_decifrado);
						if ($mensaje_decifrado != '') {
							try {
								$mensaje_explode = explode(";", $mensaje_decifrado);
								error_log('Explode mensaje: ' . print_r($mensaje_explode, true));
								$empleadoF = $mensaje_explode[0];
								$empleadoactiv = $mensaje_explode[3];
								$tipoTarjeta = $mensaje_explode[4];
								if (!empty($empleadoF)) {
									$userF = "Scanner";
									$dias = array("Domingo","Lunes","Martes","Miercoles","Jueves","Viernes","Sábado");
									$dia = $dias[date('w')];
									$Hoy = date("Y-m-d H:i:s");
									$Hoyd = date("Y-m-d");
									$sql_acceso = "SELECT emp.empleadoID,emp.acceso,ifnull(emp.DepartamentoID,0) as DepartamentoID,ifnull(emp.puestoID,0) as puestoID,emp.fotografia,ifnull(ro.departamento,'no tiene') as departamento,ifnull(pu.nombre,'no tiene') as nombre , concat(emp.nombre, ' ', emp.apellidoP, ' ', emp.apellidoM) as nombreCompleto,empr.nombreComercial,emp.estatus,emp.NSS,emp.comedor,emp.empresaID,IFNULL(emp.reg_remoto,0) as reg_remoto FROM tb_empleados  as emp
			LEFT JOIN cat_roles ro ON ro.DepartamentoID = emp.DepartamentoID
			LEFT JOIN cat_puesto pu ON pu.puestoID = emp.puestoID
			INNER JOIN tb_empresas empr ON empr.empresaID = emp.empresaID
			WHERE emp.estatus ='Activo' AND  emp.empleadoID =".$empleadoF;
									error_log('SQL acceso: ' . $sql_acceso);
									sc_lookup(acc, $sql_acceso);
									if (!empty({acc[0][0]})) {
										$acceso = {acc[0][1]};
										$departamento = {acc[0][2]};
										$puesto = {acc[0][3]};
										$fotoN = {acc[0][4]};
										$departamentName = {acc[0][5]};
										$puestoName = {acc[0][6]};
										$NameComplet = {acc[0][7]};
										$empresaName = {acc[0][8]};
										$estatusEM = {acc[0][9]};
										$nssINV = {acc[0][10]};
										$comedorflg = {acc[0][11]};
										$empresasI = {acc[0][12]};
										$reg_remoto = intval({acc[0][13]});
										
										// Validar si el empleado tiene permiso de registro remoto
										if ($reg_remoto == 0) {
											error_log("RegistroEntradaOffline BLOQUEADO: Empleado $empleadoF ($NameComplet) NO tiene permiso de registro remoto (reg_remoto=0)");
											echo json_encode([
												'estatus' => '0',
												'mensaje' => 'No tienes permiso para realizar registros remotos. Contacta a Recursos Humanos para solicitar acceso.',
												'codigo' => 'SIN_PERMISO_REMOTO'
											]);
											exit();
										}
										error_log("RegistroEntradaOffline PERMITIDO: Empleado $empleadoF tiene reg_remoto=$reg_remoto");
										
										$rutafoto = fnConfsis_v2(2,'N');
										if($fotoN == ''){
											$fotoN = '/scriptcase/app/Gilneas/_lib/img/_lib/img/grp__NM__bg__NM__userDOMO.png';
											$rutafotoCompleta = $fotoN;
										}else{
											$rutafotoCompleta = $rutafoto.'/fotosEmpleados/'.$fotoN;
										}
										error_log('Empleado encontrado: ' . $empleadoF . ' - ' . $NameComplet);
									} else {
										error_log('Empleado inactivo o inexistente');
										echo json_encode(['estatus' => '0', 'mensaje' => 'Empleado inactivo o inexistente']);
										exit();
									}
									// Extraer datos originales (hora, latitud, longitud) del primer segmento del request
	                $cadena_tiempo = $partes[0];
	                $array_tiempo = explode(",", $cadena_tiempo);
	                $diaCadena = isset($array_tiempo[0]) ? $array_tiempo[0] : date('d');
	                $horaCadena = isset($array_tiempo[1]) ? $array_tiempo[1] : date('H');
	                $minutoCadena = isset($array_tiempo[2]) ? $array_tiempo[2] : date('i');
	                $segundoCadena = isset($array_tiempo[3]) ? $array_tiempo[3] : date('s');
	                $latitude = isset($array_tiempo[4]) ? $array_tiempo[4] : '';
	                $longitude = isset($array_tiempo[5]) ? $array_tiempo[5] : '';
	                // Construir la fecha y hora original
	                $anioActual = date('Y');
	                $mesActual = date('m');
	                $fechaH = $anioActual.'-'.$mesActual.'-'.$diaCadena.' '.str_pad($horaCadena,2,'0',STR_PAD_LEFT).':'.str_pad($minutoCadena,2,'0',STR_PAD_LEFT).':'.str_pad($segundoCadena,2,'0',STR_PAD_LEFT);
	                $Hoyd = $anioActual.'-'.$mesActual.'-'.$diaCadena;
	                $dia = array("Domingo","Lunes","Martes","Miercoles","Jueves","Viernes","Sábado")[date('w', strtotime($Hoyd))];
	                // --- Alternancia robusta por día ---
	                $ultimo_sql = "SELECT tipo FROM tb_entrada_salida WHERE empleadoID = '$empleadoF' AND DATE(fechaH) = '$Hoyd' AND fechaH < '$fechaH' ORDER BY fechaH DESC LIMIT 1";
	                sc_lookup(ult, $ultimo_sql);
	                $esPrimerRegistroDelDia = false; // Bandera para identificar primer registro del día
	                
	                if (!empty({ult}) && isset({ult[0][0]})) {
	                    $ultimoTipo = intval({ult[0][0]});
	                    if ($ultimoTipo === 1) {
	                        $vtipo = 2; // Si el último fue entrada, ahora salida
	                    } else {
	                        $vtipo = 1; // Si el último fue salida, ahora entrada
	                    }
	                } else {
	                    $vtipo = 1; // Si no hay registro ese día, es entrada
	                    $esPrimerRegistroDelDia = true; // Es el primer registro del día
	                }
	                
	                error_log("OFFLINE - Tipo determinado: $vtipo, Es primer registro del día: " . ($esPrimerRegistroDelDia ? 'SI' : 'NO'));
	                
	                // Actualizar acceso y puerto según el tipo
	                if ($vtipo == 2) {
	                    $update_sql = 'UPDATE tb_empleados SET acceso = "Salida" WHERE empleadoID = ' . $empleadoF;
	                    $update_sql_p = "UPDATE tb_empleados SET puerto = '$puertoSal' WHERE empleadoID =" . $empleadoF;
	                } else {
	                    $update_sql = 'UPDATE tb_empleados SET acceso = "Entrada" WHERE empleadoID =' . $empleadoF;
	                    $update_sql_p = "UPDATE tb_empleados SET puerto = '$puertoEn' WHERE empleadoID =" . $empleadoF;
	                }
	                sc_exec_sql($update_sql);
	                sc_exec_sql($update_sql_p);
					// --- Insert igual que en RegistroEntrada, usando $vtipo ---
	$insert_sql = 'INSERT INTO tb_entrada_salida '
	    . ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, hadware, latitud, longitud, direccion, compania, ubicacion_Acc)'
	    . ' VALUES ('.$empleadoF.', "'.$fechaH.'", '.$vtipo.', "'.$dia.'", "'.$fechaH.'", "'.$userF.'", "'.$tipoTarjeta.'", "'.$hadwareB.'", "'.$latitude.'", "'.$longitude.'", "'.$address.'", "'.$company.'", "'.$locate.'" )';
	error_log('Insert SQL: ' . $insert_sql);
	sc_exec_sql($insert_sql);
	
	// Validar geocerca después del INSERT offline
	$lastInsertID = 0;
	$query_last_id = "SELECT LAST_INSERT_ID()";
	sc_lookup(last_id, $query_last_id);
	if (!empty({last_id[0][0]})) {
		$lastInsertID = {last_id[0][0]};
		
		// VALIDAR GEOCERCA solo en el PRIMER registro de entrada del día
		// Los registros subsiguientes NO requieren validación
		if ($vtipo == 1 && !empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
			$geocercaResult = validarGeocerca($empleadoF, $latitude, $longitude);
			
			// Determinar estado de validación automático
			$estadoValidacionOffline = 'No_Requiere';
			$comentarioOffline = '';
			
			// IMPORTANTE: Solo el PRIMER registro del día puede requerir validación
			// Si es fuera de geocerca Y es el primer registro del día, marcar como Pendiente
			if ($geocercaResult['validacion'] === 'Fuera' && $esPrimerRegistroDelDia) {
				$estadoValidacionOffline = 'Pendiente';
				// Comentario mejorado con dirección y formato solicitado
				$comentarioOffline = 'OFFLINE Fuera de zona - ' . $address . ' - Requiere validación por falta de red';
				error_log("OFFLINE ENTRADA (PRIMER REGISTRO): Fuera de zona - Marcado como Pendiente (EmpleadoID: $empleadoF)");
			} else if ($geocercaResult['validacion'] === 'Fuera') {
				// Fuera de zona pero NO es el primer registro, no requiere validación
				$estadoValidacionOffline = 'No_Requiere';
				error_log("OFFLINE ENTRADA (NO ES PRIMER REGISTRO): Fuera de zona - No requiere validación (EmpleadoID: $empleadoF)");
			} else {
				error_log("OFFLINE ENTRADA: En zona - No requiere validación (EmpleadoID: $empleadoF)");
			}
			
			// Actualizar el registro con datos de geocerca Y estado de validación
			$update_geocerca = "UPDATE tb_entrada_salida SET 
				geocercaID = " . ($geocercaResult['geocercaID'] ? $geocercaResult['geocercaID'] : 'NULL') . ",
				distanciaMetros = " . ($geocercaResult['distanciaMetros'] ? $geocercaResult['distanciaMetros'] : 'NULL') . ",
				validacionGeocerca = '" . $geocercaResult['validacion'] . "',
				estadoValidacionGeocerca = '$estadoValidacionOffline',
				comentario = '" . addslashes($comentarioOffline) . "'
				WHERE salidEnt = $lastInsertID";
			sc_exec_sql($update_geocerca);
			
			error_log("OFFLINE ENTRADA - Geocerca validada: ID=" . $geocercaResult['geocercaID'] . 
				", Dist=" . $geocercaResult['distanciaMetros'] . "m" .
				", Val=" . $geocercaResult['validacion'] . 
				", Estado=" . $estadoValidacionOffline .
				", EsPrimerRegistro=" . ($esPrimerRegistroDelDia ? 'SI' : 'NO'));
		} else {
			error_log("OFFLINE: Tipo $vtipo - No se valida geocerca (solo tipo 1/entrada requiere validación)");
		}
	}
	
	// --- Ejecutar procedimientos igual que en flujo online ---
	$Salid = 0;
	$query_last = "SELECT last_insert_id();";
	sc_lookup(rstl, $query_last);
	$Salid = isset({rstl[0][0]}) ? {rstl[0][0]} : 0;
	if ($vtipo == 2) {
	    $setencia = "CALL pdHorasTrabajadas_m($Salid,$vtipo,$empleadoF,'$dia','$fechaH');";
	    sc_exec_sql($setencia);
	    error_log("OFFLINE Ejecutado pdHorasTrabajadas_m: $setencia");
	} elseif ($vtipo == 1) {
	    $setencia = "CALL pdHorasComedor_m($Salid,$vtipo,$empleadoF,'$dia','$fechaH');";
	    sc_exec_sql($setencia);
	    error_log("OFFLINE Ejecutado pdHorasComedor_m: $setencia");
	}
	if ($vtipo == 1 && $empresasI != 2) {
	    $setenciaF = "CALL pdHorasFuera($Salid,$vtipo,$empleadoF,'$dia','$fechaH');";
	    sc_exec_sql($setenciaF);
	    error_log("OFFLINE Ejecutado pdHorasFuera: $setenciaF");
	}
	// Proceso puntualidad sin tomar comedor
	$rgcomedor = "SELECT salidEnt, empleadoID, tipo,ubicacion_Acc,fechaH FROM tb_entrada_salida WHERE empleadoID = '" .  $empleadoF . "' ORDER BY fechaH  DESC limit 1";
	sc_lookup(accAC,$rgcomedor);
	$Salid2= 0;
	if (!empty({accAC[0][0]})){
	    $Ubicacion={accAC[0][3]};
	    $Salid2 = {accAC[0][0]};
	    if($Ubicacion == "Comedor"){
	        error_log("OFFLINE Proceso puntualidad: ubicación Comedor");
	    }elseif($vtipo == 1 && $empresasI != 2){
	        error_log("OFFLINE Proceso puntualidad: tipo 1");
	        $query_lasts = "SELECT last_insert_id();";
	        sc_lookup(rstl,$query_lasts);
	        $Salid2 = {rstl[0][0]};
	        $setencia2 = "CALL pdPuntualidad_m($Salid2,$vtipo,$empleadoF,'$dia','$fechaH');";
	        sc_exec_sql($setencia2);
	        error_log("OFFLINE Ejecutado pdPuntualidad_m: $setencia2");
	    }
	}
	
	// ============================================================
	// ENVÍO DE NOTIFICACIÓN AL JEFE SI ESTÁ FUERA DE GEOCERCA
	// ============================================================
	$urlValidacionOffline = null;
	$jefeNotificado = false;
	
	// Verificar si el registro quedó como Pendiente (fuera de geocerca)
	if (isset($estadoValidacionOffline) && $estadoValidacionOffline === 'Pendiente' && isset($lastInsertID) && $lastInsertID > 0) {
		error_log("OFFLINE: Registro fuera de geocerca - Buscando jefe para notificar");
		
		// Buscar jefe directo
		$sql_jefe_offline = "SELECT ei.jefeID FROM empleados_info ei INNER JOIN tb_empleados e ON e.empleadoID = ei.empleadoID WHERE e.empleadoID = $empleadoF LIMIT 1";
		sc_lookup(jefe_offline_rs, $sql_jefe_offline);
		$jefeEmpleadoInfoID_offline = !empty({jefe_offline_rs}) ? {jefe_offline_rs[0][0]} : null;
		
		// Si no hay jefe directo, buscar por departamento
		if (empty($jefeEmpleadoInfoID_offline)) {
			$sql_jefe_dept_offline = "SELECT jefe_empleadoInfoID FROM cat_jefes_departamento WHERE departamento = (SELECT DepartamentoID FROM tb_empleados WHERE empleadoID = $empleadoF) LIMIT 1";
			sc_lookup(jefe_dept_offline_rs, $sql_jefe_dept_offline);
			$jefeEmpleadoInfoID_offline = !empty({jefe_dept_offline_rs}) ? {jefe_dept_offline_rs[0][0]} : null;
		}
		
		// Obtener datos del jefe
		if (!empty($jefeEmpleadoInfoID_offline)) {
			$sql_jefe_data_offline = "SELECT e.empleadoID, e.telefono, e.nombre, e.apellidoP, e.apellidoM, e.correo FROM tb_empleados e INNER JOIN empleados_info ei ON e.empleadoID = ei.empleadoID WHERE ei.empleadoInfoID = $jefeEmpleadoInfoID_offline LIMIT 1";
			sc_lookup(jefe_data_offline_rs, $sql_jefe_data_offline);
			
			if (!empty({jefe_data_offline_rs})) {
				$jefeID_offline = {jefe_data_offline_rs[0][0]};
				$telefonoJefe_offline = {jefe_data_offline_rs[0][1]};
				$nombreJefe_offline = trim({jefe_data_offline_rs[0][2]} . ' ' . {jefe_data_offline_rs[0][3]} . ' ' . {jefe_data_offline_rs[0][4]});
				$correoJefe_offline = !empty({jefe_data_offline_rs[0][5]}) ? {jefe_data_offline_rs[0][5]} : null;
				
				// Generar URL de validación
				$urlValidacionOffline = generarUrlValidacion($lastInsertID, $empleadoF, $jefeID_offline);
				
				error_log("OFFLINE: Jefe encontrado - ID=$jefeID_offline, Tel=$telefonoJefe_offline, Correo=$correoJefe_offline");
				
				// Generar hash para validación de email
				$secret_key = 'bsys_geocerca_2025_secret_key';
				$data_to_hash_offline = $lastInsertID . '|' . $empleadoF . '|' . $jefeID_offline . '|' . $secret_key;
				$hash_email_offline = substr(hash('sha256', $data_to_hash_offline), 0, 16);
				
				// Generar URLs de acción para el correo
				$url_base_offline = 'https://dev.bsys.mx/scriptcase/app/Gilneas/validar_geocerca/validar_geocerca.php';
				$url_aprobar_offline = $url_base_offline . "?s=$lastInsertID&e=$empleadoF&j=$jefeID_offline&h=$hash_email_offline&accion=aprobar";
				$url_rechazar_offline = $url_base_offline . "?s=$lastInsertID&e=$empleadoF&j=$jefeID_offline&h=$hash_email_offline&accion=rechazar";
				
				// ============================================================
				// ENVÍO DE CORREO (MÁS CONFIABLE QUE SMS)
				// ============================================================
				if (!empty($correoJefe_offline)) {
					error_log("OFFLINE: Enviando email a: $correoJefe_offline");
					
					$mail_smtp_server = 'smtp.gmail.com';
					$mail_smtp_user   = 'helpbinfo@gmail.com';
					$mail_smtp_pass   = 'wovrqesluirppipu';
					$mail_from        = 'helpbinfo@gmail.com';
					$mail_to_offline  = $correoJefe_offline;
					$mail_subject_offline = '🔔 OFFLINE Validación de Asistencia - ' . $NameComplet;
					
					$mail_message_offline = '<html><head><meta charset="utf-8"><style>';
					$mail_message_offline .= 'body{background:#f7f8fa;font-family:Arial,sans-serif;color:#222;margin:0;padding:0;}';
					$mail_message_offline .= '.card{background:#fff;border-radius:12px;max-width:600px;margin:30px auto;box-shadow:0 4px 20px rgba(0,0,0,0.1);overflow:hidden;}';
					$mail_message_offline .= '.header{background:linear-gradient(135deg,#ff6b35 0%,#f7931e 100%);color:#fff;padding:24px;text-align:center;}';
					$mail_message_offline .= '.content{padding:28px;}';
					$mail_message_offline .= '.info{background:#fff3e0;border-left:4px solid #ff6b35;padding:14px;margin:16px 0;border-radius:4px;}';
					$mail_message_offline .= '.btn-container{text-align:center;margin:30px 0;}';
					$mail_message_offline .= '.btn{display:inline-block;padding:14px 40px;text-decoration:none;border-radius:8px;margin:8px;font-weight:bold;font-size:16px;}';
					$mail_message_offline .= '.btn-aprobar{background:#27ae60;color:#fff;}';
					$mail_message_offline .= '.btn-rechazar{background:#e74c3c;color:#fff;}';
					$mail_message_offline .= '.footer{text-align:center;color:#888;font-size:0.9em;padding:20px;border-top:1px solid #eee;}';
					$mail_message_offline .= '.offline-badge{background:#ff6b35;color:#fff;padding:4px 12px;border-radius:20px;font-size:0.8em;display:inline-block;margin-bottom:10px;}';
					$mail_message_offline .= '</style></head><body>';
					$mail_message_offline .= '<div class="card">';
					$mail_message_offline .= '<div class="header"><span class="offline-badge">📴 REGISTRO OFFLINE</span><h2 style="margin:10px 0 0 0;">Validación de Asistencia Requerida</h2></div>';
					$mail_message_offline .= '<div class="content">';
					$mail_message_offline .= '<p>Hola <strong>' . htmlspecialchars($nombreJefe_offline) . '</strong>,</p>';
					$mail_message_offline .= '<p>Se registró una asistencia <strong>sin conexión a internet</strong> y requiere tu validación:</p>';
					$mail_message_offline .= '<div class="info">';
					$mail_message_offline .= '<strong>👤 Empleado:</strong> ' . htmlspecialchars($NameComplet) . '<br>';
					$mail_message_offline .= '<strong>📅 Fecha:</strong> ' . date('d/m/Y H:i', strtotime($fechaH)) . '<br>';
					$mail_message_offline .= '<strong>📍 Ubicación:</strong> ' . htmlspecialchars($address) . '<br>';
					$mail_message_offline .= '<strong>⚠️ Situación:</strong> Registro fuera de zona asignada (sin conexión)<br>';
					$mail_message_offline .= '<strong>💬 Motivo:</strong> ' . htmlspecialchars($comentarioOffline);
					$mail_message_offline .= '</div>';
					$mail_message_offline .= '<div class="btn-container">';
					$mail_message_offline .= '<a href="' . htmlspecialchars($url_aprobar_offline) . '" class="btn btn-aprobar">✅ APROBAR</a>';
					$mail_message_offline .= '<a href="' . htmlspecialchars($url_rechazar_offline) . '" class="btn btn-rechazar">🚫 RECHAZAR</a>';
					$mail_message_offline .= '</div>';
					$mail_message_offline .= '<p style="font-size:0.85em;color:#666;text-align:center;margin-top:20px;">';
					$mail_message_offline .= 'Este registro se sincronizó cuando el empleado recuperó conexión a internet.';
					$mail_message_offline .= '</p>';
					$mail_message_offline .= '</div>';
					$mail_message_offline .= '<div class="footer">DRT Recursos Humanos &bull; Sistema de Asistencia &bull; ' . date('Y') . '</div>';
					$mail_message_offline .= '</div></body></html>';
					
					$mail_format_offline  = 'H';
					$mail_copies_offline  = '';
					$mail_tp_copies_offline = '';
					$mail_port_offline    = '587';
					$mail_security_offline = 'T';
					
					sc_mail_send(
						$mail_smtp_server,
						$mail_smtp_user,
						$mail_smtp_pass,
						$mail_from,
						$mail_to_offline,
						$mail_subject_offline,
						$mail_message_offline,
						$mail_format_offline,
						$mail_copies_offline,
						$mail_tp_copies_offline,
						$mail_port_offline,
						$mail_security_offline
					);
					
					$jefeNotificado = true;
					error_log("OFFLINE: Email enviado exitosamente al jefe - Correo: $correoJefe_offline");
				}
				
				// Enviar SMS si hay teléfono (usando textbee como en online)
				if (!empty($telefonoJefe_offline)) {
					// Formatear teléfono
					$telefono_intl_offline = $telefonoJefe_offline;
					if (strlen($telefonoJefe_offline) == 10 && substr($telefonoJefe_offline, 0, 1) !== '+') {
						$telefono_intl_offline = '+52' . $telefonoJefe_offline;
					} else if (substr($telefonoJefe_offline, 0, 2) === '52' && substr($telefonoJefe_offline, 0, 1) !== '+') {
						$telefono_intl_offline = '+' . $telefonoJefe_offline;
					}
					
					// Unificar formato SMS offline con correo y agregar dos ligas directas
					$secret_key_sms_off = 'bsys_geocerca_2025_secret_key';
					$data_to_hash_sms_off = $lastInsertID . '|' . $empleadoF . '|' . $jefeID_offline . '|' . $secret_key_sms_off;
					$hash_sms_off = substr(hash('sha256', $data_to_hash_sms_off), 0, 16);
					$url_base_sms_off = 'https://dev.bsys.mx/scriptcase/app/Gilneas/validar_geocerca/validar_geocerca.php';
					$url_aprobar_sms_off = $url_base_sms_off . "?s=$lastInsertID&e=$empleadoF&j=$jefeID_offline&h=$hash_sms_off&accion=aprobar";
					$url_rechazar_sms_off = $url_base_sms_off . "?s=$lastInsertID&e=$empleadoF&j=$jefeID_offline&h=$hash_sms_off&accion=rechazar";
					$mensaje_sms_offline = "🔔 Validación de Asistencia (OFFLINE)\n\n";
					$mensaje_sms_offline .= "Empleado: $NameComplet\n";
					$mensaje_sms_offline .= "Fecha: " . date('d/m/Y H:i', strtotime($fechaH)) . "\n";
					$mensaje_sms_offline .= "Ubicación: $address\n";
					$mensaje_sms_offline .= "Motivo: $comentarioOffline\n\n";
					$mensaje_sms_offline .= "✅ Aprobar: $url_aprobar_sms_off\n";
					$mensaje_sms_offline .= "🚫 Rechazar: $url_rechazar_sms_off";
					
					// Enviar SMS via API de textbee (mismas credenciales que online)
					$api_key_offline = 'a06862d8-f452-45a5-b9e5-6da701555901';
					$device_id_offline = '6876663ce7c673140c7ef5e6';
					$url_sms_api_offline = 'https://api.textbee.dev/api/v1/gateway/devices/' . $device_id_offline . '/send-sms';
					
					$data_sms_offline = json_encode(['recipients' => [$telefono_intl_offline], 'message' => $mensaje_sms_offline], JSON_UNESCAPED_UNICODE);
					
					$opts_sms_offline = [
						'http' => [
							'header'  => "Content-type: application/json\r\n" .
										 "x-api-key: $api_key_offline\r\n" .
										 "Accept: application/json",
							'method'  => 'POST',
							'content' => $data_sms_offline,
							'timeout' => 15
						]
					];
					
					$context_sms_offline = stream_context_create($opts_sms_offline);
					$result_sms_offline = @file_get_contents($url_sms_api_offline, false, $context_sms_offline);
					
					$http_code_offline = 0;
					if (isset($http_response_header)) {
						foreach ($http_response_header as $hdr) {
							if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $hdr, $m)) {
								$http_code_offline = intval($m[1]);
								break;
							}
						}
					}
					
					if ($http_code_offline == 200 || $http_code_offline == 201) {
						$jefeNotificado = true;
						error_log("OFFLINE: SMS enviado exitosamente al jefe via textbee - Tel: $telefono_intl_offline");
					} else {
						error_log("OFFLINE: Error enviando SMS textbee - HTTP $http_code_offline - Response: " . ($result_sms_offline ?: '(sin respuesta)'));
					}
				}
			}
		}
	}
	
	error_log('--- FIN PROCESO REGISTRO OFFLINE ---');
							$response = [
								'estatus' => '1',
								'mensaje' => 'Registro offline procesado correctamente',
								'nombre' => $NameComplet,
								'empresa' => $empresaName,
								'tipoAccion' => ($vtipo == 1 ? 'Entrada' : 'Salida'),
								'fueraGeocerca' => (isset($estadoValidacionOffline) && $estadoValidacionOffline === 'Pendiente'),
								'jefeNotificado' => $jefeNotificado
							];
							error_log('Registro offline procesado correctamente para empleado: ' . $empleadoF);
							echo json_encode($response);
							exit();
						}
					} catch (Exception $e) {
						error_log('Error al decodificar la información offline: ' . $e->getMessage());
						echo json_encode(['estatus' => '0', 'mensaje' => 'Error al decodificar la información offline']);
						exit();
					}
				} else {
					error_log('No se pudo decodificar la cadena offline');
					echo json_encode(['estatus' => '0', 'mensaje' => 'No se pudo decodificar la cadena offline']);
					exit();
				}
			} else {
				error_log('No existe la cadena offline');
				echo json_encode(['estatus' => '0', 'mensaje' => 'No existe la cadena offline']);
				exit();
			}
		} catch (Throwable $e) {
			error_log('Excepción global en RegistroEntradaOffline: ' . $e->getMessage());
			echo json_encode(['estatus' => '0', 'mensaje' => 'Error inesperado en el backend']);
			exit();
		}
		break;
			 
		case 'Nexus':
				
		$flagTime = "";
		$partes = explode(":", $request); // separar por ":"
		$partes2 = explode(",", $partes[1]); // separar la segunda parte por ","

		$cadena_tiempo  = $partes[0];

		$cadena_empleado = $partes[1];

		//$cadena = "19,09,06,21.33443939,-100.32232323";
		$array = explode(",", $cadena_tiempo); // separar la cadena por comas y obtener un array
		$primeros_tres = array_slice($array, 0, 4); // obtener los primeros tres elementos
		$cadena_tiempo = implode(",", $primeros_tres); // unir los elementos con una coma
		//echo $resultado; // imprimir el resultado: "19,09,06"
		$latitude = isset($array[4]) ? $array[4] : 'empty';
		$longitude  = isset($array[5])? $array[5] : 'empty';
		//echo $cadena_tiempo;


		$outputQuery = "SELECT regID FROM cat_config_sis WHERE registroID = 9";
		sc_lookup(rsOutput,$outputQuery);

		if(count({rsOutput}) >0){

			$flagTime = {rsOutput[0][0]}; // minutos

			}else{
			$flagTime = 0;
			}
					// Convertir el valor de $flagTime de minutos a segundos
			$flagTime = $flagTime * 60;
		   // $flagtime = 60; // Rango de 1 minuto en segundos
			//$cadena = "30,18,53,40";
			//$valores = explode(',', $cadena);
			// Obtener la fecha y hora actual
			$fechaActual = date('d');
			$horaActual = date('H');
			$minutoActual = date('i');
			$segundoActual = date('s');     
			// Obtener los valores de día, hora, minuto y segundo de la cadena

			$diaCadena = $array[0];
			$horaCadena = $array[1];
			$minutoCadena = $array[2];
			$segundoCadena = $array[3];
/*
			echo "\n flag".$flagTime." \n ";
			echo "\n dia cadena".$diaCadena." \n ";
			echo "\n dia hora".$horaCadena." \n ";
			echo "\n dia minuto".$minutoCadena." \n";
			echo "\n dia segundo".$segundoCadena." \n";
			echo "\n fecha actual".$fechaActual." \n";
			echo "\n segundo actual".$segundoActual." \n";
*/
			// Comprobar si el día y la hora son los mismos


			if ($diaCadena == $fechaActual && $horaCadena == $horaActual) {
				// Convertir los minutos y segundos de la cadena a segundos
				$segundosCadena = ($minutoCadena * 60) + $segundoCadena;
				//echo "\n segundos cadena ".$segundosCadena." \n";


				// Calcular el límite superior del rango
				$limiteSuperior = $segundosCadena + $flagTime;  
				//echo "limite superior".$limiteSuperior;


				// Calcular la diferencia de tiempo en segundos
				 $segundosActuales = ($minutoActual * 60) + $segundoActual;
				//echo "\n segundosActuales".$segundosActuales." \n";
				 $diferencia = $segundosActuales - $segundosCadena;

				//echo "\n diferencia  ".$diferencia."  \n"."\n";

				$flagTime = $flagTime + $segundoCadena;
				// Verificar si la diferencia de tiempo está dentro del rango permitido
			if ($diferencia >= 0 && $diferencia <= $flagTime &&  $segundosActuales <= $limiteSuperior ) {
				//error_log("despues de validacion tiempo  ");

			 
			 		$request = $cadena_empleado;
				if($request != ''){

					error_log($request);
					$mensaje_decifrado = encryptor('decrypt',$request);
					//echo $mensaje_decifrado;
					if($mensaje_decifrado != ''){
					//error_log("tercer if TRY desencripto el mensaje");
					try {
						   $mensaje_explode = explode(";",$mensaje_decifrado);

						   $empleadoF = $mensaje_explode[0];
						   $empleadoactiv = $mensaje_explode[3];
						   //$empleadoactiv = $mensaje_explode[6];
						   $tipoTarjeta = $mensaje_explode[4];
						   //$tipoTarjeta = $mensaje_explode[7];

						if(!empty($empleadoF)){
							$acceso='';
							//error_log("cuarto if se aplico explode ");
							$userF = "Scanner";
							//$empresaF = $mensaje_explode[4];

							$dias = array("Domingo","Lunes","Martes","Miercoles","Jueves","Viernes","Sábado");

							$dia = $dias[date('w')];
							$Hoy = date("Y-m-d H:i:s");

							$Hoyd = date("Y-m-d");

							//$sql_acceso = "SELECT empleadoID,acceso,DepartamentoID,puestoID,fotografia FROM tb_empleados WHERE empleadoID = ".$empleadoF;
							$sql_acceso = "SELECT emp.empleadoID,emp.acceso,ifnull(emp.DepartamentoID,0) as DepartamentoID,ifnull(emp.puestoID,0) as puestoID,emp.fotografia,ifnull(ro.departamento,'no tiene') as departamento,ifnull(pu.nombre,'no tiene') as nombre , concat(emp.nombre, ' ', emp.apellidoP, ' ', emp.apellidoM) as nombreCompleto,empr.nombreComercial,emp.estatus,emp.NSS,emp.comedor,emp.empresaID,emp.archivo FROM tb_empleados  as emp
			LEFT JOIN cat_roles ro ON ro.DepartamentoID = emp.DepartamentoID
			LEFT JOIN cat_puesto pu ON pu.puestoID = emp.puestoID
			INNER JOIN tb_empresas empr ON empr.empresaID = emp.empresaID
			WHERE emp.estatus ='Activo' AND  emp.empleadoID =".$empleadoF;
							sc_lookup(acc, $sql_acceso);

							if (!empty({acc[0][0]})) {
								 $acceso = {acc[0][1]};
								 $departamento = {acc[0][2]};	 
								 $puesto = {acc[0][3]};	 
								 $fotoN = {acc[0][4]};	
								 $departamentName = {acc[0][5]};
								 $puestoName = {acc[0][6]};
								 $NameComplet = {acc[0][7]};
								 $empresaName = {acc[0][8]};
								 $estatusEM = {acc[0][9]};
								 $nssINV = {acc[0][10]};
								 // --- Lógica para cumpleaños ---
								 $esCumpleanios = false;
								 if (!empty($nssINV)) {
									 $sql_cumple = "SELECT fecha_nacimiento FROM empleados_info WHERE NSS = '$nssINV' LIMIT 1";
									 sc_lookup(cumple, $sql_cumple);
									 if (!empty({cumple[0][0]})) {
										 $fecha_nac = {cumple[0][0]};
										 $hoy = date('m-d');
										 $cumple = date('m-d', strtotime($fecha_nac));
										 if ($hoy == $cumple) {
											 $esCumpleanios = true;
										 }
									 }
								 }
								 $comedorflg = {acc[0][11]};
								 $empresasI = {acc[0][12]};
								$archivoM = {acc[0][13]};
								 $rutafoto = fnConfsis_v2(2,'N');
								if($fotoN == ''){
								 //$fotoN = $rutafoto'/grp__NM__bg__NM__userDOMO.png';
								  // $vrLogo='/scriptcase/app/Gilneas/_lib/img/grp__NM__img__NM__falconlogoMI.png';
								
									
									
							$imagenBase64 = $archivoM;
									
							if (!empty($imagenBase64)) {
								// Obtén el tipo de imagen y la cadena base64 sin el encabezado 'data:image/png;base64,'
								$imagenData = substr($imagenBase64, strpos($imagenBase64, ',') + 1);
								$imagenTipo = str_replace('data:image/png;base64,', '', $imagenBase64);
								$ruta64N= fnConfsis_v2(2,'N');
								$ruta64= fnConfsis_v2(2,'v');
								$rutafotoRelativa64 = fnpathUrl($ruta64,'img');

								$file = $rutafotoRelativa64.'fotosEmpleados/';

								// Decodifica la cadena base64 y guarda el contenido en un archivo temporal
								$nombreArchivo = 'temp_image_' . uniqid() . '.png';
								//$rutaArchivoTemporal = sys_get_temp_dir() . '/' . $nombreArchivo;
								$rutaArchivoTemporal = $file. 'imgTEMp/'. $nombreArchivo;

								file_put_contents($rutaArchivoTemporal, base64_decode($imagenData));

								// Genera la etiqueta HTML con la imagen


								// Asigna la etiqueta HTML al campo de imagen

								$fototemp = $rutafoto.'/fotosEmpleados/imgTEMp/'. $nombreArchivo;;
								$rutafotoCompleta = $fototemp;
							} else {
								// Si no hay imagen, muestra un texto alternativo
								//{foto} = 'No hay imagen disponible';
								 $fotoN = '/scriptcase/app/Gilneas/_lib/img/_lib/img/grp__NM__bg__NM__userDOMO.png';
								 $rutafotoCompleta = $fotoN;
							}
									
									
									
								}else{
								 //$rutafotoCompleta = $rutafoto.'/fotosEmpleados/'."1".$fotoN;
								 $rutafotoCompleta = $rutafoto.'/fotosEmpleados/'.$fotoN;
								}	
								//error_log("quinto if se se buscan los datos del empleado  ");
						}else{
							$array = array();
							$array = array(
							  'empresa' => 'no exiten datos de empresa',
							  'nombre' =>  'no exite nombre',
							  'Departamento' =>  'No exite departamento',
							  'puesto' =>  'No existe puesto'

							);

							$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'usuarrio Inactivo o Inexistente');
							header("HTTP/1.1 200 OK");
							echo json_encode($objResponse);

							}

						/*	
							$check_sql = "SELECT empleadoID FROM tb_entrada_salida WHERE empleadoID = '" 
											.  $empleadoF . "' AND  DATE_FORMAT(fechaH,'%Y-%m-%d') = '". $Hoyd . "'";
							sc_lookup(rs, $check_sql);

							if (!isset({rs[0][0]})) {
								 $acceso = 'Salida';
							}
					*/		
							if($departamento == 2 || $departamento == 3 || $departamento == 4 || $departamento == 5 || $departamento == 6 || $departamento == 7 || $departamento == 14 || $departamento == 29 || $puesto == 218 || $empresasI == 2){

							//if( in_array($departamento, array(2,3,4,5,6), true )){

							}else{
								$check_sql = "SELECT empleadoID FROM tb_entrada_salida WHERE empleadoID = '" 
												.  $empleadoF . "' AND  DATE_FORMAT(fechaH,'%Y-%m-%d') = '". $Hoyd . "'";
								sc_lookup(rs, $check_sql);

								if (!isset({rs[0][0]})) {
									 $acceso = 'Salida';
								}					

							}

							if ($acceso == 'Entrada') {
								$update_sql = 'UPDATE tb_empleados SET acceso = "Salida" WHERE empleadoID = ' . $empleadoF;
								$update_sql_p = "UPDATE tb_empleados SET puerto = '$puertoSal' WHERE empleadoID =" . $empleadoF;
								$vtipo=2;
								//error_log("if donde actualiza el estatus a salida del empelado debhe ser tipo 2 ");

							} else {
								$update_sql = 'UPDATE tb_empleados SET acceso = "Entrada" WHERE empleadoID =' . $empleadoF;
								$update_sql_p = "UPDATE tb_empleados SET puerto = '$puertoEn' WHERE empleadoID =" . $empleadoF;
								$vtipo=1;
								//error_log("if donde actualiza el estatus a entrada del empelado debhe ser tipo 1 ");

							}
							sc_exec_sql($update_sql);
							sc_exec_sql($update_sql_p);

							if($nssINV == '88888888888'){
								$check_Acceso_Inv = "SELECT invitadoID,empleadoID,empresaID,nombre,MotivoVisita FROM tb_invitados WHERE empleadoID = ".$empleadoF." AND DATE_FORMAT(fechaRegistro,'%Y-%m-%d') = '". $Hoyd . "' ORDER BY invitadoID  DESC LIMIT 1";
								sc_lookup(invv,$check_Acceso_Inv);
								//echo $check_Acceso_Inv;
								if (!empty({invv[0][0]})){
									 $invt={invv[0][0]};
									 $NameComplet={invv[0][3]};


								$insert_sqlINV = 'INSERT INTO tb_entrada_salida'
									. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, IDinvitado, hadware, latitud, longitud, direccion, compania,ubicacion_Acc)'
									. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'", '.$invt.', "'.$hadwareB.'", "'.$latitude.'", "'.$longitude.'", "'.$address.'", "'.$company.'", "'.$locate.'" )';
								sc_exec_sql($insert_sqlINV);
								
								// Validar geocerca para invitados
								$lastInsertID = 0;
								$query_last_id = "SELECT LAST_INSERT_ID()";
								sc_lookup(last_id, $query_last_id);
								if (!empty({last_id[0][0]})) {
									$lastInsertID = {last_id[0][0]};
									
									if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
										$geocercaResult = validarGeocerca($empleadoF, $latitude, $longitude);
										
										$update_geocerca = "UPDATE tb_entrada_salida SET 
											geocercaID = " . ($geocercaResult['geocercaID'] ? $geocercaResult['geocercaID'] : 'NULL') . ",
											distanciaMetros = " . ($geocercaResult['distanciaMetros'] ? $geocercaResult['distanciaMetros'] : 'NULL') . ",
											validacionGeocerca = '" . $geocercaResult['validacion'] . "'
											WHERE salidEnt = $lastInsertID";
										sc_exec_sql($update_geocerca);
									}
								}
								}else{
									$NameComplet="Sin registro de invitado";
								}


							}elseif($nssINV == '99999999999'){
								/*
								$insert_sql = 'INSERT INTO tb_Ac_proveedor_almacen '
									. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso)'
									. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'" )';
								sc_exec_sql($insert_sql);
								*/
								$check_Acceso_Prov = "SELECT ProveedorID,empleadoID,empresaID,nombre,MotivoVisita FROM tb_proveedores_Acc WHERE empleadoID = ".$empleadoF." AND DATE_FORMAT(fechaRegistro,'%Y-%m-%d') = '". $Hoyd . "' ORDER BY ProveedorID  DESC LIMIT 1";
								sc_lookup(prov,$check_Acceso_Prov);
								//echo $check_Acceso_Inv;
								if (!empty({prov[0][0]})){
									 $prove={prov[0][0]};
									 $NameComplet={prov[0][3]};


								$insert_sqlProv = 'INSERT INTO tb_entrada_salida'
									. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, IDproveedor, hadware, latitud, longitud, direccion, compania, ubicacion_Acc)'
									. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'", '.$prove.', "'.$hadwareB.'", "'.$latitude.'", "'.$longitude.'", "'.$address.'", "'.$company.'", "'.$locate.'" )';
								sc_exec_sql($insert_sqlProv);
								
								// Validar geocerca para proveedores
								$lastInsertID = 0;
								$query_last_id = "SELECT LAST_INSERT_ID()";
								sc_lookup(last_id, $query_last_id);
								if (!empty({last_id[0][0]})) {
									$lastInsertID = {last_id[0][0]};
									
									if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
										$geocercaResult = validarGeocerca($empleadoF, $latitude, $longitude);
										
										$update_geocerca = "UPDATE tb_entrada_salida SET 
											geocercaID = " . ($geocercaResult['geocercaID'] ? $geocercaResult['geocercaID'] : 'NULL') . ",
											distanciaMetros = " . ($geocercaResult['distanciaMetros'] ? $geocercaResult['distanciaMetros'] : 'NULL') . ",
											validacionGeocerca = '" . $geocercaResult['validacion'] . "'
											WHERE salidEnt = $lastInsertID";
										sc_exec_sql($update_geocerca);
									}
								}
								}else{
									$NameComplet="Sin registro de Proveedor";
								}

							}else{

								$insert_sql = 'INSERT INTO tb_entrada_salida '
									. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, hadware, latitud, longitud, direccion, compania, ubicacion_Acc)'
									. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'", "'.$hadwareB.'", "'.$latitude.'", "'.$longitude.'", "'.$address.'", "'.$company.'", "'.$locate.'" )';
								sc_exec_sql($insert_sql);
								
								// Validar geocerca después del INSERT
								$lastInsertID = 0;
								$query_last_id = "SELECT LAST_INSERT_ID()";
								sc_lookup(last_id, $query_last_id);
								if (!empty({last_id[0][0]})) {
									$lastInsertID = {last_id[0][0]};
									
									// Validar geocerca si tenemos coordenadas
									if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
										$geocercaResult = validarGeocerca($empleadoF, $latitude, $longitude);
										
										// Actualizar el registro con los datos de geocerca
										$update_geocerca = "UPDATE tb_entrada_salida SET 
											geocercaID = " . ($geocercaResult['geocercaID'] ? $geocercaResult['geocercaID'] : 'NULL') . ",
											distanciaMetros = " . ($geocercaResult['distanciaMetros'] ? $geocercaResult['distanciaMetros'] : 'NULL') . ",
											validacionGeocerca = '" . $geocercaResult['validacion'] . "'
											WHERE salidEnt = $lastInsertID";
										sc_exec_sql($update_geocerca);
										
										error_log("Geocerca validada - ID: " . $geocercaResult['geocercaID'] . 
											", Distancia: " . $geocercaResult['distanciaMetros'] . 
											", Validación: " . $geocercaResult['validacion']);
									}
								}
							}

							//error_log("Realiza el insert de la entrada");
							$check_Acceso = 'SELECT empleadoID,acceso FROM tb_empleados WHERE empleadoID ='.$empleadoF;
							sc_lookup(acc,$check_Acceso);
							if (!empty({acc[0][0]})){
								 $accesoE={acc[0][1]};
								 $empID = {acc[0][0]};

								$AccesoComedor = "SELECT salidEnt, empleadoID, tipo,ubicacion_Acc,fechaH FROM tb_entrada_salida WHERE empleadoID = '" .  $empID . "' AND ubicacion_Acc = 'Comedor' AND  DATE_FORMAT(fechaH,'%Y-%m-%d')= '". $Hoyd . "' ORDER BY fechaH  DESC limit 1";

								sc_lookup(accA,$AccesoComedor);
								$Salid= 0;
								if (!empty({accA[0][0]})){
									 $Ubicc={accA[0][3]};
									 $Salid = {accA[0][0]};
								}
								/*
								 $Salid= 0;
								 $query_last = "SELECT last_insert_id();";
								 sc_lookup(rstl,$query_last);
								 $Salid = {rstl[0][0]};
								 */
							}


							$array = array();
														$array = array(
															'EntSalID' => $Salid,
															'empleadoID' => $empID,
															'empresa' => $empresaName,
															'nombre' => $NameComplet,
															'Departamento' => $departamentName,
															'puesto' => $puestoName,
															'fotografia' => $rutafotoCompleta,
															'Acceso' => $accesoE,
															'tipoCredencial' => $tipoTarjeta,
															'comedorFLG' =>$comedorflg,
															'cumpleanios' => $esCumpleanios
														);

							$objResponse = array('estatus'=>'1','detalles'=>$array,'mensaje'=>'Acceso correcto a las instalaciones');
							header("HTTP/1.1 200 OK");
							echo json_encode($objResponse);

							//error_log($objResponse);
							if($vtipo == 2){

								$Salid= 0;
								$query_last = "SELECT last_insert_id();";
								sc_lookup(rstl,$query_last);
								$Salid = {rstl[0][0]};
								$setencia ="CALL pdHorasTrabajadas_m($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
								sc_exec_sql($setencia);
								//error_log("salida $Salid tipo $vtipo empleado $empleadoF dia $dia hoy $Hoy entra a la validacion del tipo y proceso call");	
							}elseif($vtipo == 1){
								$Salid= 0;
								$query_last = "SELECT last_insert_id();";
								sc_lookup(rstl,$query_last);
								$Salid = {rstl[0][0]};
								$setencia ="CALL pdHorasComedor_m($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
								sc_exec_sql($setencia);
								//error_log("salida $Salid tipo $vtipo empleado $empleadoF dia $dia hoy $Hoy entra a la validacion del tipo y proceso call");	

							}
							
					error_log("empresa ".$empresasI);
							if($vtipo == 1 && $empresasI != 2){
								 error_log("entro a la validaccion oficna ");
								$Salid= 0;
								$query_last = "SELECT last_insert_id();";
								sc_lookup(rstl,$query_last);

								$Salid = {rstl[0][0]};

								$setenciaF ="CALL pdHorasFuera($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
								sc_exec_sql($setenciaF);
								error_log("salida: $Salid tipo: $vtipo Empleado: $empleadoF Nombre: $NameComplet dia: $dia hoy: $Hoy entra a la validacion del tipo y proceso call Horas fuera");	
								//error_log("salida $Salid tipo $vtipo empleado $empleadoF dia $dia hoy $Hoy entra a la validacion del tipo y proceso call");	

								}
							
							
							

							
							//error_log("enntro ws pruba");
							//proceso puntualidad sin tomar comedor
							$rgcomedor = "SELECT salidEnt, empleadoID, tipo,ubicacion_Acc,fechaH FROM tb_entrada_salida WHERE empleadoID = '" .  $empleadoF . "' ORDER BY fechaH  DESC limit 1";
								sc_lookup(accAC,$rgcomedor);
							  //error_log($rgcomedor);
								$Salid= 0;
								if (!empty({accAC[0][0]})){
									 $Ubicacion={accAC[0][3]};
									 if($Ubicacion == "Comedor"){
										 //error_log("entro al proceso puntaulidad");
									 
									 }elseif($vtipo == 1 && $empresasI != 2){
										  //error_log("paso al vr tipo 1 ");
									 
										$Salid2= 0;
										$query_lasts = "SELECT last_insert_id();";
										sc_lookup(rstl,$query_lasts);
										$Salid2 = {rstl[0][0]};
										$setencia2 ="CALL pdPuntualidad_m($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
										sc_exec_sql($setencia2);
																		 
									 }
				
								}
							
							
						}else{
							$array = array();
							$array = array(
							  'empresa' => 'no exiten datos de empresa',
							  'nombre' =>  'no exite nombre',
							  'Departamento' =>  'No exite departamento',
							  'puesto' =>  'No existe puesto'

							);

							$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'No se encontraron datos del Empleado o no esta activo');
							header("HTTP/1.1 200 OK");
							echo json_encode($objResponse);
						}

						}catch (Exception $e) {
							$array = array();
							$array = array(
							  'empresa' => 'No se puede decodificar la informacion',
							  'nombre' =>  'No se puede decodificar la informacion',
							  'Departamento' =>  'No se puede decodificar la informacion',
							  'puesto' =>  'No se puede decodificar la informacion'
							);

							$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'No se puede decodificar la informacion');
							header("HTTP/1.1 200 OK");
							echo json_encode($objResponse);
				}	

				}else{
					$array = array();
					$array = array(
					  'empresa' => 'No existe la cadena',
					  'nombre' =>  'No existe la cadena',
					  'Departamento' =>  'No existe la cadena',
					  'puesto' =>  'No existe la cadena'

					);

					$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'No existe la cadena');
					header("HTTP/1.1 200 OK");
					echo json_encode($objResponse);


				}//tercer if
			}//segundo IF
				}else {
				header("HTTP/1.1 200 OK");
				$array = array();
				$array = array('estatus' => '0', 'mensaje' => "Tu codigo QR expiro pasando los  minutos", 'DEFAULT' => 'ADIOS popo att: raul');
				echo json_encode($array);
			}

		}else{
				header("HTTP/1.1 200 OK");
				$array = array();
				$array = array('estatus' => '0', 'mensaje' => "Tu codigo QR expiro pasando los  dias y  hora", 'DEFAULT' => 'ADIOS popo att: raul');
				echo json_encode($array);
		}
		exit(); 
		break;
				
		case 'NormalCheck':
				error_log("entro case normal credencial tradicional");
		 if($request != ''){

		error_log($request);
		$mensaje_decifrado = encryptor('decrypt',$request);
		//echo $mensaje_decifrado;
		if($mensaje_decifrado != ''){
			//error_log("tercer if TRY desencripto el mensaje");
			try {
				   $mensaje_explode = explode(";",$mensaje_decifrado);

				   $empleadoF = $mensaje_explode[0];
				   $empleadoactiv = $mensaje_explode[3];
				   //$empleadoactiv = $mensaje_explode[6];
				   $tipoTarjeta = $mensaje_explode[4];
				   //$tipoTarjeta = $mensaje_explode[7];

				if(!empty($empleadoF)){
					$acceso='';
					$userF = "Scanner";
					$dias = array("Domingo","Lunes","Martes","Miercoles","Jueves","Viernes","Sábado");

					$dia = $dias[date('w')];
					$Hoy = date("Y-m-d H:i:s");

					$Hoyd = date("Y-m-d");

					//$sql_acceso = "SELECT empleadoID,acceso,DepartamentoID,puestoID,fotografia FROM tb_empleados WHERE empleadoID = ".$empleadoF;
					$sql_acceso = "SELECT emp.empleadoID,emp.acceso,ifnull(emp.DepartamentoID,0) as DepartamentoID,ifnull(emp.puestoID,0) as puestoID,emp.fotografia,ifnull(ro.departamento,'no tiene') as departamento,ifnull(pu.nombre,'no tiene') as nombre , concat(emp.nombre, ' ', emp.apellidoP, ' ', emp.apellidoM) as nombreCompleto,empr.nombreComercial,emp.estatus,emp.NSS,emp.comedor,emp.empresaID,emp.archivo FROM tb_empleados  as emp
	LEFT JOIN cat_roles ro ON ro.DepartamentoID = emp.DepartamentoID
	LEFT JOIN cat_puesto pu ON pu.puestoID = emp.puestoID
	INNER JOIN tb_empresas empr ON empr.empresaID = emp.empresaID
	WHERE emp.estatus ='Activo' AND  emp.empleadoID =".$empleadoF;
					sc_lookup(acc, $sql_acceso);

					if (!empty({acc[0][0]})) {
						 $acceso = {acc[0][1]};
						 $departamento = {acc[0][2]};	 
						 $puesto = {acc[0][3]};	 
						 $fotoN = {acc[0][4]};	
						 $departamentName = {acc[0][5]};
						 $puestoName = {acc[0][6]};
						 $NameComplet = {acc[0][7]};
						 $empresaName = {acc[0][8]};
						 $estatusEM = {acc[0][9]};
						 $nssINV = {acc[0][10]};
						 // --- Lógica para cumpleaños ---
						 $esCumpleanios = false;
						 if (!empty($nssINV)) {
							 $sql_cumple = "SELECT fecha_nacimiento FROM empleados_info WHERE NSS = '$nssINV' LIMIT 1";
							 sc_lookup(cumple, $sql_cumple);
							 if (!empty({cumple[0][0]})) {
								 $fecha_nac = {cumple[0][0]};
								 $hoy = date('m-d');
								 $cumple = date('m-d', strtotime($fecha_nac));
								 if ($hoy == $cumple) {
									 $esCumpleanios = true;
								 }
							 }
						 }
						 $comedorflg = {acc[0][11]};
						 $empresasI = {acc[0][12]};
						$archivoM = {acc[0][13]};
						 $rutafoto = fnConfsis_v2(2,'N');
						if($fotoN == ''){

							$imagenBase64 = $archivoM;
									
							if (!empty($imagenBase64)) {
								// Obtén el tipo de imagen y la cadena base64 sin el encabezado 'data:image/png;base64,'
								$imagenData = substr($imagenBase64, strpos($imagenBase64, ',') + 1);
								$imagenTipo = str_replace('data:image/png;base64,', '', $imagenBase64);
								$ruta64N= fnConfsis_v2(2,'N');
								$ruta64= fnConfsis_v2(2,'v');
								$rutafotoRelativa64 = fnpathUrl($ruta64,'img');

								$file = $rutafotoRelativa64.'fotosEmpleados/';

								// Decodifica la cadena base64 y guarda el contenido en un archivo temporal
								$nombreArchivo = 'temp_image_' . uniqid() . '.png';
								//$rutaArchivoTemporal = sys_get_temp_dir() . '/' . $nombreArchivo;
								$rutaArchivoTemporal = $file. 'imgTEMp/'. $nombreArchivo;

								file_put_contents($rutaArchivoTemporal, base64_decode($imagenData));

								// Genera la etiqueta HTML con la imagen


								// Asigna la etiqueta HTML al campo de imagen

								$fototemp = $rutafoto.'/fotosEmpleados/imgTEMp/'. $nombreArchivo;;
								$rutafotoCompleta = $fototemp;
							} else {
								// Si no hay imagen, muestra un texto alternativo
								//{foto} = 'No hay imagen disponible';
								 $fotoN = '/scriptcase/app/Gilneas/_lib/img/_lib/img/grp__NM__bg__NM__userDOMO.png';
								 $rutafotoCompleta = $fotoN;
							}
							
							
						}else{
						 //$rutafotoCompleta = $rutafoto.'/fotosEmpleados/'."1".$fotoN;
						 $rutafotoCompleta = $rutafoto.'/fotosEmpleados/'.$fotoN;
						}	
				}else{
					$array = array();
					$array = array(
					  'empresa' => 'no exiten datos de empresa',
					  'nombre' =>  'no exite nombre',
					  'Departamento' =>  'No exite departamento',
					  'puesto' =>  'No existe puesto'

					);

					$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'usuarrio Inactivo o Inexistente');
					header("HTTP/1.1 200 OK");
					echo json_encode($objResponse);

					}

				/*	
					$check_sql = "SELECT empleadoID FROM tb_entrada_salida WHERE empleadoID = '" 
									.  $empleadoF . "' AND  DATE_FORMAT(fechaH,'%Y-%m-%d') = '". $Hoyd . "'";
					sc_lookup(rs, $check_sql);

					if (!isset({rs[0][0]})) {
						 $acceso = 'Salida';
					}
			*/		
					if($departamento == 2 || $departamento == 3 || $departamento == 4 || $departamento == 5 || $departamento == 6 || $departamento == 7 || $departamento == 14 || $departamento == 29 || $puesto == 218 || $empresasI == 2){

					//if( in_array($departamento, array(2,3,4,5,6), true )){

					}else{
						$check_sql = "SELECT empleadoID FROM tb_entrada_salida WHERE empleadoID = '" 
										.  $empleadoF . "' AND  DATE_FORMAT(fechaH,'%Y-%m-%d') = '". $Hoyd . "'";
						sc_lookup(rs, $check_sql);

						if (!isset({rs[0][0]})) {
							 $acceso = 'Salida';
						}					

					}

					if ($acceso == 'Entrada') {
						$update_sql = 'UPDATE tb_empleados SET acceso = "Salida" WHERE empleadoID = ' . $empleadoF;
						$update_sql_p = "UPDATE tb_empleados SET puerto = '$puertoSal' WHERE empleadoID =" . $empleadoF;
						$vtipo=2;
						//error_log("if donde actualiza el estatus a salida del empelado debhe ser tipo 2 ");

					} else {
						$update_sql = 'UPDATE tb_empleados SET acceso = "Entrada" WHERE empleadoID =' . $empleadoF;
						$update_sql_p = "UPDATE tb_empleados SET puerto = '$puertoEn' WHERE empleadoID =" . $empleadoF;
						$vtipo=1;
						//error_log("if donde actualiza el estatus a entrada del empelado debhe ser tipo 1 ");

					}
					sc_exec_sql($update_sql);
					sc_exec_sql($update_sql_p);

					if($nssINV == '88888888888'){
						$check_Acceso_Inv = "SELECT invitadoID,empleadoID,empresaID,nombre,MotivoVisita FROM tb_invitados WHERE empleadoID = ".$empleadoF." AND DATE_FORMAT(fechaRegistro,'%Y-%m-%d') = '". $Hoyd . "' ORDER BY invitadoID  DESC LIMIT 1";
						sc_lookup(invv,$check_Acceso_Inv);
						//echo $check_Acceso_Inv;
						if (!empty({invv[0][0]})){
							 $invt={invv[0][0]};
							 $NameComplet={invv[0][3]};


						$insert_sqlINV = 'INSERT INTO tb_entrada_salida'
							. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, IDinvitado, hadware, latitud, longitud, direccion, compania,ubicacion_Acc)'
							. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'", '.$invt.', "'.$hadwareB.'", "'.$latitude.'", "'.$longitude.'", "'.$address.'", "'.$company.'", "'.$locate.'" )';
						sc_exec_sql($insert_sqlINV);
						
						// Validar geocerca para invitados (NormalCheck)
						$lastInsertID = 0;
						$query_last_id = "SELECT LAST_INSERT_ID()";
						sc_lookup(last_id, $query_last_id);
						if (!empty({last_id[0][0]})) {
							$lastInsertID = {last_id[0][0]};
							
							if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
								$geocercaResult = validarGeocerca($empleadoF, $latitude, $longitude);
								
								$update_geocerca = "UPDATE tb_entrada_salida SET 
									geocercaID = " . ($geocercaResult['geocercaID'] ? $geocercaResult['geocercaID'] : 'NULL') . ",
									distanciaMetros = " . ($geocercaResult['distanciaMetros'] ? $geocercaResult['distanciaMetros'] : 'NULL') . ",
									validacionGeocerca = '" . $geocercaResult['validacion'] . "'
									WHERE salidEnt = $lastInsertID";
								sc_exec_sql($update_geocerca);
							}
						}
						}else{
							$NameComplet="Sin registro de invitado";
						}


					}elseif($nssINV == '99999999999'){
						/*
						$insert_sql = 'INSERT INTO tb_Ac_proveedor_almacen '
							. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso)'
							. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'" )';
						sc_exec_sql($insert_sql);
						*/
						$check_Acceso_Prov = "SELECT ProveedorID,empleadoID,empresaID,nombre,MotivoVisita FROM tb_proveedores_Acc WHERE empleadoID = ".$empleadoF." AND DATE_FORMAT(fechaRegistro,'%Y-%m-%d') = '". $Hoyd . "' ORDER BY ProveedorID  DESC LIMIT 1";
						sc_lookup(prov,$check_Acceso_Prov);
						//echo $check_Acceso_Inv;
						if (!empty({prov[0][0]})){
							 $prove={prov[0][0]};
							 $NameComplet={prov[0][3]};


						$insert_sqlProv = 'INSERT INTO tb_entrada_salida'
							. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, IDproveedor, hadware, latitud, longitud, direccion, compania, ubicacion_Acc)'
							. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'", '.$prove.', "'.$hadwareB.'", "'.$latitude.'", "'.$longitude.'", "'.$address.'", "'.$company.'", "'.$locate.'" )';
						sc_exec_sql($insert_sqlProv);
						
						// Validar geocerca para proveedores (NormalCheck)
						$lastInsertID = 0;
						$query_last_id = "SELECT LAST_INSERT_ID()";
						sc_lookup(last_id, $query_last_id);
						if (!empty({last_id[0][0]})) {
							$lastInsertID = {last_id[0][0]};
							
							if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
								$geocercaResult = validarGeocerca($empleadoF, $latitude, $longitude);
								
								$update_geocerca = "UPDATE tb_entrada_salida SET 
									geocercaID = " . ($geocercaResult['geocercaID'] ? $geocercaResult['geocercaID'] : 'NULL') . ",
									distanciaMetros = " . ($geocercaResult['distanciaMetros'] ? $geocercaResult['distanciaMetros'] : 'NULL') . ",
									validacionGeocerca = '" . $geocercaResult['validacion'] . "'
									WHERE salidEnt = $lastInsertID";
								sc_exec_sql($update_geocerca);
							}
						}
						}else{
							$NameComplet="Sin registro de Proveedor";
						}

					}else{

						$insert_sql = 'INSERT INTO tb_entrada_salida '
							. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, hadware, latitud, longitud, direccion, compania, ubicacion_Acc)'
							. ' VALUES ('.$empleadoF.', "'.$Hoy.'", '.$vtipo.', "'.$dia.'", "'.$Hoy.'", "'.$userF.'", "'.$tipoTarjeta.'", "'.$hadwareB.'", "'.$latitude.'", "'.$longitude.'", "'.$address.'", "'.$company.'", "'.$locate.'" )';
						sc_exec_sql($insert_sql);
						
						// Validar geocerca después del INSERT (NormalCheck)
						$lastInsertID = 0;
						$query_last_id = "SELECT LAST_INSERT_ID()";
						sc_lookup(last_id, $query_last_id);
						if (!empty({last_id[0][0]})) {
							$lastInsertID = {last_id[0][0]};
							
							// Validar geocerca si tenemos coordenadas
							if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
								$geocercaResult = validarGeocerca($empleadoF, $latitude, $longitude);
								
								// Actualizar el registro con los datos de geocerca
								$update_geocerca = "UPDATE tb_entrada_salida SET 
									geocercaID = " . ($geocercaResult['geocercaID'] ? $geocercaResult['geocercaID'] : 'NULL') . ",
									distanciaMetros = " . ($geocercaResult['distanciaMetros'] ? $geocercaResult['distanciaMetros'] : 'NULL') . ",
									validacionGeocerca = '" . $geocercaResult['validacion'] . "'
									WHERE salidEnt = $lastInsertID";
								sc_exec_sql($update_geocerca);
								
								error_log("Geocerca validada (NormalCheck) - ID: " . $geocercaResult['geocercaID'] . 
									", Distancia: " . $geocercaResult['distanciaMetros'] . 
									", Validación: " . $geocercaResult['validacion']);
							}
						}
					}

					//error_log("Realiza el insert de la entrada");
					$check_Acceso = 'SELECT empleadoID,acceso FROM tb_empleados WHERE empleadoID ='.$empleadoF;
					sc_lookup(acc,$check_Acceso);
					if (!empty({acc[0][0]})){
						 $accesoE={acc[0][1]};
						 $empID = {acc[0][0]};

						$AccesoComedor = "SELECT salidEnt, empleadoID, tipo,ubicacion_Acc,fechaH FROM tb_entrada_salida WHERE empleadoID = '" .  $empID . "' AND ubicacion_Acc = 'Comedor' AND  DATE_FORMAT(fechaH,'%Y-%m-%d')= '". $Hoyd . "' ORDER BY fechaH  DESC limit 1";

						sc_lookup(accA,$AccesoComedor);
						$Salid= 0;
						if (!empty({accA[0][0]})){
							 $Ubicc={accA[0][3]};
							 $Salid = {accA[0][0]};
						}
						/*
						 $Salid= 0;
						 $query_last = "SELECT last_insert_id();";
						 sc_lookup(rstl,$query_last);
						 $Salid = {rstl[0][0]};
						 */
					}


					$array = array();
										$array = array(
											'EntSalID' => $Salid,
											'empleadoID' => $empID,
											'empresa' => $empresaName,
											'nombre' => $NameComplet,
											'Departamento' => $departamentName,
											'puesto' => $puestoName,
											'fotografia' => $rutafotoCompleta,
											'Acceso' => $accesoE,
											'tipoCredencial' => $tipoTarjeta,
											'comedorFLG' =>$comedorflg,
											'cumpleanios' => $esCumpleanios
										);

					$objResponse = array('estatus'=>'1','detalles'=>$array,'mensaje'=>'Acceso correcto a las instalaciones');
					header("HTTP/1.1 200 OK");
					echo json_encode($objResponse);


					if($vtipo == 2){

						$Salid= 0;
						$query_last = "SELECT last_insert_id();";
						sc_lookup(rstl,$query_last);
						$Salid = {rstl[0][0]};
						$setencia ="CALL pdHorasTrabajadas_m($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
						sc_exec_sql($setencia);
						//error_log("salida $Salid tipo $vtipo empleado $empleadoF dia $dia hoy $Hoy entra a la validacion del tipo y proceso call");	
					}elseif($vtipo == 1){
						$Salid= 0;
						$query_last = "SELECT last_insert_id();";
						sc_lookup(rstl,$query_last);
						$Salid = {rstl[0][0]};
						$setencia ="CALL pdHorasComedor_m($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
						sc_exec_sql($setencia);
						//error_log("salida $Salid tipo $vtipo empleado $empleadoF dia $dia hoy $Hoy entra a la validacion del tipo y proceso call");	

					}
					
					 error_log("Empresa ".$empresasI);
					if($vtipo == 1 && $empresasI != 2){
						 error_log("entro a la validacion oficina ");
						$Salid= 0;
						$query_last = "SELECT last_insert_id();";
						sc_lookup(rstl,$query_last);
						
						$Salid = {rstl[0][0]};
						
						$setenciaF ="CALL pdHorasFuera($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
						sc_exec_sql($setenciaF);
						error_log("Salida: $Salid Tipo: $vtipo Empleado: $empleadoF Nombre: $NameComplet Dia: $dia Hoy: $Hoy entra a la validacion del tipo y proceso call Horas fuera");	
						//error_log("salida $Salid tipo $vtipo empleado $empleadoF dia $dia hoy $Hoy entra a la validacion del tipo y proceso call");	

						}
					
							//error_log("enntro ws pruba");
							//proceso puntualidad sin tomar comedor
							$rgcomedor = "SELECT salidEnt, empleadoID, tipo,ubicacion_Acc,fechaH FROM tb_entrada_salida WHERE empleadoID = '" .  $empleadoF . "' ORDER BY fechaH  DESC limit 1";
								sc_lookup(accAC,$rgcomedor);
							  error_log($rgcomedor);
								$Salid= 0;
								if (!empty({accAC[0][0]})){
									 $Ubicacion={accAC[0][3]};
									 if($Ubicacion == "Comedor"){
										 error_log("entro al proceso puntualidad");
									 
									 }elseif($vtipo == 1 && $empresasI != 2){
										  error_log("paso al vr tipo 1 ");
									 
										$Salid2= 0;
										$query_lasts = "SELECT last_insert_id();";
										sc_lookup(rstl,$query_lasts);
										$Salid2 = {rstl[0][0]};
										$setencia2 ="CALL pdPuntualidad_m($Salid,$vtipo,$empleadoF,'".$dia."','".$Hoy."');";
										sc_exec_sql($setencia2);
										 
										 
									 }
				
								}
					


				}else{
					$array = array();
					$array = array(
					  'empresa' => 'no exiten datos de empresa',
					  'nombre' =>  'no exite nombre',
					  'Departamento' =>  'No exite departamento',
					  'puesto' =>  'No existe puesto'

					);

					$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'No se encontraron datos del Empleado o no esta activo');
					header("HTTP/1.1 200 OK");
					echo json_encode($objResponse);
				}

				}catch (Exception $e) {
					$array = array();
					$array = array(
					  'empresa' => 'No se puede decodificar la informacion',
					  'nombre' =>  'No se puede decodificar la informacion',
					  'Departamento' =>  'No se puede decodificar la informacion',
					  'puesto' =>  'No se puede decodificar la informacion'
					);

					$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'No se puede decodificar la informacion');
					header("HTTP/1.1 200 OK");
					echo json_encode($objResponse);
		}	

		}else{
			$array = array();
			$array = array(
			  'empresa' => 'No existe la cadena',
			  'nombre' =>  'No existe la cadena',
			  'Departamento' =>  'No existe la cadena',
			  'puesto' =>  'No existe la cadena'

			);

			$objResponse = array('estatus'=>'0','detalles'=>$array,'mensaje'=>'No existe la cadena');
			header("HTTP/1.1 200 OK");
			echo json_encode($objResponse);


		}//tercer if
	}//segundo IF

        exit(); 
        break;
			//fin regisdtro nomral request	
		default:
		header("HTTP/1.1 200 OK");
		$array = array();
		$array = array( 'estatus' => '0','mensaje' => "No existe la funcion fn en GET",'DEFAULT'=>'ADIOS popo DC');
		echo json_encode($array);
		exit(); 
		break;	   
	 			  	 
	 }// Fin switch	
	

	
}	
