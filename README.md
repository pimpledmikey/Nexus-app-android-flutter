# Nexus MK 📱

<div align="center">
  <img src="assets/logo_nexus.png" alt="Nexus MK Logo" width="200"/>
  
  ### Sistema de Control de Acceso y Asistencia Empresarial
  
  [![Flutter](https://img.shields.io/badge/Flutter-3.0+-02569B?style=for-the-badge&logo=flutter)](https://flutter.dev)
  [![Dart](https://img.shields.io/badge/Dart-3.0+-0175C2?style=for-the-badge&logo=dart)](https://dart.dev)
  [![iOS](https://img.shields.io/badge/iOS-14+-000000?style=for-the-badge&logo=apple)](https://www.apple.com/ios)
  [![Android](https://img.shields.io/badge/Android-6.0+-3DDC84?style=for-the-badge&logo=android)](https://www.android.com)
</div>

## 📋 Descripción

**Nexus MK** es una aplicación móvil empresarial desarrollada en Flutter para la gestión de control de acceso y registro de asistencia de empleados. La aplicación combina tecnología de escaneo QR, autenticación biométrica, geolocalización y comunicación TCP para ofrecer un sistema completo y seguro.

### ✨ Características Principales

- 🔐 **Autenticación Biométrica**: Face ID / Touch ID / Huella digital
- 📷 **Escaneo QR**: Lectura rápida de códigos QR de empleados
- 📍 **Geolocalización**: Verificación de ubicación en tiempo real
- 🚪 **Control de Puertas**: Integración TCP para apertura automática de puertas
- 🎉 **Celebraciones**: Animaciones especiales para cumpleaños de empleados
- 🎨 **UI Moderna**: Diseño Material 3 con animaciones Lottie
- 🌐 **Modo Offline**: Funcionalidad parcial sin conexión
- 🔔 **Notificaciones Push**: Firebase Cloud Messaging
- 📊 **Reportes**: Historial de asistencia y accesos

## 🚀 Tecnologías Utilizadas

### Core
- **Flutter 3.x** - Framework multiplataforma
- **Dart 3.x** - Lenguaje de programación
- **Provider** - Gestión de estado

### Funcionalidades
- **Mobile Scanner** - Escaneo de códigos QR
- **Local Auth** - Autenticación biométrica
- **Geolocator** - Servicios de geolocalización
- **Firebase** - Cloud Messaging y servicios backend
- **Lottie** - Animaciones vectoriales
- **SQLite** - Base de datos local
- **HTTP** - Comunicación con APIs

### UI/UX
- **Material 3** - Sistema de diseño moderno
- **Google Fonts** - Tipografías personalizadas
- **Animations** - Transiciones fluidas

## 🛠️ Instalación

### Prerrequisitos

- Flutter SDK (3.0 o superior)
- Dart SDK (3.0 o superior)
- Android Studio / Xcode
- Git

### Clonar el Repositorio

```bash
git clone https://github.com/tu-usuario/nexus-mk.git
cd nexus-mk
```

### Instalar Dependencias

```bash
flutter pub get
```

### Configuración

1. **Configurar Firebase**:
   - Descarga `google-services.json` (Android) y `GoogleService-Info.plist` (iOS)
   - Colócalos en las rutas correspondientes

2. **Variables de Entorno**:
   ```bash
   # Edita con tus credenciales de servidor
   API_URL=https://tu-api.com
   TCP_HOST=192.168.1.100
   TCP_PORT=8080
   ```

3. **Permisos iOS**:
   - Activar "Modo de Desarrollador" en el dispositivo iOS
   - Configurar certificados de firma en Xcode

### Ejecutar la Aplicación

#### Android
```bash
flutter run
```

#### iOS
```bash
# Modo Debug (requiere Modo de Desarrollador)
flutter run

# Modo Profile/Release
flutter run --profile
flutter run --release
```

## 📂 Estructura del Proyecto

```
lib/
├── main.dart                 # Punto de entrada
├── models/                   # Modelos de datos
├── providers/                # Gestión de estado (Provider)
├── screens/                  # Pantallas de la aplicación
│   ├── splash_screen.dart
│   ├── login_screen.dart
│   ├── scanner_screen.dart
│   └── employee_details_screen.dart
├── services/                 # Servicios y APIs
│   ├── tcp_service.dart
│   ├── auth_service.dart
│   └── location_service.dart
└── utils/                    # Utilidades y helpers
    ├── theme.dart
    └── constants.dart
```

## 🔧 Configuración Adicional

### Android

**Package Name**: `com.pimpledmikey.nexus_goll_final`

**Permisos principales**:
- Internet
- Ubicación (Fine & Coarse)
- Cámara
- Autenticación biométrica

### iOS

**Bundle ID**: `com.pimpledmikey.nexus-goll-final`

**Permisos en Info.plist**:
- Ubicación (Always & WhenInUse)
- Cámara
- Face ID

## 🎨 Personalización

### Tema
Edita `lib/utils/theme.dart` para personalizar colores y estilos:

```dart
class NexusTheme {
  static const Color primary = Color(0xFF1E88E5);
  static const Color secondary = Color(0xFF26A69A);
  // ...
}
```

### Assets
- **Iconos**: `assets/icons/`
- **Imágenes**: `assets/images/`
- **Animaciones Lottie**: `assets/lottie/`

## 🚪 Funcionalidad TCP

La aplicación se conecta a un servidor TCP para controlar puertas de acceso automáticamente según la ubicación y tipo de acceso del empleado.

## 📦 Build para Producción

### Android (APK/AAB)
```bash
# APK
flutter build apk --release

# AAB (Google Play)
flutter build appbundle --release
```

### iOS (IPA)
```bash
flutter build ios --release

# Luego en Xcode:
# Product > Archive > Distribute App
```

## 🤝 Contribuir

1. Fork el proyecto
2. Crea una rama para tu feature (`git checkout -b feature/AmazingFeature`)
3. Commit tus cambios (`git commit -m 'Add some AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## 📝 Licencia

Este proyecto es privado y confidencial. Todos los derechos reservados.

## 👥 Equipo

- **Desarrollo**: Miguel Ángel Ávila Requena
- **Organización**: Nexus Team

## 📞 Contacto

- **Email**: pimpledmikey@hotmail.com

## 🙏 Agradecimientos

- Flutter Team por el increíble framework
- Comunidad de desarrolladores Flutter
- Todos los contribuidores del proyecto

---

<div align="center">
  <p>Hecho con ❤️ usando Flutter</p>
  <p>© 2025 Nexus MK. Todos los derechos reservados.</p>
</div>
