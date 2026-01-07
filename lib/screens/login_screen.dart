import 'dart:async';
import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:app_settings/app_settings.dart';
import '../providers/user_provider.dart';
import 'home_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen>
    with TickerProviderStateMixin {
  final TextEditingController emailController = TextEditingController();
  String? imei;
  Position? position;
  bool loading = false;
  String? error;
  bool waitingLocation = true;
  bool waitingImei = true;
  Stream<ServiceStatus>? _serviceStatusStream;
  StreamSubscription<ServiceStatus>? _serviceStatusSub;

  // Animaciones
  late AnimationController _cardController;
  late Animation<double> _cardSlide;
  late Animation<double> _cardOpacity;

  // Focus
  final FocusNode _emailFocus = FocusNode();
  bool _isEmailFocused = false;

  @override
  void initState() {
    super.initState();
    _initAnimations();
    _initDeviceInfo();
    _obtenerGeolocalizacion();
    
    emailController.addListener(() {
      setState(() {});
    });
    
    _emailFocus.addListener(() {
      setState(() {
        _isEmailFocused = _emailFocus.hasFocus;
      });
    });
    
    _serviceStatusStream = Geolocator.getServiceStatusStream();
    _serviceStatusSub = _serviceStatusStream!.listen((status) {
      if (status == ServiceStatus.disabled) {
        setState(() {
          error = 'La geolocalización se ha desactivado. Actívala para continuar.';
          waitingLocation = false;
        });
        if (mounted) {
          _showLocationDialog();
        }
      } else if (status == ServiceStatus.enabled && error != null) {
        _obtenerGeolocalizacion();
      }
    });
  }

  void _initAnimations() {
    // Animación de entrada del formulario
    _cardController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 800),
    );
    
    _cardSlide = Tween<double>(begin: 50, end: 0).animate(
      CurvedAnimation(parent: _cardController, curve: Curves.easeOutCubic),
    );
    
    _cardOpacity = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _cardController, curve: Curves.easeIn),
    );

    // Iniciar animación
    Future.delayed(const Duration(milliseconds: 200), () {
      if (mounted) _cardController.forward();
    });
  }

  Future<void> _initDeviceInfo() async {
    if (!mounted) return;
    setState(() { waitingImei = true; });
    final prefs = await SharedPreferences.getInstance();
    String? deviceId = prefs.getString('nexus_device_id');
    if (deviceId == null || deviceId.isEmpty) {
      deviceId = 'nexus_${List.generate(16, (index) => math.Random().nextInt(10)).join()}';
      await prefs.setString('nexus_device_id', deviceId);
    }
    debugPrint('ID persistente generado/obtenido: $deviceId');
    if (!mounted) return;
    setState(() {
      imei = deviceId;
      waitingImei = false;
    });
  }

  void _showLocationDialog() {
    showDialog(
      context: context,
      builder: (ctx) {
        final mq = MediaQuery.of(ctx);
        return MediaQuery(
          data: mq.copyWith(textScaleFactor: mq.textScaleFactor.clamp(1.0, 1.15)),
          child: AlertDialog(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
            title: Row(
              children: [
                Icon(Icons.location_off, color: Colors.orange.shade700),
                const SizedBox(width: 10),
                const Expanded(child: Text('Ubicación desactivada')),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text('Debes activar la ubicación en tu dispositivo para continuar.'),
                  const SizedBox(height: 18),
                  SizedBox(
                    width: double.infinity,
                    child: TextButton(
                      onPressed: () => Navigator.of(ctx).pop(),
                      style: TextButton.styleFrom(
                        foregroundColor: Colors.grey.shade600,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      child: const Text('Cancelar'),
                    ),
                  ),
                  const SizedBox(height: 8),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: () {
                        Navigator.of(ctx).pop();
                        AppSettings.openAppSettings();
                      },
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF1565C0),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      child: const Text('Abrir ajustes'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Future<void> _showPermissionRationale() async {
    await showDialog(
      context: context,
      builder: (ctx) {
        final mq = MediaQuery.of(ctx);
        return MediaQuery(
          data: mq.copyWith(textScaleFactor: mq.textScaleFactor.clamp(1.0, 1.15)),
          child: AlertDialog(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
            title: Row(
              children: [
                const Icon(Icons.location_on, color: Color(0xFF1565C0)),
                const SizedBox(width: 10),
                const Expanded(child: Text('Permiso requerido')),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text('Para usar la app necesitas permitir el acceso a la ubicación. Esto es necesario para validar tu acceso y registrar tu actividad.'),
                  const SizedBox(height: 18),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: () => Navigator.of(ctx).pop(),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF1565C0),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      child: const Text('Continuar'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
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
        if (mounted) _showLocationDialog();
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
          if (mounted) {
            showDialog(
              context: context,
              builder: (ctx) {
                final mq = MediaQuery.of(ctx);
                return MediaQuery(
                  data: mq.copyWith(textScaleFactor: mq.textScaleFactor.clamp(1.0, 1.15)),
                  child: AlertDialog(
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                    title: const Text('Permiso requerido'),
                    content: SingleChildScrollView(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          const Text('Debes permitir el acceso a la ubicación desde los ajustes del dispositivo para continuar.'),
                          const SizedBox(height: 18),
                          SizedBox(
                            width: double.infinity,
                            child: TextButton(
                              onPressed: () => Navigator.of(ctx).pop(),
                              style: TextButton.styleFrom(
                                foregroundColor: Colors.grey.shade600,
                                padding: const EdgeInsets.symmetric(vertical: 14),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              ),
                              child: const Text('Cancelar'),
                            ),
                          ),
                          const SizedBox(height: 8),
                          SizedBox(
                            width: double.infinity,
                            child: ElevatedButton(
                              onPressed: () {
                                Navigator.of(ctx).pop();
                                AppSettings.openAppSettings();
                              },
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFF1565C0),
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(vertical: 14),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              ),
                              child: const Text('Abrir ajustes'),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
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
          MaterialPageRoute(builder: (context) => const HomeScreen()),
        );
      });
      return const SizedBox.shrink();
    }

    final ready = !waitingLocation && !waitingImei && error == null;
    final size = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: const Color(0xFFF5F9FF),
      body: Stack(
        children: [
          // Círculos decorativos de fondo
          _buildBackgroundCircles(size),
          
          // Contenido principal
          SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 24),
                child: AnimatedBuilder(
                  animation: _cardController,
                  builder: (context, child) {
                    return Transform.translate(
                      offset: Offset(0, _cardSlide.value),
                      child: Opacity(
                        opacity: _cardOpacity.value,
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const SizedBox(height: 40),
                            
                            // Título principal
                            const Text(
                              'Inicia sesión',
                              style: TextStyle(
                                fontSize: 32,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF1A56DB),
                                letterSpacing: 0.5,
                              ),
                            ),
                            
                            const SizedBox(height: 12),
                            
                            // Subtítulo
                            Text(
                              '¿Bienvenido de nuevo!,\nNexus',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.w600,
                                color: Colors.grey[800],
                                height: 1.3,
                              ),
                            ),
                            
                            const SizedBox(height: 48),
                            
                            // Campo de correo/teléfono
                            _buildEmailField(),
                            
                            const SizedBox(height: 24),
                            
                            // Mensajes de error/warning
                            if (error != null)
                              _buildErrorMessage(error!, isRetryable: true),
                            
                            if (userProvider.errorMessage.isNotEmpty)
                              _buildErrorMessage(userProvider.errorMessage),
                            
                            if (imei == null || imei == 'unknown_device')
                              _buildWarningMessage(
                                'No se pudo obtener el identificador del dispositivo.',
                              ),
                            
                            if (position == null && error == null && !waitingLocation)
                              _buildErrorMessage(
                                'No se pudo obtener la ubicación. Verifica los permisos.',
                              ),
                            
                            const SizedBox(height: 24),
                            
                            // Botón de ingreso
                            _buildLoginButton(userProvider, ready),
                            
                            const SizedBox(height: 24),
                            
                            // Estado del sistema
                            _buildStatusIndicator(ready),
                            
                            const SizedBox(height: 40),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildBackgroundCircles(Size size) {
    return Stack(
      children: [
        // Círculo grande arriba izquierda
        Positioned(
          top: -size.height * 0.12,
          left: -size.width * 0.25,
          child: Container(
            width: size.width * 0.7,
            height: size.width * 0.7,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: const Color(0xFF1A56DB).withOpacity(0.06),
            ),
          ),
        ),
        
        // Círculo mediano arriba derecha
        Positioned(
          top: -size.height * 0.05,
          right: -size.width * 0.15,
          child: Container(
            width: size.width * 0.5,
            height: size.width * 0.5,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: const Color(0xFF1A56DB).withOpacity(0.04),
            ),
          ),
        ),
        
        // Círculo pequeño centro derecha
        Positioned(
          top: size.height * 0.35,
          right: -size.width * 0.1,
          child: Container(
            width: size.width * 0.3,
            height: size.width * 0.3,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: const Color(0xFF1A56DB).withOpacity(0.03),
            ),
          ),
        ),
        
        // Círculo abajo izquierda
        Positioned(
          bottom: -size.height * 0.08,
          left: -size.width * 0.15,
          child: Container(
            width: size.width * 0.5,
            height: size.width * 0.5,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: const Color(0xFF1A56DB).withOpacity(0.04),
            ),
          ),
        ),
        
        // Círculo abajo derecha
        Positioned(
          bottom: size.height * 0.1,
          right: -size.width * 0.2,
          child: Container(
            width: size.width * 0.4,
            height: size.width * 0.4,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: const Color(0xFF1A56DB).withOpacity(0.03),
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildEmailField() {
    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: _isEmailFocused 
              ? const Color(0xFF1A56DB) 
              : const Color(0xFF1A56DB).withOpacity(0.3),
          width: _isEmailFocused ? 2 : 1.5,
        ),
        color: Colors.white,
        boxShadow: _isEmailFocused
            ? [
                BoxShadow(
                  color: const Color(0xFF1A56DB).withOpacity(0.1),
                  blurRadius: 10,
                  offset: const Offset(0, 4),
                ),
              ]
            : [],
      ),
      child: TextField(
        controller: emailController,
        focusNode: _emailFocus,
        keyboardType: TextInputType.emailAddress,
        style: TextStyle(
          color: Colors.grey[800],
          fontSize: 16,
        ),
        cursorColor: const Color(0xFF1A56DB),
        decoration: InputDecoration(
          hintText: 'Correo o Teléfono',
          hintStyle: TextStyle(
            color: Colors.grey[400],
            fontSize: 16,
          ),
          border: InputBorder.none,
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 20,
            vertical: 18,
          ),
        ),
      ),
    );
  }

  Widget _buildErrorMessage(String message, {bool isRetryable = false}) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.red.shade50,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.red.shade200),
      ),
      child: Column(
        children: [
          Row(
            children: [
              Icon(Icons.error_outline, color: Colors.red.shade600, size: 20),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  message,
                  style: TextStyle(color: Colors.red.shade700, fontSize: 13),
                ),
              ),
            ],
          ),
          if (isRetryable) ...[
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                icon: const Icon(Icons.refresh, size: 18),
                label: const Text('Reintentar'),
                onPressed: () {
                  _obtenerGeolocalizacion();
                  _initDeviceInfo();
                },
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.red.shade600,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildWarningMessage(String message) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.orange.shade50,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.orange.shade200),
      ),
      child: Row(
        children: [
          Icon(Icons.warning_amber_outlined, color: Colors.orange.shade700, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: TextStyle(color: Colors.orange.shade800, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildLoginButton(UserProvider userProvider, bool ready) {
    final canLogin = emailController.text.isNotEmpty && ready && !loading;
    
    return AnimatedContainer(
      duration: const Duration(milliseconds: 300),
      height: 56,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        color: canLogin ? const Color(0xFF1A56DB) : Colors.grey.shade300,
        boxShadow: canLogin
            ? [
                BoxShadow(
                  color: const Color(0xFF1A56DB).withOpacity(0.4),
                  blurRadius: 12,
                  offset: const Offset(0, 6),
                ),
              ]
            : [],
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: canLogin
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
                      MaterialPageRoute(builder: (context) => const HomeScreen()),
                    );
                  }
                }
              : null,
          child: Center(
            child: loading
                ? SizedBox(
                    height: 24,
                    width: 24,
                    child: CircularProgressIndicator(
                      strokeWidth: 2.5,
                      color: Theme.of(context).colorScheme.primary,
                    ),
                  )
                : Text(
                    'Ingresar',
                    style: TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                      color: canLogin ? Colors.white : Colors.grey.shade500,
                    ),
                  ),
          ),
        ),
      ),
    );
  }

  Widget _buildStatusIndicator(bool ready) {
    if (waitingLocation || waitingImei) {
      return Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          SizedBox(
            width: 18,
            height: 18,
            child: CircularProgressIndicator(
              strokeWidth: 2,
              color: Colors.grey.shade500,
            ),
          ),
          const SizedBox(width: 12),
          Text(
            waitingLocation ? 'Obteniendo ubicación...' : 'Preparando dispositivo...',
            style: TextStyle(
              color: Colors.grey.shade600,
              fontSize: 14,
            ),
          ),
        ],
      );
    }
    
    if (ready) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        decoration: BoxDecoration(
          color: Colors.green.shade50,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: Colors.green.shade200),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.check_circle,
              color: Colors.green.shade600,
              size: 20,
            ),
            const SizedBox(width: 8),
            Text(
              'Listo para ingresar',
              style: TextStyle(
                color: Colors.green.shade700,
                fontSize: 14,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
      );
    }
    
    return const SizedBox.shrink();
  }

  @override
  void dispose() {
    _serviceStatusSub?.cancel();
    _cardController.dispose();
    _emailFocus.dispose();
    emailController.dispose();
    super.dispose();
  }
}
