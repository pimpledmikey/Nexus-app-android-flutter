import 'package:http/http.dart' as http;
import 'dart:convert';
import 'dart:io';
import 'dart:async';
import 'dart:typed_data';
import '../utils/nexus_crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';

class ApiService {
  static Future<Map<String, dynamic>> loginNexus(String input, String imei) async {
    String email = '';
    String phone = '';
    if (input.contains('@')) {
      email = input;
    } else {
      phone = input;
    }
    final url = Uri.parse(
      'https://dev.bsys.mx/scriptcase/app/Gilneas/ws_nexus_geo/ws_nexus_geo.php?fn=NexusDK&email=$email&phone=$phone&imei=$imei',
    );
    debugPrint('URL de login enviada: $url');
    try {
      final response = await http.get(url).timeout(
        const Duration(seconds: 15),
        onTimeout: () {
          debugPrint('Timeout en login');
          throw Exception('Timeout');
        },
      );
      debugPrint('Respuesta WS: ${response.body}');
      if (response.statusCode == 200) {
        return json.decode(response.body);
      } else {
        return {'estatus': '0', 'mensaje': 'Error de conexión'};
      }
    } catch (e) {
      debugPrint('Error en login: $e');
      return {'estatus': '0', 'mensaje': 'Sin conexión'};
    }
  }

  static Future<Map<String, dynamic>> registroRemoto({
    required String empleadoID,
    required String nombre,
    required String latitud,
    required String longitud,
    required String cadenaEmpleado,
    required String cadenaTiempo,
    String? cadenaEncriptada,
    String direccion = '',
    String empresa = 'DRT',
    String ubicacionAcc = 'Remoto',
    String tipoHard = 'Nexus',
    String? esPendiente,
    int? tipo,
    String? motivoFueraGeocerca,
  }) async {
    final encrypted = cadenaEncriptada ?? NexusCrypto.encryptor(cadenaEmpleado);
    final request = '$cadenaTiempo:$encrypted';
    final requestParam = Uri.encodeComponent(request);
    debugPrint('Request param: $request');
    String urlStr =
      'https://dev.bsys.mx/scriptcase/app/Gilneas/ws_nexus_geo/ws_nexus_geo.php?fn=RegistroRemoto'
      '&request=$requestParam'
      '&direccion=${Uri.encodeComponent(direccion)}'
      '&empresa=${Uri.encodeComponent(empresa)}'
      '&ubicacionAcc=${Uri.encodeComponent(ubicacionAcc)}'
      '&tipoHard=${Uri.encodeComponent(tipoHard)}';
    if (esPendiente != null) {
      urlStr += '&esPendiente=$esPendiente';
    }
    if (tipo != null) {
      urlStr += '&tipo=$tipo';
    }
    if (motivoFueraGeocerca != null && motivoFueraGeocerca.isNotEmpty) {
      urlStr += '&motivoFueraGeocerca=${Uri.encodeComponent(motivoFueraGeocerca)}';
    }
    final url = Uri.parse(urlStr);
    debugPrint('URL de registro enviada: $url');
    try {
      final response = await http.get(url).timeout(
        const Duration(seconds: 10),
        onTimeout: () {
          debugPrint('Timeout en registro remoto');
          throw Exception('Timeout');
        },
      );
      debugPrint('Respuesta WS: ${response.body}');
      if (response.statusCode == 200) {
        return json.decode(response.body);
      } else {
        return {'estatus': '0', 'mensaje': 'Error de conexión'};
      }
    } catch (e) {
      debugPrint('Error en registro remoto: $e');
      return {'estatus': '0', 'mensaje': 'Sin conexión'};
    }
  }

  static String encodeUser(Map<String, dynamic> user) {
    return json.encode(user);
  }

  /// Decodifica un usuario desde un String JSON.
  /// Si el string es null, vacío o inválido, retorna un mapa vacío.
  static Map<String, dynamic> decodeUser(String? userString) {
    if (userString == null || userString.trim().isEmpty) return <String, dynamic>{};
    try {
      final decoded = json.decode(userString);
      if (decoded is Map<String, dynamic>) return decoded;
      return <String, dynamic>{};
    } catch (_) {
      return <String, dynamic>{};
    }
  }

  /// Convierte una imagen a Base64 desde su path local
  static Future<String?> imageToBase64(String filePath) async {
    try {
      final file = File(filePath);
      if (!await file.exists()) {
        debugPrint('[ApiService] Archivo no existe: $filePath');
        return null;
      }
      // Intentar comprimir la imagen antes de convertir a base64
      final compressed = await _compressImageFile(filePath);
      final bytes = compressed ?? await file.readAsBytes();
      return base64Encode(bytes);
    } catch (e) {
      debugPrint('[ApiService] Error convirtiendo imagen a base64: $e');
      return null;
    }
  }

  /// Comprime una imagen usando `flutter_image_compress`.
  /// Retorna los bytes comprimidos o null si falla.
  static Future<Uint8List?> _compressImageFile(String path, {int quality = 75, int minWidth = 1024}) async {
    try {
      final result = await FlutterImageCompress.compressWithFile(
        path,
        quality: quality,
        minWidth: minWidth,
        // Mantener orientación EXIF
        keepExif: true,
      );
      if (result == null) return null;
      debugPrint('[ApiService] Imagen comprimida: ${path} -> ${result.length} bytes (quality=$quality)');
      return Uint8List.fromList(result);
    } catch (e) {
      debugPrint('[ApiService] Error comprimiendo imagen: $e');
      return null;
    }
  }

  /// Realiza POST con reintentos exponenciales. Retorna `http.Response` o lanza excepción.
  static Future<http.Response> _postWithRetry(Uri url, {required Map<String, dynamic> body, int maxRetries = 3, Duration initialDelay = const Duration(seconds: 2)}) async {
    int attempt = 0;
    Duration delay = initialDelay;
    while (true) {
      attempt++;
      try {
        final resp = await http.post(
          url,
          headers: {'Content-Type': 'application/json'},
          body: json.encode(body),
        ).timeout(const Duration(seconds: 30));
        return resp;
      } catch (e) {
        if (attempt >= maxRetries) rethrow;
        debugPrint('[ApiService] POST attempt $attempt failed: $e. Retrying in ${delay.inSeconds}s');
        await Future.delayed(delay);
        delay *= 2;
      }
    }
  }

  /// Envía registro remoto CON evidencias en base64 (POST)
  /// Las evidencias se envían como array JSON de objetos {filename, base64, mimetype}
  static Future<Map<String, dynamic>> registroRemotoConEvidencias({
    required String empleadoID,
    required String nombre,
    required String latitud,
    required String longitud,
    required String cadenaEmpleado,
    required String cadenaTiempo,
    String? cadenaEncriptada,
    String direccion = '',
    String empresa = 'DRT',
    String ubicacionAcc = 'Remoto',
    String tipoHard = 'Nexus',
    String? esPendiente,
    String? motivoFueraGeocerca,
    List<String>? attachmentPaths,
  }) async {
    // 1) Ejecutar RegistroRemoto (GET) para crear el registro y obtener salidEnt
    final registroResp = await registroRemoto(
      empleadoID: empleadoID,
      nombre: nombre,
      latitud: latitud,
      longitud: longitud,
      cadenaEmpleado: cadenaEmpleado,
      cadenaTiempo: cadenaTiempo,
      cadenaEncriptada: cadenaEncriptada,
      direccion: direccion,
      empresa: empresa,
      ubicacionAcc: ubicacionAcc,
      tipoHard: tipoHard,
      esPendiente: esPendiente,
      motivoFueraGeocerca: motivoFueraGeocerca,
    );

    // Si el registro falló, retornar el resultado
    if (registroResp['estatus'] != '1') return registroResp;

    // Obtener salidEnt si existe
    final salidEnt = registroResp['salidEnt'] ?? null;
    if (salidEnt == null) {
      debugPrint('[ApiService] No se recibió salidEnt del registro; no se podrán subir evidencias.');
      return registroResp;
    }

    // 2) Si hay attachments y el registro quedó en estado 'Pendiente', convertir a base64 y llamar a SubirEvidencias
    final estadoRegistro = (registroResp['estadoValidacionGeocerca'] ?? '').toString();
    if (attachmentPaths != null && attachmentPaths.isNotEmpty && estadoRegistro == 'Pendiente') {
      List<Map<String, String>> evidencias = [];
      for (final path in attachmentPaths) {
        final base64 = await imageToBase64(path);
        if (base64 != null) {
          final filename = path.split('/').last;
          evidencias.add({'filename': filename, 'base64': base64, 'mimetype': 'image/jpeg'});
        }
      }

      if (evidencias.isNotEmpty) {
        try {
          // Envío DIRECTO al handler de evidencias (subir_evidencias.php)
          final primaryUrl = Uri.parse('https://dev.bsys.mx/scriptcase/app/Gilneas/subir_evidencias/subir_evidencias.php');
          debugPrint('[ApiService] Direct URL usada para SubirEvidencias: $primaryUrl');

          final empleadoIdVal = int.tryParse(empleadoID) ?? empleadoID;
          final body = {
            'fn': 'SubirEvidencias',
            'salidEnt': salidEnt,
            'empleadoID': empleadoIdVal,
            'evidencias': evidencias,
            // Durante pruebas dejar false para evitar envíos de correo
            'notifyJefe': false,
            'motivo': motivoFueraGeocerca ?? ''
          };

          // Comprobar tamaños grandes (advertencia)
          for (final ev in evidencias) {
            final b64 = ev['base64'] ?? '';
            if (b64.length > 6 * 1024 * 1024) { // ~6MB base64 ~ 4.4MB raw
              debugPrint('[ApiService] Atención: evidencia ${ev['filename']} supera ~6MB base64, puede fallar la petición');
            }
          }

          debugPrint('[ApiService] Subiendo ${evidencias.length} evidencia(s) para salidEnt=$salidEnt via WS_Nexus');

          try {
            final resp = await _postWithRetry(primaryUrl, body: body);
            if (resp.statusCode != 200) {
              registroResp['evidenciasGuardadas'] = 0;
              registroResp['subirEvidenciasResp'] = {'estatus': '0', 'mensaje': 'Error subiendo evidencias: HTTP ${resp.statusCode}'};
              return registroResp;
            }

            Map<String, dynamic> subir;
            try {
              subir = json.decode(resp.body);
            } catch (e) {
              debugPrint('[ApiService] Respuesta no-JSON desde direct handler: ${resp.body}');
              registroResp['evidenciasGuardadas'] = 0;
              registroResp['subirEvidenciasResp'] = {'estatus': '0', 'mensaje': 'Respuesta no-JSON del servidor'};
              return registroResp;
            }

            final guardadas = subir['guardadas'] ?? 0;
            if (guardadas > 0) await limpiarEvidenciasLocales(attachmentPaths);
            registroResp['evidenciasGuardadas'] = guardadas;
            registroResp['subirEvidenciasResp'] = subir;
            return registroResp;
          } catch (e) {
            debugPrint('[ApiService] Error subiendo evidencias (direct): $e');
            registroResp['evidenciasGuardadas'] = 0;
            registroResp['subirEvidenciasResp'] = {'estatus': '0', 'mensaje': 'Exception: $e'};
            return registroResp;
          }
        } catch (e) {
          debugPrint('[ApiService] Error subiendo evidencias: $e');
          registroResp['evidenciasGuardadas'] = 0;
          registroResp['subirEvidenciasResp'] = {'estatus': '0', 'mensaje': 'Exception: $e'};
          return registroResp;
        }
      }
    } else {
      if (attachmentPaths != null && attachmentPaths.isNotEmpty) {
        debugPrint('[ApiService] Evidencias presentes pero registro no en estado Pendiente (estado: $estadoRegistro) - no se subieron');
      }
    }

    // Si no había evidencias, devolver la respuesta del registro
    return registroResp;
  }

  /// Elimina archivos de evidencia locales después de envío exitoso
  static Future<void> limpiarEvidenciasLocales(List<String> paths) async {
    for (final path in paths) {
      try {
        final file = File(path);
        if (await file.exists()) {
          await file.delete();
          debugPrint('[ApiService] Evidencia local eliminada: $path');
        }
      } catch (e) {
        debugPrint('[ApiService] Error eliminando evidencia: $e');
      }
    }
  }
}
