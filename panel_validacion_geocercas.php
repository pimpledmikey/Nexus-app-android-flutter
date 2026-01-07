<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Validación - Registros Fuera de Geocerca</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .filters {
            padding: 20px 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .stats {
            padding: 20px 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .stat-card {
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            color: white;
        }
        
        .stat-card.pendiente {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .stat-card.revision {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .stat-card.validado {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .stat-card.rechazado {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .stat-card h3 {
            font-size: 32px;
            margin-bottom: 5px;
        }
        
        .stat-card p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .table-container {
            padding: 30px;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f8f9fa;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            font-size: 13px;
            text-transform: uppercase;
            border-bottom: 2px solid #dee2e6;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #f1f3f5;
            font-size: 14px;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-pendiente {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-revision {
            background: #cfe2ff;
            color: #084298;
        }
        
        .badge-validado {
            background: #d1e7dd;
            color: #0f5132;
        }
        
        .badge-rechazado {
            background: #f8d7da;
            color: #842029;
        }
        
        .badge-fuera {
            background: #f8d7da;
            color: #842029;
        }
        
        .badge-dentro {
            background: #d1e7dd;
            color: #0f5132;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            margin: 0 3px;
        }
        
        .btn-success {
            background: #198754;
            color: white;
        }
        
        .btn-success:hover {
            background: #157347;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #bb2d3b;
        }
        
        .btn-info {
            background: #0dcaf0;
            color: white;
        }
        
        .btn-info:hover {
            background: #0aa2c0;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state svg {
            width: 100px;
            height: 100px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .modal-header h2 {
            color: #212529;
            font-size: 24px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 14px;
            resize: vertical;
            min-height: 100px;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5c636a;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .info-item {
            padding: 10px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        
        .info-item label {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            display: block;
            margin-bottom: 5px;
        }
        
        .info-item value {
            font-size: 14px;
            color: #212529;
            font-weight: 600;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 10px 5px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗺️ Panel de Validación de Geocercas</h1>
            <p>Gestión de registros de asistencia fuera de geocerca</p>
        </div>
        
        <div class="filters">
            <div class="filter-group">
                <label>Estado</label>
                <select id="filtroEstado">
                    <option value="Pendiente">Pendiente</option>
                    <option value="En_Revision">En Revisión</option>
                    <option value="todos">Todos</option>
                    <option value="Validado">Validado</option>
                    <option value="Rechazado">Rechazado</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Fecha Inicio</label>
                <input type="date" id="fechaInicio">
            </div>
            
            <div class="filter-group">
                <label>Fecha Fin</label>
                <input type="date" id="fechaFin">
            </div>
            
            <div class="filter-group" style="align-self: flex-end;">
                <button class="btn btn-primary" onclick="cargarRegistros()">
                    🔍 Buscar
                </button>
            </div>
            
            <div class="filter-group" style="align-self: flex-end; margin-left: auto;">
                <button class="btn btn-primary" onclick="cargarRegistros()">
                    🔄 Actualizar
                </button>
            </div>
        </div>
        
        <div class="stats" id="estadisticas">
            <div class="stat-card pendiente">
                <h3 id="statPendiente">-</h3>
                <p>Pendientes</p>
            </div>
            <div class="stat-card revision">
                <h3 id="statRevision">-</h3>
                <p>En Revisión</p>
            </div>
            <div class="stat-card validado">
                <h3 id="statValidado">-</h3>
                <p>Validados</p>
            </div>
            <div class="stat-card rechazado">
                <h3 id="statRechazado">-</h3>
                <p>Rechazados</p>
            </div>
        </div>
        
        <div class="table-container">
            <div id="loading" class="loading" style="display: none;">
                <p>Cargando registros...</p>
            </div>
            
            <div id="content"></div>
        </div>
    </div>
    
    <!-- Modal de Validación -->
    <div class="modal" id="modalValidacion">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Validar Registro</h2>
            </div>
            
            <div class="info-grid" id="infoRegistro"></div>
            
            <div class="form-group">
                <label>Comentario del Supervisor</label>
                <textarea id="comentarioSupervisor" placeholder="Ingrese su comentario sobre esta validación..."></textarea>
            </div>
            
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
                <button class="btn btn-danger btn-sm" onclick="validarRegistro('rechazar')">❌ Rechazar</button>
                <button class="btn btn-info btn-sm" onclick="validarRegistro('revisar')">👁️ Marcar en Revisión</button>
                <button class="btn btn-success btn-sm" onclick="validarRegistro('validar')">✅ Validar</button>
            </div>
        </div>
    </div>
    
    <script>
        // Configuración
        const API_URL = 'https://dev.bsys.mx/scriptcase/app/Gilneas/ws_nexus_geo/ws_nexus_geo.php';
        const USUARIO_ACTUAL = '<?php echo isset($_SESSION['usuario']) ? $_SESSION['usuario'] : 'Supervisor'; ?>';
        
        let registroSeleccionado = null;
        
        // Inicializar fechas
        document.getElementById('fechaInicio').valueAsDate = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000);
        document.getElementById('fechaFin').valueAsDate = new Date();
        
        // Cargar registros al inicio
        window.onload = () => {
            cargarRegistros();
        };
        
        async function cargarRegistros() {
            const estado = document.getElementById('filtroEstado').value;
            const fechaInicio = document.getElementById('fechaInicio').value;
            const fechaFin = document.getElementById('fechaFin').value;
            
            document.getElementById('loading').style.display = 'block';
            document.getElementById('content').innerHTML = '';
            
            try {
                const url = `${API_URL}?fn=ListarRegistrosPendientes&estado=${estado}&fechaInicio=${fechaInicio}&fechaFin=${fechaFin}`;
                const response = await fetch(url);
                const data = await response.json();
                
                document.getElementById('loading').style.display = 'none';
                
                if (data.estatus === '1') {
                    mostrarRegistros(data.registros);
                    actualizarEstadisticas(data.registros);
                } else {
                    document.getElementById('content').innerHTML = '<div class="empty-state"><p>Error al cargar registros</p></div>';
                }
            } catch (error) {
                document.getElementById('loading').style.display = 'none';
                console.error('Error:', error);
                document.getElementById('content').innerHTML = '<div class="empty-state"><p>Error de conexión</p></div>';
            }
        }
        
        function mostrarRegistros(registros) {
            if (registros.length === 0) {
                document.getElementById('content').innerHTML = `
                    <div class="empty-state">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3>No hay registros</h3>
                        <p>No se encontraron registros con los filtros seleccionados</p>
                    </div>
                `;
                return;
            }
            
            let html = `
                <table>
                    <thead>
                        <tr>
                            <th>Fecha/Hora</th>
                            <th>Empleado</th>
                            <th>Empresa</th>
                            <th>Tipo</th>
                            <th>Geocerca</th>
                            <th>Distancia</th>
                            <th>Estado</th>
                            <th>Validación</th>
                            <th>Motivo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            registros.forEach(reg => {
                const fecha = new Date(reg.fechaHora).toLocaleString('es-MX');
                const estadoBadge = obtenerBadgeEstado(reg.estadoValidacion);
                const validacionBadge = reg.validacionGeocerca === 'Fuera' ? 
                    '<span class="badge badge-fuera">Fuera</span>' : 
                    '<span class="badge badge-dentro">Dentro</span>';
                
                html += `
                    <tr>
                        <td>${fecha}</td>
                        <td><strong>${reg.nombreCompleto}</strong><br><small>${reg.departamento}</small></td>
                        <td>${reg.empresa}</td>
                        <td>${reg.tipo}</td>
                        <td>${reg.nombreGeocerca || 'N/A'}</td>
                        <td>${reg.distanciaMetros}m</td>
                        <td>${estadoBadge}</td>
                        <td>${validacionBadge}</td>
                        <td><small>${reg.motivoEmpleado || '-'}</small></td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick='abrirModalValidacion(${JSON.stringify(reg)})'>
                                Ver Detalles
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            document.getElementById('content').innerHTML = html;
        }
        
        function obtenerBadgeEstado(estado) {
            const badges = {
                'Pendiente': '<span class="badge badge-pendiente">⏳ Pendiente</span>',
                'En_Revision': '<span class="badge badge-revision">👁️ En Revisión</span>',
                'Validado': '<span class="badge badge-validado">✅ Validado</span>',
                'Rechazado': '<span class="badge badge-rechazado">❌ Rechazado</span>'
            };
            return badges[estado] || estado;
        }
        
        function actualizarEstadisticas(registros) {
            const stats = {
                Pendiente: 0,
                En_Revision: 0,
                Validado: 0,
                Rechazado: 0
            };
            
            registros.forEach(reg => {
                if (stats.hasOwnProperty(reg.estadoValidacion)) {
                    stats[reg.estadoValidacion]++;
                }
            });
            
            document.getElementById('statPendiente').textContent = stats.Pendiente;
            document.getElementById('statRevision').textContent = stats.En_Revision;
            document.getElementById('statValidado').textContent = stats.Validado;
            document.getElementById('statRechazado').textContent = stats.Rechazado;
        }
        
        function abrirModalValidacion(registro) {
            registroSeleccionado = registro;
            
            const infoHtml = `
                <div class="info-item">
                    <label>Empleado</label>
                    <value>${registro.nombreCompleto}</value>
                </div>
                <div class="info-item">
                    <label>Fecha/Hora</label>
                    <value>${new Date(registro.fechaHora).toLocaleString('es-MX')}</value>
                </div>
                <div class="info-item">
                    <label>Geocerca</label>
                    <value>${registro.nombreGeocerca || 'N/A'}</value>
                </div>
                <div class="info-item">
                    <label>Distancia</label>
                    <value>${registro.distanciaMetros} metros</value>
                </div>
                <div class="info-item">
                    <label>Ubicación</label>
                    <value>${registro.direccion}</value>
                </div>
                <div class="info-item">
                    <label>Estado Actual</label>
                    <value>${registro.estadoValidacion}</value>
                </div>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Motivo del Empleado</label>
                    <value>${registro.motivoEmpleado || 'Sin motivo proporcionado'}</value>
                </div>
                ${registro.validadoPor ? `
                <div class="info-item">
                    <label>Validado Por</label>
                    <value>${registro.validadoPor}</value>
                </div>
                <div class="info-item">
                    <label>Fecha Validación</label>
                    <value>${new Date(registro.fechaValidacion).toLocaleString('es-MX')}</value>
                </div>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Comentario Supervisor</label>
                    <value>${registro.comentarioSupervisor || '-'}</value>
                </div>
                ` : ''}
            `;
            
            document.getElementById('infoRegistro').innerHTML = infoHtml;
            document.getElementById('comentarioSupervisor').value = '';
            document.getElementById('modalValidacion').classList.add('active');
        }
        
        function cerrarModal() {
            document.getElementById('modalValidacion').classList.remove('active');
            registroSeleccionado = null;
        }
        
        async function validarRegistro(accion) {
            if (!registroSeleccionado) return;
            
            const comentario = document.getElementById('comentarioSupervisor').value;
            const salidEnt = registroSeleccionado.salidEnt;
            
            try {
                const url = `${API_URL}?fn=ValidarRegistroGeocerca&salidEnt=${salidEnt}&accion=${accion}&usuario=${encodeURIComponent(USUARIO_ACTUAL)}&comentario=${encodeURIComponent(comentario)}`;
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.estatus === '1') {
                    alert('✅ Registro ' + data.mensaje);
                    cerrarModal();
                    cargarRegistros();
                } else {
                    alert('❌ Error: ' + data.mensaje);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Error de conexión');
            }
        }
        
        // Cerrar modal al hacer clic fuera
        document.getElementById('modalValidacion').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModal();
            }
        });
    </script>
</body>
</html>
