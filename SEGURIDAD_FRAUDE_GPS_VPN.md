# 🛡️ SEGURIDAD: Protección contra Fraude en Registros de Asistencia

## 📋 Índice
1. [Vulnerabilidades Actuales](#vulnerabilidades-actuales)
2. [Soluciones Implementadas](#soluciones-implementadas)
3. [Niveles de Seguridad](#niveles-de-seguridad)
4. [Cómo Usar](#cómo-usar)
5. [Recomendaciones Adicionales](#recomendaciones-adicionales)

---

## � Sistema de Seguridad - Detección de Fraude GPS/VPN

## ✅ ESTADO: IMPLEMENTADO Y FUNCIONAL

### Plugin Utilizado
- **safe_device: ^1.3.8** (actualizado, compatible con Dart 3)
- Detección de: GPS falso, root/jailbreak, emuladores, modo desarrollador

### Nivel de Seguridad Implementado: **NIVEL 2 (Recomendado)**

#### Comportamiento:
- ✅ **BLOQUEA**: GPS falso y VPN activa
- ⚠️ **ADVIERTE**: Dispositivos root/jailbreak (permite continuar)
- 📊 **Registra**: Todas las detecciones en logs

---

## 📋 Verificaciones Implementadas

### 1. GPS Falso (Mock Location) ❌ BLOQUEADO
```dart
SafeDevice.isMockLocation  // Detecta apps como Fake GPS
+ precisión GPS > 100m     // Indicador adicional
```
**Acción**: BLOQUEA el registro y muestra diálogo de error

### 2. VPN Activa ❌ BLOQUEADO
```dart
NetworkInterface.list()  // Verifica interfaces tun, ppp, utun, tap, ipsec
```
**Acción**: BLOQUEA el registro y muestra diálogo de error

### 3. Root/Jailbreak ⚠️ ADVERTENCIA
```dart
SafeDevice.isJailBroken  // Detecta dispositivos comprometidos
```
**Acción**: Permite registro pero muestra advertencia en SnackBar

### 4. Emulador ℹ️ INFORMATIVO
```dart
SafeDevice.isRealDevice  // Detecta si corre en emulador
```
**Acción**: Solo log, no afecta registro (útil para desarrollo)

### 5. Modo Desarrollador (Android) ℹ️ INFORMATIVO
```dart
SafeDevice.isDevelopmentModeEnable
```
**Acción**: Solo log, no afecta registro

---

## 🎯 Flujo de Verificación

```
Usuario presiona "Enviar registro"
    ↓
🔒 SecurityService.performSecurityCheck(position)
    ↓
┌─────────────────────────────────────┐
│ Verificaciones en Paralelo:         │
│ • isLocationMocked()                │
│ • isVpnActive()                     │
│ • isDeviceCompromised()             │
│ • verifyTimestamp() (TODO)          │
└─────────────────────────────────────┘
    ↓
Resultado: {passed, checks, warnings, severity}
    ↓
┌─────────────────────────────────────┐
│ severity == 'critical' o 'high'?    │
│ (GPS falso o VPN)                   │
└─────────────────────────────────────┘
    ↓ SI
❌ BLOQUEAR: Mostrar diálogo y return
    ↓ NO
┌─────────────────────────────────────┐
│ severity == 'medium'?               │
│ (Root/jailbreak)                    │
└─────────────────────────────────────┘
    ↓ SI
⚠️ ADVERTIR: SnackBar naranja
    ↓
✅ CONTINUAR: Flujo normal de registro
```

---

## 📂 Archivos Modificados

### 1. `lib/services/security_service.dart` ✅ CREADO
- Métodos de detección usando `safe_device`
- `performSecurityCheck()` como función principal
- Logs detallados con emojis para fácil debugging

### 2. `lib/screens/registro_remoto_screen.dart` ✅ MODIFICADO
- Import de `SecurityService`
- Verificación antes de enviar registro
- Diálogo de bloqueo personalizado
- SnackBar de advertencia

### 3. `pubspec.yaml` ✅ MODIFICADO
```yaml
safe_device: ^1.3.8  # Detección de seguridad: root, GPS falso, emulador
```

---

## 🚀 Cómo Probar

### Probar GPS Falso:
1. Instalar app "Fake GPS Location" en Android
2. Activar opciones de desarrollador
3. Seleccionar app de ubicación falsa
4. Intentar registrar → **Debe bloquear**

### Probar VPN:
1. Conectar cualquier VPN (ProtonVPN, NordVPN, etc.)
2. Intentar registrar → **Debe bloquear**

### Probar Root:
1. Dispositivo rooteado/jailbreak
2. Intentar registrar → **Debe advertir pero permitir**

---

## 📊 Logs de Seguridad

Los logs aparecen en consola con formato visual:

```
🔒 Iniciando verificación de seguridad...
⚠️ SEGURIDAD: GPS falso detectado (mock location habilitado)
⚠️ SEGURIDAD: VPN activa detectada - Interface: tun0
🔒 Resultado seguridad: ❌ RECHAZADO
⚠️ Advertencias: [GPS falso detectado, VPN activa detectada]
```

---

## 🔧 Configuración de Niveles de Seguridad

### Cambiar a Nivel 1 (Solo Advertir):
```dart
// En _enviarRegistroRemoto, reemplazar:
if (!securityPassed && (severity == 'critical' || severity == 'high')) {
  // ... bloqueo
}

// Por:
if (!securityPassed) {
  // Mostrar advertencia pero permitir
  ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(content: Text('⚠️ ${warnings.join(", ")}')),
  );
}
```

### Cambiar a Nivel 3 (Bloquear Todo):
```dart
// Bloquear CUALQUIER fallo de seguridad
if (!securityPassed) {
  // ... mostrar diálogo y bloquear
  return;
}
```

---

## ⏭️ Mejoras Futuras (Opcionales)

### 1. Verificación de Timestamp con Servidor
```dart
static Future<Map<String, dynamic>> verifyTimestamp() async {
  // Endpoint en WS_Nexus.php que retorne timestamp del servidor
  final serverTime = await ApiService.getServerTime();
  final deviceTime = DateTime.now();
  final diff = deviceTime.difference(serverTime).inMinutes.abs();
  
  return {
    'isValid': diff <= 5, // Max 5 min diferencia
    'deviceTime': deviceTime.toIso8601String(),
    'serverTime': serverTime.toIso8601String(),
    'differenceSeconds': diff * 60,
  };
}
```

### 2. Almacenar Flags de Seguridad en BD
```sql
ALTER TABLE tb_entrada_salida 
ADD COLUMN securityFlags VARCHAR(500) NULL,
ADD COLUMN securitySeverity ENUM('none','low','medium','high','critical') DEFAULT 'none';
```

### 3. Panel de Validación con Alertas de Seguridad
- Mostrar registros con advertencias de seguridad
- Filtrar por severity
- Dashboard con métricas de intentos bloqueados

---

## 📝 Notas Importantes

### Limitaciones de `safe_device`:
- ❌ **NO detecta VPN directamente** (usamos método manual con NetworkInterface)
- ✅ Detecta GPS falso en Android (requiere permisos de ubicación)
- ✅ Detecta root/jailbreak con múltiples métodos
- ✅ Compatible con iOS y Android

### Alternativas Consideradas:
- `trust_fall`: **DESCARTADO** (obsoleto, 6 años sin actualizar, incompatible Dart 3)
- `flutter_jailbreak_detection`: Solo root/jailbreak, no GPS/VPN
- `geolocator`: Solo mock location en Android

### Por qué `safe_device` es la mejor opción:
- ✅ Actualizado recientemente (25 días)
- ✅ 347 likes, 115k downloads
- ✅ Detección múltiple (root + GPS + emulador + dev mode)
- ✅ Configurable (puede desactivar checks específicos)
- ✅ Compatible Dart 3 / Flutter 3.x

---

## 🎉 Resultado Final

El sistema ahora:
1. ✅ **Protege** contra GPS falso y VPN
2. ✅ **Advierte** sobre dispositivos root/jailbreak
3. ✅ **Registra** todos los intentos en logs
4. ✅ **UX clara** con diálogos informativos
5. ✅ **Configurable** (cambiar nivel fácilmente)
6. ✅ **Testeado** y listo para producción

**Estado**: LISTO PARA USAR 🚀

### **Problema 1: GPS Falso**
**Riesgo:** Empleado usa apps como "Fake GPS" para falsificar ubicación
- ❌ Registra entrada "dentro de zona" estando físicamente lejos
- ❌ No hay validación de integridad del GPS
- ❌ Fácil de hacer con apps gratuitas

### **Problema 2: VPN**
**Riesgo:** Empleado usa VPN para ocultar ubicación real
- ❌ Puede simular estar en otro país/ciudad
- ❌ IP del servidor no refleja ubicación física real
- ❌ Apps VPN muy comunes (NordVPN, ExpressVPN, etc.)

### **Problema 3: Dispositivos Comprometidos**
**Riesgo:** Dispositivos rooteados/jailbreak permiten mayor manipulación
- ❌ Pueden modificar comportamiento del GPS
- ❌ Pueden bypassear verificaciones de la app
- ❌ Pueden modificar hora del sistema

### **Problema 4: Manipulación de Timestamp**
**Riesgo:** Empleado cambia hora del dispositivo
- ❌ Puede registrar entrada/salida en horarios falsos
- ❌ No hay sincronización con servidor

---

## ✅ Soluciones Implementadas

### **Nivel 1: Detección Básica (Ya implementado)**

#### 📍 **1. Detección de GPS Falso**
```dart
// Verifica precisión GPS
if (position.accuracy > 100) {
  // GPS probablemente falso - precisión muy baja
  return true;
}
```

**Cómo funciona:**
- GPS real típicamente tiene precisión < 20m
- GPS falso suele tener precisión > 100m
- Marca como sospechoso para revisión manual

#### 🌐 **2. Detección de VPN**
```dart
// Verifica interfaces de red
for (var interface in interfaces) {
  if (name.contains('tun') || name.contains('ppp')) {
    // VPN detectada
    return true;
  }
}
```

**Interfaces VPN comunes:**
- `tun0`, `tun1` - OpenVPN, WireGuard
- `ppp0` - PPTP VPN
- `utun` - iOS VPN
- `tap` - TAP VPN

#### 🔓 **3. Detección de Root/Jailbreak**
```dart
// Android
final rootFiles = [
  '/system/app/Superuser.apk',
  '/sbin/su',
  '/system/bin/su',
  ...
];

// iOS
final jailbreakFiles = [
  '/Applications/Cydia.app',
  '/Library/MobileSubstrate/MobileSubstrate.dylib',
  ...
];
```

#### ⏰ **4. Validación de Timestamp**
```dart
// Compara hora dispositivo vs servidor
final deviceTime = DateTime.now();
// Obtener serverTime desde backend
// Si diferencia > 5 minutos → sospechoso
```

---

## 🔐 Niveles de Seguridad

### **Nivel 1: Advertencias (Implementado)**
- ✅ Detecta amenazas pero **permite registro**
- ✅ Marca registro como "REQUIERE REVISIÓN"
- ✅ Supervisor revisa manualmente en panel web

### **Nivel 2: Bloqueo Parcial (Recomendado)**
- ⚠️ **VPN activa** → Bloquear registro
- ⚠️ **GPS falso** → Bloquear registro
- ✅ **Root/Jailbreak** → Advertencia pero permitir
- ✅ **Timestamp** → Sincronizar con servidor

### **Nivel 3: Bloqueo Total (Máxima seguridad)**
- ❌ Cualquier amenaza detectada → Bloquear completamente
- ❌ Requiere dispositivo limpio y sin modificaciones
- ⚠️ **Advertencia:** Puede afectar usuarios legítimos

---

## 🚀 Cómo Usar

### **1. Integrar en registro_remoto_screen.dart**

```dart
import '../services/security_service.dart';

Future<void> _enviarRegistroRemoto(Map<String, dynamic> datos) async {
  // PASO 1: Verificación de seguridad
  final securityCheck = await SecurityService.performSecurityCheck(position!);
  
  // PASO 2: Evaluar resultado
  if (!securityCheck['passed']) {
    final warnings = securityCheck['warnings'] as List<String>;
    final severity = securityCheck['severity'];
    
    // NIVEL 1: Solo advertir (actual)
    if (severity == 'high' || severity == 'critical') {
      // Mostrar diálogo de advertencia
      final continuar = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Row(
            children: [
              Icon(Icons.warning, color: Colors.red),
              SizedBox(width: 8),
              Text('Alerta de Seguridad'),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('Se detectaron las siguientes anomalías:'),
              SizedBox(height: 8),
              ...warnings.map((w) => Text('• $w')),
              SizedBox(height: 16),
              Text(
                'Tu registro será marcado para revisión.',
                style: TextStyle(fontWeight: FontWeight.bold, color: Colors.orange),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text('Cancelar'),
            ),
            ElevatedButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text('Continuar de todos modos'),
              style: ElevatedButton.styleFrom(backgroundColor: Colors.orange),
            ),
          ],
        ),
      );
      
      if (continuar != true) return;
    }
    
    // NIVEL 2: Bloquear (recomendado para producción)
    /*
    if (severity == 'critical') {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('❌ Registro bloqueado por seguridad: ${warnings.join(", ")}'),
          backgroundColor: Colors.red,
          duration: Duration(seconds: 10),
        ),
      );
      return; // Bloquear completamente
    }
    */
  }
  
  // PASO 3: Agregar datos de seguridad al registro
  datos['securityCheck'] = securityCheck;
  datos['securityPassed'] = securityCheck['passed'];
  datos['securityWarnings'] = (securityCheck['warnings'] as List).join(', ');
  
  // PASO 4: Continuar con registro normal...
  final resp = await ApiService.registroRemoto(...);
}
```

### **2. Modificar Backend (WS_Nexus.php)**

```php
// En RegistroRemoto case, agregar:
$securityPassed = isset($_GET['securityPassed']) ? $_GET['securityPassed'] : 'true';
$securityWarnings = isset($_GET['securityWarnings']) ? $_GET['securityWarnings'] : '';

// Si NO pasó validación de seguridad, marcar como sospechoso
if ($securityPassed === 'false') {
    $estadoValidacion = 'Pendiente';
    
    // Agregar advertencias de seguridad al comentario
    if (!empty($comentario)) {
        $comentario .= ' | SEGURIDAD: ' . $securityWarnings;
    } else {
        $comentario = 'SEGURIDAD: ' . $securityWarnings;
    }
    
    error_log("⚠️ REGISTRO SOSPECHOSO - Empleado: $empleadoF, Alertas: $securityWarnings");
}
```

### **3. Agregar Columna a Base de Datos (Opcional)**

```sql
-- Agregar columna para tracking de seguridad
ALTER TABLE tb_entrada_salida 
ADD COLUMN securityFlags VARCHAR(500) DEFAULT NULL COMMENT 'Alertas de seguridad detectadas',
ADD COLUMN securitySeverity ENUM('none', 'low', 'medium', 'high', 'critical') DEFAULT 'none';

-- Index para búsquedas rápidas de registros sospechosos
CREATE INDEX idx_security ON tb_entrada_salida(securitySeverity, estadoValidacionGeocerca);
```

---

## 📊 Panel de Supervisión

### **Agregar en panel_validacion_geocercas.php**

```php
// Filtro adicional para registros con alertas de seguridad
$filtro_seguridad = isset($_POST['filtroSeguridad']) ? $_POST['filtroSeguridad'] : 'todos';

$where_conditions[] = "
    CASE 
        WHEN securitySeverity IN ('high', 'critical') THEN 1
        ELSE 0
    END = " . ($filtro_seguridad == 'sospechosos' ? '1' : '0');

// En la tabla HTML, mostrar badge de seguridad
if ($registro['securitySeverity'] == 'critical') {
    echo '<span class="badge badge-danger">🚨 ALERTA CRÍTICA</span>';
} elseif ($registro['securitySeverity'] == 'high') {
    echo '<span class="badge badge-warning">⚠️ SOSPECHOSO</span>';
}

// Mostrar detalles de seguridad
echo '<small class="text-muted">' . $registro['securityFlags'] . '</small>';
```

---

## 🎯 Recomendaciones Adicionales

### **1. Plugins Nativos Recomendados**

```yaml
dependencies:
  # Detección avanzada de seguridad
  flutter_jailbreak_detection: ^1.10.0  # Detecta root/jailbreak
  safe_device: ^1.1.5  # Suite completa de seguridad
  trust_fall: ^3.0.0  # Detección de mock location nativa
```

### **2. Verificación de Integridad App**

```dart
// Evitar que la app corra en emulador
import 'package:device_info_plus/device_info_plus.dart';

Future<bool> isRunningOnEmulator() async {
  final deviceInfo = DeviceInfoPlugin();
  
  if (Platform.isAndroid) {
    final info = await deviceInfo.androidInfo;
    return !info.isPhysicalDevice;
  }
  
  if (Platform.isIOS) {
    final info = await deviceInfo.iosInfo;
    return !info.isPhysicalDevice;
  }
  
  return false;
}
```

### **3. Geofencing Nativo (Android/iOS)**

```dart
// Usar geofencing nativo del sistema operativo
// Más difícil de falsificar que coordenadas GPS
import 'package:geofence_service/geofence_service.dart';

final geofenceService = GeofenceService.instance.setup(
  interval: 5000,
  accuracy: 100,
  loiteringDelayMs: 60000,
  statusChangeDelayMs: 10000,
  allowMockLocations: false, // ← CRÍTICO
);
```

### **4. Foto con Timestamp y Ubicación**

```dart
// Tomar foto con metadata de ubicación y hora
import 'package:camera/camera.dart';

// Al registrar entrada, tomar foto automática
// Metadata incluye: GPS coords, timestamp, EXIF data
// Más difícil de falsificar que solo coordenadas
```

### **5. WiFi/Bluetooth Nearby**

```dart
// Verificar redes WiFi cercanas
// Si siempre registra desde la "misma" red pero en ubicaciones diferentes = sospechoso
import 'package:network_info_plus/network_info_plus.dart';

final wifiName = await NetworkInfo().getWifiName();
final wifiBSSID = await NetworkInfo().getWifiBSSID();

// Guardar en registro para correlación
```

---

## 🔥 Casos de Uso Reales

### **Escenario 1: Empleado con GPS Falso**
```
1. Empleado instala "Fake GPS Location"
2. Establece ubicación falsa en zona de trabajo
3. Intenta registrar entrada
4. App detecta: accuracy = 500m (muy alta)
5. Marca registro como "SOSPECHOSO"
6. Supervisor ve alerta en panel web
7. Solicita explicación al empleado
```

### **Escenario 2: Empleado con VPN**
```
1. Empleado activa NordVPN
2. Intenta registrar asistencia
3. App detecta: interface "tun0" activa
4. Bloquea registro con mensaje:
   "❌ VPN detectada. Desactiva la VPN para registrar."
5. Empleado debe desactivar VPN para continuar
```

### **Escenario 3: Dispositivo Rooteado**
```
1. Empleado usa teléfono rooteado
2. Intenta registrar
3. App detecta: archivo /system/bin/su existe
4. Advierte: "Dispositivo modificado detectado"
5. Permite registro pero marca como "REVISIÓN REQUERIDA"
6. Almacena flag en base de datos
```

---

## 📈 Métricas de Seguridad

### **Dashboard de Alertas (Agregar a panel web)**

```sql
-- Registros con alertas de seguridad por día
SELECT 
    DATE(fechaH) as fecha,
    securitySeverity,
    COUNT(*) as total
FROM tb_entrada_salida 
WHERE securitySeverity IN ('high', 'critical')
GROUP BY DATE(fechaH), securitySeverity
ORDER BY fecha DESC;

-- Empleados con más alertas de seguridad
SELECT 
    empleadoID,
    COUNT(*) as total_alertas,
    GROUP_CONCAT(DISTINCT securityFlags) as tipos_alerta
FROM tb_entrada_salida
WHERE securitySeverity IN ('high', 'critical')
GROUP BY empleadoID
ORDER BY total_alertas DESC
LIMIT 10;
```

---

## ⚖️ Balance Seguridad vs Usabilidad

| Nivel | Seguridad | Usabilidad | Recomendado Para |
|-------|-----------|------------|------------------|
| **Nivel 1** (Actual) | ⭐⭐ | ⭐⭐⭐⭐⭐ | Empresas pequeñas con confianza |
| **Nivel 2** (Recomendado) | ⭐⭐⭐⭐ | ⭐⭐⭐ | Mayoría de empresas |
| **Nivel 3** (Máximo) | ⭐⭐⭐⭐⭐ | ⭐⭐ | Empresas con historial de fraude |

---

## 🎓 Conclusión

**NO es posible prevenir 100% del fraude**, pero estas medidas:

✅ **Aumentan el esfuerzo** requerido para engañar al sistema  
✅ **Detectan intentos obvios** de manipulación  
✅ **Generan evidencia** para auditorías  
✅ **Disuaden** a empleados de intentar fraude  

**Recomendación:** Implementar **Nivel 2** (bloqueo de VPN/GPS falso) + auditorías periódicas + cultura de confianza.

---

## 📞 Soporte

Para implementar estas mejoras de seguridad, contacta al equipo de desarrollo.
