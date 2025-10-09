import 'package:flutter/material.dart';

class _TypewriterText extends StatefulWidget {
  final String text;
  final TextStyle? textStyle;
  final Duration duration;

  const _TypewriterText({
    required this.text,
    this.textStyle,
    this.duration = const Duration(milliseconds: 1200),
    Key? key,
  }) : super(key: key);

  @override
  State<_TypewriterText> createState() => _TypewriterTextState();
}

class _TypewriterTextState extends State<_TypewriterText> with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  late Animation<int> _charCount;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      duration: widget.duration,
      vsync: this,
    );
    _charCount = StepTween(begin: 0, end: widget.text.length).animate(_controller);
    _controller.forward();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _charCount,
      builder: (context, child) {
        String visibleText = widget.text.substring(0, _charCount.value);
        return Text(
          visibleText,
          style: widget.textStyle,
          textAlign: TextAlign.center,
        );
      },
    );
  }
}
