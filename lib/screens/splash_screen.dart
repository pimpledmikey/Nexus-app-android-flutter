import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'login_screen.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with TickerProviderStateMixin {
  late AnimationController _nController;
  late AnimationController _slideController;
  late AnimationController _logoController;
  late List<AnimationController> _letterControllers;
  late Animation<double> _nSizeAnim;
  late Animation<Alignment> _nAlignAnim;
  late List<Animation<Offset>> _slideAnims;
  late Animation<Offset> _logoSlideAnim;
  late Animation<double> _logoFadeAnim;
  late List<Animation<Offset>> _letterSlideAnims;
  bool _showRest = false;
  final List<String> _letters = ['N', 'E', 'X', 'U', 'S'];

  @override
  void initState() {
    super.initState();
    _nController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 600), // más rápido
    );
    _slideController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 600), // más rápido
    );
    _logoController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 600), // más rápido
    );
    _letterControllers = List.generate(_letters.length, (i) => AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 200), // aún más rápido
    ));
    _letterSlideAnims = List.generate(_letters.length, (i) => Tween<Offset>(
      begin: const Offset(1.5, 0),
      end: Offset.zero,
    ).animate(CurvedAnimation(
      parent: _letterControllers[i],
      curve: Curves.easeOut,
    )));
    _logoFadeAnim = CurvedAnimation(parent: _logoController, curve: Curves.easeInOut);
    _logoSlideAnim = Tween<Offset>(
      begin: const Offset(1.5, 0),
      end: Offset.zero,
    ).animate(CurvedAnimation(parent: _logoController, curve: Curves.easeOut));
    _startAnim();
  }

  Future<void> _startAnim() async {
    await Future.delayed(const Duration(milliseconds: 200));
    await _nController.forward();
    setState(() => _showRest = true);
    // Animar letras una por una
    for (int i = 0; i < _letters.length; i++) {
      await _letterControllers[i].forward();
      await Future.delayed(const Duration(milliseconds: 30)); // aún más rápido
    }
    await Future.delayed(const Duration(milliseconds: 60));
    await _logoController.forward();
    await Future.delayed(const Duration(milliseconds: 350)); // más rápido
    if (mounted) {
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const LoginScreen()),
      );
    }
  }

  @override
  void dispose() {
    _nController.dispose();
    _slideController.dispose();
    _logoController.dispose();
    for (final c in _letterControllers) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final screenSize = MediaQuery.of(context).size;
    return Scaffold(
      body: Stack(
        children: [
          // Fondo animado con gradiente
          AnimatedContainer(
            duration: const Duration(milliseconds: 900),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  Color.lerp(const Color(0xFF001e38), const Color(0xFF0a3d62), _nController.value)!,  
                  Color.lerp(const Color(0xFF001e38), const Color(0xFF3c6382), _logoController.value)!,
                ],
              ),
            ),
            width: double.infinity,
            height: double.infinity,
          ),
          Center(
            child: AnimatedBuilder(
              animation: Listenable.merge([_nController, _slideController]),
              builder: (context, child) {
                // Fase 1: N gigante centrada (pantalla completa)
                if (_nController.value == 0) {
                  return Center(
                    child: AnimatedDefaultTextStyle(
                      duration: const Duration(milliseconds: 400),
                      style: GoogleFonts.lato(
                        fontSize: screenSize.width * 0.85, // tamaño pantalla
                        fontWeight: FontWeight.w700,
                        color: Colors.white,
                        letterSpacing: 2,
                      ),
                      child: const Text('N'),
                    ),
                  );
                }
                // Fase 2: N se reduce y se alinea a la izquierda, aparecen las demás letras
                return Center(
                  child: SizedBox(
                    width: 380,
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: [
                        ...List.generate(_letters.length, (i) {
                          return SlideTransition(
                            position: _letterSlideAnims[i],
                            child: Text(
                              _letters[i],
                              style: GoogleFonts.lato(
                                fontSize: 54,
                                fontWeight: FontWeight.w700,
                                color: Colors.white,
                                letterSpacing: 2,
                              ),
                            ),
                          );
                        }),
                        SlideTransition(
                          position: _logoSlideAnim,
                          child: FadeTransition(
                            opacity: _logoFadeAnim,
                            child: Padding(
                              padding: const EdgeInsets.only(left: 16.0),
                              child: Image.asset(
                                'assets/logo_nexus.png',
                                width: 54,
                                height: 54,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
