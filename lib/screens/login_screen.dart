import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'dart:math';
import 'package:app_settings/app_settings.dart';
import '../providers/user_provider.dart';
import 'dart:ui';
import 'registro_remoto_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final TextEditingController emailController = TextEditingController();
  String? imei;
  Position? position;
  bool loading = false;
  String? error;
  bool waitingLocation = true;
  bool waitingImei = true;
  Stream<ServiceStatus>? _serviceStatusStream;
  StreamSubscription<ServiceStatus>? _serviceStatusSub;

  @override
  void initState() {
    super.initState();
    _initDeviceInfo();
    _obtenerGeolocalizacion();
    emailController.addListener(() {
      setState(() {});
    });
    _serviceStatusStream = Geolocator.getServiceStatusStream();
    _serviceStatusSub = _serviceStatusStream!.listen((status) {
      if (status == ServiceStatus.disabled) {
        setState(() {
          error = 'La geolocalización se ha desactivado. Actívala para continuar.';
          waitingLocation = false;
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
                    AppSettings.openAppSettings();
                  },
                  child: const Text('Abrir ajustes'),
                ),
                TextButton(
                  onPressed: () => Navigator.of(ctx).pop(),
                  child: const Text('Cancelar'),
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
  }

  Future<void> _initDeviceInfo() async {
    if (!mounted) return;
    setState(() { waitingImei = true; });
    final prefs = await SharedPreferences.getInstance();
    String? deviceId = prefs.getString('nexus_device_id');
    if (deviceId == null || deviceId.isEmpty) {
      deviceId = 'nexus_${List.generate(16, (index) => Random().nextInt(10)).join()}';
      await prefs.setString('nexus_device_id', deviceId);
    }
    debugPrint('ID persistente generado/obtenido: $deviceId');
    if (!mounted) return;
    setState(() {
      imei = deviceId;
      waitingImei = false;
    });
  }

  Future<void> _showPermissionRationale() async {
    await showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Permiso de ubicación requerido'),
        content: const Text('Para usar la app necesitas permitir el acceso a la ubicación. Esto es necesario para validar tu acceso y registrar tu actividad.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Continuar'),
          ),
        ],
      ),
    );
  }

  Future<void> _obtenerGeolocalizacion() async {
    if (!mounted) return;
    setState(() { waitingLocation = true; error = null; });
    bool serviceEnabled;
    LocationPermission permission;

    try {
      serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        if (!mounted) return;
        setState(() {
          error = 'La geolocalización está desactivada. Actívala para continuar.';
          waitingLocation = false;
        });
        // Botón para abrir ajustes de ubicación
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
                    AppSettings.openAppSettings();
                  },
                  child: const Text('Abrir ajustes'),
                ),
                TextButton(
                  onPressed: () => Navigator.of(ctx).pop(),
                  child: const Text('Cancelar'),
                ),
              ],
            ),
          );
        }
        return;
      }

      permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
        await _showPermissionRationale();
        permission = await Geolocator.requestPermission();
        if (permission == LocationPermission.denied) {
          if (!mounted) return;
          setState(() {
            error = 'Permiso de ubicación denegado. Debes permitirlo para continuar.';
            waitingLocation = false;
          });
          return;
        }
        if (permission == LocationPermission.deniedForever) {
          if (!mounted) return;
          setState(() {
            error = 'Permiso de ubicación denegado permanentemente. Ve a ajustes para activarlo.';
            waitingLocation = false;
          });
          // Mostrar diálogo para abrir ajustes de la app
          if (mounted) {
            showDialog(
              context: context,
              builder: (ctx) => AlertDialog(
                title: const Text('Permiso requerido'),
                content: const Text('Debes permitir el acceso a la ubicación desde los ajustes del dispositivo para continuar.'),
                actions: [
                  TextButton(
                    onPressed: () {
                      Navigator.of(ctx).pop();
                      AppSettings.openAppSettings();
                    },
                    child: const Text('Abrir ajustes'),
                  ),
                  TextButton(
                    onPressed: () => Navigator.of(ctx).pop(),
                    child: const Text('Cancelar'),
                  ),
                ],
              ),
            );
          }
          return;
        }
      }

      final pos = await Geolocator.getCurrentPosition(desiredAccuracy: LocationAccuracy.high);
      if (!mounted) return;
      setState(() {
        position = pos;
        waitingLocation = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        error = 'No se pudo obtener la ubicación. Verifica los permisos de ubicación en tu dispositivo.';
        waitingLocation = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final userProvider = Provider.of<UserProvider>(context);
    final user = userProvider.userData;

    if (userProvider.isLoggedIn && user != null) {
      Future.microtask(() {
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (context) => const RegistroRemotoScreen()),
        );
      });
      return const SizedBox.shrink();
    }

    final ready = !waitingLocation && !waitingImei && error == null;

    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              Color(0xFFe0eafc),
              Color(0xFFcfdef3),
              Color(0xFFf9fafc),
            ],
          ),
        ),
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const SizedBox(height: 32),
                // Icono de login arriba del título
                Center(
                  child: CircleAvatar(
                    radius: 44,
                    backgroundColor: Colors.white,
                    child: Icon(
                      Icons.login, // Icono de acceso/login
                      size: 64,
                      color: Colors.blueAccent,
                    ),
                  ),
                ),
                const SizedBox(height: 18),
                Text(
                  'Nexus MK',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold, color: Colors.blue.shade700, letterSpacing: 1.2),
                ),
                const SizedBox(height: 8),
                Text(
                  'Accede a tu cuenta',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 15, color: Colors.grey.shade700, fontWeight: FontWeight.w400),
                ),
                const SizedBox(height: 32),
                // Card opaco, limpio, sin glassmorphism
                Card(
                  elevation: 4,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                  color: Colors.white,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 28, horizontal: 20),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        TextField(
                          controller: emailController,
                          keyboardType: TextInputType.emailAddress,
                          decoration: InputDecoration(
                            labelText: 'Correo o Teléfono',
                            prefixIcon: const Icon(Icons.person_outline),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
                            filled: true,
                            fillColor: Colors.grey[100],
                          ),
                        ),
                        const SizedBox(height: 20),
                        if (error != null)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 8),
                            child: Column(
                              children: [
                                Text(error!, style: const TextStyle(color: Colors.red)),
                                const SizedBox(height: 8),
                                ElevatedButton.icon(
                                  icon: const Icon(Icons.refresh),
                                  label: const Text('Reintentar'),
                                  onPressed: () {
                                    _obtenerGeolocalizacion();
                                    _initDeviceInfo();
                                  },
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: Colors.blue.shade700,
                                    foregroundColor: Colors.white,
                                    minimumSize: const Size(0, 40),
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        if (userProvider.errorMessage.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 8),
                            child: Text(
                              userProvider.errorMessage,
                              style: const TextStyle(color: Colors.red),
                            ),
                          ),
                        if (imei == null || imei == 'unknown_device')
                          Padding(
                            padding: const EdgeInsets.only(bottom: 8),
                            child: Text(
                              'No se pudo obtener el identificador del dispositivo. Se usará un valor genérico.',
                              style: TextStyle(color: Colors.orange.shade700, fontSize: 13),
                              textAlign: TextAlign.center,
                            ),
                          ),
                        if (position == null && error == null && !waitingLocation)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 8),
                            child: Text(
                              'No se pudo obtener la ubicación. Verifica los permisos de ubicación en tu dispositivo.',
                              style: TextStyle(color: Colors.red.shade700, fontSize: 13),
                              textAlign: TextAlign.center,
                            ),
                          ),
                        const SizedBox(height: 8),
                        ElevatedButton(
                          onPressed: (emailController.text.isNotEmpty && ready && !loading)
                              ? () async {
                                  FocusScope.of(context).unfocus();
                                  setState(() { loading = true; });
                                  await userProvider.login(
                                    emailController.text,
                                    imei!,
                                    context,
                                  );
                                  setState(() { loading = false; });
                                  if (userProvider.isLoggedIn) {
                                    Navigator.pushReplacement(
                                      context,
                                      MaterialPageRoute(builder: (context) => const RegistroRemotoScreen()),
                                    );
                                  }
                                }
                              : null,
                          style: ElevatedButton.styleFrom(
                            backgroundColor: Colors.blue.shade700,
                            foregroundColor: Colors.white,
                            minimumSize: const Size.fromHeight(50),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                            textStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                            elevation: 2,
                          ),
                          child: loading
                              ? const SizedBox(height: 24, width: 24, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                              : const Text('Ingresar'),
                        ),
                        const SizedBox(height: 18),
                        if (ready)
                          Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.verified, color: Colors.green.shade400, size: 18),
                              const SizedBox(width: 6),
                              Text('Listo para ingresar', style: TextStyle(color: Colors.green.shade700)),
                            ],
                          ),
                        if (waitingLocation || waitingImei)
                          const Padding(
                            padding: EdgeInsets.only(top: 16),
                            child: Center(child: CircularProgressIndicator()),
                          ),
                        const SizedBox(height: 8),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 24),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  void dispose() {
    _serviceStatusSub?.cancel();
    emailController.dispose();
    super.dispose();
  }
}
