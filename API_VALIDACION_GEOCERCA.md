# 📍 API de Validación de Registros Fuera de Geocerca

## Descripción General

Sistema de validación para registros de asistencia realizados **fuera de las geocercas asignadas**. Permite un flujo de aprobación supervisado con estados rastreables.

---

## 🔄 Flujo de Estados

```
┌─────────────────────────────────────────────────────────────┐
│                    FLUJO DE VALIDACIÓN                       │
└─────────────────────────────────────────────────────────────┘

1️⃣  No_Requiere
    ↓
    └─→ Registro dentro de geocerca o sin geocerca asignada
        (No necesita validación)

2️⃣  Pendiente
    ↓
    └─→ Registro FUERA de geocerca + motivo proporcionado
        (Requiere atención del supervisor)

3️⃣  En_Revision
    ↓
    └─→ Supervisor vio el registro pero aún no decide
        (Opcional: permite marcar como "visto")

4️⃣  Validado / Rechazado
    ↓
    └─→ Supervisor tomó decisión final
        (Proceso completado)
```

---

## 📊 Campos Nuevos en `tb_entrada_salida`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `estadoValidacionGeocerca` | ENUM | Estado actual: No_Requiere, Pendiente, En_Revision, Validado, Rechazado |
| `validadoPor` | VARCHAR(100) | Usuario/Supervisor que validó |
| `fechaValidacion` | DATETIME | Cuándo se validó/rechazó |
| `comentarioValidacion` | VARCHAR(500) | Comentario del supervisor |

---

## 🚀 Endpoints Disponibles

### 1️⃣ Listar Registros Pendientes de Validación

**URL:** `ws_nexus_geo.php?fn=ListarRegistrosPendientes`

**Método:** GET

**Parámetros opcionales:**
```
empresaID      - Filtrar por empresa (int)
fechaInicio    - Fecha inicio (YYYY-MM-DD) - Default: hace 30 días
fechaFin       - Fecha fin (YYYY-MM-DD) - Default: hoy
estado         - Filtrar por estado específico o 'todos'
               (Pendiente, En_Revision, Validado, Rechazado, todos)
```

**Ejemplo de uso:**
```bash
# Listar todos los pendientes de los últimos 30 días
GET /ws_nexus_geo.php?fn=ListarRegistrosPendientes

# Filtrar por empresa específica
GET /ws_nexus_geo.php?fn=ListarRegistrosPendientes&empresaID=1

# Ver solo los que están en revisión
GET /ws_nexus_geo.php?fn=ListarRegistrosPendientes&estado=En_Revision

# Rango de fechas personalizado
GET /ws_nexus_geo.php?fn=ListarRegistrosPendientes&fechaInicio=2025-11-01&fechaFin=2025-11-07

# Ver TODOS los estados (incluyendo validados y rechazados)
GET /ws_nexus_geo.php?fn=ListarRegistrosPendientes&estado=todos
```

**Respuesta exitosa:**
```json
{
  "estatus": "1",
  "total": 3,
  "registros": [
    {
      "salidEnt": 12345,
      "empleadoID": 1531,
      "nombreCompleto": "Juan Pérez García",
      "empresa": "DRT",
      "departamento": "Sistemas",
      "fechaHora": "2025-11-07 13:26:32",
      "tipo": "Entrada",
      "validacionGeocerca": "Fuera",
      "geocercaID": 7,
      "nombreGeocerca": "Planta Principal",
      "distanciaMetros": 37,
      "motivoEmpleado": "Tráfico en la autopista",
      "estadoValidacion": "Pendiente",
      "validadoPor": null,
      "fechaValidacion": null,
      "comentarioSupervisor": null,
      "direccion": "Ciprés LB, Santiago de Querétaro",
      "latitud": "20.6505978",
      "longitud": "-100.4335463"
    }
  ]
}
```

---

### 2️⃣ Validar/Rechazar Registro

**URL:** `ws_nexus_geo.php?fn=ValidarRegistroGeocerca`

**Método:** GET

**Parámetros requeridos:**
```
salidEnt   - ID del registro a validar (int) ✅ REQUERIDO
accion     - Acción a realizar ✅ REQUERIDO
             Valores: 'revisar', 'validar', 'rechazar'
```

**Parámetros opcionales:**
```
usuario     - Nombre del supervisor que valida (string)
              Default: 'Sistema'
comentario  - Comentario del supervisor (string)
```

**Ejemplos de uso:**

#### a) Marcar como "En Revisión"
```bash
GET /ws_nexus_geo.php?fn=ValidarRegistroGeocerca
    &salidEnt=12345
    &accion=revisar
    &usuario=Maria_Lopez
```

#### b) Validar (Aprobar)
```bash
GET /ws_nexus_geo.php?fn=ValidarRegistroGeocerca
    &salidEnt=12345
    &accion=validar
    &usuario=Carlos_Supervisor
    &comentario=Motivo+justificado+por+tráfico+verificado
```

#### c) Rechazar
```bash
GET /ws_nexus_geo.php?fn=ValidarRegistroGeocerca
    &salidEnt=12345
    &accion=rechazar
    &usuario=Ana_RH
    &comentario=Motivo+no+válido+según+política+de+asistencia
```

**Respuesta exitosa:**
```json
{
  "estatus": "1",
  "mensaje": "Registro validado exitosamente",
  "salidEnt": 12345,
  "nuevoEstado": "Validado",
  "validadoPor": "Carlos_Supervisor",
  "fechaValidacion": "2025-11-07 14:30:00"
}
```

**Respuesta de error:**
```json
{
  "estatus": "0",
  "mensaje": "Registro no encontrado"
}
```

---

## 🔧 Integración con RegistroRemoto

### Comportamiento Automático

Cuando un empleado hace un **RegistroRemoto**:

1. **Dentro de geocerca** → `estadoValidacionGeocerca = 'No_Requiere'`
2. **Fuera de geocerca SIN motivo** → `estadoValidacionGeocerca = 'No_Requiere'`
3. **Fuera de geocerca CON motivo** → `estadoValidacionGeocerca = 'Pendiente'` ⚠️

### Respuesta del RegistroRemoto

Ahora incluye el campo `estadoValidacionGeocerca`:

```json
{
  "estatus": "1",
  "mensaje": "✅ En Rango registrado - Pendiente de validación por geocerca",
  "empleado": "Juan Pérez García",
  "empleadoID": 1531,
  "tipo": 1,
  "tipoTexto": "Entrada",
  "estado": "En Rango",
  "requiereValidacion": true,
  "esPrimerRegistroDelDia": true,
  "estadoValidacionGeocerca": "Pendiente",
  "registro": "completado"
}
```

---

## 💡 Casos de Uso

### Caso 1: Panel de Supervisor
```dart
// Obtener registros pendientes para mostrar en dashboard
final response = await http.get(
  Uri.parse('$baseUrl/ws_nexus_geo.php?fn=ListarRegistrosPendientes&estado=Pendiente')
);

// Mostrar lista con botones "Validar" / "Rechazar"
// Al hacer clic, llamar a ValidarRegistroGeocerca
```

### Caso 2: Validación Rápida
```dart
// Supervisor revisa motivo y valida
await http.get(
  Uri.parse('$baseUrl/ws_nexus_geo.php?fn=ValidarRegistroGeocerca'
    '&salidEnt=$registroId'
    '&accion=validar'
    '&usuario=$supervisorNombre'
    '&comentario=Aprobado')
);
```

### Caso 3: Proceso en 2 Pasos
```dart
// Paso 1: Marcar como "visto" sin decidir aún
await validar(salidEnt: 123, accion: 'revisar', usuario: 'Supervisor1');

// Paso 2: Después de investigar, tomar decisión
await validar(salidEnt: 123, accion: 'validar', 
  usuario: 'Supervisor1',
  comentario: 'Verificado con cliente que hubo junta fuera de oficina');
```

---

## 📋 Consultas SQL Útiles

### Ver todos los pendientes de validación
```sql
SELECT 
    es.salidEnt,
    CONCAT(e.nombre, ' ', e.apellidoP) as empleado,
    es.fechaH,
    es.estadoValidacionGeocerca,
    es.comentario as motivo,
    es.distanciaMetros
FROM tb_entrada_salida es
INNER JOIN tb_empleados e ON es.empleadoID = e.empleadoID
WHERE es.estadoValidacionGeocerca = 'Pendiente'
ORDER BY es.fechaH DESC;
```

### Estadísticas de validaciones
```sql
SELECT 
    estadoValidacionGeocerca,
    COUNT(*) as total,
    DATE(fechaH) as fecha
FROM tb_entrada_salida
WHERE estadoValidacionGeocerca != 'No_Requiere'
GROUP BY estadoValidacionGeocerca, DATE(fechaH)
ORDER BY fecha DESC;
```

### Registros validados por supervisor
```sql
SELECT 
    validadoPor,
    COUNT(*) as total_validados,
    SUM(CASE WHEN estadoValidacionGeocerca = 'Validado' THEN 1 ELSE 0 END) as aprobados,
    SUM(CASE WHEN estadoValidacionGeocerca = 'Rechazado' THEN 1 ELSE 0 END) as rechazados
FROM tb_entrada_salida
WHERE validadoPor IS NOT NULL
GROUP BY validadoPor;
```

---

## ⚠️ Notas Importantes

1. **Permisos**: Asegúrate de ejecutar el script `db_migration_estado_validacion.sql` antes de usar estos endpoints

2. **Límite de resultados**: `ListarRegistrosPendientes` devuelve máximo 100 registros. Usa paginación si necesitas más

3. **Estados finales**: Una vez marcado como `Validado` o `Rechazado`, el registro no debería cambiar de estado (aunque técnicamente es posible)

4. **Auditoría completa**: Todos los cambios de estado quedan registrados con usuario y fecha

5. **Compatibilidad**: Los registros antiguos (antes de la migración) tendrán `estadoValidacionGeocerca = NULL`, se puede actualizar a `'No_Requiere'` con un UPDATE

---

## 🎯 Próximos Pasos

- [ ] Crear pantalla de supervisor en Flutter
- [ ] Implementar notificaciones push cuando haya registros pendientes
- [ ] Dashboard con métricas de validaciones
- [ ] Exportar reportes de registros fuera de geocerca
- [ ] Configurar escalamiento automático (si pasan X horas sin validar)
