import 'dart:convert';
import 'package:flutter/material.dart';
import 'home_screen.dart';
import 'package:provider/provider.dart';
import '../providers/user_provider.dart';

class MenuPrincipalScreen extends StatelessWidget {
  const MenuPrincipalScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final user = Provider.of<UserProvider>(context).userData;
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    Widget avatarWidget;
    if (user != null && user['fotografia'] != null && user['fotografia'].toString().isNotEmpty) {
      try {
        // Quitar prefijo data:image/xxx;base64, si existe
        String fotoBase64 = user['fotografia'].toString();
        if (fotoBase64.contains(',')) {
          fotoBase64 = fotoBase64.split(',').last;
        }
        final bytes = base64Decode(fotoBase64);
        avatarWidget = Container(
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            boxShadow: [
              BoxShadow(
                color: theme.colorScheme.primary.withOpacity(0.18),
                blurRadius: 18,
                offset: const Offset(0, 8),
              ),
            ],
          ),
          child: CircleAvatar(
            radius: 54,
            backgroundColor: theme.colorScheme.primary,
            child: ClipOval(
              child: Image.memory(
                bytes,
                width: 108,
                height: 108,
                fit: BoxFit.cover, // Ajusta la imagen al círculo sin zoom excesivo
                errorBuilder: (context, error, stackTrace) => Icon(Icons.person, size: 54, color: theme.colorScheme.onPrimary),
              ),
            ),
          ),
        );
      } catch (_) {
        avatarWidget = _defaultAvatar(theme);
      }
    } else {
      avatarWidget = _defaultAvatar(theme);
    }
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              Color(0xFFe0eafc),
              Color(0xFFcfdef3),
              Color(0xFFf9fafc),
            ],
          ),
        ),
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                avatarWidget,
                const SizedBox(height: 24),
                Card(
                  elevation: 2,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 20, horizontal: 16),
                    child: Column(
                      children: [
                        Text('Nombre', style: theme.textTheme.labelMedium),
                        Text('${user?['nombre'] ?? ''}', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
                        const SizedBox(height: 8),
                        Text('Empresa', style: theme.textTheme.labelMedium),
                        Text('${user?['empresa'] ?? ''}', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 32),
                FilledButton.icon(
                  icon: const Icon(Icons.fingerprint),
                  label: const Text('Registrar remotamente'),
                  onPressed: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (context) => const HomeScreen(),
                      ),
                    );
                  },
                  style: FilledButton.styleFrom(
                    minimumSize: const Size.fromHeight(50),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                ),
                const SizedBox(height: 16),
                // Botón de cerrar sesión oculto por defecto
                if (false) // Cambia a true para mostrarlo en el futuro
                  OutlinedButton.icon(
                    icon: const Icon(Icons.logout),
                    label: const Text('Cerrar sesión'),
                    onPressed: () {
                      Provider.of<UserProvider>(context, listen: false).logout(context);
                    },
                    style: OutlinedButton.styleFrom(
                      minimumSize: const Size.fromHeight(50),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _defaultAvatar(ThemeData theme) {
    return Container(
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        boxShadow: [
          BoxShadow(
            color: theme.colorScheme.primary.withOpacity(0.18),
            blurRadius: 18,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: CircleAvatar(
        radius: 54,
        backgroundColor: theme.colorScheme.primary,
        child: Icon(Icons.person, size: 54, color: theme.colorScheme.onPrimary),
      ),
    );
  }
}

// Animación de carga elegante
class ModernLoader extends StatelessWidget {
  const ModernLoader({super.key});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: SizedBox(
        width: 48,
        height: 48,
        child: CircularProgressIndicator(
          strokeWidth: 6,
          valueColor: AlwaysStoppedAnimation<Color>(Colors.blueAccent),
          backgroundColor: Colors.blue.shade100,
        ),
      ),
    );
  }
}
