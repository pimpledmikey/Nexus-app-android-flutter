import 'package:flutter/material.dart';
import 'package:flutter_spinkit/flutter_spinkit.dart';
import 'package:provider/provider.dart';
import '../providers/user_provider.dart';
import '../services/registros_db_helper.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';

class HistorialScreen extends StatefulWidget {
  const HistorialScreen({super.key});

  @override
  State<HistorialScreen> createState() => _HistorialScreenState();
}

class _HistorialScreenState extends State<HistorialScreen> {
  List<Map<String, dynamic>> registros = [];
  final ScrollController _scrollController = ScrollController();
  final int _pageSize = 10;
  int _currentPage = 0;
  bool _isLoadingMore = false;
  bool _hasMore = true;
  bool loading = true;
  String? error;
  int? _totalItems; // total suministrado por el backend (si disponible)
  int get _totalPages => _totalItems == null ? 0 : ((_totalItems! + _pageSize - 1) ~/ _pageSize);

  // Intento de paginación remota: parámetros de consulta
  String _serverUrlBase(String empleadoID) =>
      'https://dev.bsys.mx/scriptcase/app/Gilneas/ws_nexus_geo/ws_nexus_geo.php?fn=HistorialRegistros&empleadoID=$empleadoID';

  @override
  void initState() {
    super.initState();
    _cargarHistorial();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scrollController.removeListener(_onScroll);
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (!_scrollController.hasClients) return;
    final threshold = 200.0; // pixels from bottom when we trigger load
    if (_scrollController.position.pixels >= _scrollController.position.maxScrollExtent - threshold) {
      if (!_isLoadingMore && _hasMore) {
        _loadMore();
      }
    }
  }

  void _loadMore() async {
    if (_isLoadingMore || !_hasMore) return;
    setState(() => _isLoadingMore = true);
    await _fetchPage(_currentPage + 1);
    setState(() => _isLoadingMore = false);
  }

  Future<void> _cargarHistorial() async {
    setState(() {
      loading = true;
      error = null;
    });
    // Reiniciar paginación
    registros = [];
    _currentPage = 0;
    _hasMore = true;
    try {
      final userProvider = Provider.of<UserProvider>(context, listen: false);
      final user = userProvider.userData;
      final empleadoID = user?['empleadoID']?.toString();
      if (empleadoID != null && empleadoID.isNotEmpty) {
        await _fetchPage(1);
      } else {
        // Si no hay empleado, no cargamos nada
        setState(() {
          loading = false;
        });
      }
    } catch (e) {
      debugPrint('Error cargando historial: $e');
      setState(() {
        registros = [];
        loading = false;
      });
    }
  }

  Future<void> _fetchPage(int page) async {
    // Intenta paginación remota con parámetros 'page' y 'limit'. Si falla, intenta cargar desde DB en chunk.
    final userProvider = Provider.of<UserProvider>(context, listen: false);
    final user = userProvider.userData;
    final empleadoID = user?['empleadoID']?.toString();
    if (empleadoID == null || empleadoID.isEmpty) return;

    try {
      // Nota: el webservice espera el parámetro 'limite' (español), no 'limit'.
      final url = Uri.parse('${_serverUrlBase(empleadoID)}&page=$page&limite=$_pageSize');
      debugPrint('Paginación: solicitando página $page -> $url');
      final response = await http.get(url).timeout(const Duration(seconds: 10));
        if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['estatus'] == '1' && data['registros'] != null) {
          final fetched = List<Map<String, dynamic>>.from(data['registros']);
          // Si el backend devuelve 'total' lo usamos para calcular páginas
          if (data.containsKey('total')) {
            try {
              _totalItems = int.tryParse(data['total'].toString());
            } catch (_) {
              _totalItems = null;
            }
          }
          if (page == 1) {
            registros = fetched;
          } else {
            registros.addAll(fetched);
          }
          _currentPage = page;
          // Si tenemos total, determinamos hasMore a partir de total; sino usamos el length
          if (_totalItems != null) {
            _hasMore = registros.length < _totalItems!;
          } else {
            _hasMore = fetched.length == _pageSize;
          }
          setState(() {
            loading = false;
          });
          return;
        }
      }
      // Si el servidor no soporta paginación o no devuelve bien, fallback a DB¡
      debugPrint('Servidor no suministró página; usando DB local como fallback.');
    } catch (e) {
      debugPrint('Error paginación remota: $e');
    }

    // Fallback: intentar cargar desde la DB local por bloques
    try {
      final start = (page - 1) * _pageSize;
      // Intentamos pedir con offset si el helper lo soporta
      List<dynamic> historialLocal;
      // Solicitar desde la DB local un bloque hasta `page * pageSize` y slicear.
      final hasta = _pageSize * page;
      historialLocal = await RegistrosDbHelper().obtenerHistorial(limite: hasta);
      if (historialLocal.length > start) {
        historialLocal = historialLocal.sublist(start);
      } else {
        historialLocal = [];
      }

      final converted = historialLocal.map((reg) {
        final estadoVal = reg['estadoValidacion'] ?? 'Validado';
        return {
          'fecha': reg['fecha'],
          'hora': reg['hora'],
          'tipo': reg['tipo'],
          'ubicacion': reg['ubicacion'] ?? reg['nombreGeocerca'] ?? 'Sin ubicación',
          'estado': estadoVal,
          'dentroGeocerca': reg['dentroGeocerca'],
          'nombreGeocerca': reg['nombreGeocerca'],
          'motivo': reg['motivo'] ?? '',
        };
      }).toList();

      if (page == 1) {
        registros = converted;
      } else {
        registros.addAll(converted);
      }
      _currentPage = page;
      _hasMore = converted.length == _pageSize;
      setState(() {
        loading = false;
      });
    } catch (e) {
      debugPrint('Error cargando historial desde DB: $e');
      if (page == 1) {
        setState(() {
          registros = [];
          loading = false;
        });
      }
    }
  }

  Color _getEstadoColor(String estado) {
    switch (estado) {
      case 'Validado':
        return Colors.green;
      case 'Pendiente Validación':
        return Colors.orange;
      case 'Rechazado':
        return Colors.red;
      default:
        return Colors.grey;
    }
  }

  IconData _getEstadoIcon(String estado) {
    switch (estado) {
      case 'Validado':
        return Icons.check_circle;
      case 'Pendiente Validación':
        return Icons.hourglass_top;
      case 'Rechazado':
        return Icons.cancel;
      default:
        return Icons.help_outline;
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final mq = MediaQuery.of(context);
    final double textScale = mq.textScaleFactor.clamp(1.0, 1.15).toDouble();

    return Scaffold(
      backgroundColor: Colors.transparent,
      resizeToAvoidBottomInset: true,
      body: SafeArea(
        bottom: false,
        child: MediaQuery(
          data: mq.copyWith(textScaleFactor: textScale),
          child: Column(
              children: [
                // Header
                Padding(
                  padding: const EdgeInsets.all(20),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: theme.colorScheme.primary.withOpacity(0.1),
                          borderRadius: BorderRadius.circular(14),
                        ),
                        child: Icon(
                          Icons.history_rounded,
                          color: theme.colorScheme.primary,
                          size: 28,
                        ),
                      ),
                      const SizedBox(width: 16),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Historial',
                              style: theme.textTheme.headlineSmall?.copyWith(
                                fontWeight: FontWeight.bold,
                                color: theme.colorScheme.onSurface,
                              ),
                            ),
                            Text(
                              'Tus registros de asistencia',
                              style: theme.textTheme.bodyMedium?.copyWith(
                                color: Colors.grey[600],
                              ),
                            ),
                          ],
                        ),
                      ),
                      IconButton(
                        onPressed: _cargarHistorial,
                        icon: Icon(
                          Icons.refresh_rounded,
                          color: theme.colorScheme.primary,
                        ),
                        tooltip: 'Actualizar',
                      ),
                    ],
                  ),
                ),

                // Contenido
                Expanded(
                  child: loading
                      ? Center(
                          child: SpinKitChasingDots(
                            color: theme.colorScheme.primary,
                            size: 50,
                          ),
                        )
                      : error != null
                          ? Center(
                              child: Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(
                                    Icons.error_outline,
                                    size: 64,
                                    color: Colors.red[300],
                                  ),
                                  const SizedBox(height: 16),
                                  Text(
                                    error!,
                                    style: TextStyle(color: Colors.red[600]),
                                    textAlign: TextAlign.center,
                                  ),
                                  const SizedBox(height: 16),
                                  OutlinedButton.icon(
                                    onPressed: _cargarHistorial,
                                    icon: const Icon(Icons.refresh),
                                    label: const Text('Reintentar'),
                                  ),
                                ],
                              ),
                            )
                          : registros.isEmpty
                              ? Center(
                                  child: Column(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: [
                                      Icon(
                                        Icons.inbox_rounded,
                                        size: 80,
                                        color: Colors.grey[400],
                                      ),
                                      const SizedBox(height: 16),
                                      Text(
                                        'No hay registros',
                                        style: theme.textTheme.titleLarge?.copyWith(
                                          color: Colors.grey[600],
                                        ),
                                      ),
                                      const SizedBox(height: 8),
                                      Text(
                                        'Tus registros de asistencia aparecerán aquí',
                                        style: TextStyle(color: Colors.grey[500]),
                                      ),
                                    ],
                                  ),
                                )
                              : RefreshIndicator(
                                  onRefresh: _cargarHistorial,
                                  child: ListView.builder(
                                    controller: _scrollController,
                                    padding: EdgeInsets.fromLTRB(16, 0, 16, MediaQuery.of(context).viewPadding.bottom + 18 + 80),
                                    itemCount: registros.length + 1, // +1 para el footer de paginación
                                    itemBuilder: (context, index) {
                                      if (index >= registros.length) {
                                        // Footer: loader opcional + controles de paginación
                                        return Padding(
                                          padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewPadding.bottom + 12, top: 12),
                                          child: Column(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              if (_isLoadingMore) ...[
                                                const SizedBox(height: 8),
                                                const SizedBox(
                                                  height: 28,
                                                  width: 28,
                                                  child: CircularProgressIndicator(strokeWidth: 2.5),
                                                ),
                                                const SizedBox(height: 8),
                                              ],
                                              Row(
                                                mainAxisSize: MainAxisSize.min,
                                                mainAxisAlignment: MainAxisAlignment.center,
                                                children: [
                                                  OutlinedButton(
                                                    onPressed: _currentPage > 1 && !_isLoadingMore ? () async { await _fetchPage(_currentPage - 1); } : null,
                                                    child: const Text('Anterior'),
                                                  ),
                                                  const SizedBox(width: 12),
                                                  Container(
                                                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                                                    decoration: BoxDecoration(
                                                      color: Colors.white,
                                                      borderRadius: BorderRadius.circular(20),
                                                      boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 6)],
                                                    ),
                                                    child: Text(
                                                      _totalItems != null ? 'Página ${_currentPage == 0 ? 1 : _currentPage} de $_totalPages' : 'Página ${_currentPage == 0 ? 1 : _currentPage}',
                                                      style: const TextStyle(fontWeight: FontWeight.w600),
                                                    ),
                                                  ),
                                                  const SizedBox(width: 12),
                                                  OutlinedButton(
                                                    onPressed: _hasMore && !_isLoadingMore ? () async { await _fetchPage(_currentPage + 1); } : null,
                                                    child: const Text('Siguiente'),
                                                  ),
                                                ],
                                              ),
                                            ],
                                          ),
                                        );
                                      }
                                      final reg = registros[index];
                                      final esEntrada = reg['tipo'] == 'Entrada';
                                      final estadoValidacion = reg['estado'] ?? 'Validado';
                                      final dentroGeocerca = reg['dentroGeocerca'] == true;
                                      final motivo = reg['motivo'] ?? '';

                                      return Container(
                                        margin: const EdgeInsets.only(bottom: 12),
                                        decoration: BoxDecoration(
                                          color: Colors.white,
                                          borderRadius: BorderRadius.circular(16),
                                          boxShadow: [
                                            BoxShadow(
                                              color: Colors.black.withOpacity(0.05),
                                              blurRadius: 10,
                                              offset: const Offset(0, 4),
                                            ),
                                          ],
                                        ),
                                        child: ListTile(
                                          contentPadding: const EdgeInsets.symmetric(
                                            horizontal: 16,
                                            vertical: 8,
                                          ),
                                          leading: Container(
                                            width: 48,
                                            height: 48,
                                            decoration: BoxDecoration(
                                              color: esEntrada
                                                  ? Colors.green.withOpacity(0.1)
                                                  : Colors.red.withOpacity(0.1),
                                              borderRadius: BorderRadius.circular(12),
                                            ),
                                            child: Icon(
                                              esEntrada ? Icons.login_rounded : Icons.logout_rounded,
                                              color: esEntrada ? Colors.green : Colors.red,
                                            ),
                                          ),
                                          title: Row(
                                            children: [
                                              Flexible(
                                                child: Text(
                                                  reg['tipo'] ?? '',
                                                  style: const TextStyle(
                                                    fontWeight: FontWeight.bold,
                                                    fontSize: 16,
                                                  ),
                                                  overflow: TextOverflow.ellipsis,
                                                ),
                                              ),
                                              const SizedBox(width: 8),
                                              // Estado de validación
                                              Container(
                                                padding: const EdgeInsets.symmetric(
                                                  horizontal: 8,
                                                  vertical: 2,
                                                ),
                                                decoration: BoxDecoration(
                                                  color: _getEstadoColor(estadoValidacion).withOpacity(0.1),
                                                  borderRadius: BorderRadius.circular(8),
                                                ),
                                                child: Row(
                                                  mainAxisSize: MainAxisSize.min,
                                                  children: [
                                                    Icon(
                                                      _getEstadoIcon(estadoValidacion),
                                                      size: 12,
                                                      color: _getEstadoColor(estadoValidacion),
                                                    ),
                                                    const SizedBox(width: 4),
                                                    Text(
                                                      estadoValidacion == 'Pendiente Validación' ? 'Pendiente' : estadoValidacion,
                                                      style: TextStyle(
                                                        fontSize: 11,
                                                        fontWeight: FontWeight.w600,
                                                        color: _getEstadoColor(estadoValidacion),
                                                      ),
                                                    ),
                                                  ],
                                                ),
                                              ),
                                              const SizedBox(width: 4),
                                              // Indicador de geocerca
                                              Container(
                                                padding: const EdgeInsets.symmetric(
                                                  horizontal: 6,
                                                  vertical: 2,
                                                ),
                                                decoration: BoxDecoration(
                                                  color: dentroGeocerca ? Colors.blue.withOpacity(0.1) : Colors.purple.withOpacity(0.1),
                                                  borderRadius: BorderRadius.circular(8),
                                                ),
                                                child: Row(
                                                  mainAxisSize: MainAxisSize.min,
                                                  children: [
                                                    Icon(
                                                      dentroGeocerca ? Icons.location_on : Icons.location_off,
                                                      size: 10,
                                                      color: dentroGeocerca ? Colors.blue[700] : Colors.purple[700],
                                                    ),
                                                    const SizedBox(width: 2),
                                                    Text(
                                                      dentroGeocerca ? 'En zona' : 'Remoto',
                                                      style: TextStyle(
                                                        fontSize: 9,
                                                        fontWeight: FontWeight.w600,
                                                        color: dentroGeocerca ? Colors.blue[700] : Colors.purple[700],
                                                      ),
                                                    ),
                                                  ],
                                                ),
                                              ),
                                            ],
                                          ),
                                          subtitle: Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              const SizedBox(height: 4),
                                              Row(
                                                children: [
                                                  Icon(
                                                    Icons.access_time,
                                                    size: 14,
                                                    color: Colors.grey[600],
                                                  ),
                                                  const SizedBox(width: 4),
                                                  Text(
                                                    '${reg['fecha']} - ${reg['hora']}',
                                                    style: TextStyle(
                                                      color: Colors.grey[600],
                                                      fontSize: 13,
                                                    ),
                                                  ),
                                                ],
                                              ),
                                              const SizedBox(height: 2),
                                              Row(
                                                children: [
                                                  Icon(
                                                    Icons.location_on_outlined,
                                                    size: 14,
                                                    color: Colors.grey[600],
                                                  ),
                                                  const SizedBox(width: 4),
                                                  Expanded(
                                                    child: Text(
                                                      reg['ubicacion'] ?? '',
                                                      style: TextStyle(
                                                        color: Colors.grey[600],
                                                        fontSize: 13,
                                                      ),
                                                      maxLines: 1,
                                                      overflow: TextOverflow.ellipsis,
                                                    ),
                                                  ),
                                                ],
                                              ),
                                              // Mostrar motivo si existe (simplificado)
                                              if (motivo.isNotEmpty)
                                                Padding(
                                                  padding: const EdgeInsets.only(top: 4),
                                                  child: Row(
                                                    children: [
                                                      Icon(
                                                        Icons.comment_outlined,
                                                        size: 12,
                                                        color: Colors.orange[700],
                                                      ),
                                                      const SizedBox(width: 4),
                                                      Text(
                                                        'Ver motivo',
                                                        style: TextStyle(
                                                          fontSize: 11,
                                                          color: Colors.orange[700],
                                                          fontWeight: FontWeight.w500,
                                                        ),
                                                      ),
                                                    ],
                                                  ),
                                                ),
                                            ],
                                          ),
                                          trailing: Icon(
                                            Icons.chevron_right,
                                            color: Colors.grey[400],
                                          ),
                                          onTap: () {
                                            // Mostrar detalles del registro
                                            _mostrarDetalles(context, reg);
                                          },
                                        ),
                                      );
                                    },
                                  ),
                                ),
                ),
              ],
            ),
          ),
        ),
    );
  }

  void _mostrarDetalles(BuildContext context, Map<String, dynamic> reg) {
    final theme = Theme.of(context);
    final esEntrada = reg['tipo'] == 'Entrada';
    final estadoValidacion = reg['estado'] ?? 'Validado';
    final motivo = reg['motivo'] ?? '';
    
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.55,
        minChildSize: 0.3,
        maxChildSize: 0.85,
        builder: (context, scrollController) => SingleChildScrollView(
          controller: scrollController,
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Center(
                  child: Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(
                      color: Colors.grey[300],
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                ),
                const SizedBox(height: 20),
                Center(
                  child: Container(
                    width: 64,
                    height: 64,
                    decoration: BoxDecoration(
                      color: esEntrada
                          ? Colors.green.withOpacity(0.1)
                          : Colors.red.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(16),
                    ),
                    child: Icon(
                      esEntrada ? Icons.login_rounded : Icons.logout_rounded,
                      color: esEntrada ? Colors.green : Colors.red,
                      size: 32,
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                Center(
                  child: Text(
                    reg['tipo'] ?? '',
                    style: theme.textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                Center(
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    decoration: BoxDecoration(
                      color: _getEstadoColor(estadoValidacion).withOpacity(0.1),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          _getEstadoIcon(estadoValidacion),
                          size: 16,
                          color: _getEstadoColor(estadoValidacion),
                        ),
                        const SizedBox(width: 6),
                        Text(
                          estadoValidacion,
                          style: TextStyle(
                            fontWeight: FontWeight.w600,
                            color: _getEstadoColor(estadoValidacion),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 24),
                _buildDetalleRow(Icons.calendar_today, 'Fecha', reg['fecha'] ?? ''),
                _buildDetalleRow(Icons.access_time, 'Hora', reg['hora'] ?? ''),
                _buildDetalleRow(Icons.location_on, 'Ubicación', reg['ubicacion'] ?? ''),
                // Mostrar motivo si existe
                if (motivo.isNotEmpty) ...[
                  const Divider(height: 24),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.orange.withOpacity(0.08),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Row(
                          children: [
                            Icon(Icons.comment, size: 18, color: Colors.orange[700]),
                            const SizedBox(width: 8),
                            Flexible(
                              child: Text(
                                'Motivo de registro',
                                style: TextStyle(
                                  fontWeight: FontWeight.w600,
                                  color: Colors.orange[800],
                                ),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        SelectableText(
                          motivo,
                          style: TextStyle(
                            color: Colors.orange[900],
                            fontStyle: FontStyle.italic,
                            fontSize: 13,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: 16),
                // Espacio para evitar overflow con el teclado
                SizedBox(height: MediaQuery.of(context).viewInsets.bottom),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildDetalleRow(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 20, color: Colors.grey[600]),
          const SizedBox(width: 12),
          Flexible(
            child: Text(
              '$label:',
              style: TextStyle(
                color: Colors.grey[600],
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                fontWeight: FontWeight.w600,
              ),
              textAlign: TextAlign.end,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
}
