import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:geolocator/geolocator.dart';
import 'package:geocoding/geocoding.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter/services.dart';
import 'package:lottie/lottie.dart';
import 'package:local_auth/local_auth.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:permission_handler/permission_handler.dart' as perm_handler;
import '../providers/user_provider.dart';
import '../services/api_service.dart';
import '../services/registros_db_helper.dart';
import '../services/geocerca_service.dart';
import './_typewriter_secret_dialog.dart';
import './_confirmacion_fuera_geocerca_dialog.dart';

class RegistroRemotoScreen extends StatefulWidget {
  const RegistroRemotoScreen({super.key});

  @override
  State<RegistroRemotoScreen> createState() => _RegistroRemotoScreenState();
}

class _RegistroRemotoScreenState extends State<RegistroRemotoScreen> {
  int _easterEggTapCount = 0;
  DateTime? _lastEasterEggTap;
  final LocalAuthentication auth = LocalAuthentication();
  bool loading = false;
  String? error;
  Position? position;
  DateTime? lastPositionTime;
  Timer? locationTimer;

  static const int maxLocationAgeSeconds = 60;
  Stream<ServiceStatus>? _serviceStatusStream;
  StreamSubscription<ServiceStatus>? _serviceStatusSub;
  List<Map<String, dynamic>> registrosPendientes = [];
  // Cambia el tipo de la suscripción para aceptar List<ConnectivityResult>
  StreamSubscription<List<ConnectivityResult>>? _connectivitySub;

  final FlutterLocalNotificationsPlugin _localNotifications = FlutterLocalNotificationsPlugin();
  bool biometriaActivada = false;

  // Variables para geocerca
  Map<String, dynamic>? estadoGeocerca;
  Timer? geocercaTimer;
  bool verificandoGeocerca = false;

  @override
  void initState() {
    super.initState();
    _requestNotificationPermission();
    _initNotifications();
    _startLocationUpdates();
    _cargarPreferenciaBiometria();
    _iniciarVerificacionGeocerca();
    _serviceStatusStream = Geolocator.getServiceStatusStream();
    _serviceStatusSub = _serviceStatusStream!.listen((status) {
      if (status == ServiceStatus.disabled) {
        setState(() {
          error = 'La geolocalización se ha desactivado. Actívala para continuar.';
          loading = false;
        });
        if (mounted) {
          showDialog(
            context: context,
            builder: (ctx) => AlertDialog(
              title: const Text('Ubicación desactivada'),
              content: const Text('Debes activar la ubicación en tu dispositivo para continuar.'),
              actions: [
                TextButton(
                  onPressed: () {
                    Navigator.of(ctx).pop();
                    // Puedes abrir ajustes si lo deseas
                  },
                  child: const Text('Aceptar'),
                ),
              ],
            ),
          );
        }
      } else if (status == ServiceStatus.enabled && error != null) {
        // Si se reactiva la ubicación, intentar obtenerla de nuevo
        _obtenerGeolocalizacion();
      }
    });
    _cargarRegistrosPendientes();
    _connectivitySub = Connectivity().onConnectivityChanged.listen((results) {
      final result = results.isNotEmpty ? results.first : ConnectivityResult.none;
      if (result != ConnectivityResult.none) {
        _enviarRegistrosPendientes(silencioso: true);
      }
    });
  }

  Future<void> _requestNotificationPermission() async {
    bool permisoConcedido = true;
    if (Platform.isAndroid) {
      final androidInfo = await DeviceInfoPlugin().androidInfo;
      if (androidInfo.version.sdkInt >= 33) {
        final status = await perm_handler.Permission.notification.request();
        permisoConcedido = status.isGranted;
      }
    } else if (Platform.isIOS) {
      final iosPlugin = _localNotifications.resolvePlatformSpecificImplementation<
          IOSFlutterLocalNotificationsPlugin>();
      if (iosPlugin != null) {
        final result = await iosPlugin.requestPermissions(
          alert: true,
          badge: true,
          sound: true,
        );
        permisoConcedido = result ?? false;
      }
    }
    if (!permisoConcedido && mounted) {
      // Mostrar diálogo amigable si el usuario rechaza el permiso
      showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Permiso de notificaciones requerido'),
          content: const Text(
              'Para recibir alertas importantes, activa las notificaciones en la configuración de la app.'),
          actions: [
            TextButton(
              onPressed: () {
                Navigator.of(ctx).pop();
                perm_handler.openAppSettings();
              },
              child: const Text('Abrir configuración'),
            ),
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(),
              child: const Text('Cancelar'),
            ),
          ],
        ),
      );
    }
  }

  Future<void> _initNotifications() async {
    const AndroidInitializationSettings androidSettings = AndroidInitializationSettings('@mipmap/ic_launcher');
    const DarwinInitializationSettings iosSettings = DarwinInitializationSettings();
    const InitializationSettings initSettings = InitializationSettings(
      android: androidSettings,
      iOS: iosSettings,
    );
    await _localNotifications.initialize(initSettings);
  }

  Future<void> _showRegistroEnviadoNotification() async {
    const AndroidNotificationDetails androidDetails = AndroidNotificationDetails(
      'registro_channel',
      'Registros enviados',
      channelDescription: 'Notificaciones de registros enviados automáticamente',
      importance: Importance.max,
      priority: Priority.high,
      ticker: 'ticker',
    );
    const NotificationDetails notifDetails = NotificationDetails(android: androidDetails);
    await _localNotifications.show(
      0,
      'Registro enviado',
      'Un registro pendiente se envió exitosamente.',
      notifDetails,
    );
  }

  Future<void> _showRegistroPendienteNotification() async {
    const AndroidNotificationDetails androidDetails = AndroidNotificationDetails(
      'registro_channel',
      'Registros enviados',
      channelDescription: 'Notificaciones de registros enviados automáticamente',
      importance: Importance.max,
      priority: Priority.high,
      ticker: 'ticker',
    );
    const NotificationDetails notifDetails = NotificationDetails(android: androidDetails);
    await _localNotifications.show(
      1,
      'Registro guardado sin conexión',
      'El registro se guardó y se enviará automáticamente cuando vuelva la red.',
      notifDetails,
    );
  }

  // --- NUEVO: Métodos para SQLite ---
  Future<void> _cargarRegistrosPendientes() async {
    final listString = await RegistrosDbHelper().obtenerRegistros();
    registrosPendientes = listString
        .map((e) => ApiService.decodeUser(e))
        .where((reg) => reg.isNotEmpty)
        .toList();
  }

  Future<void> _guardarRegistroPendiente(Map<String, dynamic> reg) async {
    // Guardar los campos mínimos requeridos y los extras para el backend
    final regCopia = <String, dynamic>{
      'cadenaTiempo': (reg['cadenaTiempo'] ?? '').toString(),
      'cadenaEncriptada': (reg['cadenaEncriptada'] ?? '').toString(),
      // Campos extra requeridos por el backend con los nombres correctos
      'direccion': reg['direccion'] ?? '',
      'hadware': reg['hadware'] ?? reg['tipoHard'] ?? 'Nexus',
      'compania': reg['compania'] ?? reg['empresa'] ?? 'DRT',
      'ubicacion_Acc': reg['ubicacion_Acc'] ?? reg['ubicacionAcc'] ?? 'Remoto',
    };
    final tieneCadenaTiempo = regCopia['cadenaTiempo']!.isNotEmpty;
    final tieneCadenaEncriptada = regCopia['cadenaEncriptada']!.isNotEmpty;
    if (!tieneCadenaTiempo || !tieneCadenaEncriptada) {
      debugPrint('[WARN] Intento de guardar registro pendiente incompleto. Se omite: '
          '${JsonEncoder.withIndent('  ').convert(regCopia)}');
      return;
    }
    await RegistrosDbHelper().insertarRegistro(ApiService.encodeUser(regCopia));
    await _cargarRegistrosPendientes();
  }

  Future<void> _eliminarRegistroPendientePorId(int id) async {
    await RegistrosDbHelper().eliminarRegistro(id);
    await _cargarRegistrosPendientes();
  }

  Future<void> _enviarRegistrosPendientes({bool silencioso = false}) async {
    await _vibrar(); // Vibrar al iniciar el envío
    await _cargarRegistrosPendientes();
    if (registrosPendientes.isEmpty) return;
    final pendientes = List<Map<String, dynamic>>.from(registrosPendientes)
        .where((reg) {
          final tieneCadenaTiempo = reg['cadenaTiempo'] != null && reg['cadenaTiempo'].toString().isNotEmpty;
          final tieneCadenaEncriptada = reg['cadenaEncriptada'] != null && reg['cadenaEncriptada'].toString().isNotEmpty;
          if (!tieneCadenaTiempo || !tieneCadenaEncriptada) {
            debugPrint('[WARN] Registro pendiente corrupto/incompleto detectado y omitido: \x1B[34m${JsonEncoder.withIndent('  ').convert(reg)}');
          }
          return tieneCadenaTiempo && tieneCadenaEncriptada;
        })
        .toList();
    if (pendientes.isEmpty) {
      debugPrint('[INFO] No hay registros válidos para enviar.');
      return;
    }
    bool usarOrdenCronologicoReal = true;
    void ordenarPorDateTime(List<Map<String, dynamic>> list) {
      list.sort((a, b) {
        DateTime? parse(String s) {
          try {
            final parts = s.split(',');
            if (parts.length < 6) return null;
            final now = DateTime.now();
            return DateTime(now.year, now.month, int.parse(parts[0]), int.parse(parts[1]), int.parse(parts[2]), int.parse(parts[3]));
          } catch (_) {
            return null;
          }
        }
        final da = parse((a['cadenaTiempo'] ?? '').toString());
        final db = parse((b['cadenaTiempo'] ?? '').toString());
        if (da == null && db == null) return 0;
        if (da == null) return 1;
        if (db == null) return -1;
        return da.compareTo(db);
      });
    }
    if (usarOrdenCronologicoReal) {
      ordenarPorDateTime(pendientes);
      debugPrint('[INFO] Ordenando registros pendientes por DateTime real extraído de cadenaTiempo.');
    }
    int total = pendientes.length;
    int enviados = 0;
    bool modalAbierto = false;
    bool envioExitoso = false;
    bool envioError = false;
    StateSetter? setModalState;
    if (!silencioso) {
      await showModalBottomSheet(
        context: context,
        isDismissible: false,
        enableDrag: false,
        backgroundColor: Colors.transparent,
        builder: (ctx) {
          modalAbierto = true;
          return StatefulBuilder(
            builder: (context, setStateModal) {
              setModalState = setStateModal;
              return AnimatedContainer(
                duration: const Duration(milliseconds: 400),
                curve: Curves.easeInOut,
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.surface,
                  borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
                ),
                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Container(
                      width: 40,
                      height: 4,
                      margin: const EdgeInsets.only(bottom: 16),
                      decoration: BoxDecoration(
                        color: Colors.grey.shade300,
                        borderRadius: BorderRadius.circular(2),
                      ),
                    ),
                    if (!envioExitoso && !envioError) ...[
                      const Text('Enviando registros pendientes', style: TextStyle(fontWeight: FontWeight.bold)),
                      const SizedBox(height: 16),
                      LinearProgressIndicator(value: total == 0 ? 0 : enviados / total),
                      const SizedBox(height: 16),
                      Text('Enviando ${enviados + 1} de $total...'),
                    ] else if (envioExitoso) ...[
                      Lottie.asset('assets/lottie/success.json', width: 80, repeat: false),
                      const SizedBox(height: 12),
                      const Text('¡Todos los registros enviados!', style: TextStyle(color: Colors.green, fontWeight: FontWeight.bold)),
                    ] else if (envioError) ...[
                      Lottie.asset('assets/lottie/error.json', width: 80, repeat: false),
                      const SizedBox(height: 12),
                      const Text('Ocurrió un error al enviar.', style: TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
                    ],
                  ],
                ),
              );
            },
          );
        },
      );
    }
    debugPrint('--- Registros pendientes en SQLite antes de enviar ---');
    for (var i = 0; i < pendientes.length; i++) {
      debugPrint('[$i] \x1B[32m${pendientes[i]}\x1B[0m');
    }
    // Envío secuencial para máxima robustez
    try {
      for (final reg in pendientes) {
        debugPrint('[OFFLINE-WS] Enviando request: tiempo/cadena -> \x1B[34m${reg['cadenaTiempo']}\u001b[0m:${reg['cadenaEncriptada']}');
        final resp = await ApiService.registroRemoto(
          empleadoID: '',
          nombre: '',
          latitud: '',
          longitud: '',
          cadenaEmpleado: '',
          cadenaTiempo: reg['cadenaTiempo'],
          cadenaEncriptada: reg['cadenaEncriptada'],
          direccion: reg['direccion'] ?? '',
          empresa: reg['compania'] ?? '',
          ubicacionAcc: reg['ubicacion_Acc'] ?? '',
          tipoHard: reg['hadware'] ?? '',
          esPendiente: '1',
          motivoFueraGeocerca: reg['motivoFueraGeocerca'],
        );
        debugPrint('Respuesta WS: $resp');
        if (resp['estatus'] == '1' || resp['estatus'] == null) {
          final db = await RegistrosDbHelper().db;
          final res = await db.query('registros');
          debugPrint('Intentando eliminar registro con cadenaEncriptada: \x1B[33m${reg['cadenaEncriptada']}\x1B[0m y cadenaTiempo: \x1B[33m${reg['cadenaTiempo']}\x1B[0m');
          final idx = res.indexWhere((x) {
            final rawRegistro = x['data'];
            if (rawRegistro == null || (rawRegistro is String && rawRegistro.isEmpty)) {
              debugPrint('[WARN] Registro en SQLite con campo "data" null o vacío. id=\x1B[33m${x['id']}\x1B[0m');
              return false;
            }
            final decoded = ApiService.decodeUser(rawRegistro as String);
            final regCadena = (reg['cadenaTiempo'] ?? '').toString();
            final regEnc = (reg['cadenaEncriptada'] ?? '').toString();
            final decodedCadena = (decoded['cadenaTiempo'] ?? '').toString();
            final decodedEnc = (decoded['cadenaEncriptada'] ?? '').toString();
            if (regCadena.isEmpty || decodedCadena.isEmpty || regEnc.isEmpty || decodedEnc.isEmpty) {
              debugPrint('[WARN] Algún campo es null o vacío: regCadena=\x1B[31m$regCadena\x1B[0m, decodedCadena=\x1B[31m$decodedCadena\x1B[0m, regEnc=\x1B[31m$regEnc\x1B[0m, decodedEnc=\x1B[31m$decodedEnc\x1B[0m');
              return false;
            }
            return decodedCadena == regCadena && decodedEnc == regEnc;
          });
          debugPrint('Resultado idx para eliminar: $idx');
          if (idx != -1) {
            final id = res[idx]['id'] as int;
            await _eliminarRegistroPendientePorId(id);
            enviados++;
            await _showRegistroEnviadoNotification();
          } else {
            debugPrint('No se encontró el registro para eliminar en SQLite');
          }
        } else {
          envioError = true;
          if (!silencioso && setModalState != null) setModalState!(() {});
          break;
        }
        if (!silencioso && setModalState != null) setModalState!(() {});
      }
      if (!envioError) envioExitoso = true;
      if (!silencioso && setModalState != null) setModalState!(() {});
      await Future.delayed(const Duration(seconds: 2));
    } catch (e) {
      debugPrint('Error al enviar registros pendientes: $e');
      envioError = true;
      if (!silencioso && setModalState != null) setModalState!(() {});
      await Future.delayed(const Duration(seconds: 2));
    }
    await _cargarRegistrosPendientes();
    if (!silencioso && modalAbierto && Navigator.of(context).canPop()) {
      Navigator.of(context).pop();
      modalAbierto = false;
    }
    debugPrint('Registros pendientes restantes: ${registrosPendientes.length}');
    if (!silencioso && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Registros enviados: $enviados / $total'),
          backgroundColor: enviados == total ? Colors.green : Colors.orange,
          behavior: SnackBarBehavior.floating,
        ),
      );
      if (registrosPendientes.isNotEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Quedaron ${registrosPendientes.length} registros pendientes por enviar.'),
            backgroundColor: Colors.red,
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    }
  }

  void _iniciarVerificacionGeocerca() {
    geocercaTimer = Timer.periodic(const Duration(seconds: 10), (_) {
      _verificarGeocerca();
    });
  }

  Future<void> _verificarGeocerca() async {
    if (verificandoGeocerca || position == null) return;
    
    final userProvider = Provider.of<UserProvider>(context, listen: false);
    final user = userProvider.userData;
    final empleadoID = user?['empleadoID']?.toString();
    
    if (empleadoID == null || empleadoID.isEmpty) return;
    
    setState(() {
      verificandoGeocerca = true;
    });
    
    try {
      final resultado = await GeocercaService.verificarGeocerca(
        empleadoID: empleadoID,
        latitud: position!.latitude,
        longitud: position!.longitude,
      );
      
      if (mounted) {
        setState(() {
          estadoGeocerca = resultado;
          verificandoGeocerca = false;
        });
      }
    } catch (e) {
      debugPrint('Error verificando geocerca: $e');
      if (mounted) {
        setState(() {
          verificandoGeocerca = false;
        });
      }
    }
  }

  @override
  void dispose() {
    locationTimer?.cancel();
    geocercaTimer?.cancel();
    _serviceStatusSub?.cancel();
    _connectivitySub?.cancel();
    super.dispose();
  }

  void _startLocationUpdates() {
    _obtenerGeolocalizacion();
    locationTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      _obtenerGeolocalizacion(periodic: true);
    });
  }

  Future<void> _obtenerGeolocalizacion({bool periodic = false}) async {
    if (!periodic) {
      setState(() {
        loading = true;
        error = null;
      });
    }
    bool serviceEnabled;
    LocationPermission permission;

    serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      if (!periodic) {
        setState(() {
          error = 'La geolocalización está desactivada. Actívala para continuar.';
          loading = false;
        });
      }
      return;
    }

    permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied) {
        if (!periodic) {
          setState(() {
            error = 'Permiso de geolocalización denegado.';
            loading = false;
          });
        }
        return;
      }
    }
    if (permission == LocationPermission.deniedForever) {
      if (!periodic) {
        setState(() {
          error = 'Permiso de geolocalización denegado permanentemente.';
          loading = false;
        });
      }
      return;
    }

    try {
      final pos = await Geolocator.getCurrentPosition(desiredAccuracy: LocationAccuracy.high);
      setState(() {
        position = pos;
        lastPositionTime = DateTime.now();
        loading = false;
      });
      
      // Verificar geocerca cuando se actualiza la posición
      if (!periodic) {
        _verificarGeocerca();
      }
    } catch (e) {
      if (!periodic) {
        setState(() {
          error = 'No se pudo obtener la ubicación.';
          loading = false;
        });
      }
    }
  }

  Future<String> _obtenerDireccion(double lat, double lng) async {
    try {
      final placemarks = await placemarkFromCoordinates(lat, lng).timeout(
        const Duration(seconds: 7),
        onTimeout: () => [],
      );
      if (placemarks.isNotEmpty) {
        final p = placemarks.first;
        return [p.street, p.locality, p.administrativeArea, p.country]
            .where((e) => e != null && e.isNotEmpty)
            .join(', ');
      }
    } catch (_) {}
    return 'No disponible';
  }

  bool get ubicacionReciente {
    if (lastPositionTime == null) return false;
    final diff = DateTime.now().difference(lastPositionTime!);
    return diff.inSeconds <= maxLocationAgeSeconds;
  }

  String? get advertenciaUbicacion {
    if (position == null) return null;
    if (!ubicacionReciente) {
      return 'La ubicación es antigua. Espera a que se actualice para registrar.';
    }
    return null;
  }

  Future<void> _vibrar() async {
    await HapticFeedback.mediumImpact();
  }

  Future<bool> _hasRealInternet() async {
    try {
      final result = await InternetAddress.lookup('google.com').timeout(const Duration(seconds: 3));
      return result.isNotEmpty && result[0].rawAddress.isNotEmpty;
    } catch (_) {
      return false;
    }
  }

  Future<void> _enviarRegistroRemoto(Map<String, dynamic> datos) async {
    await _vibrar();
    if (!mounted) return;
    
    // Verificar geocerca antes de enviar
    String? motivoFueraGeocerca;
    if (estadoGeocerca != null && estadoGeocerca!['validacion'] == 'Fuera') {
      motivoFueraGeocerca = await mostrarDialogoFueraGeocerca(
        context: context,
        mensajeGeocerca: estadoGeocerca!['mensaje'] ?? 'Fuera de geocerca',
      );
      
      if (motivoFueraGeocerca == null) {
        // Usuario canceló
        return;
      }
      
      // Agregar motivo a los datos
      datos['motivoFueraGeocerca'] = motivoFueraGeocerca;
    }
    
    setState(() { loading = true; error = null; });
    // Verificar conectividad física
    final connectivityResults = await Connectivity().checkConnectivity();
    final connectivity = connectivityResults.isNotEmpty ? connectivityResults.first : ConnectivityResult.none;
    // --- NUEVO: Reintentar obtener dirección hasta lograrlo o agotar intentos ---
    String direccion = datos['direccion'] ?? '';
    int intentos = 0;
    const int maxIntentos = 3;
    while ((direccion.isEmpty || direccion == 'No disponible') && datos['latitud'] != null && datos['longitud'] != null && intentos < maxIntentos) {
      direccion = await _obtenerDireccion(
        double.tryParse(datos['latitud'].toString()) ?? 0.0,
        double.tryParse(datos['longitud'].toString()) ?? 0.0,
      );
      if (direccion == 'No disponible') {
        await Future.delayed(const Duration(seconds: 3));
      }
      intentos++;
    }
    // Actualiza el campo dirección en datos
    datos['direccion'] = direccion;
    if (connectivity == ConnectivityResult.none) {
      // Sin red física: guardar local y notificar
      // Guardar con esPendiente=1
      final datosPendiente = Map<String, dynamic>.from(datos);
      datosPendiente['esPendiente'] = '1';
      datosPendiente.remove('tipo'); // Eliminar campo tipo si existe
      await _guardarRegistroPendiente(datosPendiente);
      debugPrint('Sin red física: registro guardado localmente (SQLite)');
      if (!mounted) return;
      setState(() {
        loading = false;
        error = null;
      });
      await _showRegistroPendienteNotification();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Row(
            children: [
              const Icon(Icons.cloud_off, color: Colors.white),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  'Sin conexión: registro guardado localmente (#${registrosPendientes.length} pendiente${registrosPendientes.length > 1 ? 's' : ''}). Se enviará cuando vuelva la red.',
                  style: const TextStyle(fontWeight: FontWeight.w500),
                ),
              ),
            ],
          ),
          backgroundColor: Colors.orange.shade700,
          behavior: SnackBarBehavior.floating,
          duration: const Duration(seconds: 5),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          margin: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
        ),
      );
      return;
    }
    // Si hay red física, verificar acceso real a internet
    final hasInternet = await _hasRealInternet();
    if (!hasInternet) {
      // Guardar con esPendiente=1
      final datosPendiente = Map<String, dynamic>.from(datos);
      datosPendiente['esPendiente'] = '1';
      datosPendiente.remove('tipo'); // Eliminar campo tipo si existe
      await _guardarRegistroPendiente(datosPendiente);
      debugPrint('Sin internet real: registro guardado localmente (SQLite)');
      if (!mounted) return;
      setState(() {
        loading = false;
        error = 'No hay internet real. El registro se guardó y se enviará automáticamente cuando vuelva la red.';
      });
      await _showRegistroPendienteNotification();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('No hay internet real. Registro guardado localmente (#${registrosPendientes.length} pendiente${registrosPendientes.length > 1 ? 's' : ''}). Se enviará cuando vuelva la conexión.'),
          backgroundColor: Colors.orange,
          behavior: SnackBarBehavior.floating,
          duration: const Duration(seconds: 4),
        ),
      );
      return;
    }
    // Si hay internet real, intentar el envío remoto
    try {
      // Envío normal: esPendiente=0
      final datosNormal = Map<String, dynamic>.from(datos);
      datosNormal['esPendiente'] = '0';
      datosNormal.remove('tipo'); // Eliminar campo tipo si existe
      if (motivoFueraGeocerca != null) {
        datosNormal['motivoFueraGeocerca'] = motivoFueraGeocerca;
      }
      final resp = await ApiService.registroRemoto(
        empleadoID: datosNormal['empleadoID'],
        nombre: datosNormal['nombre'],
        latitud: datosNormal['latitud'],
        longitud: datosNormal['longitud'],
        cadenaEmpleado: datosNormal['cadenaEmpleado'],
        cadenaTiempo: datosNormal['cadenaTiempo'],
        cadenaEncriptada: datosNormal['cadenaEncriptada'],
        direccion: datosNormal['direccion'],
        empresa: datosNormal['empresa'],
        ubicacionAcc: datosNormal['ubicacionAcc'],
        tipoHard: datosNormal['tipoHard'],
        esPendiente: datosNormal['esPendiente'],
        motivoFueraGeocerca: datosNormal['motivoFueraGeocerca'],
      );
      debugPrint('Respuesta del webservice: $resp');
      if (!mounted) return;
      if (resp['estatus'] == '1') {
        setState(() { loading = false; });
        if (!mounted) return;
        // Mostrar el mensaje personalizado del backend (entrada/salida)
        final mensaje = resp['mensaje'] ?? 'Registro enviado exitosamente.';
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(mensaje),
            backgroundColor: Colors.green,
            behavior: SnackBarBehavior.floating,
          ),
        );
      } else {
        // Error del servidor (ej: código QR expirado)
        final mensajeError = resp['mensaje'] ?? 'Error desconocido';
        
        if (mensajeError.contains('expiro') || mensajeError.contains('minutos')) {
          // Código QR expirado - guardar para reenvío posterior
          final datosPendiente = Map<String, dynamic>.from(datos);
          datosPendiente['esPendiente'] = '1';
          datosPendiente.remove('tipo');
          if (motivoFueraGeocerca != null) {
            datosPendiente['motivoFueraGeocerca'] = motivoFueraGeocerca;
          }
          await _guardarRegistroPendiente(datosPendiente);
          
          if (!mounted) return;
          setState(() {
            loading = false;
            error = null;
          });
          
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Código QR expirado. Registro guardado y se reenviará automáticamente.'),
              backgroundColor: Colors.orange,
              behavior: SnackBarBehavior.floating,
              duration: const Duration(seconds: 4),
            ),
          );
        } else {
          // Otro tipo de error
          if (!mounted) return;
          setState(() {
            loading = false;
            error = 'Error: $mensajeError';
          });
        }
      }
    } catch (e) {
      debugPrint('Error al enviar registro remoto: $e');
      // Guardar con esPendiente=1
      final datosPendiente = Map<String, dynamic>.from(datos);
      datosPendiente['esPendiente'] = '1';
      datosPendiente.remove('tipo'); // Eliminar campo tipo si existe
      await _guardarRegistroPendiente(datosPendiente);
      if (!mounted) return;
      setState(() {
        loading = false;
        error = 'No se pudo conectar con el servidor. El registro se guardó y se enviará automáticamente cuando vuelva la red.';
      });
      await _showRegistroPendienteNotification();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('No se pudo conectar. Registro guardado localmente (#${registrosPendientes.length} pendiente${registrosPendientes.length > 1 ? 's' : ''}). Se enviará cuando vuelva la conexión.'),
          backgroundColor: Colors.orange,
          behavior: SnackBarBehavior.floating,
          duration: const Duration(seconds: 4),
        ),
      );
    }
  }

  Future<bool> _autenticarBiometrico() async {
    try {
      final bool canCheck = await auth.canCheckBiometrics;
      final bool isAvailable = await auth.isDeviceSupported();
      // Si la biometría está deshabilitada en la app, simplemente permitir
      if (!biometriaActivada) return true;
      // Si el dispositivo no soporta biometría o no está configurada, permitir el registro normal
      if (!canCheck || !isAvailable) return true;
      final bool didAuthenticate = await auth.authenticate(
        localizedReason: 'Por seguridad, autentícate para registrar tu asistencia',
        options: const AuthenticationOptions(biometricOnly: true, stickyAuth: true),
      );
      if (!didAuthenticate && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Autenticación biométrica fallida o cancelada.'),
            backgroundColor: Colors.red,
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
      return didAuthenticate;
    } catch (e) {
      debugPrint('Error biométrico: $e');
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Error biométrico: $e'),
            backgroundColor: Colors.red,
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
      return true; // Permitir el registro si ocurre un error inesperado
    }
  }

  Future<void> _cargarPreferenciaBiometria() async {
    final prefs = await SharedPreferences.getInstance();
    setState(() {
      biometriaActivada = prefs.getBool('biometria_activada') ?? false;
    });
  }

  Future<void> _guardarPreferenciaBiometria(bool value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('biometria_activada', value);
    setState(() {
      biometriaActivada = value;
    });
  }

  @override
  Widget build(BuildContext context) {
    final userProvider = Provider.of<UserProvider>(context, listen: false);
    final user = userProvider.userData;
    final theme = Theme.of(context);

    return Scaffold(
      // Fondo degradado minimalista
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              Color(0xFFe0eafc), // azul claro
              Color(0xFFcfdef3), // azul-grisáceo
              Color(0xFFf9fafc), // casi blanco
            ],
          ),
        ),
        child: Center(
          child: SingleChildScrollView(
            child: Padding(
              padding: const EdgeInsets.all(24.0),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Container(
                    decoration: BoxDecoration(
                      color: theme.colorScheme.primary.withOpacity(0.08),
                      borderRadius: BorderRadius.circular(24),
                      boxShadow: [
                        BoxShadow(
                          color: theme.colorScheme.primary.withOpacity(0.08),
                          blurRadius: 24,
                          offset: const Offset(0, 8),
                        ),
                      ],
                    ),
                    padding: const EdgeInsets.all(24),
                    child: Icon(Icons.fingerprint, size: 80, color: theme.colorScheme.primary),
                  ),
                  const SizedBox(height: 32),
                  GestureDetector(
                    onTap: () async {
                      final now = DateTime.now();
                      if (_lastEasterEggTap == null || now.difference(_lastEasterEggTap!) > Duration(seconds: 2)) {
                        _easterEggTapCount = 1;
                      } else {
                        _easterEggTapCount++;
                      }
                      _lastEasterEggTap = now;
                      if (_easterEggTapCount == 7) {
                        await HapticFeedback.heavyImpact();
                        _easterEggTapCount = 0;
                        // Mostrar mensaje de modo secreto activado con efecto máquina de escribir nativo
                        await showDialog(
                          context: context,
                          barrierDismissible: false,
                          builder: (ctx) => TypewriterSecretDialog(),
                        );
                        // Luego mostrar el minijuego con círculos
                        int secretNumber = 1 + (DateTime.now().millisecondsSinceEpoch % 10);
                        bool acertado = false;
                        // Lista para bloquear los números incorrectos
                        List<bool> bloqueados = List.generate(10, (_) => false);
                        showDialog(
                          context: context,
                          barrierDismissible: false,
                          builder: (ctx) {
                            return StatefulBuilder(
                              builder: (context, setState) {
                                // Función para mostrar el confetti fullscreen
                                void showConfettiFullScreen() {
                                  Navigator.of(ctx).pop(); // Cierra el minijuego
                                  showGeneralDialog(
                                    context: context,
                                    barrierDismissible: false,
                                    barrierColor: Colors.black.withOpacity(0.3),
                                    transitionDuration: const Duration(milliseconds: 300),
                                    pageBuilder: (context, anim1, anim2) {
                                      return Scaffold(
                                        backgroundColor: Colors.transparent,
                                        body: Stack(
                                          children: [
                                            Positioned.fill(
                                              child: Lottie.asset(
                                                'assets/lottie/Confetti.json',
                                                fit: BoxFit.cover,
                                                repeat: false,
                                              ),
                                            ),
                                            Center(
                                              child: Container(
                                                padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 32),
                                                decoration: BoxDecoration(
                                                  color: Colors.white,
                                                  borderRadius: BorderRadius.circular(32),
                                                  boxShadow: [
                                                    BoxShadow(
                                                      color: Colors.black.withOpacity(0.15),
                                                      blurRadius: 32,
                                                      offset: const Offset(0, 8),
                                                    ),
                                                  ],
                                                ),
                                                child: Column(
                                                  mainAxisSize: MainAxisSize.min,
                                                  children: [
                                                    const Text(
                                                      '¡Correcto! El número secreto era:',
                                                      style: TextStyle(fontWeight: FontWeight.bold, color: Colors.deepPurple, fontSize: 22),
                                                    ),
                                                    Text(
                                                      '$secretNumber',
                                                      style: const TextStyle(fontSize: 48, fontWeight: FontWeight.bold, color: Colors.green),
                                                    ),
                                                    const SizedBox(height: 16),
                                                    const Text('¡Felicidades, encontraste el modo secreto!', style: TextStyle(fontSize: 20)),
                                                    const SizedBox(height: 32),
                                                    FilledButton(
                                                      onPressed: () => Navigator.of(context).pop(),
                                                      child: const Text('Cerrar', style: TextStyle(fontWeight: FontWeight.bold)),
                                                    ),
                                                  ],
                                                ),
                                              ),
                                            ),
                                          ],
                                        ),
                                      );
                                    },
                                  );
                                }
                                return Dialog(
                                  backgroundColor: Colors.transparent,
                                  insetPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 24),
                                  child: Container(
                                    decoration: BoxDecoration(
                                      gradient: const LinearGradient(
                                        colors: [Color(0xFFe0c3fc), Color(0xFF8ec5fc)],
                                        begin: Alignment.topLeft,
                                        end: Alignment.bottomRight,
                                      ),
                                      borderRadius: BorderRadius.circular(32),
                                      border: Border.all(
                                        color: Colors.deepPurple.withOpacity(0.5),
                                        width: 3,
                                      ),
                                      boxShadow: [
                                        BoxShadow(
                                          color: Colors.deepPurple.withOpacity(0.12),
                                          blurRadius: 24,
                                          offset: const Offset(0, 8),
                                        ),
                                      ],
                                    ),
                                    child: Padding(
                                      padding: const EdgeInsets.all(24.0),
                                      child: Column(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          if (!acertado) ...[
                                            // Animación de fuegos artificiales en la parte superior
                                            SizedBox(
                                              height: 140,
                                              child: Lottie.asset(
                                                'assets/lottie/Fireworks.json',
                                                fit: BoxFit.contain,
                                                repeat: true,
                                              ),
                                            ),
                                            const SizedBox(height: 8),
                                            Text(
                                              '¡Easter Egg! 🎉',
                                              style: TextStyle(
                                                fontWeight: FontWeight.bold,
                                                fontSize: 22,
                                                color: Colors.deepPurple.shade700,
                                                letterSpacing: 1.2,
                                              ),
                                            ),
                                            const SizedBox(height: 8),
                                            const Text('Minijuego secreto', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: Colors.deepPurple)),
                                            const SizedBox(height: 12),
                                            const Text('Adivina el número secreto (1-10):'),
                                            const SizedBox(height: 16),
                                            Wrap(
                                              spacing: 12,
                                              runSpacing: 12,
                                              children: List.generate(10, (i) {
                                                int num = i + 1;
                                                bool isBlocked = bloqueados[i];
                                                return GestureDetector(
                                                  onTap: isBlocked
                                                      ? null
                                                      : () {
                                                          if (num == secretNumber) {
                                                            // Mostrar confetti fullscreen y cerrar minijuego
                                                            showConfettiFullScreen();
                                                          } else {
                                                            setState(() {
                                                              bloqueados[i] = true;
                                                            });
                                                            ScaffoldMessenger.of(context).showSnackBar(
                                                              SnackBar(content: Text('¡$num no es! Intenta de nuevo.'), backgroundColor: const Color.fromARGB(230, 111, 46, 197)),
                                                            );
                                                          }
                                                        },
                                                  child: CircleAvatar(
                                                    radius: 26,
                                                    backgroundColor: isBlocked
                                                        ? Colors.grey.shade300
                                                        : Colors.deepPurple.shade100,
                                                    child: Text(
                                                      '$num',
                                                      style: TextStyle(
                                                        fontSize: 20,
                                                        fontWeight: FontWeight.bold,
                                                        color: isBlocked ? Colors.grey : Colors.deepPurple,
                                                      ),
                                                    ),
                                                  ),
                                                );
                                              }),
                                            ),
                                            const SizedBox(height: 16),
                                            TextButton(
                                              onPressed: () => Navigator.of(ctx).pop(),
                                              child: const Text('Salir'),
                                            ),
                                          ],
                                        ],
                                      ),
                                    ),
                                  ),
                                );
                              },
                            );
                          },
                        );
                      }
                    },
                    child: Card(
                      elevation: 2,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                      color: Colors.white.withOpacity(0.85),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 20, horizontal: 16),
                        child: Column(
                          children: [
                            Text('Empleado', style: theme.textTheme.labelMedium),
                            Text('${user?['nombre'] ?? ''}', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
                            const SizedBox(height: 8),
                            Text('Empresa', style: theme.textTheme.labelMedium),
                            Text('${user?['empresa'] ?? ''}', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 24),
                  if (loading) const CircularProgressIndicator(),
                  if (error != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 16),
                      child: Text(error!, style: TextStyle(color: theme.colorScheme.error)),
                    ),
                  if (position != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Text('Ubicación: ${position!.latitude}, ${position!.longitude}', style: theme.textTheme.bodySmall),
                    ),
                  // Widget de estado de zona de trabajo - minimalista
                  if (position != null)
                    Container(
                      margin: const EdgeInsets.only(bottom: 16),
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.9),
                        borderRadius: BorderRadius.circular(12),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withOpacity(0.05),
                            blurRadius: 8,
                            offset: const Offset(0, 2),
                          ),
                        ],
                      ),
                      child: Row(
                        children: [
                          if (verificandoGeocerca)
                            SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                valueColor: AlwaysStoppedAnimation<Color>(
                                  theme.colorScheme.primary,
                                ),
                              ),
                            )
                          else
                            Icon(
                              IconData(
                                GeocercaService.getIconForStatus(estadoGeocerca?['validacion'] ?? 'Error'),
                                fontFamily: 'MaterialIcons',
                              ),
                              size: 20,
                              color: Color(GeocercaService.getColorForStatus(estadoGeocerca?['validacion'] ?? 'Error')),
                            ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Text(
                              verificandoGeocerca 
                                  ? 'Verificando ubicación...'
                                  : estadoGeocerca?['mensaje'] ?? 'Verificando zona de trabajo...',
                              style: theme.textTheme.bodyMedium?.copyWith(
                                fontWeight: FontWeight.w500,
                                color: verificandoGeocerca 
                                    ? theme.colorScheme.primary
                                    : Color(GeocercaService.getColorForStatus(estadoGeocerca?['validacion'] ?? 'Error')),
                              ),
                            ),
                          ),
                          if (!verificandoGeocerca && position != null)
                            IconButton(
                              onPressed: _verificarGeocerca,
                              icon: Icon(
                                Icons.refresh_rounded,
                                size: 18,
                                color: theme.colorScheme.primary.withOpacity(0.7),
                              ),
                              tooltip: 'Actualizar estado',
                              padding: const EdgeInsets.all(4),
                              constraints: const BoxConstraints(
                                minWidth: 32,
                                minHeight: 32,
                              ),
                            ),
                        ],
                      ),
                    ),
                  if (advertenciaUbicacion != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 8, bottom: 8),
                      child: Text(advertenciaUbicacion!, style: TextStyle(color: theme.colorScheme.secondary)),
                    ),
                  const SizedBox(height: 24),
                  Row(
                    children: [
                      Expanded(
                        child: FilledButton.icon(
                          icon: const Icon(Icons.send),
                          label: const Text('Enviar registro'),
                          onPressed: (position != null && !loading && ubicacionReciente)
                              ? () async {
                                  if (biometriaActivada) {
                                    final ok = await _autenticarBiometrico();
                                    if (!ok) {
                                      if (mounted) {
                                        ScaffoldMessenger.of(context).showSnackBar(
                                          const SnackBar(content: Text('Autenticación biométrica fallida o cancelada.')),
                                        );
                                      }
                                      return;
                                    }
                                  }
                                  await _vibrar();
                                  final user = userProvider.userData;
                                  if (user == null) return;
                                  final cadenaEncriptada = user['cadena'] ?? '';
                                  final now = DateTime.now();
                                  final dia = now.day.toString().padLeft(2, '0');
                                  final hora = now.hour.toString().padLeft(2, '0');
                                  final minuto = now.minute.toString().padLeft(2, '0');
                                  final segundo = now.second.toString().padLeft(2, '0');
                                  final lat = position!.latitude.toString();
                                  final lng = position!.longitude.toString();
                                  final cadenaTiempo = '$dia,$hora,$minuto,$segundo,$lat,$lng';
                                  final direccion = await _obtenerDireccion(position!.latitude, position!.longitude);
                                  final datos = {
                                    'empleadoID': user['empleadoID'].toString(),
                                    'nombre': user['nombre'] ?? '',
                                    'latitud': lat,
                                    'longitud': lng,
                                    'cadenaEmpleado': '',
                                    'cadenaTiempo': cadenaTiempo,
                                    'cadenaEncriptada': cadenaEncriptada,
                                    'direccion': direccion,
                                    'empresa': 'DRT',
                                    'ubicacionAcc': 'Remoto',
                                    'tipoHard': 'Nexus',
                                  };
                                  await _enviarRegistroRemoto(datos);
                                }
                              : null,
                          style: FilledButton.styleFrom(
                            minimumSize: const Size.fromHeight(50),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          icon: const Icon(Icons.refresh),
                          label: const Text('Reintentar geolocalización'),
                          onPressed: loading ? null : () async {
                            await _vibrar();
                            _obtenerGeolocalizacion(periodic: false);
                          },
                          style: OutlinedButton.styleFrom(
                            minimumSize: const Size.fromHeight(50),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: SwitchListTile.adaptive(
                          value: biometriaActivada,
                          onChanged: (v) => _guardarPreferenciaBiometria(v),
                          title: const Text('Solicitar biometría al registrar'),
                          secondary: const Icon(Icons.fingerprint),
                          contentPadding: EdgeInsets.zero,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      // Oculto el botón de registros pendientes
                      // Expanded(
                      //   child: OutlinedButton.icon(
                      //     icon: const Icon(Icons.list_alt),
                      //     label: const Text('Ver registros pendientes (debug)'),
                      //     onPressed: ...
                      //   ),
                      // ),
                    ],
                  ),
                  const SizedBox(height: 16),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// IMPORTANTE: Cuando se reenvía un registro pendiente, NO modificar ni recalcular
// los valores de cadenaTiempo, latitud, longitud, etc. Se deben usar exactamente
// los valores guardados en el registro pendiente (momento original del evento offline).
// Esto asegura que el backend registre la hora y ubicación reales del evento.
