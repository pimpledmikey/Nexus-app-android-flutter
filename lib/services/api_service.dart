import 'package:http/http.dart' as http;
import 'dart:convert';
import '../utils/nexus_crypto.dart';
import 'package:flutter/foundation.dart';

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
      final response = await http.get(url);
      debugPrint('Respuesta WS: ${response.body}');
      if (response.statusCode == 200) {
        return json.decode(response.body);
      } else {
        return {'estatus': '0', 'mensaje': 'Error de conexión'};
      }
    } catch (e) {
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
      final response = await http.get(url);
      debugPrint('Respuesta WS: ${response.body}');
      if (response.statusCode == 200) {
        return json.decode(response.body);
      } else {
        return {'estatus': '0', 'mensaje': 'Error de conexión'};
      }
    } catch (e) {
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
}
