header('Content-Type: text/html; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('Content-Type: application/json');
header('Content-Type: text/javascript');
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// ============================================================
// FUNCIÓN DE VALIDACIÓN DE GEOCERCAS
// ============================================================

function validarGeocerca($empleadoID, $latitud, $longitud) {
    // Inicializar resultado por defecto
    $resultado = array(
        'geocercaID' => null,
        'distanciaMetros' => null,
        'validacion' => 'Sin_Geocerca'
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
        
        // Buscar geocercas aplicables al empleado
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
            ORDER BY g.tipoAsignacion DESC, g.geocercaID";
        
        sc_lookup(geocercas_aplicables, $sql_geocercas);
        
        if (!isset({geocercas_aplicables}) || empty({geocercas_aplicables})) {
            return $resultado;
        }
        
        $geocercaMasCercana = null;
        $distanciaMinima = PHP_INT_MAX;
        
        // Evaluar cada geocerca
        foreach ({geocercas_aplicables} as $geocerca) {
            $geocercaID = $geocerca[0];
            $geoLat = floatval($geocerca[1]);
            $geoLng = floatval($geocerca[2]);
            $radio = intval($geocerca[3]);
            $nombre = $geocerca[4];
            $tipo = $geocerca[5];
            
            // Calcular distancia usando la fórmula Haversine
            $distancia = calcularDistanciaHaversine($latitud, $longitud, $geoLat, $geoLng);
            
            error_log("Evaluando geocerca $nombre (ID: $geocercaID): Distancia $distancia m, Radio $radio m");
            
            // Si está dentro del radio
            if ($distancia <= $radio) {
                $resultado['geocercaID'] = $geocercaID;
                $resultado['distanciaMetros'] = round($distancia);
                $resultado['validacion'] = 'Dentro';
                
                error_log("GEOCERCA VÁLIDA: Empleado $empleadoID dentro de geocerca $nombre");
                return $resultado;
            }
            
            // Rastrear la geocerca más cercana
            if ($distancia < $distanciaMinima) {
                $distanciaMinima = $distancia;
                $geocercaMasCercana = $geocercaID;
            }
        }
        
        // Si llegamos aquí, no está dentro de ninguna geocerca
        if ($geocercaMasCercana !== null) {
            $resultado['geocercaID'] = $geocercaMasCercana;
            $resultado['distanciaMetros'] = round($distanciaMinima);
            $resultado['validacion'] = 'Fuera';
            
            error_log("FUERA DE GEOCERCA: Empleado $empleadoID fuera de todas las geocercas. Más cercana: $geocercaMasCercana a $distanciaMinima m");
        }
        
    } catch (Exception $e) {
        error_log("Error en validación de geocerca: " . $e->getMessage());
    }
    
    return $resultado;
}

function calcularDistanciaHaversine($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // Radio de la Tierra en metros
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) + 
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earthRadius * $c;
}

// ============================================================
// INICIO DEL WEBSERVICE CON ESTRUCTURA ORIGINAL
// ============================================================

error_log("WS_Nexus");	

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
    // Redirigir a RegistroEntradaOffline si esPendiente == '1' y la función es RegistroEntrada
    if ($funcion == 'RegistroEntrada' && $esPendiente == '1') {
        $funcion = 'RegistroEntradaOffline';
        error_log('Redirigiendo a RegistroEntradaOffline por esPendiente=1');
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
					if ($mensaje_decifrado != '') {
						$empleadoF = $mensaje_decifrado;
						
						// Redirigir a RegistroEntradaOffline si esPendiente == '1'
						if ($esPendiente == '1') {
							// Usar la lógica de RegistroEntradaOffline
							$vtipo = 1; // Default entrada
							$dia = date('Y-m-d');
							$fechaH = date('Y-m-d H:i:s');
							$userF = $empleadoF;
							$tipoTarjeta = 'Registro Remoto';
							
							// Obtener datos del empleado
							$sql_acceso = "SELECT emp.empleadoID, emp.acceso, emp.DepartamentoID, emp.puestoID, emp.foto,
								de.departamento, pu.puesto, concat(emp.nombre, ' ', emp.apellidoP, ' ', emp.apellidoM) as nombreCompleto,
								empr.empresa, emp.estatus, emp.nss, emp.comedor, empr.empresaID  
								FROM tb_empleados emp 
								LEFT JOIN cat_departamentos de ON de.DepartamentoID = emp.DepartamentoID
								LEFT JOIN cat_roles ro ON ro.DepartamentoID = emp.DepartamentoID
								LEFT JOIN cat_puesto pu ON pu.puestoID = emp.puestoID
								INNER JOIN tb_empresas empr ON empr.empresaID = emp.empresaID
								WHERE emp.estatus ='Activo' AND emp.empleadoID = " . intval($empleadoF);
							
							sc_lookup(acc, $sql_acceso);
							
							if (!empty({acc[0][0]})) {
								// Insert del registro
								$insert_sql = 'INSERT INTO tb_entrada_salida '
									. ' (empleadoID, fechaH, tipo, diaD, fechaRegistro, usuarioMod, tipoAcceso, hadware, latitud, longitud, direccion, compania, ubicacion_Acc)'
									. ' VALUES (' . intval($empleadoF) . ', "' . $fechaH . '", ' . $vtipo . ', "' . $dia . '", "' . $fechaH . '", "' . $userF . '", "' . $tipoTarjeta . '", "' . $hadwareB . '", "' . $latitude . '", "' . $longitude . '", "' . $address . '", "' . $company . '", "' . $locate . '" )';
								
								error_log('RegistroRemoto Insert SQL: ' . $insert_sql);
								sc_exec_sql($insert_sql);
								
								// Obtener el ID del registro insertado
								$query_last = "SELECT last_insert_id();";
								sc_lookup(rstl, $query_last);
								$Salid = isset({rstl[0][0]}) ? {rstl[0][0]} : 0;
								
								// Actualizar con motivo fuera de geocerca si existe
								if (!empty($motivoFueraGeocerca)) {
									$update_motivo = "UPDATE tb_entrada_salida SET observaciones = '" . addslashes($motivoFueraGeocerca) . "' WHERE salidEnt = " . $Salid;
									sc_exec_sql($update_motivo);
								}
								
								// Validar geocerca si tenemos coordenadas
								if (!empty($latitude) && !empty($longitude) && $latitude !== 'empty' && $longitude !== 'empty') {
									$validacionGeocerca = validarGeocerca(intval($empleadoF), floatval($latitude), floatval($longitude));
									error_log("Validación geocerca para registro $Salid: " . json_encode($validacionGeocerca));
								}
								
								// Ejecutar procedimientos
								if ($vtipo == 2) {
									$setencia = "CALL pdHorasTrabajadas_m($Salid,$vtipo,$empleadoF,'$dia','$fechaH');";
									sc_exec_sql($setencia);
								} elseif ($vtipo == 1) {
									$setencia = "CALL pdHorasComedor_m($Salid,$vtipo,$empleadoF,'$dia','$fechaH');";
									sc_exec_sql($setencia);
								}
								
								$response = [
									'estatus' => '1',
									'mensaje' => 'Registro remoto procesado correctamente',
									'empleadoID' => $empleadoF,
									'salidEnt' => $Salid,
									'motivoFueraGeocerca' => $motivoFueraGeocerca
								];
								
								header("HTTP/1.1 200 OK");
								echo json_encode($response);
								exit();
							} else {
								$response = ['estatus' => '0', 'mensaje' => 'Empleado no encontrado o inactivo'];
								header("HTTP/1.1 400 Bad Request");
								echo json_encode($response);
								exit();
							}
						} else {
							// Lógica para registros normales (esPendiente = 0) - misma lógica pero sin restricciones de tiempo
							// Aquí puedes implementar la lógica similar a RegistroEntrada pero sin validación de tiempo
							$response = ['estatus' => '1', 'mensaje' => 'Registro remoto normal procesado'];
							header("HTTP/1.1 200 OK");
							echo json_encode($response);
							exit();
						}
					} else {
						$response = ['estatus' => '0', 'mensaje' => 'Error al desencriptar datos del empleado'];
						header("HTTP/1.1 400 Bad Request");
						echo json_encode($response);
						exit();
					}
				} else {
					$response = ['estatus' => '0', 'mensaje' => 'Formato de request inválido'];
					header("HTTP/1.1 400 Bad Request");
					echo json_encode($response);
					exit();
				}
			} else {
				$response = ['estatus' => '0', 'mensaje' => 'Request vacío'];
				header("HTTP/1.1 400 Bad Request");
				echo json_encode($response);
				exit();
			}
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
									$sql_acceso = "SELECT emp.empleadoID,emp.acceso,ifnull(emp.DepartamentoID,0) as DepartamentoID,ifnull(emp.puestoID,0) as puestoID,emp.fotografia,ifnull(ro.departamento,'no tiene') as departamento,ifnull(pu.nombre,'no tiene') as nombre , concat(emp.nombre, ' ', emp.apellidoP, ' ', emp.apellidoM) as nombreCompleto,empr.nombreComercial,emp.estatus,emp.NSS,emp.comedor,emp.empresaID FROM tb_empleados  as emp
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
	                if (!empty({ult}) && isset({ult[0][0]})) {
	                    $ultimoTipo = intval({ult[0][0]});
	                    if ($ultimoTipo === 1) {
	                        $vtipo = 2; // Si el último fue entrada, ahora salida
	                    } else {
	                        $vtipo = 1; // Si el último fue salida, ahora entrada
	                    }
	                } else {
	                    $vtipo = 1; // Si no hay registro ese día, es entrada
	                }
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
			
			error_log("Geocerca validada OFFLINE - ID: " . $geocercaResult['geocercaID'] . 
				", Distancia: " . $geocercaResult['distanciaMetros'] . 
				", Validación: " . $geocercaResult['validacion']);
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
	error_log('--- FIN PROCESO REGISTRO OFFLINE ---');
							$response = [
								'estatus' => '1',
								'mensaje' => 'Registro offline procesado correctamente',
								'nombre' => $NameComplet,
								'empresa' => $empresaName,
								'tipoAccion' => ($vtipo == 1 ? 'Entrada' : 'Salida')
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