import 'package:http/http.dart' as http;
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

class GeocercaService {
  /// Verifica si un empleado está dentro de alguna geocerca asignada
  static Future<Map<String, dynamic>> verificarGeocerca({
    required String empleadoID,
    required double latitud,
    required double longitud,
  }) async {
    try {
      final url = Uri.parse(
        'https://dev.bsys.mx/scriptcase/app/Gilneas/ws_nexus_geo/ws_nexus_geo.php?fn=VerificarGeocerca'
        '&empleadoID=$empleadoID'
        '&latitud=$latitud'
        '&longitud=$longitud',
      );
      
      debugPrint('URL verificación geocerca: $url');
      
      final response = await http.get(url);
      debugPrint('Respuesta geocerca: ${response.body}');
      
      if (response.statusCode == 200) {
        final resultado = json.decode(response.body);
        
        // Si el servidor retorna error porque no existe la función, usar modo temporal
        if (resultado['estatus'] == '0' && resultado['mensaje'].toString().contains('No existe la funcion')) {
          debugPrint('Función VerificarGeocerca no disponible en servidor, usando modo temporal');
          return {
            'estatus': '1',
            'geocercaID': null,
            'distanciaMetros': null,
            'validacion': 'Sin_Geocerca',
            'mensaje': 'Verificación de zona pendiente',
          };
        }
        
        return {
          'estatus': '1',
          'geocercaID': resultado['geocercaID'],
          'distanciaMetros': resultado['distanciaMetros'],
          'validacion': resultado['validacion'], // 'Dentro', 'Fuera', 'Sin_Geocerca'
          'mensaje': _generarMensajeGeocerca(resultado),
        };
      } else {
        return {
          'estatus': '0',
          'validacion': 'Error',
          'mensaje': 'Error al verificar geocerca'
        };
      }
    } catch (e) {
      debugPrint('Error verificando geocerca: $e');
      return {
        'estatus': '0',
        'validacion': 'Error',
        'mensaje': 'Sin conexión para verificar geocerca'
      };
    }
  }

  static String _generarMensajeGeocerca(Map<String, dynamic> resultado) {
    final validacion = resultado['validacion'] ?? 'Error';
    final distancia = resultado['distanciaMetros'];
    
    switch (validacion) {
      case 'Dentro':
        return 'Dentro de tu zona de trabajo';
      case 'Fuera':
        if (distancia != null) {
          return 'Fuera de tu zona (\${distancia}m de distancia)';
        }
        return 'Fuera de tu zona de trabajo';
      case 'Sin_Geocerca':
        return 'Sin zona de trabajo asignada';
      default:
        return 'Verificando ubicación';
    }
  }

  /// Obtiene el color para mostrar el estado de geocerca
  static int getColorForStatus(String validacion) {
    switch (validacion) {
      case 'Dentro':
        return 0xFF4CAF50; // Verde suave
      case 'Fuera':
        return 0xFFFF7043; // Naranja-rojo suave
      case 'Sin_Geocerca':
        return 0xFF42A5F5; // Azul suave
      default:
        return 0xFF78909C; // Gris azulado suave
    }
  }

  /// Obtiene el icono para mostrar el estado de geocerca
  static IconData getIconForStatus(String validacion) {
    switch (validacion) {
      case 'Dentro':
        return Icons.check_circle;
      case 'Fuera':
        return Icons.warning_amber_rounded;
      case 'Sin_Geocerca':
        return Icons.location_disabled;
      case 'Sin_Conexion':
        return Icons.wifi_off;
      default:
        return Icons.location_searching;
    }
  }

  /// Verifica si es el primer registro del día para determinar si pedir motivo
  static Future<Map<String, dynamic>> verificarTipoRegistro({
    required String empleadoID,
  }) async {
    try {
      final url = Uri.parse(
        'https://dev.bsys.mx/scriptcase/app/Gilneas/ws_nexus_geo/ws_nexus_geo.php?fn=VerificarTipoRegistro'
        '&empleadoID=$empleadoID',
      );
      
      debugPrint('URL verificación tipo registro: $url');
      
      final response = await http.get(url).timeout(const Duration(seconds: 5));
      debugPrint('Respuesta tipo registro: ${response.body}');
      
      if (response.statusCode == 200) {
        final resultado = json.decode(response.body);
        
        // Si la función no existe en el servidor, asumir que sí requiere motivo
        if (resultado['estatus'] == '0') {
          return {
            'estatus': '0',
            'estadoRegistro': 'Entrada',
            'requierePedirMotivo': true,
          };
        }
        
        return {
          'estatus': '1',
          'estadoRegistro': resultado['estadoRegistro'] ?? 'Entrada',
          'requierePedirMotivo': resultado['requierePedirMotivo'] ?? true,
        };
      } else {
        return {
          'estatus': '0',
          'estadoRegistro': 'Entrada',
          'requierePedirMotivo': true,
        };
      }
    } catch (e) {
      debugPrint('Error verificando tipo registro: $e');
      return {
        'estatus': '0',
        'estadoRegistro': 'Entrada',
        'requierePedirMotivo': true,
      };
    }
  }
}
