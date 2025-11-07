import 'package:flutter/material.dart';

class ConfirmacionFueraGeocercaDialog extends StatefulWidget {
  final String mensajeGeocerca;
  final VoidCallback onConfirmar;
  final VoidCallback onCancelar;

  const ConfirmacionFueraGeocercaDialog({
    super.key,
    required this.mensajeGeocerca,
    required this.onConfirmar,
    required this.onCancelar,
  });

  @override
  State<ConfirmacionFueraGeocercaDialog> createState() => _ConfirmacionFueraGeocercaDialogState();
}

class _ConfirmacionFueraGeocercaDialogState extends State<ConfirmacionFueraGeocercaDialog> {
  final TextEditingController _motivoController = TextEditingController();
  bool _puedeConfirmar = false;

  @override
  void initState() {
    super.initState();
    _motivoController.addListener(_validarMotivo);
  }

  void _validarMotivo() {
    final texto = _motivoController.text.trim();
    final palabras = texto.split(RegExp(r'\s+')).where((word) => word.isNotEmpty).length;
    setState(() {
      _puedeConfirmar = texto.isNotEmpty && palabras >= 1;
    });
  }

  String get motivo => _motivoController.text.trim();

  @override
  void dispose() {
    _motivoController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
        title: const Row(
        children: [
          Icon(Icons.location_off, color: Colors.orange, size: 28),
          SizedBox(width: 8),
          Text('Fuera de tu zona'),
        ],
      ),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
          Text(
            widget.mensajeGeocerca,
            style: const TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.w500,
            ),
          ),
          const SizedBox(height: 16),
          const Text(
            '¿Estás seguro que quieres registrar tu ubicación fuera de tu zona de trabajo asignada?',
            style: TextStyle(fontSize: 14),
          ),
          const SizedBox(height: 16),
          const Text(
            'Motivo (requerido - mínimo 1 palabra):',
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.bold,
            ),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _motivoController,
            maxLines: 3,
            maxLength: 200,
            decoration: InputDecoration(
              hintText: 'Explica por qué estás registrando fuera de tu zona de trabajo...',
              border: OutlineInputBorder(
                borderSide: BorderSide(
                  color: _puedeConfirmar ? Colors.green : Colors.red,
                ),
              ),
              focusedBorder: OutlineInputBorder(
                borderSide: BorderSide(
                  color: _puedeConfirmar ? Colors.green : Colors.red,
                  width: 2,
                ),
              ),
              counterText: '',
              suffixIcon: _puedeConfirmar 
                  ? const Icon(Icons.check, color: Colors.green)
                  : const Icon(Icons.warning, color: Colors.red),
            ),
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Palabras: ${_motivoController.text.trim().split(RegExp(r'\s+')).where((word) => word.isNotEmpty).length}',
                style: TextStyle(
                  fontSize: 12,
                  color: _puedeConfirmar ? Colors.green : Colors.red,
                  fontWeight: FontWeight.w500,
                ),
              ),
              Text(
                '${_motivoController.text.length}/200 caracteres',
                style: TextStyle(
                  fontSize: 12,
                  color: Colors.grey[600],
                ),
              ),
            ],
          ),
        ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: widget.onCancelar,
          child: const Text('Cancelar'),
        ),
        ElevatedButton(
          onPressed: _puedeConfirmar
              ? () {
                  Navigator.of(context).pop(motivo);
                  widget.onConfirmar();
                }
              : null,
          style: ElevatedButton.styleFrom(
            backgroundColor: Colors.orange,
            foregroundColor: Colors.white,
          ),
          child: const Text('Confirmar Registro'),
        ),
      ],
    );
  }
}

/// Función helper para mostrar el diálogo y obtener el motivo
Future<String?> mostrarDialogoFueraGeocerca({
  required BuildContext context,
  required String mensajeGeocerca,
}) async {
  return showDialog<String>(
    context: context,
    barrierDismissible: false,
    builder: (context) => ConfirmacionFueraGeocercaDialog(
      mensajeGeocerca: mensajeGeocerca,
      onConfirmar: () {}, // Se maneja en el pop
      onCancelar: () => Navigator.of(context).pop(),
    ),
  );
}