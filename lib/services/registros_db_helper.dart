import 'package:sqflite/sqflite.dart';
import 'package:path/path.dart';
import 'package:flutter/foundation.dart';

class RegistrosDbHelper {
  static final RegistrosDbHelper _instance = RegistrosDbHelper._internal();
  factory RegistrosDbHelper() => _instance;
  RegistrosDbHelper._internal();

  Database? _db;

  Future<Database> get db async {
    if (_db != null) return _db!;
    _db = await _initDb();
    return _db!;
  }

  Future<Database> _initDb() async {
    final dbPath = await getDatabasesPath();
    final path = join(dbPath, 'registros_pendientes.db');
    return await openDatabase(
      path,
      version: 2,
      onCreate: (db, version) async {
        await db.execute('''
          CREATE TABLE registros(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data TEXT
          )
        ''');
        await db.execute('''
          CREATE TABLE historial(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tipo TEXT,
            fecha TEXT,
            hora TEXT,
            ubicacion TEXT,
            dentroGeocerca INTEGER,
            nombreGeocerca TEXT,
            latitud REAL,
            longitud REAL,
            sincronizado INTEGER DEFAULT 0,
            estadoValidacion TEXT DEFAULT 'Validado',
            motivo TEXT,
            createdAt TEXT DEFAULT CURRENT_TIMESTAMP
          )
        ''');
      },
      onUpgrade: (db, oldVersion, newVersion) async {
        if (oldVersion < 2) {
          await db.execute('''
            CREATE TABLE IF NOT EXISTS historial(
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              tipo TEXT,
              fecha TEXT,
              hora TEXT,
              ubicacion TEXT,
              dentroGeocerca INTEGER,
              nombreGeocerca TEXT,
              latitud REAL,
              longitud REAL,
              sincronizado INTEGER DEFAULT 0,
              estadoValidacion TEXT DEFAULT 'Validado',
              motivo TEXT,
              createdAt TEXT DEFAULT CURRENT_TIMESTAMP
            )
          ''');
        }
      },
    );
  }

  Future<void> insertarRegistro(String data) async {
    final database = await db;
    await database.insert('registros', {'data': data});
  }

  Future<List<String>> obtenerRegistros() async {
    final database = await db;
    final res = await database.query('registros');
    return res.map((e) => e['data'] as String).toList();
  }

  Future<void> eliminarRegistro(int id) async {
    final database = await db;
    await database.delete('registros', where: 'id = ?', whereArgs: [id]);
  }

  Future<void> limpiarRegistros() async {
    final database = await db;
    await database.delete('registros');
  }

  /// Inserta un registro en el historial local
  Future<void> insertarHistorial({
    required String tipo,
    required String fecha,
    required String hora,
    String? ubicacion,
    bool dentroGeocerca = true,
    String? nombreGeocerca,
    double? latitud,
    double? longitud,
    bool sincronizado = false,
    String estadoValidacion = 'Validado',
    String? motivo,
  }) async {
    final database = await db;
    await database.insert('historial', {
      'tipo': tipo,
      'fecha': fecha,
      'hora': hora,
      'ubicacion': ubicacion ?? '',
      'dentroGeocerca': dentroGeocerca ? 1 : 0,
      'nombreGeocerca': nombreGeocerca ?? '',
      'latitud': latitud ?? 0.0,
      'longitud': longitud ?? 0.0,
      'sincronizado': sincronizado ? 1 : 0,
      'estadoValidacion': estadoValidacion,
      'motivo': motivo,
      'createdAt': DateTime.now().toIso8601String(),
    });
    debugPrint('Historial insertado: \$tipo - \$fecha \$hora');
  }

  /// Obtiene el historial local con límite opcional
  Future<List<Map<String, dynamic>>> obtenerHistorial({int? limite}) async {
    final database = await db;
    final res = await database.query(
      'historial',
      orderBy: 'createdAt DESC',
      limit: limite,
    );
    return res.map((e) => {
      'id': e['id'],
      'tipo': e['tipo'],
      'fecha': e['fecha'],
      'hora': e['hora'],
      'ubicacion': e['ubicacion'],
      'dentroGeocerca': e['dentroGeocerca'] == 1,
      'nombreGeocerca': e['nombreGeocerca'],
      'latitud': e['latitud'],
      'longitud': e['longitud'],
      'sincronizado': e['sincronizado'] == 1,
      'estadoValidacion': e['estadoValidacion'] ?? 'Validado',
      'motivo': e['motivo'],
    }).toList();
  }

  /// Marca un registro del historial como sincronizado
  Future<void> marcarSincronizado(int id) async {
    final database = await db;
    await database.update(
      'historial',
      {'sincronizado': 1},
      where: 'id = ?',
      whereArgs: [id],
    );
  }
}
