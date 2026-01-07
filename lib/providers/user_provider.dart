import 'package:flutter/material.dart';
import '../services/api_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

class UserProvider extends ChangeNotifier {
  String errorMessage = '';
  Map<String, dynamic>? userData;
  bool isLoggedIn = false;

  UserProvider() {
    _loadUser();
  }

  Future<void> _loadUser() async {
    final prefs = await SharedPreferences.getInstance();
    final userString = prefs.getString('userData');
    if (userString != null) {
      userData = Map<String, dynamic>.from(ApiService.decodeUser(userString));
      isLoggedIn = true;
      notifyListeners();
    }
  }

  Future<void> login(String email, String imei, BuildContext context) async {
    errorMessage = '';
    notifyListeners();

    final response = await ApiService.loginNexus(email, imei);

    if (response['estatus'] == '1') {
      userData = response['detalles'];
      isLoggedIn = true;
      // Guarda datos localmente (persistencia de sesión)
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('userData', ApiService.encodeUser(userData!));
      notifyListeners();
    } else {
      errorMessage = response['mensaje'] ?? 'Error desconocido';
      notifyListeners();
    }
  }

  Future<void> logout(BuildContext? context) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('userData');
    userData = null;
    isLoggedIn = false;
    notifyListeners();
    if (context != null) {
      Navigator.of(context).pushNamedAndRemoveUntil('/', (route) => false);
    }
  }

  /// Alias de logout para compatibilidad
  Future<void> clearUser() async {
    await logout(null);
  }
}
