# Fase 4 — descubrimiento y renombrado de grabaciones

## Alcance y evidencia

Versión **2026091900 / 0.7.0-alpha**. Rama **codex/fase-4-recordings**.
Base: Fase 3.4.1, commit 9c17fbe5209c95a3baf26bb7c308fc472cfc1ef3.

El propietario informó smoke real aprobado de Meet-first: Space, COHOST,
artifactConfig, Calendar native, invitación docente, reuniones simples y
recurrentes, mismo Meet y generación del MP4 en Drive del organizer institucional.
También confirmó que Calendar no adjunta automáticamente ese MP4.
Esta evidencia valida la base; **no constituye smoke de Fase 4**.

Esta fase consulta Meet, registra metadatos y renombra exclusivamente el MP4.
No busca carpetas/archivos por título, no descarga contenido, no cambia permisos
ni ubicación, no recupera transcripciones ni publica a estudiantes.
publicationmode, autorecord y autotranscript conservan su comportamiento.

## Fuentes oficiales

Documentación consultada el 19/09/2026:

- [ConferenceRecords.list](https://developers.google.com/workspace/meet/api/reference/rest/v2/conferenceRecords/list):
  filtro space.name, pageSize=100, nextPageToken.
- [ConferenceRecord](https://developers.google.com/workspace/meet/api/reference/rest/v2/conferenceRecords):
  nombre, referencia space, inicio y fin reales; retención de 30 días después de finalizar.
- [Recordings.list](https://developers.google.com/workspace/meet/api/reference/rest/v2/conferenceRecords.recordings/list):
  lista paginada por conferencia.
- [Recording](https://developers.google.com/workspace/meet/api/reference/rest/v2/conferenceRecords.recordings):
  STARTED, ENDED, FILE_GENERATED, driveDestination.file y exportUri.
- [Drive files.get](https://developers.google.com/workspace/drive/api/reference/rest/v3/files/get) y
  [files.update](https://developers.google.com/workspace/drive/api/reference/rest/v3/files/update):
  consulta y actualización de metadatos por ID.
- [Scopes Drive](https://developers.google.com/workspace/drive/api/guides/api-specific-auth):
  drive.metadata está clasificado como **restringido**.

El backfill no puede recuperar conferenceRecords expirados antes de instalar esta
fase. La ausencia remota nunca borra los metadatos locales.

## Arquitectura

~~~text
Space canónico permanente
  -> recording_service: conferenceRecords.list (todas las páginas)
    -> recordings.list por conferencia (todas las páginas)
      -> recording_manager: upsert local
        -> rename_manager: GET Drive por driveDestination.file
          -> PATCH {"name": desiredfilename}, si hace falta
~~~

- recording_transport: OAuth nativo, timeouts, sin redirecciones, errores numéricos
  sanitizados; rechaza HTTP dentro de transacciones.
- recording_service: valida nombres, parent exacto, Space, estados y timestamps.
  No utiliza Calendar, meetingCode, búsqueda Drive, carpetas ni caché PoC.
- recording_manager: propiedad histórica, revisión, lock meeting:{id},
  persistencia transaccional corta por conferencia y colas independientes.
- rename_manager: revisión/presupuesto propio, nombre congelado y convergencia.
- filename: helper puro Unicode y timezone explícita.
- polling: planificación local con el schedule existente, sin modificarlo.
- discover_recordings: scheduled cada cinco minutos, solo selecciona/encola.
- sync_recordings y rename_recording: workers adhoc persistentes.
- recording_list: catálogo local paginado de 50 registros.
- recordings.php / actions: POST autorizado; sin HTTP en la petición web.

Cada lista tiene máximo defensivo de 100 páginas de 100 elementos; se detectan
tokens cíclicos. Rebasarlo es error visible, nunca éxito parcial.
Se agotan las páginas de una conferencia antes de numerar sus grabaciones.
Conferencias ya confirmadas se conservan si otra conferencia falla.

Elegibilidad: Meet-first, Space ready y nombre canónico válido.
Un alias con formato de meetingCode no es identidad válida.
Históricos calendar con Space canónico admiten **descubrimiento de solo lectura**;
su rename queda skipped. No se infieren Spaces ni se consulta legacy.

Todo cliente usa el accountid de la actividad, incluso si la cuenta está deshabilitada
para nuevas reuniones. Nunca se sustituye por la predeterminada actual.
La fábrica OAuth existente verifica la identidad institucional original.
Estas tareas no crean Spaces, eventos, Members ni configuraciones de artifacts.

## Base de datos

install.xml, upgrade y savepoint **2026091900** sincronizados.
El upgrade no hace HTTP ni encola tareas. El scheduler inicia el backfill gradual.

### tupmeet_conferences

- id, tupmeetid (relación local).
- conferencename char(255), índice único global.
- starttime, endtime: timestamps reales; fin 0 mientras continúa.
- firstseen, lastseen, timecreated, timemodified.

### tupmeet_recordings

- id, tupmeetid, conferenceid: actividad y conferencia.
- recordingname char(255), índice único global.
- state char(20), starttime, startnanos (fracción RFC3339 de 0 a 999999999), endtime.
- drivefileid char(200), exporturi text; nulos hasta FILE_GENERATED.
- desiredfilename, drivefilename, originalfilename: char(255), inicialmente nulos.
- partnumber: posición por inicio real dentro de la conferencia.
- renamestatus, renameattempts, renamehttpstatus, renamedat.
- renameversion char(32), renamenextattempt, renamequeued: revisión,
  not-before de retry y lease de cola/cooldown manual.
- firstseen, lastseen, timecreated, timemodified.
- Índice de pendientes por renamestatus,renamenextattempt.

No se guarda JSON remoto completo, tokens, contenido, participantes ni permisos.
parents se compara en memoria y no se almacena.

### Coordinación en tupmeet

- recordingsyncstatus: idle, pending, syncing, ready, error.
- recordingslastsync: última lectura completa exitosa.
- recordingsnextsync: próxima comprobación o not-before/lease; 0 significa dormida.
- recordingsyncattempts: presupuesto persistente de esta revisión.
- recordingsyncversion: invalida workers antiguos.
- recordingshttpstatus: diagnóstico numérico sin mensajes remotos.
- recordingsyncqueued: marca de cola y cooldown manual.
- recordingscheckedversion: revisión del horario Calendar considerada para polling.
  Una edición puede despertar una actividad dormida; no modifica Calendar.

Índice recordingsyncstatus,recordingsnextsync para selección due.
No se reutilizan estados de Calendar, Space, COHOST o artifacts.
Cambiar la cuenta predeterminada no cambia relaciones históricas.

## Estados y persistencia

- STARTED: grabando; no consultar Drive.
- ENDED: procesando; no consultar Drive.
- FILE_GENERATED: exige fileId/exportUri válidos y permite preparar rename.
- Estado desconocido o recurso mal formado: error seguro de descubrimiento.
- Regresiones STARTED/ENDED no degradan FILE_GENERATED confirmado.
- Identidad, parent o fileId contradictorios no redirigen un registro existente.
- Solo recursos observados actualizan lastseen; firstseen permanece.
- Índices únicos/upserts impiden duplicados por reejecución.
- No se borra por ausencia, retención ni error de permisos.

| Rename | Significado |
| --- | --- |
| unavailable | Todavía no hay MP4 generado |
| pending | Nombre preparado; tarea pendiente o retry transitorio |
| ready | Nombre confirmado por metadatos Drive |
| error | No se pudo confirmar; requiere revisión/reintento explícito |
| skipped | Histórico de solo lectura, canRename=false o conflicto de orden tardío |

Error/skipped conserva FILE_GENERATED, fileId y exportUri: la grabación sigue
disponible al gestor. No modifica el descubrimiento ni la reunión.

## Polling y recuperación

- Scheduler cada cinco minutos, **máximo 25 actividades due por ejecución**.
  Distribuye el backfill y evita concentrar cientos de actividades en una tarea.
- Adhoc deduplicado por ID/revisión. Sin cuota fija de una/dos reuniones por minuto.
- Renames pendientes se recuperan en su propio lote de hasta 25.
- Lease de 30 minutos para recuperar tareas interrumpidas conservando revisión y presupuesto.
- Primera lectura de backfill inmediata al ser elegible.
- STARTED reciente: próxima consulta aproximadamente en cinco minutos.
- ENDED reciente: diez minutos.
- Sesión recién terminada sin grabación: diez minutos durante tres días;
  después diaria hasta una ventana máxima de 30 días.
- Grabaciones generadas: una comprobación al día siguiente si la sesión terminó
  hace menos de 24 horas, para segmentos tardíos; después queda dormida hasta la
  próxima sesión o una petición manual. Un STARTED/ENDED conocido mantiene su
  ventana propia de procesamiento.
- Recurrente: también se considera el final local de la próxima sesión,
  respetando DST mediante schedule::next_session y duración local.
- Fuera de esas ventanas queda dormida hasta edición relevante o petición manual.
- Un error terminal no se rearma automáticamente en cada scheduler.

Lecturas y PATCH name admiten **cinco intentos por revisión**. HTTP 429, 5xx y
transporte usan backoff exponencial persistido (60, 120, 240, 480 segundos antes
del siguiente intento), además del backoff nativo de Moodle.
401/403/404, respuestas inválidas y contradicciones requieren revisión.
Los cuerpos de error y credenciales nunca se registran.

Retry manual: POST, sesskey, capacidad y relación local correcta; cooldown de
60 segundos y lock. Solo reabre el subsistema solicitado. Workers obsoletos no
confirman revisiones nuevas. HTTP después de persistir intento y fuera de transacciones.

## Nombre automático

~~~text
[actividad] - YYYY-MM-DD - HH-MM.mp4
[actividad] - YYYY-MM-DD - HH-MM - Parte 2.mp4
~~~

La fecha/hora deriva de **conferenceRecord.startTime**, en la zona guardada.
Puede diferir del horario programado si se inició antes/después.
No depende de la timezone por defecto de PHP.

Conserva Unicode, acentos y ñ; elimina controles; sustituye
\ / : * ? " < > | por separadores; normaliza espacios; evita base vacía/puntos
finales; limita a 240 bytes UTF-8 sin cortar caracteres y reserva el sufijo completo.
La primera grabación no lleva “Parte 1”.

Orden: startTime ascendente y nombre canónico como desempate determinista.
desiredfilename se fija en la primera preparación FILE_GENERATED y no cambia por
ediciones Moodle posteriores. Rename ready evita incluso volver a consultar Drive.

Si Google revela después un segmento anterior que exigiría renumerar nombres
congelados, se conserva el historial y el nuevo segmento queda skipped para revisión.
No se renombra el histórico ni se fabrica un nombre duplicado.
startnanos conserva la fracción de segundo RFC3339 de cada Recording para respetar
el orden incluso dentro del mismo segundo. Solo inicios exactamente iguales
usan el nombre canónico como desempate.

## Drive: exclusivamente metadatos

1. ID de Recording.driveDestination.file, nunca del navegador.
2. GET files/{id} con proyección id,name,mimeType,parents,trashed,capabilities(canRename).
3. Validar ID exacto, mimeType=video/mp4 y trashed=false.
4. Guardar nombre original observado antes de PATCH.
5. Nombre ya coincidente: ready sin PATCH.
6. canRename presente y distinto de true: skipped.
7. PATCH con cuerpo **únicamente name**.
8. Confirmar ID, MP4, nombre y parents inalterados en la respuesta.

La misma proyección GET/PATCH permite confirmar también la papelera.
No se envían parents, permissions, owners, description, appProperties,
content, media, addParents ni removeParents.
No hay búsqueda, listado Drive, descarga, cambio de permisos ni borrado.
Respuesta perdida tras PATCH: GET recupera el nombre y evita otro PATCH.
Comparar parents detecta discrepancias; no revierte movimientos externos.

## OAuth y configuración manual

Scopes adicionales finales, solo para issuers Google registrados en TUP Meet:

1. https://www.googleapis.com/auth/calendar.events.owned
2. https://www.googleapis.com/auth/meetings.space.settings
3. https://www.googleapis.com/auth/meetings.space.created
4. https://www.googleapis.com/auth/meetings.space.readonly
5. https://www.googleapis.com/auth/drive.metadata (**nuevo, restringido**)

Moodle añade sus scopes nativos de identidad; no se sustituyen.
No se solicitan drive, drive.readonly ni drive.meet.readonly.
No se añade otro scope Meet: created/readonly existentes cubren estas lecturas.

Antes del smoke staging:

1. Instalar/actualizar plugin y ejecutar actualización Moodle.
2. Habilitar **Google Drive API** en el proyecto Google Cloud correspondiente.
3. Revisar consentimiento, política Workspace y autorización del scope restringido.
   Producción/distribución puede exigir verificación OAuth y requisitos adicionales
   de políticas Google según la configuración de la aplicación.
4. Administración del sitio > Servidor > Servicios OAuth 2: reconectar la cuenta
   de sistema del issuer registrado, aceptando también drive.metadata.
5. Plugins > Módulos de actividad > TUP Meet: verificar identidad original.
   Reconectar cada cuenta histórica que deba renombrar. No reemplazar la identidad
   de un issuer histórico por otra cuenta.
6. Asegurar cron periódico y adhoc; revisar “Descubrir grabaciones de Meet pendientes”.
7. Quien abre el enlace necesita permisos Drive existentes. Ser docente Moodle
   no concede permisos nuevos en Drive.

No incluir Client ID, Client Secret, tokens, correos reales ni URLs privadas en Git.

## UI y privacidad

Grabaciones solo se renderiza con **moodle/course:manageactivities** en el módulo:
sesión real, estado, nombre observado (o previsto si todavía no se confirmó),
nombre original cuando esté disponible, enlace Drive y rename.
Se pagina localmente; renderizar no hace HTTP. STARTED/ENDED sin enlace.
FILE_GENERATED usa exportUri validada: HTTPS y host/ruta Drive del mismo fileId.
Enlaces target="_blank", rel="noopener noreferrer". Nombres escapados.
Estudiantes no reciben sección, enlaces ni diagnósticos.

Privacy declara tablas, identificadores, tiempos, nombres y transferencias Meet/Drive.
Son metadatos institucionales compartidos, sin listas de participantes ni inferencia
de participación personal. Los nombres pueden contener información personal escrita
en el título. El proveedor COHOST conserva su comportamiento; los metadatos compartidos
no se atribuyen exclusivamente al docente ni se borran por su baja.

Eliminar actividad borra primero grabaciones/conferencias locales.
No elimina Space, Calendar, Member, MP4, carpetas ni otros recursos Google.
Los originales permanecen bajo control y retención institucional.

## Smoke posterior — pendiente

### Reunión simple

1. Completar configuración OAuth/Drive y verificar identidad.
2. Crear TUP Meet normal con grabación automática.
3. Entrar únicamente como COHOST, grabar dos o tres minutos y finalizar.
4. Esperar procesamiento/cron o pulsar “Sincronizar grabaciones”.
5. Verificar conferencia real, FILE_GENERATED, fileId y exportUri.
6. Verificar nombre original observado y esperado usando inicio real.
7. Confirmar rename ready; abrir Drive con cuenta ya autorizada.
8. Confirmar que solo cambió nombre del MP4: misma carpeta, ubicación, propietario
   y permisos. No depende del nombre/estructura de la carpeta.
9. Repetir sync: sin cambios de nombre ni registros duplicados.
10. Editar título Moodle: nombre histórico permanece.
11. Confirmar que Calendar no fue fuente de descubrimiento.
12. Ver como estudiante: sin grabaciones ni enlaces.

### Serie y segmentos

1. Crear serie corta y realizar dos sesiones reales.
2. Confirmar un Space, distintas conferenceRecords y grabaciones por sesión.
3. Cada nombre usa fecha/hora real de su conferencia.
4. Detener/reiniciar grabación en una sesión: primera sin sufijo, segunda “Parte 2”.
5. Probar retry rename con autorización restaurada sin recrear otros recursos.
6. Registrar evidencia sin información sensible.

La validación Google real queda a cargo del staging autorizado.
No se ejecuta Google real en PHPUnit.

## Validación automatizada

Se conservan los 232 casos base; solo cambian expectativas de scopes/savepoint.
Nuevas pruebas usan servicios reales, XMLDB y colas con transporte OAuth simulado,
nombres, polling, upgrade, capacidades, POST/sesskey y enlaces.
Matriz: Moodle 4.5 / 5.0 / 5.1, PHP 8.3, MariaDB 10.11.
Workflow intacto. Los resultados locales no implican CI remoto ni smoke real.

Resultados finales del 19/09/2026, PHP 8.3.33 y MariaDB 10.11.14:

| Moodle | PHPUnit | Pruebas | Aserciones | Resultado |
| --- | --- | ---: | ---: | --- |
| 4.5.14 | 9.6.34 | 310 | 6640 | PASS |
| 5.0.10 | 11.5.55 | 310 | 6641 | PASS |
| 5.1.7 | 11.5.55 | 310 | 6641 | PASS |

Se conservan las 232 pruebas previas y se añaden 78 casos.
Ejecución completa con fail-on-warning, fail-on-risky, fail-on-incomplete y
fail-on-skipped; en PHPUnit 11 también fail-on-notice y fail-on-phpunit-deprecation.
Sin errores, advertencias, riesgos ni casos omitidos. Una respuesta vacía del
simulador de paginación se corrigió de array JSON a objeto JSON antes de esta
ejecución final; la validación estricta de producción se conservó.

PHPCS Moodle con warning-severity=1: PASS. Sintaxis de 79 archivos PHP: PASS.
git diff --check: PASS. Validación estructural Moodle Plugin CI y siete pares
upgrade/savepoint: PASS. Instalación XMLDB y upgrade real desde 0.6.1 probados en
la matriz; índices únicos confirmados además en las tres bases desechables.
Escaneo de patrones de credenciales: sin hallazgos en los 100 archivos del plugin.
No se modificaron el workflow ni los servicios/gestores previos de provisioning,
Calendar, COHOST, artifacts, schedule o PoC.

### Cobertura de los contratos solicitados

| Contratos | Evidencia automatizada |
| --- | --- |
| 1–8: Space, páginas, recurrencia, upsert e historial | recordings_test: full_metadata_flow, pagination_of_both_lists, recurrence_persists_multiple_conferences, absence_never_deletes_local_history |
| 9–19: recursos, estados, parent, orden y destinos | recordings_test: invalid_google_resource, processing_transitions, multiple_segments, fractional_start_order |
| 20–27: índices, instalación, upgrade y borrado local | upgrade_test, recording_policy_test: upgrade_preserves_history; recordings_test: local_delete_removes_children_only |
| 28–40: nombres, Unicode, zona y congelamiento | recording_policy_test: filename, filename_unicode_limit, filename_ignores_php_timezone; recordings_test: full_metadata_flow y multiple_segments |
| 41–56: scopes y límites Drive | cohost_test/meeting_manager_test: scopes; recordings_test: full_metadata_flow, drive_validation, patch_confirmation, lost_patch_response y drive_retry_budget |
| 57–68: tareas, propietario, polling y locks | recordings_test: scheduler_batch, interrupted_discovery, rename_recovery, not_before, lock_contention, http_never_runs_inside_transaction; recording_policy_test: dynamic_polling y recurring_polling_across_dst |
| 69–76: capacidades, estados, URLs y acciones | recording_ui_test: catálogo, enlaces, autorización, retry rename y sync manual |
| 77–85: regresión de fases previas | Suite anterior conservada; comprobación Git de CI, provisioning, Calendar, COHOST, artifacts, recurrencia y PoC sin cambios; sin modificaciones a core ni mod_googlemeet |

El transporte simulado admite únicamente lecturas de conferenceRecords/recordings
y GET/PATCH de un fileId Drive. Rechaza rutas de búsqueda, permisos y descarga.
Todas las solicitudes HTTP de las pruebas verifican que no hay transacción abierta.
Los errores simulados son sintéticos; no se utilizan cuentas, tokens ni archivos reales.

### Límites operativos

- El descubrimiento automático sigue el horario guardado. Una sesión celebrada
  fuera de las ventanas previstas puede requerir sincronización manual.
- Rename ready representa la última confirmación. Un cambio de nombre realizado
  posteriormente directamente en Drive no dispara otra consulta ni PATCH automático.
- Los segmentos que aparezcan después de cerrar la ventana de asentamiento pueden
  descubrirse manualmente mientras Google conserve los conferenceRecords.
- Conservar exportUri no concede acceso Drive: depende de permisos existentes.

## Archivos de la fase

### Nuevos (19)

- classes/local/google/drive_metadata_service.php
- classes/local/google/recording_exception.php
- classes/local/google/recording_service.php
- classes/local/google/recording_transport.php
- classes/local/recording/actions.php
- classes/local/recording/filename.php
- classes/local/recording/polling.php
- classes/local/recording/recording_manager.php
- classes/local/recording/rename_manager.php
- classes/output/recording_list.php
- classes/task/discover_recordings.php
- classes/task/rename_recording.php
- classes/task/sync_recordings.php
- db/tasks.php
- docs/PHASE4.md
- recordings.php
- tests/recording_policy_test.php
- tests/recording_ui_test.php
- tests/recordings_test.php

### Modificados (15)

- README.md
- classes/privacy/provider.php
- db/install.xml
- db/upgrade.php
- docs/ARCHITECTURE.md
- docs/ROADMAP.md
- lang/en/tupmeet.php
- lang/es/tupmeet.php
- lang/es_mx/tupmeet.php
- lib.php
- tests/cohost_test.php
- tests/meeting_manager_test.php
- tests/upgrade_test.php
- version.php
- view.php

No se eliminaron archivos. La rama base de Fase 3.4.1 permanece en su SHA original.
