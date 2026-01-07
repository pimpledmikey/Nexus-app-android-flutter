# 🎯 Guía de Implementación - Panel Web de Validación de Geocercas

## 📋 Resumen de Cambios

### 1. RegistroEntradaOffline - Validación Automática ✅

**Cambio implementado en `WS_Nexus.php`:**
- Los registros offline ahora validan geocerca automáticamente al conectarse
- Si están **fuera de geocerca**, se marcan como `Pendiente` sin necesidad de que el usuario ingrese motivo
- Se agrega comentario automático: *"Registro offline - Sin conectividad en el momento del registro"*

**Lógica:**
```php
if ($geocercaResult['validacion'] === 'Fuera') {
    $estadoValidacionOffline = 'Pendiente';
    $comentarioOffline = 'Registro offline - Sin conectividad en el momento del registro';
}
```

### 2. Panel Web PHP - Gestión de Validaciones ✅

**Archivo creado:** `panel_validacion_geocercas.php`

Panel web responsive para supervisores que permite:
- ✅ Ver todos los registros pendientes de validación
- ✅ Filtrar por estado, fechas, empresa
- ✅ Ver estadísticas en tiempo real
- ✅ Validar, rechazar o marcar como "en revisión"
- ✅ Agregar comentarios del supervisor
- ✅ Funciona en móvil, tablet y PC

---

## 🔧 Implementación en ScriptCase

### Opción 1: Blank PHP (Recomendado)

#### Paso 1: Crear Blank Application
```
ScriptCase → New Application → Blank
Nombre: panel_validacion_geocercas
```

#### Paso 2: Configurar el Blank
1. En el editor de ScriptCase, pegar el contenido de `panel_validacion_geocercas.php`
2. Configurar permisos de acceso (solo supervisores/RH)

#### Paso 3: Integrar con Sesión de ScriptCase
Modificar la línea 334 para obtener el usuario actual:
```php
const USUARIO_ACTUAL = '<?php echo $_SESSION['usr_login']; ?>';
```

O si usas otro nombre de sesión:
```php
const USUARIO_ACTUAL = '<?php echo $_SESSION['nombre_usuario']; ?>';
```

#### Paso 4: Configurar en Menú
- Agregar opción en el menú principal: "Validación de Geocercas"
- Icono sugerido: 🗺️ o 📍
- Solo visible para roles: Supervisor, RH, Admin

---

### Opción 2: Grid Application con Modal

Si prefieres usar un Grid nativo de ScriptCase:

#### Grid Configuration:
```sql
SELECT 
    es.salidEnt,
    es.empleadoID,
    CONCAT(e.nombre, ' ', e.apellidoP, ' ', e.apellidoM) as empleado,
    emp.nombre as empresa,
    es.fechaH,
    es.tipo,
    es.validacionGeocerca,
    g.nombre as geocerca,
    es.distanciaMetros,
    es.comentario as motivo,
    es.estadoValidacionGeocerca,
    es.validadoPor,
    es.fechaValidacion
FROM tb_entrada_salida es
INNER JOIN tb_empleados e ON es.empleadoID = e.empleadoID
LEFT JOIN tb_empresas emp ON e.empresaID = emp.empresaID
LEFT JOIN tb_geocercas g ON es.geocercaID = g.geocercaID
WHERE es.estadoValidacionGeocerca != 'No_Requiere'
ORDER BY es.fechaH DESC
```

#### Agregar Botones de Acción:
- Botón "Validar" (verde) → Llama a `ValidarRegistroGeocerca?accion=validar`
- Botón "Rechazar" (rojo) → Llama a `ValidarRegistroGeocerca?accion=rechazar`
- Botón "Ver" (azul) → Abre modal con detalles

---

## 📧 Integración con Correo Electrónico

### Configurar Notificaciones por Email

Agregar al final de `RegistroRemoto` y `RegistroEntradaOffline`:

**Nota:** El endpoint ahora acepta un parámetro POST opcional `attachments_base64` que debe ser un JSON-encoded array con hasta 2 strings Base64 (puede incluir el prefijo data:image/...). Cuando se recibe, el servidor guardará esos objetos dentro de la columna `evidencia_json` de `tb_entrada_salida` como un array de objetos: `{ filename, mimetype, base64 }` y creará un registro en `tb_evidencias` con metadatos. Esto permite almacenar evidencias directamente en la base de datos sin archivos en disco.


```php
// Si el registro queda Pendiente, enviar email a supervisores
if ($estadoValidacion === 'Pendiente' || $estadoValidacionOffline === 'Pendiente') {
    $asunto = "⚠️ Registro fuera de geocerca pendiente de validación";
    $mensaje = "
    <h2>Nuevo Registro Pendiente</h2>
    <p><strong>Empleado:</strong> $nombreCompleto</p>
    <p><strong>Fecha:</strong> $fechaH</p>
    <p><strong>Geocerca:</strong> {$validacionGeocerca['geocercaID']}</p>
    <p><strong>Distancia:</strong> {$validacionGeocerca['distanciaMetros']} metros</p>
    <p><strong>Motivo:</strong> " . ($motivoFueraGeocerca ?: 'Registro offline - Sin conectividad') . "</p>
    <br>
    <a href='https://dev.bsys.mx/scriptcase/app/Gilneas/panel_validacion_geocercas'>
        Ir al Panel de Validación
    </a>
    ";
    
    // Obtener emails de supervisores
    $sql_supervisores = "SELECT email FROM tb_empleados WHERE rol = 'Supervisor' AND empresaID = $empresaID";
    sc_lookup(supervisores, $sql_supervisores);
    
    if (!empty({supervisores})) {
        foreach ({supervisores} as $sup) {
            $emailSupervisor = $sup[0];
            // Usar función de ScriptCase para enviar email
            sc_mail_send(
                'smtp.tuservidor.com',
                'noreply@tuempresa.com',
                $emailSupervisor,
                $asunto,
                $mensaje,
                'H', // HTML
                '', '', '', // Archivos adjuntos
                'usuario_smtp',
                'password_smtp'
            );
        }
    }
}
```

---

## 🔐 Control de Acceso

### Configurar Permisos en ScriptCase

1. **Crear Grupo "Supervisores":**
   - Security → Groups → New Group
   - Nombre: `Supervisores_Geocerca`

2. **Asignar Aplicaciones:**
   - Dar acceso a: `panel_validacion_geocercas`
   - Permisos: Ver, Editar (validar/rechazar)

3. **Usuarios Autorizados:**
   - Solo RH, Supervisores y Administradores

---

## 📱 Acceso desde Móvil

El panel es **100% responsive** y funciona en:
- 📱 iPhone/Android
- 💻 PC/Laptop  
- 📊 Tablets

**URL de acceso directo:**
```
https://dev.bsys.mx/scriptcase/app/Gilneas/panel_validacion_geocercas
```

Puedes incluir este link en:
- Correos de notificación
- Menú de la app móvil
- Portal web de empleados

---

## 🎨 Personalización

### Cambiar Colores del Panel

Editar en `<style>`:

```css
/* Header principal */
.header {
    background: linear-gradient(135deg, #TU_COLOR_1 0%, #TU_COLOR_2 100%);
}

/* Botón primario */
.btn-primary {
    background: #TU_COLOR;
}
```

### Agregar Logo de Empresa

Después de la línea del `<h1>`:
```html
<img src="/ruta/a/tu/logo.png" alt="Logo" style="max-width: 200px; margin-bottom: 15px;">
```

---

## 📊 Reportes y Estadísticas

### Query para Reporte Mensual

```sql
SELECT 
    DATE_FORMAT(es.fechaH, '%Y-%m') as mes,
    emp.nombre as empresa,
    es.estadoValidacionGeocerca,
    COUNT(*) as total,
    AVG(es.distanciaMetros) as distancia_promedio
FROM tb_entrada_salida es
INNER JOIN tb_empleados e ON es.empleadoID = e.empleadoID
INNER JOIN tb_empresas emp ON e.empresaID = emp.empresaID
WHERE es.estadoValidacionGeocerca != 'No_Requiere'
GROUP BY mes, empresa, es.estadoValidacionGeocerca
ORDER BY mes DESC, empresa;
```

### Crear Report en ScriptCase

1. New Application → Report
2. Usar query anterior
3. Agregar gráficas:
   - Pie Chart: Distribución por estado
   - Bar Chart: Tendencia mensual
   - Summary: Totales por empresa

---

## 🚀 Pruebas

### Checklist de Validación

- [ ] Ejecutar migración SQL: `db_migration_estado_validacion.sql`
- [ ] Subir `panel_validacion_geocercas.php` a ScriptCase
- [ ] Configurar permisos de acceso
- [ ] Probar registro offline que quede fuera de geocerca
- [ ] Verificar que aparezca en panel como "Pendiente"
- [ ] Probar validación desde panel web
- [ ] Verificar que se actualice el estado
- [ ] Probar desde móvil
- [ ] Configurar emails (opcional)

---

## 🔄 Flujo Completo

```
┌─────────────────────────────────────────────────┐
│  EMPLEADO REGISTRA SIN CONEXIÓN (OFFLINE)      │
│  Ubicación: 50m fuera de geocerca               │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  App Flutter: Guarda registro en SQLite local   │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  Cuando hay conexión: Envía a backend          │
│  RegistroEntradaOffline                         │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  Backend valida geocerca automáticamente        │
│  ✅ Dentro → No_Requiere                        │
│  ❌ Fuera → Pendiente (comentario automático)   │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  📧 Email a supervisores (opcional)             │
│  "Nuevo registro pendiente de validación"      │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  SUPERVISOR abre panel web                      │
│  panel_validacion_geocercas.php                 │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  Supervisor revisa:                             │
│  - Ubicación del registro                       │
│  - Distancia a geocerca                         │
│  - Comentario automático                        │
│  - Historial del empleado                       │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  Supervisor DECIDE:                             │
│  ✅ Validar → Estado: Validado                  │
│  ❌ Rechazar → Estado: Rechazado                │
│  👁️ Revisar → Estado: En_Revision               │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  Se actualiza registro en BD con:              │
│  - Nuevo estado                                 │
│  - Usuario validador                            │
│  - Fecha validación                             │
│  - Comentario supervisor                        │
└─────────────────────────────────────────────────┘
```

---

## 💡 Tips y Mejores Prácticas

1. **Backups automáticos:**
   - Configurar backup diario de `tb_entrada_salida`
   - Especialmente importante con el nuevo campo de validación

2. **Auditoría:**
   - Todos los cambios quedan registrados (quién, cuándo, por qué)
   - Útil para disputas o revisiones

3. **Performance:**
   - Índice ya creado en `estadoValidacionGeocerca`
   - Panel carga solo últimos 30 días por defecto

4. **Escalabilidad:**
   - Si hay muchos pendientes, considerar auto-validar después de X días
   - O escalar a supervisor de nivel superior

5. **Comunicación:**
   - Email inmediato para registros críticos
   - Resumen diario para el resto

---

## 📞 Soporte

Si encuentras algún problema:

1. Verificar logs en `/var/log/apache2/error.log`
2. Revisar que la migración SQL se ejecutó correctamente
3. Confirmar que el endpoint API está respondiendo:
   ```
   curl "https://dev.bsys.mx/.../ws_nexus_geo.php?fn=ListarRegistrosPendientes"
   ```

---

## 🎉 ¡Listo!

Con esta implementación tienes:
- ✅ Validación automática en registros offline
- ✅ Panel web para supervisores
- ✅ Control total de geocercas
- ✅ Auditoría completa
- ✅ Acceso desde cualquier dispositivo

**¡Excelente trabajo!** 🚀
