import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

class _ShakyCircle extends StatefulWidget {
  final int number;
  final VoidCallback onCorrect;
  final VoidCallback onIncorrect;
  final bool isCorrect;
  final bool acertado;

  const _ShakyCircle({
    required this.number,
    required this.onCorrect,
    required this.onIncorrect,
    required this.isCorrect,
    required this.acertado,
    Key? key,
  }) : super(key: key);

  @override
  State<_ShakyCircle> createState() => _ShakyCircleState();
}

class _ShakyCircleState extends State<_ShakyCircle> with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  late Animation<double> _shakeAnim;
  bool _showGreen = false;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      duration: const Duration(milliseconds: 350),
      vsync: this,
    );
    _shakeAnim = TweenSequence([
      TweenSequenceItem(tween: Tween(begin: 0.0, end: 10.0), weight: 1),
      TweenSequenceItem(tween: Tween(begin: 10.0, end: -10.0), weight: 1),
      TweenSequenceItem(tween: Tween(begin: -10.0, end: 0.0), weight: 1),
    ]).animate(CurvedAnimation(parent: _controller, curve: Curves.elasticIn));
  }

  void _handleTap() {
    if (widget.acertado) return;
    if (widget.isCorrect) {
      setState(() {
        _showGreen = true;
      });
      HapticFeedback.heavyImpact();
      widget.onCorrect();
    } else {
      _controller.forward(from: 0);
      HapticFeedback.vibrate();
      widget.onIncorrect();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    Color bgColor = _showGreen ? Colors.green.shade400 : Colors.deepPurple.shade100;
    Color textColor = _showGreen ? Colors.white : Colors.deepPurple;
    double radius = _showGreen ? 32 : 26;
    FontWeight weight = _showGreen ? FontWeight.bold : FontWeight.normal;
    return AnimatedBuilder(
      animation: _shakeAnim,
      builder: (context, child) {
        return Transform.translate(
          offset: Offset(_shakeAnim.value, 0),
          child: GestureDetector(
            onTap: _handleTap,
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 250),
              curve: Curves.easeOut,
              width: radius * 2,
              height: radius * 2,
              decoration: BoxDecoration(
                color: bgColor,
                shape: BoxShape.circle,
                boxShadow: [
                  if (_showGreen)
                    BoxShadow(
                      color: Colors.green.withOpacity(0.4),
                      blurRadius: 12,
                      spreadRadius: 2,
                    ),
                ],
              ),
              child: Center(
                child: Text(
                  '${widget.number}',
                  style: TextStyle(
                    fontSize: _showGreen ? 24 : 20,
                    fontWeight: weight,
                    color: textColor,
                  ),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
