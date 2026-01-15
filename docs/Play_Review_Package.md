Play Console - Paquete de revisión para Nexus Goll
===============================================

Resumen para el revisor
-----------------------

- Nombre de la app: Nexus (com.pimpledmikey.nexus_goll_final)
- Propietario/Empresa: Corporativo DRT
- Propósito principal del uso de la ubicación: Validación de registros de asistencia dentro de geocercas autorizadas.
- Estado actual: Se ha actualizado la Política de Privacidad y se proveen divulgación prominente y textos justificativos para la revisión.

Archivos incluidos en este paquete
---------------------------------

- Google Play Authorization Letter: Google_Play_Authorization_Letter.html
- Política de Privacidad actualizada: privacy-policy.html
- Texto de divulgación in-app (lista abajo)
- Justificación técnica y medidas de seguridad (lista abajo)

Texto de divulgación in-app (usar exactamente en la pantalla previa al request):
-----------------------------------------------------------------------

"Nexus Goll solicita acceso a tu ubicación para validar que tu registro de asistencia se realiza desde la zona autorizada por la empresa. Las coordenadas se usarán únicamente para este propósito, se transmitirán cifradas y se almacenarán según la política de la empresa. Si no autorizas, no podrás completar el registro desde esta ubicación."

Justificación del uso de ubicación
---------------------------------

1. Necesidad funcional: la aplicación verifica que el empleado se encuentre físicamente en la ubicación asignada (geocerca) al momento de marcar asistencia. Sin la ubicación, no es posible validar presencia.
2. Alcance y minimización: la app sólo recoge coordenadas al momento de un registro y las asocia al evento; no realiza tracking continuo en background ni comparte la ubicación para otros fines.
3. Retención: los datos de ubicación se conservan el tiempo necesario para cumplir con obligaciones laborales y administrativas (ver Política de Privacidad). Se eliminarán cuando corresponda.

Medidas de seguridad y privacidad
---------------------------------

- Transmisión cifrada mediante HTTPS/TLS.
- Almacenamiento local cifrado para modo offline.
- Acceso a datos limitado a personal autorizado (RRHH, supervisores).
- No se vende ni comparte para publicidad.
- Verificaciones anti-fraude (detección de GPS falso, VPN) son usadas únicamente para validar integridad del registro; las comprobaciones se realizan sobre indicadores y no implican compartir datos con terceros.

Declaración sobre background location
-------------------------------------

La aplicación no solicita permiso de ubicación en segundo plano (`ACCESS_BACKGROUND_LOCATION`) en el flujo estándar de control de asistencia. La ubicación se solicita sólo en primer plano cuando el usuario realiza un registro o mantiene la pantalla de registro activa.

Capturas y evidencias recomendadas para adjuntar al formulario de revisión
---------------------------------------------------------------------------

1. Screenshot de la pantalla in-app que muestra la divulgación prominente antes de solicitar permiso de ubicación (usar el texto de divulgación exacto).
2. Screenshot del diálogo nativo del permiso de ubicación (Android) cuando se solicita.
3. Screenshot de la sección de la Política de Privacidad publicada en la web mostrando la sección de ubicación y divulgación.
4. (Si aplica) Carta de autorización o evidencia contractual que justifique que la app se distribuye a empleados (adjuntar Google_Play_Authorization_Letter.html).

Respuestas sugeridas para formulario de Google Play (cuando pregunte por la razón del permiso de ubicación)
---------------------------------------------------------------------------------------------------

- "La aplicación requiere la ubicación para validar que el registro de asistencia se realiza dentro de la geocerca autorizada por el empleador. La ubicación sólo se recopila cuando el usuario realiza un registro y se usa exclusivamente para validación de asistencia."

Notas operativas para el equipo de desarrollo / release
------------------------------------------------------

1. Asegurarse de que `versionCode` y `versionName` sean incrementados antes de subir el nuevo AAB.
2. Subir el AAB en el mismo track donde estaba la versión retirada (por ejemplo, producción) y hacer rollout al 100% después de `Send for review`.
3. Completar el formulario Data Safety en Play Console describiendo recolección de ubicación, imágenes y datos de contacto.
4. Revisar `AndroidManifest.xml` y eliminar o documentar cualquier meta-data que pueda hacer que Play considere la app como "monitoring tool". Si la app es una solución EMM/enterprise, indicar distribución por Managed Google Play.

Contacto
--------

Departamento de Sistemas - Corporativo DRT
sistemas@corporativodrt.com
