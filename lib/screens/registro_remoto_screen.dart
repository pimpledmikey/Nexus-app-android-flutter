import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
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
import 'package:flutter_spinkit/flutter_spinkit.dart';
import '../providers/user_provider.dart';
import '../services/api_service.dart';
import '../services/registros_db_helper.dart';
import '../services/geocerca_service.dart';
import '../services/security_service.dart';
import 'package:qr_flutter/qr_flutter.dart';
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

  // Cache de imagen del empleado para evitar parpadeo
  Uint8List? _cachedFotoBytes;
  String? _lastFotoBase64;

  // Contador de intentos de GPS para mostrar botón "Registrar sin GPS"
  int _intentosGps = 0;
  static const int _maxIntentosParaSinGps = 5;
  bool _reintentandoGps = false;

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
      // Adjuntos (paths locales) soportados para envío posterior
      'attachments': reg['attachments'] ?? [] ,
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
                      SpinKitChasingDots(
                        color: Theme.of(context).colorScheme.primary,
                        size: 48,
                      ),
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
        // Soporte para adjuntos en registros pendientes
        final List<String> pendingAttachments = List<String>.from(reg['attachments'] ?? []);
        Map<String, dynamic> resp;
        if (pendingAttachments.isNotEmpty) {
          resp = await ApiService.registroRemotoConEvidencias(
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
            attachmentPaths: pendingAttachments,
          );
        } else {
          resp = await ApiService.registroRemoto(
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
        }
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
          content: Row(
            children: [
              Icon(
                enviados == total ? Icons.check_circle : Icons.upload,
                color: Colors.white,
                size: 20,
              ),
              const SizedBox(width: 12),
              Text('Registros enviados: $enviados / $total'),
            ],
          ),
          backgroundColor: enviados == total ? Colors.green : Colors.orange,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        ),
      );
      if (registrosPendientes.isNotEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Row(
              children: [
                const Icon(Icons.pending, color: Colors.white, size: 20),
                const SizedBox(width: 12),
                Text('Quedaron ${registrosPendientes.length} registros pendientes por enviar.'),
              ],
            ),
            backgroundColor: Colors.red,
            behavior: SnackBarBehavior.floating,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
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

  Future<void> _verificarGeocerca({bool silencioso = true}) async {
    if (verificandoGeocerca || position == null) return;
    
    // Verificar conectividad PRIMERO para no bloquear si no hay internet
    final connectivityResults = await Connectivity().checkConnectivity();
    final connectivity = connectivityResults.isNotEmpty ? connectivityResults.first : ConnectivityResult.none;
    
    // Si no hay conexión, no intentar verificar geocerca (evita bloqueo)
    if (connectivity == ConnectivityResult.none) {
      debugPrint('Sin conexión - omitiendo verificación de geocerca');
      if (estadoGeocerca == null && mounted) {
        setState(() {
          estadoGeocerca = {
            'estatus': '0',
            'validacion': 'Sin_Conexion',
            'mensaje': 'Sin conexión para verificar zona',
          };
        });
      }
      return;
    }
    
    final userProvider = Provider.of<UserProvider>(context, listen: false);
    final user = userProvider.userData;
    final empleadoID = user?['empleadoID']?.toString();
    
    if (empleadoID == null || empleadoID.isEmpty) return;
    
    // Solo mostrar loading si no es silencioso y es la primera vez
    if (!silencioso || estadoGeocerca == null) {
      setState(() {
        verificandoGeocerca = true;
      });
    } else {
      verificandoGeocerca = true; // Sin setState para no parpadear
    }
    
    try {
      final resultado = await GeocercaService.verificarGeocerca(
        empleadoID: empleadoID,
        latitud: position!.latitude,
        longitud: position!.longitude,
      );
      
      if (mounted) {
        // Solo actualizar UI si cambió el estado
        final cambio = estadoGeocerca?['validacion'] != resultado['validacion'] ||
                        estadoGeocerca?['mensaje'] != resultado['mensaje'];
        estadoGeocerca = resultado;
        verificandoGeocerca = false;
        if (cambio || !silencioso) {
          setState(() {});
        }
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
      // Timeout de 30 segundos (sin A-GPS puede tardar más en modo avión)
      final pos = await Geolocator.getCurrentPosition(desiredAccuracy: LocationAccuracy.high)
          .timeout(const Duration(seconds: 30), onTimeout: () {
        debugPrint('Timeout obteniendo ubicación GPS (30s)');
        throw Exception('Timeout GPS');
      });
      if (!mounted) return;
      setState(() {
        position = pos;
        lastPositionTime = DateTime.now();
        loading = false;
        error = null; // Limpiar error si se obtuvo posición
        _intentosGps = 0; // Resetear intentos al obtener posición exitosamente
      });
      
      // Verificar geocerca cuando se actualiza la posición
      if (!periodic) {
        _verificarGeocerca();
      }
    } catch (e) {
      debugPrint('Error GPS: $e');
      if (!periodic) {
        setState(() {
          _intentosGps++;
          if (_intentosGps >= _maxIntentosParaSinGps) {
            error = 'No se pudo obtener la ubicación después de $_intentosGps intentos. Puedes registrar sin GPS.';
          } else {
            error = 'No se pudo obtener la ubicación (intento $_intentosGps de $_maxIntentosParaSinGps). Pulsa "Reintentar GPS" o sal al exterior.';
          }
          loading = false;
          _reintentandoGps = false;
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
    
    setState(() { loading = true; error = null; });
    
    // ===== PASO 0: VERIFICAR CONECTIVIDAD PRIMERO (RÁPIDO) =====
    // Esto permite guardar offline inmediatamente en modo avión
    final connectivityResults = await Connectivity().checkConnectivity();
    final connectivity = connectivityResults.isNotEmpty ? connectivityResults.first : ConnectivityResult.none;
    
    // Si no hay conectividad física (modo avión), guardar offline INMEDIATAMENTE
    if (connectivity == ConnectivityResult.none) {
      debugPrint('Sin conectividad física detectada - guardando offline inmediatamente');
      final datosPendiente = Map<String, dynamic>.from(datos);
      datosPendiente['esPendiente'] = '1';
      datosPendiente.remove('tipo');
      await _guardarRegistroPendiente(datosPendiente);
      
      if (!mounted) return;
      setState(() { loading = false; error = null; });
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
    
    // Verificar acceso real a internet (con timeout corto de 3s)
    bool hasInternet = await _hasRealInternet();
    
    // Si no hay internet real (ej: WiFi sin internet), guardar offline
    if (!hasInternet) {
      debugPrint('Sin internet real detectado - guardando offline');
      final datosPendiente = Map<String, dynamic>.from(datos);
      datosPendiente['esPendiente'] = '1';
      datosPendiente.remove('tipo');
      await _guardarRegistroPendiente(datosPendiente);
      
      if (!mounted) return;
      setState(() { loading = false; error = null; });
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
                  'Sin conexión a internet: registro guardado localmente (#${registrosPendientes.length} pendiente${registrosPendientes.length > 1 ? 's' : ''}). Se enviará automáticamente.',
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
    
    // ===== PASO 1: VERIFICACIÓN DE SEGURIDAD (solo si hay internet) =====
    if (position != null) {
      final securityCheck = await SecurityService.performSecurityCheck(position!);
      final bool securityPassed = securityCheck['passed'];
      final List<String> warnings = List<String>.from(securityCheck['warnings']);
      final String severity = securityCheck['severity'];
      
      // Nivel 2: BLOQUEAR si hay VPN o GPS falso (crítico/alto)
      if (!securityPassed && (severity == 'critical' || severity == 'high')) {
        final bool isLocationMocked = securityCheck['checks']['locationMocked'] ?? false;
        final bool isVpnActive = securityCheck['checks']['vpnActive'] ?? false;
        
        if (isLocationMocked || isVpnActive) {
          setState(() { loading = false; });
          
          if (!mounted) return;
          
          // Mostrar diálogo de bloqueo con iconos
          await showDialog(
            context: context,
            barrierDismissible: false,
            builder: (ctx) => AlertDialog(
              title: Row(
                children: [
                  Icon(Icons.security, color: Theme.of(ctx).colorScheme.error, size: 28),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text(
                      'Verificación de Seguridad',
                      style: TextStyle(fontSize: 18),
                    ),
                  ),
                ],
              ),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'No se puede registrar por las siguientes razones:',
                    style: TextStyle(fontWeight: FontWeight.w500),
                  ),
                  const SizedBox(height: 16),
                  if (isLocationMocked)
                    Row(
                      children: [
                        Icon(Icons.location_off, 
                          color: Theme.of(ctx).colorScheme.error,
                          size: 20,
                        ),
                        const SizedBox(width: 8),
                        const Expanded(
                          child: Text('GPS falso detectado'),
                        ),
                      ],
                    ),
                  if (isLocationMocked && isVpnActive)
                    const SizedBox(height: 12),
                  if (isVpnActive)
                    Row(
                      children: [
                        Icon(Icons.vpn_key_off, 
                          color: Theme.of(ctx).colorScheme.error,
                          size: 20,
                        ),
                        const SizedBox(width: 8),
                        const Expanded(
                          child: Text('VPN activa detectada'),
                        ),
                      ],
                    ),
                  const SizedBox(height: 16),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Theme.of(ctx).colorScheme.errorContainer,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Row(
                      children: [
                        Icon(
                          Icons.info_outline,
                          color: Theme.of(ctx).colorScheme.onErrorContainer,
                          size: 20,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'Por seguridad, desactiva estas funciones para registrar tu asistencia.',
                            style: TextStyle(
                              fontSize: 13,
                              color: Theme.of(ctx).colorScheme.onErrorContainer,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              actions: [
                TextButton.icon(
                  icon: const Icon(Icons.check),
                  label: const Text('Entendido'),
                  onPressed: () => Navigator.of(ctx).pop(),
                ),
              ],
            ),
          );
          
          return; // BLOQUEAR el registro
        }
      }
      
      // Si hay advertencias de nivel medio (root/jailbreak), solo mostrar advertencia pero permitir
      if (!securityPassed && severity == 'medium') {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Row(
              children: [
                const Icon(Icons.warning_amber, color: Colors.white, size: 20),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(warnings.join(", ")),
                ),
              ],
            ),
            backgroundColor: Colors.orange,
            behavior: SnackBarBehavior.floating,
            duration: const Duration(seconds: 3),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          ),
        );
        // Continuar con el registro pero registrar la advertencia
      }
    }
    
    // ===== PASO 2: Verificar si debe pedir motivo por geocerca =====
    String? motivoFueraGeocerca;
    // Verificar si es primer registro del día para determinar si pedir motivo
    final userProvider = Provider.of<UserProvider>(context, listen: false);
    final user = userProvider.userData;
    final empleadoID = user?['empleadoID']?.toString();
    
    bool requierePedirMotivo = true; // Por defecto asumir que sí
    if (empleadoID != null && empleadoID.isNotEmpty) {
      try {
        final tipoInfo = await GeocercaService.verificarTipoRegistro(empleadoID: empleadoID);
        requierePedirMotivo = tipoInfo['requierePedirMotivo'] ?? true;
        debugPrint('Tipo de registro: ${tipoInfo['estadoRegistro']}, Requiere motivo: $requierePedirMotivo');
      } catch (e) {
        debugPrint('Error verificando tipo de registro: $e');
        // Mantener valor por defecto
      }
    }
    
    // Verificar geocerca y pedir motivo solo si es necesario
    if (estadoGeocerca != null && 
        estadoGeocerca!['validacion'] == 'Fuera' && 
        requierePedirMotivo) {
      
      final resultFuera = await mostrarDialogoFueraGeocerca(
        context: context,
        mensajeGeocerca: estadoGeocerca!['mensaje'] ?? 'Fuera de geocerca',
      );

      if (resultFuera == null) {
        // Usuario canceló
        setState(() { loading = false; });
        return;
      }

      // Extraer motivo y adjuntos
      motivoFueraGeocerca = (resultFuera['motivo'] ?? '') as String?;
      final List<String> attachments = List<String>.from(resultFuera['attachments'] ?? []);

      // Agregar motivo y attachments a los datos
      datos['motivoFueraGeocerca'] = motivoFueraGeocerca;
      if (attachments.isNotEmpty) {
        datos['attachments'] = attachments;
      }
    }
    
    // ===== PASO 3: Reintentar obtener dirección =====
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
    
    // ===== PASO 4: Enviar registro al servidor =====
    try {
      // Envío normal: esPendiente=0
      final datosNormal = Map<String, dynamic>.from(datos);
      datosNormal['esPendiente'] = '0';
      datosNormal.remove('tipo'); // Eliminar campo tipo si existe
      if (motivoFueraGeocerca != null) {
        datosNormal['motivoFueraGeocerca'] = motivoFueraGeocerca;
      }
      
      // Verificar si hay evidencias adjuntas para usar el método con base64
      final List<String> attachments = List<String>.from(datos['attachments'] ?? []);
      Map<String, dynamic> resp;
      
      if (attachments.isNotEmpty) {
        // Mostrar diálogo de progreso mientras se suben evidencias
        debugPrint('[Registro] Enviando con ${attachments.length} evidencia(s)');
        showDialog(
          context: context,
          barrierDismissible: false,
          builder: (ctx) => AlertDialog(
            content: Row(
              children: [
                const SizedBox(width: 6),
                const CircularProgressIndicator(),
                const SizedBox(width: 16),
                Expanded(child: Text('Subiendo ${attachments.length} evidencia(s)...')),
              ],
            ),
          ),
        );

        resp = await ApiService.registroRemotoConEvidencias(
          empleadoID: datosNormal['empleadoID'] ?? '',
          nombre: datosNormal['nombre'] ?? '',
          latitud: datosNormal['latitud'] ?? '',
          longitud: datosNormal['longitud'] ?? '',
          cadenaEmpleado: datosNormal['cadenaEmpleado'] ?? '',
          cadenaTiempo: datosNormal['cadenaTiempo'] ?? '',
          cadenaEncriptada: datosNormal['cadenaEncriptada'],
          direccion: datosNormal['direccion'] ?? '',
          empresa: datosNormal['empresa'] ?? 'DRT',
          ubicacionAcc: datosNormal['ubicacionAcc'] ?? 'Remoto',
          tipoHard: datosNormal['tipoHard'] ?? 'Nexus',
          esPendiente: datosNormal['esPendiente'],
          motivoFueraGeocerca: datosNormal['motivoFueraGeocerca'],
          attachmentPaths: attachments,
        );

        // Cerrar diálogo de progreso si sigue abierto
        try {
          if (Navigator.of(context).canPop()) Navigator.of(context).pop();
        } catch (_) {}

        // Si se subieron evidencias, mostrar confirmación modal
        final int evidGuardadas = (resp['evidenciasGuardadas'] is int)
            ? resp['evidenciasGuardadas']
            : int.tryParse((resp['evidenciasGuardadas'] ?? '0').toString()) ?? 0;
        if (evidGuardadas > 0) {
          if (mounted) {
            await showDialog(
              context: context,
              builder: (ctx) => AlertDialog(
                title: const Text('Evidencia cargada'),
                content: Text('Se subieron $evidGuardadas evidencia(s) correctamente.'),
                actions: [
                  TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('OK')),
                ],
              ),
            );
          }
        }

      } else {
        // Método normal GET sin evidencias
        resp = await ApiService.registroRemoto(
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
      }
      debugPrint('Respuesta del webservice: $resp');
      if (!mounted) return;
      if (resp['estatus'] == '1') {
        setState(() { loading = false; });
        if (!mounted) return;
        // Mostrar el mensaje personalizado del backend (entrada/salida)
        final mensaje = resp['mensaje'] ?? 'Registro enviado exitosamente.';
        
        // Guardar en historial local
        try {
          final now = DateTime.now();
          final tipoRegistro = mensaje.toLowerCase().contains('entrada') ? 'Entrada' : 'Salida';
          await RegistrosDbHelper().insertarHistorial(
            tipo: tipoRegistro,
            fecha: '${now.day.toString().padLeft(2, '0')}/${now.month.toString().padLeft(2, '0')}/${now.year}',
            hora: '${now.hour.toString().padLeft(2, '0')}:${now.minute.toString().padLeft(2, '0')}:${now.second.toString().padLeft(2, '0')}',
            ubicacion: datosNormal['direccion'] ?? '',
            dentroGeocerca: motivoFueraGeocerca == null,
            nombreGeocerca: estadoGeocerca?['mensaje'] ?? '',
            latitud: double.tryParse(datosNormal['latitud']?.toString() ?? '0') ?? 0,
            longitud: double.tryParse(datosNormal['longitud']?.toString() ?? '0') ?? 0,
            sincronizado: true,
            estadoValidacion: motivoFueraGeocerca != null ? 'Pendiente Validación' : 'Validado',
            motivo: motivoFueraGeocerca,
          );
          debugPrint('Registro guardado en historial local');
        } catch (e) {
          debugPrint('Error guardando en historial: $e');
        }
        
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Row(
              children: [
                const Icon(Icons.check_circle, color: Colors.white, size: 20),
                const SizedBox(width: 12),
                Expanded(child: Text(mensaje)),
              ],
            ),
            backgroundColor: Colors.green,
            behavior: SnackBarBehavior.floating,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          ),
        );
        // Mostrar info de evidencias si aplica
        final int evidGuardadas = (resp['evidenciasGuardadas'] is int) ? resp['evidenciasGuardadas'] : int.tryParse((resp['evidenciasGuardadas'] ?? '0').toString()) ?? 0;
        if (evidGuardadas > 0) {
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Row(
                  children: [
                    const Icon(Icons.photo, color: Colors.white, size: 20),
                    const SizedBox(width: 12),
                    Expanded(child: Text('Se subieron $evidGuardadas evidencia(s) con el registro.')),
                  ],
                ),
                backgroundColor: Colors.blueGrey,
                behavior: SnackBarBehavior.floating,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
            );
          }
        }
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
          // Incluir attachments si existen
          if (datos['attachments'] != null) {
            datosPendiente['attachments'] = List<String>.from(datos['attachments']);
          }
          await _guardarRegistroPendiente(datosPendiente);
          
          if (!mounted) return;
          setState(() {
            loading = false;
            error = null;
          });
          
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Row(
                children: [
                  const Icon(Icons.access_time, color: Colors.white, size: 20),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text('Código QR expirado. Registro guardado y se reenviará automáticamente.'),
                  ),
                ],
              ),
              backgroundColor: Colors.orange,
              behavior: SnackBarBehavior.floating,
              duration: const Duration(seconds: 4),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
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
          content: Row(
            children: [
              const Icon(Icons.cloud_off, color: Colors.white, size: 20),
              const SizedBox(width: 12),
              Expanded(
                child: Text('No se pudo conectar. Registro guardado localmente (#${registrosPendientes.length} pendiente${registrosPendientes.length > 1 ? 's' : ''}). Se enviará cuando vuelva la conexión.'),
              ),
            ],
          ),
          backgroundColor: Colors.orange,
          behavior: SnackBarBehavior.floating,
          duration: const Duration(seconds: 4),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
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

  // Mostrar diálogo con código QR para escanear en tablet
  void _mostrarDialogoQR(BuildContext context, ThemeData theme, Map<String, dynamic>? user) {
    if (user == null || user['cadena'] == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Row(
            children: [
              Icon(Icons.warning_amber_rounded, color: Colors.white),
              const SizedBox(width: 12),
              const Text('No se encontró la cadena del empleado'),
            ],
          ),
          backgroundColor: Colors.orange,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        ),
      );
      return;
    }

    final String cadenaQR = user['cadena'].toString();
    final String nombreEmpleado = user['nombre']?.toString() ?? 'Empleado';

    showDialog(
      context: context,
      builder: (ctx) => Dialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
        child: Container(
          padding: const EdgeInsets.all(24),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(24),
            gradient: const LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                Color(0xFFf8fafc),
                Color(0xFFe0eafc),
              ],
            ),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Header
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Código QR',
                          style: theme.textTheme.headlineSmall?.copyWith(
                            fontWeight: FontWeight.bold,
                            color: theme.colorScheme.primary,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          nombreEmpleado,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: Colors.grey[600],
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ],
                    ),
                  ),
                  IconButton(
                    onPressed: () => Navigator.pop(ctx),
                    icon: Icon(Icons.close_rounded, color: Colors.grey[500]),
                  ),
                ],
              ),
              const SizedBox(height: 20),
              // QR Code con sombra y borde
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(20),
                  boxShadow: [
                    BoxShadow(
                      color: theme.colorScheme.primary.withOpacity(0.15),
                      blurRadius: 20,
                      offset: const Offset(0, 8),
                    ),
                  ],
                ),
                child: QrImageView(
                  data: cadenaQR,
                  version: QrVersions.auto,
                  size: MediaQuery.of(context).size.width * 0.5,
                  backgroundColor: Colors.white,
                  eyeStyle: QrEyeStyle(
                    eyeShape: QrEyeShape.square,
                    color: theme.colorScheme.primary,
                  ),
                  dataModuleStyle: QrDataModuleStyle(
                    dataModuleShape: QrDataModuleShape.square,
                    color: theme.colorScheme.onSurface,
                  ),
                ),
              ),
              const SizedBox(height: 20),
              // Instrucciones
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: theme.colorScheme.primary.withOpacity(0.08),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Row(
                  children: [
                    Icon(
                      Icons.info_outline_rounded,
                      color: theme.colorScheme.primary,
                      size: 20,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'Escanea este código en la tablet para registrar tu asistencia',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.primary,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // Widget de avatar por defecto cuando no hay foto
  Widget _buildDefaultAvatar(ThemeData theme) {
    return Container(
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        boxShadow: [
          BoxShadow(
            color: theme.colorScheme.primary.withOpacity(0.18),
            blurRadius: 18,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: CircleAvatar(
        radius: MediaQuery.of(context).size.width * 0.12,
        backgroundColor: theme.colorScheme.primary,
        child: Icon(Icons.person, size: MediaQuery.of(context).size.width * 0.12, color: theme.colorScheme.onPrimary),
      ),
    );
  }

  // Widget de foto del empleado con cache para evitar parpadeo
  Widget _buildEmployeePhoto(ThemeData theme, Map<String, dynamic>? user) {
    if (user != null && user['fotografia'] != null && user['fotografia'].toString().isNotEmpty) {
      try {
        // Quitar prefijo data:image/xxx;base64, si existe
        String fotoBase64 = user['fotografia'].toString();
        if (fotoBase64.contains(',')) {
          fotoBase64 = fotoBase64.split(',').last;
        }
        
        // Solo decodificar si la foto cambió
        if (_lastFotoBase64 != fotoBase64 || _cachedFotoBytes == null) {
          _cachedFotoBytes = base64Decode(fotoBase64);
          _lastFotoBase64 = fotoBase64;
        }
        
        return Container(
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            boxShadow: [
              BoxShadow(
                color: theme.colorScheme.primary.withOpacity(0.18),
                blurRadius: 18,
                offset: const Offset(0, 8),
              ),
            ],
          ),
          child: CircleAvatar(
            radius: MediaQuery.of(context).size.width * 0.12,
            backgroundColor: theme.colorScheme.primary,
            child: ClipOval(
              child: Image.memory(
                _cachedFotoBytes!,
                width: MediaQuery.of(context).size.width * 0.24,
                height: MediaQuery.of(context).size.width * 0.24,
                fit: BoxFit.cover,
                gaplessPlayback: true, // Evita parpadeo al reconstruir
                errorBuilder: (context, error, stackTrace) {
                  return Icon(
                    Icons.person,
                    size: MediaQuery.of(context).size.width * 0.12,
                    color: theme.colorScheme.onPrimary,
                  );
                },
              ),
            ),
          ),
        );
      } catch (e) {
        debugPrint('ERROR decodificando base64: $e');
        return _buildDefaultAvatar(theme);
      }
    } else {
      return _buildDefaultAvatar(theme);
    }
  }

  @override
  Widget build(BuildContext context) {
    final userProvider = Provider.of<UserProvider>(context, listen: false);
    final user = userProvider.userData;
    final theme = Theme.of(context);

    return Container(
      // Fondo degradado minimalista (este widget está dentro de `HomeScreen` que provee el Scaffold)
      // Evitamos anidar un Scaffold para que el `bottomNavigationBar` del `HomeScreen` no solape la UI.
      child: Container(
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
        child: Stack(
          children: [
            // Contenido principal centrado
            SafeArea(
              child: Center(
                child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 20.0),
                    child: LayoutBuilder(
                          builder: (context, constraints) {
                            // Detectamos layouts compactos para ajustar tamaños de botones
                            final isCompact = constraints.maxHeight < 640;
                            // Estimación de la altura del bottom navigation bar de la app
                            const double navBarHeight = 64.0;
                            final double buttonHeight = isCompact ? 44.0 : 50.0;
                            // Usar viewPadding para capturar el inset real del sistema
                            final bottomInset = MediaQuery.of(context).viewPadding.bottom;

                            // Usamos siempre el patrón que centra el contenido automáticamente.
                            // Si cabe en la pantalla, queda centrado; si no cabe, se hace scroll.

                            final content = Column(
                              mainAxisSize: MainAxisSize.min,
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                        // Espacio extra para centrar mejor (reducido)
                        const SizedBox(height: 8),
                        // Foto del empleado (con cache para evitar parpadeo)
                        _buildEmployeePhoto(theme, user),
                        const SizedBox(height: 20),
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
                        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
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
                  const SizedBox(height: 12),
                  // Quitamos el indicador de carga aquí - ahora es overlay
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
                  // Widget de estado de zona de trabajo - actualización silenciosa
                  if (position != null)
                    Container(
                      margin: const EdgeInsets.only(bottom: 16),
                      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.9),
                        borderRadius: BorderRadius.circular(14),
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
                          // Solo mostrar loading la primera vez
                          if (estadoGeocerca == null)
                            SpinKitChasingDots(
                              color: theme.colorScheme.primary,
                              size: 26,
                            )
                          else
                            Icon(
                              GeocercaService.getIconForStatus(estadoGeocerca?['validacion'] ?? 'Error'),
                              size: 26,
                              color: Color(GeocercaService.getColorForStatus(estadoGeocerca?['validacion'] ?? 'Error')),
                            ),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Builder(
                              builder: (_) {
                                String zonaText;
                                if (estadoGeocerca == null) {
                                  zonaText = 'Obteniendo ubicación...';
                                } else {
                                  final v = (estadoGeocerca?['validacion'] ?? '').toString();
                                  if (v == 'Fuera') {
                                    zonaText = 'Fuera de zona';
                                  } else if (v == 'Dentro') {
                                    zonaText = 'En zona';
                                  } else if (v == 'Sin_Conexion') {
                                    zonaText = 'Sin conexión para verificar zona';
                                  } else {
                                    zonaText = 'Estado: ' + (estadoGeocerca?['mensaje'] ?? 'Desconocido');
                                  }
                                }

                                return Text(
                                  zonaText,
                                  style: theme.textTheme.titleMedium?.copyWith(
                                    fontWeight: FontWeight.w600,
                                    fontSize: 16,
                                    color: estadoGeocerca == null
                                        ? theme.colorScheme.primary
                                        : Color(GeocercaService.getColorForStatus(estadoGeocerca?['validacion'] ?? 'Error')),
                                  ),
                                );
                              },
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
                  // Agrupar los botones inferiores dentro de SafeArea para evitar solapamiento
                  SafeArea(
                    bottom: true,
                    top: false,
                    left: false,
                    right: false,
                    child: Padding(
                      padding: EdgeInsets.only(bottom: bottomInset + navBarHeight + 8),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                        Row(
                          children: [
                            Expanded(
                              child: FilledButton.icon(
                                icon: const Icon(Icons.send),
                                label: const Text('Enviar registro'),
                                onPressed: (position != null && !loading && ubicacionReciente)
                                    ? () async {
                                        // ===== VERIFICACIÓN DE SEGURIDAD INMEDIATA =====
                                        debugPrint('🔒 Verificando seguridad al hacer clic en "Enviar registro"...');
                                        
                                        final securityCheck = await SecurityService.performSecurityCheck(position!);
                                        final bool securityPassed = securityCheck['passed'];
                                        final String severity = securityCheck['severity'];
                                        
                                        // Nivel 2: BLOQUEAR si hay VPN o GPS falso (crítico/alto)
                                        if (!securityPassed && (severity == 'critical' || severity == 'high')) {
                                          final bool isLocationMocked = securityCheck['checks']['locationMocked'] ?? false;
                                          final bool isVpnActive = securityCheck['checks']['vpnActive'] ?? false;
                                          
                                          if (isLocationMocked || isVpnActive) {
                                            await _vibrar();
                                            
                                            if (!mounted) return;
                                            
                                            // Mostrar diálogo de bloqueo INMEDIATO
                                            await showDialog(
                                              context: context,
                                              barrierDismissible: false,
                                              builder: (ctx) => AlertDialog(
                                                title: Row(
                                                  children: [
                                                    Icon(Icons.security, color: Theme.of(ctx).colorScheme.error, size: 28),
                                                    const SizedBox(width: 12),
                                                    const Expanded(
                                                      child: Text(
                                                        'Verificación de Seguridad',
                                                        style: TextStyle(fontSize: 18),
                                                      ),
                                                    ),
                                                  ],
                                                ),
                                                content: Column(
                                                  mainAxisSize: MainAxisSize.min,
                                                  crossAxisAlignment: CrossAxisAlignment.start,
                                                  children: [
                                                    const Text(
                                                      'No se puede registrar por las siguientes razones:',
                                                      style: TextStyle(fontWeight: FontWeight.w500),
                                                    ),
                                                    const SizedBox(height: 16),
                                                    if (isLocationMocked)
                                                      Row(
                                                        children: [
                                                          Icon(Icons.location_off, 
                                                            color: Theme.of(ctx).colorScheme.error,
                                                            size: 20,
                                                          ),
                                                          const SizedBox(width: 8),
                                                          const Expanded(
                                                            child: Text('GPS falso detectado'),
                                                          ),
                                                        ],
                                                      ),
                                                    if (isLocationMocked && isVpnActive)
                                                      const SizedBox(height: 12),
                                                    if (isVpnActive)
                                                      Row(
                                                        children: [
                                                          Icon(Icons.vpn_key_off, 
                                                            color: Theme.of(ctx).colorScheme.error,
                                                            size: 20,
                                                          ),
                                                          const SizedBox(width: 8),
                                                          const Expanded(
                                                            child: Text('VPN activa detectada'),
                                                          ),
                                                        ],
                                                      ),
                                                    const SizedBox(height: 16),
                                                    Container(
                                                      padding: const EdgeInsets.all(12),
                                                      decoration: BoxDecoration(
                                                        color: Theme.of(ctx).colorScheme.errorContainer,
                                                        borderRadius: BorderRadius.circular(8),
                                                      ),
                                                      child: Row(
                                                        children: [
                                                          Icon(
                                                            Icons.info_outline,
                                                            color: Theme.of(ctx).colorScheme.onErrorContainer,
                                                            size: 20,
                                                          ),
                                                          const SizedBox(width: 8),
                                                          Expanded(
                                                            child: Text(
                                                              'Por seguridad, desactiva estas funciones para registrar tu asistencia.',
                                                              style: TextStyle(
                                                                fontSize: 13,
                                                                color: Theme.of(ctx).colorScheme.onErrorContainer,
                                                              ),
                                                            ),
                                                          ),
                                                        ],
                                                      ),
                                                    ),
                                                  ],
                                                ),
                                                actions: [
                                                  TextButton.icon(
                                                    icon: const Icon(Icons.check),
                                                    label: const Text('Entendido'),
                                                    onPressed: () => Navigator.of(ctx).pop(),
                                                  ),
                                                ],
                                              ),
                                            );
                                            
                                            debugPrint('❌ Registro bloqueado por seguridad');
                                            return; // DETENER TODO AQUÍ
                                          }
                                        }
                                        
                                        debugPrint('✅ Verificación de seguridad pasada, continuando con registro...');
                                        
                                        // ===== CONTINUAR CON EL PROCESO NORMAL =====
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
                                  minimumSize: Size.fromHeight(buttonHeight),
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                ),
                              ),
                            ),
                          ],
                        ),
                        // Botón Reintentar GPS (aparece cuando falla GPS y no hemos llegado a 5 intentos)
                        if (position == null && error != null && !loading && _intentosGps < _maxIntentosParaSinGps)
                          Padding(
                            padding: const EdgeInsets.only(top: 12),
                            child: Row(
                              children: [
                                Expanded(
                                  child: FilledButton.icon(
                                    icon: _reintentandoGps 
                                        ? SizedBox(
                                            width: 18,
                                            height: 18,
                                            child: CircularProgressIndicator(
                                              strokeWidth: 2,
                                              color: Colors.white,
                                            ),
                                          )
                                        : const Icon(Icons.gps_fixed),
                                    label: Text(_reintentandoGps 
                                        ? 'Buscando GPS...' 
                                        : 'Reintentar GPS (${_intentosGps}/$_maxIntentosParaSinGps)'),
                                    onPressed: _reintentandoGps ? null : () async {
                                      await _vibrar();
                                      setState(() {
                                        _reintentandoGps = true;
                                        error = null;
                                      });
                                      // Mostrar mensaje de sugerencia
                                      ScaffoldMessenger.of(context).showSnackBar(
                                        SnackBar(
                                          content: Row(
                                            children: [
                                              const Icon(Icons.lightbulb_outline, color: Colors.white),
                                              const SizedBox(width: 12),
                                              const Expanded(
                                                child: Text(
                                                  'Tip: Sal al exterior o acércate a una ventana para mejor señal GPS.',
                                                ),
                                              ),
                                            ],
                                          ),
                                          backgroundColor: Colors.blue.shade700,
                                          behavior: SnackBarBehavior.floating,
                                          duration: const Duration(seconds: 3),
                                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                        ),
                                      );
                                      await _obtenerGeolocalizacion();
                                    },
                                    style: FilledButton.styleFrom(
                                      minimumSize: Size.fromHeight(buttonHeight),
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                      backgroundColor: Colors.blue.shade600,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        // Botón alternativo para registrar sin GPS (solo después de 5 intentos)
                        if (position == null && error != null && !loading && _intentosGps >= _maxIntentosParaSinGps)
                          Padding(
                            padding: const EdgeInsets.only(top: 12),
                            child: Row(
                              children: [
                                Expanded(
                                  child: OutlinedButton.icon(
                                    icon: const Icon(Icons.cloud_off),
                                    label: const Text('Registrar sin GPS (offline)'),
                                    onPressed: () async {
                                      await _vibrar();
                                      final user = userProvider.userData;
                                      if (user == null) return;
                                      
                                      // Mostrar confirmación
                                      final confirmar = await showDialog<bool>(
                                        context: context,
                                        builder: (ctx) => AlertDialog(
                                          title: Row(
                                            children: [
                                              Icon(Icons.gps_off, color: Colors.orange),
                                              const SizedBox(width: 12),
                                              const Expanded(child: Text('Registrar sin ubicación')),
                                            ],
                                          ),
                                          content: const Text(
                                            'No se pudo obtener tu ubicación GPS. '
                                            'El registro se guardará localmente y se enviará cuando vuelva la conexión y el GPS.\n\n'
                                            '¿Deseas continuar?',
                                          ),
                                          actions: [
                                            TextButton(
                                              onPressed: () => Navigator.pop(ctx, false),
                                              child: const Text('Cancelar'),
                                            ),
                                            FilledButton(
                                              onPressed: () => Navigator.pop(ctx, true),
                                              child: const Text('Sí, registrar'),
                                            ),
                                          ],
                                        ),
                                      );
                                      
                                      if (confirmar != true) return;
                                      
                                      // Generar registro sin ubicación
                                      final cadenaEncriptada = user['cadena'] ?? '';
                                      final now = DateTime.now();
                                      final dia = now.day.toString().padLeft(2, '0');
                                      final hora = now.hour.toString().padLeft(2, '0');
                                      final minuto = now.minute.toString().padLeft(2, '0');
                                      final segundo = now.second.toString().padLeft(2, '0');
                                      // Sin GPS: lat/lng = 0,0
                                      final cadenaTiempo = '$dia,$hora,$minuto,$segundo,0,0';
                                      
                                      final datos = {
                                        'empleadoID': user['empleadoID'].toString(),
                                        'nombre': user['nombre'] ?? '',
                                        'latitud': '0',
                                        'longitud': '0',
                                        'cadenaEmpleado': '',
                                        'cadenaTiempo': cadenaTiempo,
                                        'cadenaEncriptada': cadenaEncriptada,
                                        'direccion': 'Sin GPS disponible',
                                        'empresa': 'DRT',
                                        'ubicacionAcc': 'Remoto',
                                        'tipoHard': 'Nexus',
                                        'esPendiente': '1',
                                      };
                                      
                                      // Guardar directamente como pendiente
                                      await _guardarRegistroPendiente(datos);
                                      await _showRegistroPendienteNotification();
                                      
                                      if (!mounted) return;
                                      ScaffoldMessenger.of(context).showSnackBar(
                                        SnackBar(
                                          content: Row(
                                            children: [
                                              const Icon(Icons.save, color: Colors.white),
                                              const SizedBox(width: 12),
                                              Expanded(
                                                child: Text(
                                                  'Registro guardado sin GPS (#${registrosPendientes.length} pendiente${registrosPendientes.length > 1 ? 's' : ''}). Se enviará cuando vuelva la conexión.',
                                                ),
                                              ),
                                            ],
                                          ),
                                          backgroundColor: Colors.orange.shade700,
                                          behavior: SnackBarBehavior.floating,
                                          duration: const Duration(seconds: 4),
                                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                        ),
                                      );
                                    },
                                    style: OutlinedButton.styleFrom(
                                      minimumSize: Size.fromHeight(buttonHeight),
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                      foregroundColor: Colors.orange.shade700,
                                      side: BorderSide(color: Colors.orange.shade700),
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        const SizedBox(height: 16),
                        Row(
                          children: [
                            Expanded(
                              child: OutlinedButton.icon(
                                icon: const Icon(Icons.qr_code_2_rounded),
                                label: const Text('Generar QR para tablet'),
                                onPressed: () async {
                                  await _vibrar();
                                  _mostrarDialogoQR(context, theme, user);
                                },
                                style: OutlinedButton.styleFrom(
                                  minimumSize: Size.fromHeight(buttonHeight),
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
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
                      ],
                    ), // close inner Column (buttons)
                  ), // close Padding
                ), // close SafeArea
              ], // end content children
            ); // close content Column

            // Fallback único: centrado automático con scroll cuando sea necesario.
            // Usar viewPadding para capturar el inset real del sistema (nav bar / home indicator)
            return SingleChildScrollView(
              child: ConstrainedBox(
                constraints: BoxConstraints(minHeight: constraints.maxHeight * 0.92),
                child: Padding(
                  padding: EdgeInsets.only(bottom: bottomInset + 8),
                  child: Center(child: content),
                ),
              ),
            );
                    }
                  ),
          ),
        ),
            ),
            // Overlay de carga con SpinKitChasingDots
            if (loading)
                Container(
                  color: Colors.black.withOpacity(0.4),
                  child: Center(
                    child: SpinKitChasingDots(
                      color: Theme.of(context).colorScheme.primary,
                      size: 60,
                    ),
                  ),
                ),
          ],
        ),
      ),
    );
  }
}

// IMPORTANTE: Cuando se reenvía un registro pendiente, NO modificar ni recalcular
// los valores de cadenaTiempo, latitud, longitud, etc. Se deben usar exactamente
// los valores guardados en el registro pendiente (momento original del evento offline).
// Esto asegura que el backend registre la hora y ubicación reales del evento.
