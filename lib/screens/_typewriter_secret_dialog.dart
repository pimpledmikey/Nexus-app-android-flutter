import 'package:flutter/material.dart';
import 'package:lottie/lottie.dart';

class TypewriterSecretDialog extends StatefulWidget {
  @override
  State<TypewriterSecretDialog> createState() => _TypewriterSecretDialogState();
}

class _TypewriterSecretDialogState extends State<TypewriterSecretDialog> {
  static const String fullText = '¡Modo Secreto Activado!\nAplicación creada por Mike 🚀';
  String visibleText = '';
  int _charIndex = 0;
  late final Duration charDelay;
  bool finished = false;

  @override
  void initState() {
    super.initState();
    charDelay = const Duration(milliseconds: 35);
    _startTypewriter();
  }

  void _startTypewriter() async {
    while (_charIndex < fullText.length && mounted) {
      await Future.delayed(charDelay);
      setState(() {
        _charIndex++;
        visibleText = fullText.substring(0, _charIndex);
      });
    }
    if (mounted) setState(() => finished = true);
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: Colors.purple.shade50,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Stack(
              alignment: Alignment.center,
              children: [
                Lottie.asset(
                  'assets/lottie/success.json',
                  width: 120,
                  repeat: false,
                ),
              ],
            ),
            const SizedBox(height: 16),
            AnimatedOpacity(
              opacity: 1,
              duration: const Duration(milliseconds: 300),
              child: Text(
                visibleText,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.bold,
                  color: Colors.deepPurple,
                  letterSpacing: 0.2,
                ),
              ),
            ),
            const SizedBox(height: 20),
            TextButton(
              onPressed: finished ? () => Navigator.of(context).pop() : null,
              child: Text(
                finished ? 'Continuar' : '...',
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
