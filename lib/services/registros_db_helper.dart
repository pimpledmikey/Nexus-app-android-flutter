import 'package:sqflite/sqflite.dart';
import 'package:path/path.dart';

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
      version: 1,
      onCreate: (db, version) async {
        await db.execute('''
          CREATE TABLE registros(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data TEXT
          )
        ''');
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
}
