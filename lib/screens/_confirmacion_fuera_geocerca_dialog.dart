import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:intl/intl.dart';

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

  // Adjuntos (paths locales)
  final List<String> _attachments = [];
  static const int _maxAttachments = 2; // Máximo 2 fotos para ahorrar espacio

  // Estado para mostrar sección de evidencia solo si el usuario acepta
  bool _mostrarEvidencia = false;

  final ImagePicker _picker = ImagePicker();

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

  Future<String?> _savePickedFile(XFile file) async {
    try {
      final bytes = await file.readAsBytes();
      // Comprimir imagen para ahorrar espacio
      final dir = await getApplicationDocumentsDirectory();
      final evidenceDir = Directory('${dir.path}/evidence');
      if (!await evidenceDir.exists()) await evidenceDir.create(recursive: true);
      final timestamp = DateFormat('yyyyMMdd_HHmmss').format(DateTime.now());
      final outPath = '${evidenceDir.path}/evidence_$timestamp.jpg';

      final compressed = await FlutterImageCompress.compressWithList(
        bytes,
        minWidth: 1024,
        minHeight: 1024,
        quality: 80,
        rotate: 0,
      );
      final outFile = File(outPath);
      await outFile.writeAsBytes(compressed);
      return outFile.path;
    } catch (e) {
      debugPrint('Error saving picked file: $e');
      return null;
    }
  }

  Future<void> _pickImage(ImageSource source) async {
    try {
      // Pedir permisos según fuente
      if (source == ImageSource.camera) {
        final status = await Permission.camera.request();
        if (status.isPermanentlyDenied) {
          if (!mounted) return;
          await showDialog(
            context: context,
            builder: (ctx) => AlertDialog(
              title: const Text('Permiso de cámara necesario'),
              content: const Text('El permiso de cámara está denegado permanentemente. Abre la configuración de la app para habilitarlo.'),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(ctx).pop(),
                  child: const Text('Cancelar'),
                ),
                ElevatedButton(
                  onPressed: () async {
                    Navigator.of(ctx).pop();
                    await openAppSettings();
                  },
                  child: const Text('Abrir ajustes'),
                ),
              ],
            ),
          );
          return;
        }
        if (!status.isGranted) {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Permiso de cámara denegado. Habilítalo en Ajustes para tomar fotos.')),
          );
          return;
        }
      } else {
        final status = await Permission.photos.request();
        if (status.isPermanentlyDenied) {
          if (!mounted) return;
          await showDialog(
            context: context,
            builder: (ctx) => AlertDialog(
              title: const Text('Permiso de fotos necesario'),
              content: const Text('El permiso de fotos está denegado permanentemente. Abre la configuración de la app para habilitarlo.'),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(ctx).pop(),
                  child: const Text('Cancelar'),
                ),
                ElevatedButton(
                  onPressed: () async {
                    Navigator.of(ctx).pop();
                    await openAppSettings();
                  },
                  child: const Text('Abrir ajustes'),
                ),
              ],
            ),
          );
          return;
        }
        if (!status.isGranted) {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Permiso de fotos denegado. Habilítalo en Ajustes para seleccionar desde galería.')),
          );
          return;
        }
      }

      final XFile? picked = await _picker.pickImage(source: source, imageQuality: 85, maxWidth: 2000);
      if (picked == null) {
        // Usuario canceló la cámara
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('No se tomó ninguna foto. Puedes intentarlo nuevamente.')),
        );
        return;
      }
      final saved = await _savePickedFile(picked);
      if (saved != null) {
        setState(() {
          _attachments.add(saved);
        });
      }
    } catch (e) {
      debugPrint('Error picking image: $e');
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error al tomar la foto: $e')));
      }
    }
  }

  void _removeAttachment(int idx) async {
    final path = _attachments[idx];
    setState(() {
      _attachments.removeAt(idx);
    });
    try {
      final f = File(path);
      if (await f.exists()) await f.delete();
    } catch (_) {}
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
          Icon(Icons.location_off, color: Colors.orange, size: 32),
          SizedBox(width: 10),
          Text('Fuera de zona', style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
        ],
      ),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Mensaje simple: solo fuera de zona
            const Text(
              'Fuera de zona',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 20),
            const Text(
              'Estás registrando tu ENTRADA fuera de zona de trabajo.',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w500),
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.orange.withOpacity(0.1),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.orange.withOpacity(0.3)),
              ),
              child: const Row(
                children: [
                  Icon(Icons.info_outline, color: Colors.orange, size: 22),
                  SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'Tu registro quedará "PENDIENTE DE VALIDACIÓN" hasta que sea revisado por tu supervisor.',
                      style: TextStyle(fontSize: 15, color: Colors.orange, fontWeight: FontWeight.w500),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),
            const Text(
              'Motivo (requerido):',
              style: TextStyle(
                fontSize: 17,
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _motivoController,
              maxLines: 4,
              maxLength: 200,
              style: const TextStyle(fontSize: 16),
              decoration: InputDecoration(
                hintText: 'Explica por qué estás registrando fuera de zona...',
                hintStyle: TextStyle(fontSize: 15, color: Colors.grey),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: BorderSide(
                    color: _puedeConfirmar ? Colors.green : Colors.grey,
                  ),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: BorderSide(
                    color: _puedeConfirmar ? Colors.green : Colors.orange,
                    width: 2,
                  ),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: BorderSide(
                    color: _puedeConfirmar ? Colors.green : Colors.grey,
                  ),
                ),
                counterText: '',
                contentPadding: const EdgeInsets.all(16),
                suffixIcon: _puedeConfirmar 
                    ? const Icon(Icons.check_circle, color: Colors.green, size: 28)
                    : Icon(Icons.edit_note, color: Colors.grey, size: 28),
              ),
            ),
            const SizedBox(height: 12),
            if (_mostrarEvidencia) ...[
              // Sección de evidencia solo si el usuario acepta
              if (_attachments.isNotEmpty)
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: _attachments.asMap().entries.map((e) {
                    final idx = e.key;
                    final path = e.value;
                    return Stack(
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(8),
                          child: Image.file(
                            File(path),
                            width: 70,
                            height: 70,
                            fit: BoxFit.cover,
                          ),
                        ),
                        Positioned(
                          top: 2,
                          right: 2,
                          child: GestureDetector(
                            onTap: () => _removeAttachment(idx),
                            child: Container(
                              decoration: const BoxDecoration(
                                color: Colors.black54,
                                shape: BoxShape.circle,
                              ),
                              padding: const EdgeInsets.all(3),
                              child: const Icon(Icons.close, color: Colors.white, size: 14),
                            ),
                          ),
                        ),
                      ],
                    );
                  }).toList(),
                ),
              if (_attachments.length < _maxAttachments) ...[
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: () => _pickImage(ImageSource.camera),
                        icon: const Icon(Icons.camera_alt, size: 18),
                        label: const Text('Cámara'),
                        style: OutlinedButton.styleFrom(
                          padding: const EdgeInsets.symmetric(vertical: 10),
                        ),
                      ),
                    ),
                  ],
                ),
                Text(
                  'Máximo $_maxAttachments fotos',
                  style: TextStyle(fontSize: 12, color: Colors.grey[500]),
                ),
              ],
              const SizedBox(height: 8),
            ],
            Align(
              alignment: Alignment.centerLeft,
              child: Text(
                '${_motivoController.text.length}/200',
                style: TextStyle(
                  fontSize: 14,
                  color: Colors.grey[600],
                  fontWeight: FontWeight.w500,
                ),
              ),
            ),
          ],
        ),
      ),
      actionsPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      actions: [
        TextButton(
          onPressed: widget.onCancelar,
          style: TextButton.styleFrom(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
          ),
          child: const Text('Cancelar', style: TextStyle(fontSize: 16)),
        ),
        ElevatedButton.icon(
          icon: const Icon(Icons.check_circle, size: 22),
          onPressed: _puedeConfirmar
              ? () async {
                  // Preguntar si desea adjuntar evidencia
                  if (!_mostrarEvidencia) {
                    final resp = await showDialog<bool>(
                      context: context,
                      builder: (ctx) => AlertDialog(
                        title: const Text('¿Adjuntar evidencia?'),
                        content: const Text('¿Deseas adjuntar evidencia fotográfica a tu registro?'),
                        actions: [
                          TextButton(
                            onPressed: () => Navigator.of(ctx).pop(false),
                            child: const Text('No'),
                          ),
                          ElevatedButton(
                            onPressed: () => Navigator.of(ctx).pop(true),
                            child: const Text('Sí'),
                          ),
                        ],
                      ),
                    );
                    if (resp == true) {
                      setState(() => _mostrarEvidencia = true);
                      // Abrir cámara automáticamente para tomar la evidencia
                      try {
                        await _pickImage(ImageSource.camera);
                      } catch (e) {
                        debugPrint('Error abriendo cámara automáticamente: $e');
                      }
                      return;
                    }
                  }
                  final result = {
                    'motivo': motivo,
                    'attachments': List<String>.from(_attachments),
                  };
                  Navigator.of(context).pop(result);
                  widget.onConfirmar();
                }
              : null,
          style: ElevatedButton.styleFrom(
            backgroundColor: Colors.orange,
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          ),
          label: const Text('Confirmar Registro', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
        ),
      ],
    );
  }
}

/// Función helper para mostrar el diálogo y obtener el motivo
Future<Map<String, dynamic>?> mostrarDialogoFueraGeocerca({
  required BuildContext context,
  required String mensajeGeocerca,
}) async {
  return showDialog<Map<String, dynamic>>(
    context: context,
    barrierDismissible: false,
    builder: (context) => ConfirmacionFueraGeocercaDialog(
      mensajeGeocerca: mensajeGeocerca,
      onConfirmar: () {}, // Se maneja en el pop
      onCancelar: () => Navigator.of(context).pop(),
    ),
  );
}


