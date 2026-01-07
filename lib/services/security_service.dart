import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';
import 'package:safe_device/safe_device.dart';

/// Servicio de seguridad para detectar VPN, GPS falso, root/jailbreak
class SecurityService {
  /// Realiza verificación de seguridad completa
  /// Retorna un mapa con:
  /// - passed: true si pasó todas las verificaciones
  /// - warnings: lista de advertencias detectadas
  /// - severity: 'critical', 'high', 'medium', 'low' o 'none'
  static Future<Map<String, dynamic>> performSecurityCheck(Position position) async {
    List<String> warnings = [];
    String severity = 'none';

    try {
      // 1) Verificar GPS falso (mock location)
      if (position.isMocked) {
        warnings.add('Se detectó ubicación simulada (GPS falso)');
        severity = 'critical';
      }

      // 2) Verificar VPN activa
      if (await _isVpnActive()) {
        warnings.add('Se detectó conexión VPN activa');
        if (severity != 'critical') severity = 'high';
      }

      // 3) Verificar root/jailbreak (solo advertencia)
      if (await _isDeviceRooted()) {
        warnings.add('Dispositivo con acceso root/jailbreak detectado');
        if (severity == 'none') severity = 'medium';
      }

    } catch (e) {
      debugPrint('[SecurityService] Error en verificación de seguridad: $e');
      // En caso de error, permitir continuar pero registrar
    }

    return {
      'passed': warnings.isEmpty,
      'warnings': warnings,
      'severity': severity,
    };
  }

  /// Verifica si hay una VPN activa
  static Future<bool> _isVpnActive() async {
    try {
      // En Android/iOS, verificar interfaces de red VPN
      final interfaces = await NetworkInterface.list();
      for (final interface in interfaces) {
        final name = interface.name.toLowerCase();
        if (name.contains('tun') || 
            name.contains('tap') || 
            name.contains('ppp') ||
            name.contains('vpn') ||
            name.contains('ipsec')) {
          debugPrint('[SecurityService] VPN detectada: ${interface.name}');
          return true;
        }
      }
      return false;
    } catch (e) {
      debugPrint('[SecurityService] Error verificando VPN: $e');
      return false;
    }
  }

  /// Verifica si el dispositivo tiene root/jailbreak
  static Future<bool> _isDeviceRooted() async {
    try {
      // Usar safe_device para verificar
      final isJailBroken = await SafeDevice.isJailBroken;
      final isRealDevice = await SafeDevice.isRealDevice;
      
      if (isJailBroken) {
        debugPrint('[SecurityService] Dispositivo con jailbreak/root detectado');
        return true;
      }
      
      if (!isRealDevice) {
        debugPrint('[SecurityService] Emulador detectado');
        // No bloquear emuladores en desarrollo
        return false;
      }
      
      return false;
    } catch (e) {
      debugPrint('[SecurityService] Error verificando root/jailbreak: $e');
      return false;
    }
  }

  /// Genera mensaje amigable según las advertencias
  static String getWarningMessage(List<String> warnings) {
    if (warnings.isEmpty) return '';
    return warnings.join('\n');
  }
}
