# Fase 6A — hardening operativo y diagnóstico local

## Base y alcance

- Base: `codex/fase-5-1-ui-polish`, `fe901de7edb8f8c3674649b8c623204598d144a7`.
- Rama: `codex/fase-6a-hardening-diagnostics`.
- Versión: **2026092101 / 0.8.2-alpha**; madurez alpha conservada.
- El propietario confirmó el smoke real hasta Fase 5.1: Meet-first simple y
  recurrente, Calendar nativo y attendee, COHOST, artefactos, mismo Meet,
  descubrimiento/renombrado por cron, publicación y vista académica.
  Esta evidencia anterior no sustituye el smoke específico de 6A.
- Esta fase prepara el piloto: diagnóstico local, revisión de permisos,
  recuperación acotada, retirada de PoC y consistencia del esquema.

No agrega funciones académicas ni probes Google, scopes, transcripciones,
asistencia, participantes, backup/restore o migración. No descarga MP4, cambia
permisos, mueve videos, modifica carpetas/propietario/contenido ni borra recursos
externos. No hay reparación o reintento global. No se modifica Moodle core.

## Estado del sistema

Administración del sitio → Plugins → Módulos de actividad → TUP Meet contiene
dos entradas separadas: **Cuentas maestras** y **Estado del sistema**.
`health.php` requiere login y `moodle/site:config` en contexto de sistema.
El servicio y el renderer comprueban también esa capacidad; no se exige
`is_siteadmin`, de modo que una delegación explícita de site:config funciona.

`classes/local/diagnostic/health_service.php` usa exclusivamente consultas locales
y la API de tareas de Moodle. `classes/output/health_dashboard.php` usa tablas,
notificaciones e iconos nativos, sin JavaScript nuevo ni paleta propia.
No construye clientes OAuth, Calendar, Meet o Drive. La carga del panel no
verifica conectividad ni renueva autorizaciones. **Verificar cuenta** sigue siendo
una acción explícita en la pantalla de cuentas.

El snapshot contiene únicamente proyecciones seguras. No recupera secretos de
issuers, sujetos OAuth, correos, task customdata, trazas, cuerpos de respuesta,
fileId, exportUri, Space, event/member IDs o URLs Google. No crea tablas de
historial, datos personales persistentes ni eventos de lectura.

### Cuentas y evidencia

Muestra la versión instalada en configuración Moodle y el release del código,
conteos de cuentas históricas/habilitadas/verificadas/predeterminadas y fecha de
verificación de la predeterminada habilitada. «Configurada» requiere exactamente
una predeterminada, habilitada, verified, con issuer todavía presente. Cero,
múltiples, deshabilitada, no verificada o issuer ausente producen advertencia.
Esto no certifica que el issuer o su autorización sigan siendo utilizables hoy.

| Subsistema | Último resultado correcto registrado |
| --- | --- |
| Space (solo Meet-first) | MAX(spacemodified) en ready |
| Calendar | MAX(lastsync) en ready |
| Configuración de artefactos | MAX(meetconfigmodified) en ready |
| COHOST | MAX(cohostmodified) en ready/configured |
| Descubrimiento de grabaciones | MAX(recordingslastsync) en ready |
| Renombrado Drive | MAX(renamedat) en ready |

Sin fecha se muestra **Sin evidencia todavía**, no error. Las fechas provienen
de filas actualmente correctas: no son un historial completo y una fila que
posteriormente pasa a error deja de participar en esos máximos.

Hay SQL agregado para actividades, Space, Calendar, artefactos, COHOST,
descubrimiento, STARTED/ENDED/FILE_GENERATED, renombrado y visibilidad.
Solo Space excluye actividades históricas. Visibilidad cuenta también grabaciones
en procesamiento; no significa que todos esos archivos puedan reproducirse.
Los estados desconocidos se agrupan en un solo bucket acotado.

### Atención y rendimiento

La tabla contiene solo `error` y Space `uncertain`. Pending, creating, syncing,
STARTED y ENDED no se clasifican automáticamente como fallos. Cada subsistema
puede producir una fila para la misma actividad; el total es de incidencias,
no de reuniones únicas. Solo aparecen actividades con curso y módulo locales
resolubles; los agregados globales pueden incluir filas huérfanas.

Máximo **50 filas por página**, con orden determinista por fecha descendente,
actividad, subsistema y grabación. SQL UNION ALL, COUNT y un JOIN paginado
resuelven nombres y enlace interno sin cargar todos los registros ni producir
N+1 por actividad. Los nombres pasan por format_string con contexto explícito
y filtros deshabilitados y se presentan como texto escapado.

HTTP se muestra únicamente si existe el campo numérico persistido, validado
entre 100 y 599. Calendar y artifactConfig no guardan HTTP propio: se muestra
un guion, sin inventarlo. La fecha de Calendar es `timemodified` (edición local),
la de discovery `recordingsyncqueued` (encolado), y las demás son las fechas de
actualización de cada subsistema. No se presentan como hora exacta del fallo.
El único control de la tabla es **Abrir actividad**, hacia Moodle. La recuperación
permanece en su Administración técnica.

Las consultas agregadas siguen examinando índices/filas en la base de datos;
el límite se aplica al resultado y a la memoria PHP, no promete tiempo constante
de SQL. Las pruebas comprueban un número acotado de consultas, no milisegundos.

## Cron y tareas

`discover_recordings` conserva `*/5`. El diagnóstico usa
`core\task\manager::get_scheduled_task` y los getters públicos de la tarea.

| Estado local | Clasificación |
| --- | --- |
| No existe o deshabilitada | Requiere atención |
| Sin lastruntime | Información: sin ejecución registrada |
| faildelay activo, con ejecución registrada | Advertencia: backoff |
| Última ejecución hace 15 minutos o menos | Correcto: ejecución reciente |
| Última ejecución hace más de 15 minutos | Advertencia |

Última ejecución no equivale por sí sola a éxito de Google. Se muestran también
próxima ejecución y faildelay en segundos, sin leer logs sensibles.

Para adhoc se agregan filas cuyo componente es `mod_tupmeet` o cuya clase
corresponde a sync_space, sync_meeting, sync_cohost, sync_meet_config,
sync_recordings o rename_recording. La lectura encapsulada de `task_adhoc`
usa columnas presentes en Moodle 4.5/5.0/5.1. No hay escrituras core.

- Total en cola incluye ejecución; pendientes excluye `timestarted > 0`.
- En espera: futuro y sin faildelay.
- Listas: nextruntime vencido, sin ejecución ni faildelay.
- Backoff: faildelay positivo, sin ejecución; separado aunque nextruntime venza.
- Posiblemente atrasadas: subconjunto de listas, más de **30 minutos** vencidas.
- Más de **1000 pendientes**: advertencia de capacidad, no limitación.

Una tarea en ejecución no se declara bloqueada por su duración; un timestamp
antiguo requiere revisión humana en las herramientas de tareas de Moodle.
El panel no borra tareas, modifica horarios ni ejecuta reintentos. Tampoco aplica
throttling a creaciones normales de Space.

Referencias: [Task API Moodle 4.5](https://moodledev.io/docs/4.5/apis/subsystems/task),
[task manager](https://phpdoc.moodledev.io/4.5/db/d2e/classcore_1_1task_1_1manager.html),
[scheduled task Moodle 5.0](https://phpdoc.moodledev.io/5.0/de/d87/classcore_1_1task_1_1scheduled__task.html).

## Matriz de seguridad revisada

| Endpoint | Login/contexto | Capacidad | Método | Sesskey | HTTP externo |
| --- | --- | --- | --- | --- | --- |
| view.php | require_login curso/cm; módulo | mod/tupmeet:view | Lectura | No mutación solicitada | No |
| index.php | require_course_login; curso | Acceso Moodle al curso/instancias visibles | Lectura | No | No |
| recordings.php | require_login curso/cm; módulo | view; mutaciones manageactivities | POST para acciones | Sí, en actions::execute | No: encola discovery/rename; visibilidad local |
| retry.php | require_login curso/cm; módulo | moodle/course:manageactivities | POST | Sí | Sí, reconciliación explícita existente |
| accounts.php | require_login; sistema | moodle/site:config | GET lectura; POST registro/acciones | Moodle form o require_sesskey | Solo verificación/default/enable explícitos y flujo OAuth nativo |
| health.php | require_login; sistema | moodle/site:config | Lectura, sin acciones | No | No |
| poc_meet_first.php | Eliminado | Sin ruta de plugin | — | — | — |

Los PHP de bibliotecas, formularios y DB son puntos de integración Moodle, no
endpoints académicos independientes. Se conserva su uso desde el core.
`mod/tupmeet:manage` está declarada, pero los controles vigentes usan
`moodle/course:manageactivities`. No se cambia esa semántica ni se migran roles.
Un docente con gestión de curso no obtiene site:config automáticamente.
Estudiantes no reciben diagnóstico técnico ni pueden ejecutar acciones de
visibilidad, sync o retry. Ocultar controles no sustituye los checks del servidor.

La cobertura combina inspección de guardas/orden en rutas con pruebas reales
de capacidades, servicio/render de health, account_manager y acciones POST de
grabaciones (incluidos GET, sesskey inválida, estudiante y contexto incorrecto).
No se afirma un pentest HTTP autenticado de staging: está en el smoke pendiente.

## Retirada de la PoC

Se elimina la ruta, formulario, experiment/failure/service, registro tupmeetpoc,
definición de caché exclusiva, strings y dos metadatos de privacidad exclusivos.
Los servicios productivos Meet-first no dependen de ese namespace/caché y se
conservan. La prueba de aislamiento verifica referencias y ausencia de ruta.
La documentación histórica de Fase 3.3 permanece como evidencia, no como una
instrucción para ejecutar herramientas actuales.

De las **356 pruebas anteriores**, se retiran **27 casos exclusivamente PoC**,
según la autorización expresa de esta fase. Se conservan los otros **329** casos
de regresión, ajustando únicamente expectativas del savepoint nuevo cuando
invocan todo el upgrade. Se incorporan pruebas específicas de 6A.

Al actualizar staging, **reemplazar el directorio del plugin**, no sobreponerlo:
una copia que deja PHP eliminados mantendría accesible la herramienta antigua.
Tras upgrade, purgar cachés de Moodle para retirar definiciones obsoletas.
No se hace limpieza remota de experimentos ni se borra contenido Drive.

## Default estructural y upgrade

`tupmeet.publicationmode` cambia DEFAULT manual → automatic. No se agregan
tablas, campos o índices. install.xml, versión y savepoint se sincronizan en
2026092101. `change_field_default` modifica solo metadata estructural, sin UPDATE
de valores. Las filas manual siguen manual; automatic siguen automatic. No se
recalcula visibilidad de grabaciones existentes ni se ejecuta HTTP o encolado.

La prueba reconstruye el default manual, conserva snapshots completos de filas
con ambos valores, ejecuta upgrade y verifica que una inserción que omite el
campo recibe automatic. También se prueba la instalación limpia XMLDB.

## Pruebas operativas y límites

Se prueban volúmenes de 100/500/1000 actividades, conteos exactos, paginación de
50, ausencia de solapamiento entre páginas, consultas acotadas, BATCH=25 y dos
pasadas del dispatcher sin encolar todo. Se comprueba deduplicación y el avance
después de filas inválidas. No hay benchmark de milisegundos ni Google real.

La prueba reprodujo un bloqueo local previo: 25 identidades canónicas inválidas
con `recordingsnextsync` vencido monopolizaban el primer lote incluso después de
marcarlas error. La corrección coloca esas candidatas en error y pone nextsync=0
bajo el lock de actividad; elimina HTTP residual de esa comprobación local.
Conserva dueño, recursos, revisión e intentos, no hace HTTP ni renueva presupuestos.
La siguiente pasada puede seleccionar actividades válidas. Se cubren idle,
pending, syncing y ready, más contención y retiro stale. No se cambia la lógica
de descubrimiento remoto, renombrado, publicación o provisioning.

Se conserva la cobertura de leases vencidos, tarea desaparecida, revisión stale,
lock ocupado, backoff, presupuestos finitos y errores definitivos de discovery
y rename. Las pruebas simulan estado core solo en bases PHPUnit desechables;
el producto nunca escribe esas tablas directamente. Space mantiene su política
conservadora ante resultados ambiguos y backoff ante 429; Calendar conserva
reconciliación idempotente y la política de reintentos previa.

### Riesgos operativos conservados

- Discovery, rename, COHOST y artifactConfig tienen presupuesto de cinco intentos
  por revisión. **Calendar no tiene un presupuesto finito propio del plugin**:
  conserva el backoff nativo de Moodle. Un fallo sostenido puede mantener esa
  tarea en cola; cambiar esa política requeriría una decisión funcional aparte.
- Space tampoco convierte un 429 completo en error permanente por número de
  intentos, según el contrato aprobado de cuotas. Un timeout ambiguo sigue
  bloqueado como uncertain y nunca habilita un segundo POST automático.
- El panel no prueba vigencia OAuth ni conectividad. No se declara el sistema
  «Google operativo» a partir de resultados antiguos.
- Los agregados no sustituyen una auditoría de integridad de filas huérfanas ni
  un benchmark sobre el volumen final institucional.
- Boost Union y el acceso HTTP autenticado deben validarse en staging. Las
  pruebas locales usan Moodle/Boost y transportes simulados.

## Validación local final

PHP **8.3.33**, MariaDB **10.11.14**, bases PHPUnit locales desechables.
Instalación XMLDB inicializada correctamente en las tres versiones.

| Moodle | PHPUnit | Pruebas | Aserciones | Duración local | Resultado |
| --- | --- | --- | --- | --- | --- |
| 4.5.14 | 9.6.34 | 371 | 7409 | 23:30.102 | PASS |
| 5.0.10 | 11.5.55 | 371 | 7410 | 22:49.771 | PASS |
| 5.1.7 | 11.5.55 | 371 | 7410 | 24:06.730 | PASS |

Suite completa `mod_tupmeet_testsuite`, con fail-on-warning, fail-on-risky,
fail-on-incomplete y fail-on-skipped; en PHPUnit 11 también fail-on-notice y
fail-on-phpunit-deprecation. Sin errores, avisos, riesgos o casos omitidos.
329 casos previos conservados + 42 nuevos = 371; se retiraron los 27 exclusivos
de la PoC. Se verificaron upgrades reales en las bases desechables, incluida
la conservación íntegra de filas manual/automatic y el nuevo default de inserción.

- PHPCS Moodle, warning-severity=1: PASS, sin errores ni advertencias.
- Sintaxis PHP: **83 archivos**, PASS.
- Estructura Moodle Plugin CI: PASS.
- Upgrade/savepoints: **9 pares**, PASS.
- `git diff --check`: PASS.
- Escaneo de patrones de credenciales: **109 archivos**, sin hallazgos.
- Comparación con la base: **42 archivos operativos y de CI sin cambios**;
  única corrección de cola existente: aislamiento local de identidades inválidas.
- Simulación 100/500/1000 actividades: lotes de 25, paginación de 50,
  deduplicación y avance después de filas inválidas: PASS.
- Cola 1000/1001: umbral informativo verificado sin modificar tareas desde el panel.
- Render web Moodle/Boost con datos sintéticos y textos del plugin en es_mx:
  **320, 390, 768 y 1280 px**, sin desbordamiento de página, con 50 enlaces internos,
  sin formulario de mutación ni enlaces Google. Recursos visuales servidos desde
  archivos locales; no se validó Boost Union ni una sesión HTTP real de staging.

Durante la validación se reprodujo y corrigió el bloqueo de filas inválidas,
se completó una etiqueta faltante y se corrigió una expectativa del test de
navegación (Moodle devuelve null para una entrada inexistente). No se cambiaron
funciones Google para resolver fallos. El workflow heredado permanece intacto.

No hubo llamadas Google reales, push, merge, deploy o release. La auditoría remota,
empaquetado y smoke de staging quedan como etapas posteriores independientes.

## Smoke pendiente de autorización en staging

1. Registrar preferencias manual/automatic de actividades existentes.
2. Sustituir completamente mod/tupmeet (public/mod/tupmeet en layout 5.1),
   actualizar 0.8.1-alpha → 0.8.2-alpha y purgar cachés.
3. Verificar versión y que las preferencias y visibilidad individuales persisten.
4. Abrir Estado del sistema; comprobar cuentas, fechas, métricas, cron y cola.
5. Correlacionar una incidencia local con su actividad; comprobar paginación.
6. Confirmar que cargar health no genera HTTP Google ni nuevas tareas.
7. Probar acceso directo como estudiante y docente: health/accounts denegados;
   POST sin capacidad/sesskey y GET de mutación rechazados.
8. Confirmar vista académica, ojo, panel técnico y gestión docente intactos.
9. Comprobar que PoC no aparece en menú y su antigua URL no funciona.
10. Crear actividad normal: publicationmode automatic; comprobar grabación y
    publicación por el flujo habitual. Este smoke Google será ejecutado por el
    propietario posteriormente, no forma parte de PHPUnit ni de esta entrega local.

## Decisión futura: Fase 6B, no implementada

Migración histórica **una sola vez por CSV**, no lectura directa de mod_googlemeet.
Los videos permanecen en Drive: no mover, rename obligatorio ni cambio de permisos.
Matching futuro por nombre de sesión; normalizar sufijo técnico `(####)` y usar
fecha/hora/`Recording 2` para reconstruir sesiones y partes. Se prevé una tabla
legacy separada, sin conferenceRecords ficticios. No existe aún parser, importer,
tabla legacy, cambio Drive ni implementación de Fase 6B.

## Inventario de archivos

### Nuevos

- classes/local/diagnostic/health_service.php
- classes/output/health_dashboard.php
- docs/PHASE6A.md
- health.php
- tests/endpoint_security_test.php
- tests/health_test.php

### Modificados

- README.md
- classes/local/recording/recording_manager.php
- classes/privacy/provider.php
- db/install.xml
- db/upgrade.php
- docs/ARCHITECTURE.md
- docs/ROADMAP.md
- lang/en/tupmeet.php
- lang/es/tupmeet.php
- lang/es_mx/tupmeet.php
- settings.php
- tests/publication_upgrade_test.php
- tests/recording_policy_test.php
- tests/recording_ui_test.php
- tests/upgrade_test.php
- version.php

### Eliminados

- classes/form/poc_setup_form.php
- classes/local/poc/experiment.php
- classes/local/poc/failure.php
- classes/local/poc/service.php
- db/caches.php
- poc_meet_first.php
- tests/poc_test.php
