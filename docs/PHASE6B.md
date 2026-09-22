# Fase 6B — migración histórica de grabaciones por CSV

## Cierre posterior a la implementación

Base aprobada: `ce2408587bc6068adcf53d837dc09ef75e8589b4`.
[CI remoto 35767723395](https://github.com/victoryama-wq/moodle-mod-tupmeet/actions/runs/35767723395):
QUALITY y las tres versiones PASS; Moodle 4.5 **482 pruebas / 7848 aserciones**,
Moodle 5.0 y 5.1 **482 / 7849** cada una. Esto reemplaza el conteo local parcial de 481
que aparece en la evidencia original, anterior al último caso añadido.

Al autorizar Fase 7, el propietario confirmó smoke real staging aprobado hasta Fase 6B, incluyendo
CSV, idempotencia, visibilidad legacy y cronología combinada. Es evidencia del propietario;
no se volvió a importar un CSV real ni a llamar a Google durante Fase 7.
La sección “Smoke posterior (no ejecutado)” siguiente conserva su estado histórico original.
La validación del nuevo RC se separa en [PHASE7](PHASE7.md).

## Base y alcance

- Rama: `codex/fase-6b-legacy-csv`.
- Base exacta: `ba4899f6b758f86be548ef7e1a83b5228413a6da`, Fase 6A.
- Versión: **2026092200 / 0.8.3-alpha**, maturity alpha.
- El responsable confirmó CI remoto, upgrade y smoke de Fase 6A: menús,
  retirada de PoC, Meet, videos y vistas intactos. Es evidencia de la base,
  no constituye smoke del importador.
- Validación local sin Google real, inventario institucional, push, merge,
  deploy o release. CI remoto y smoke de 6B requieren autorización posterior.

Se importan referencias a MP4 históricos ya disponibles, nunca archivos de video.
Drive conserva ubicación, nombre, permisos, propietario y contenido. Administración
valida externamente existencia y acceso: el plugin no hace ninguna comprobación remota.
Sin scopes nuevos, files.list, búsqueda de carpetas, Meet/Calendar históricos,
transcripciones, dependencia de mod_googlemeet, backup/restore ni Fase 7.

## Arquitectura y esquema

```text
CSV normalizado -> csv_validator -> importer (preview / confirmación)
                                  -> tupmeet_legacy_recordings
Meet API -> tupmeet_conferences -> tupmeet_recordings (flujo intacto)
                                  + referencias legacy
                                  -> una tabla académica paginada
```

Servicios, formulario, exportador y renderer separados de legacy.php. No se insertan
recursos Meet ficticios ni filas legacy en tablas nativas. Discovery, rename, tareas,
Google, health y workflow CI se conservan.

Nueva tabla `tupmeet_legacy_recordings`, sin otra tabla de batches/logs:

| Campo | Tipo / default |
| --- | --- |
| id | int(10), secuencia, PK |
| tupmeetid | int(10), FK lógica XMLDB a tupmeet.id |
| sessionname | char(255) |
| sessionstart | int(10), instante Unix |
| partnumber | int(4), default 1 |
| drivefileid | char(200), índice único global |
| exporturi | text, URL canónica |
| originalfilename | char(255), auditoría, nunca identidad |
| studentvisible | int(1), default 1 |
| visibilitymodified, visibilityuserid | int(10), default 0 |
| importedat | int(10) |
| importeduserid | int(10), default 0 |
| timecreated, timemodified | int(10) |

Todos NOT NULL. Índices `studentlist(tupmeetid,studentvisible,sessionstart)`,
`visibilityuserid`, `importeduserid`. No FK a user: cero permite anonimización.
Instalación, upgrade y savepoint **2026092200** sincronizados. Upgrade solo crea
tabla/índices: no importa datos, cambia preferencias, encola ni realiza HTTP.

## Formato CSV oficial

```csv
session_name,session_date,session_time,part,drive_url,original_filename,visible,tupmeetid
```

Headers exactos en ese orden, coma, UTF-8 (BOM opcional), comillas CSV para comas
y comillas interiores. Máximo **5 MiB (5 242 880 bytes)** y **5000 filas de datos**;
líneas vacías se omiten. Plantilla solo con headers, sin datos reales ni enlaces.
`tupmeetid` es una celda opcional, no un header omitible.

- session_name: nombre académico normalizado externamente, 1–255 caracteres.
- session_date: YYYY-MM-DD; session_time: HH:MM, 24 horas.
- part: entero 1–9999, no inferido de nombres Google.
- drive_url: exclusivamente https://drive.google.com/file/d/{FILEID}/...
- original_filename: nombre original, 1–255 caracteres, conservado para auditoría.
- visible: 0/1; vacío significa **1**, independientemente de publicationmode.
- tupmeetid: ID local explícito o vacío para matching exacto normalizado.

No hay heurísticas `(####)`, Recording 2, carpetas ni parser de nombres Google.
Esa preparación es externa y sustituye el planteamiento preliminar de 6A.
No se incorporan inventarios institucionales al repositorio.
Los espacios originales de session_name y original_filename se conservan al guardar;
la normalización de espacios de session_name se aplica solo a la comparación.

### Parsing, URL y hora

Se usa `fgetcsv` sobre un flujo acotado, cerrado en finally. No hay explode ni
str_getcsv por línea. Se evita csv_import_reader porque las versiones objetivo
reescriben celdas mediante csv_export_writer y su escape de fórmulas: un título
que empieza con `=` perdería fidelidad para matching. Los exports sí usan CSV API
nativo Moodle, con defensa adicional para fórmulas precedidas por espacios/controles.

IDs `[A-Za-z0-9_-]{1,200}`, misma política que recording_service. Se rechazan hosts
alternos, credenciales en URL, puertos, HTTP, folders y esquemas activos. Se guarda
solo `https://drive.google.com/file/d/{FILEID}/view`, construida por servidor sin
query ni fragmento originales. No se consulta Google.

Fecha/hora usa la timezone guardada de la actividad, independiente de PHP y UTC.
Round-trip estricto rechaza fechas/horas normalizadas, huecos DST y horas repetidas
ambiguas. El formato no tiene offset para desambiguarlas; requieren revisar el
inventario, no se adivina una ocurrencia.

## Matching y catálogo

Solo actividades **Meet-first** con curso y course_module mod_tupmeet reales,
sin eliminación en progreso. También se rechazan IDs explícitos calendar/legacy.

Matching: trim + espacios consecutivos normalizados; exacto, sensible a mayúsculas
y acentos, sin fuzzy. Cero resultados bloquea; dos o más bloquean como ambigua.
ID explícito válido manda; diferencia de título genera advertencia sin renombrar.

Catálogo: `tupmeetid,courseid,course_shortname,activity_name,timezone,publicationmode`,
solo destinos elegibles. Sin Google IDs, cuentas u OAuth. Celdas ejecutables
(`=`, `+`, `-`, `@`, aun tras espacios/controles) llevan apóstrofo protector. No cambia DB.

## Preview y confirmación

Administración del sitio > Plugins > Módulos de actividad > TUP Meet >
**Migración histórica**, `/mod/tupmeet/legacy.php`, exclusivamente site:config.
Login y capacidad preceden upload, exports, preview y confirmación.

Subir no importa. **Revisar importación** presenta fila, sesión, fecha/hora local,
parte, destino, visible y resultado: CORRECTA, DUPLICADA/OMITIDA, ADVERTENCIA,
SIN DESTINO, AMBIGUA, ERROR. Resumen completo, máximo 50 filas por página,
texto escapado, sin IDs/URLs Drive ni filas serializadas en hidden fields.
Una fila bloqueante impide todo el lote.

Caché Moodle de sesión `legacy_preview`, exclusiva de 6B: un único preview activo
reemplazable, TTL **900 segundos**, vencimiento explícito además del TTL del backend,
userid, token aleatorio, SHA256 de bytes y huella de resolución. No es caché PoC.
Cambiar usuario, expirar o alterar hash/token bloquea confirmación. CSV temporal
solo en draft/session/temp Moodle; nunca se incluye en logs ni repositorio.

**Importar grabaciones válidas** exige POST, sesskey y site:config en el servicio.
Recibe solo token; recupera bytes originales y comprueba hash/usuario/vencimiento.
Reparsea y resuelve destinos bajo locks. Cambio de título, zona, curso o módulo
exige nuevo preview. Duplicados aparecidos entretanto se omiten de forma segura.
Un nuevo preview invalida al anterior; confirmar correctamente consume el token.

## Duplicados, concurrencia y atomicidad

- Dentro del CSV, tabla legacy o tupmeet_recordings.drivefileid: omitido, no fatal.
  Nunca actualiza visibilidad o metadatos de una referencia previa.
- Reimportar requiere otro preview; cero filas nuevas para referencias existentes.
- Consultas de duplicados agrupadas en lotes de 500 IDs.
- Índice único legacy como última barrera. Colisiones de IDs diferentes solo en
  mayúsculas se omiten conservadoramente ante collations no sensibles a caso.
- Lock global de importación y locks meeting:{id} ordenados. Contención devuelve
  mensaje de reintento sin inserts. Revalidación después de obtener locks.
- Transacción única del lote válido; una excepción revierte todas sus nuevas filas.
  SELECT FOR UPDATE de destinos serializa borrados con transacción core exterior.
  Validado en MariaDB 10.11; no se afirma compatibilidad SQL Server/Oracle.
- No se escriben tablas nativas ni tareas. La comprobación contra native se hace
  al confirmar; no arbitra descubrimientos futuros de otra actividad. Usar inventario
  realmente histórico, evitando referencias de reuniones todavía activas.

Evento de importación: contexto sistema, actor Moodle, importedcount/duplicatecount.
Sin CSV, nombres, URL o file IDs. Cada referencia conserva importedat/importeduserid.

## Integración académica y ojo

UNION ALL paginado con clave interna compuesta evita colisiones de IDs locales.
Orden global: sessionstart/conference start DESC, partnumber ASC, luego orden
nativo y desempate local. No se altera numeración ni discovery nativo.

Estudiante: SQL studentvisible=1 en ambas ramas antes del contexto, incluido el
conteo. No recibe fuente, nombres originales, IDs sueltos, atribución ni controles.
Legacy aparece Disponible / Ver grabación, mismo aspecto, target blank y
noopener noreferrer. Render no consulta Google. Ocultar no revoca acceso Drive.

Gestor: todas las filas, ojo nativo (visible t/hide, oculta t/show). Acciones fijas
hidelegacy/showlegacy, POST + sesskey + manageactivities + contexto + pertenencia.
Nunca acepta table/source del navegador. Cambia solo studentvisible,
visibilitymodified y visibilityuserid, con lock y evento local, sin HTTP.
Repetir la misma acción no alterna ni duplica eventos. Panel técnico agrega solo
el conteo de históricos, sin fileId ni rediseño de health.

Editar título conserva tupmeetid y sessionname histórico: sin rematch/rename.
Borrar actividad elimina filas locales legacy, nunca video o recursos Google.

## Privacy API

Metadata declara datos compartidos y atribución de ambos actores. Contextos y
get_users_in_context consideran importador/visibilidad, excluyendo cero.
Exporta solo atribución aprobada, en rutas separadas por actor, sin IDs/URLs Drive,
nombres ni atribución ajena. Erasure individual/lista/contexto pone importeduserid=0
y, cuando corresponde, visibilityuserid=0/visibilitymodified=0 bajo el mismo lock.
Conserva fila, studentvisible, importedat institucional y metadatos compartidos.
Moodle administra logs, draft files y caducidad de sesión mediante sus políticas.

## Pruebas

Se conservan los casos de Fase 6A. Se ajustan solo expectativas del savepoint final
y la ausencia de la caché PoC: db/caches.php ahora define exclusivamente legacy_preview.

Cobertura nueva: parser/límites/Unicode, URL hostiles, fechas/DST, matching, IDs,
duplicados, reimportación, hash/token/actor/TTL, POST/sesskey, revalidación,
transacción/rollback, exports/fórmulas, XMLDB/upgrade/índices, combinación/paginación,
filtro SQL, ojo/eventos, Privacy y borrado local. Google simulado en regresiones.
Validación local del **22/09/2026**, PHP **8.3.33**, MariaDB **10.11.14**, Windows:

| Moodle | Suite completa | Pasada final 6B | Resultado |
| --- | --- | --- | --- |
| 4.5.14 | 481 pruebas / 7844 aserciones | 111 pruebas / 410 aserciones | PASS |
| 5.0.10 | 481 pruebas / 7845 aserciones | 111 pruebas / 410 aserciones | PASS |
| 5.1.7 | 481 pruebas / 7845 aserciones | 111 pruebas / 410 aserciones | PASS |

La suite completa se inició antes del último caso que comprueba visible=1 con
publicationmode=manual. La pasada final repite todas las pruebas 6B, incluyendo
ese caso, la conservación literal de nombres y la declaración de metadata.
No se suman ejecuciones repetidas: el inventario final tiene **482 casos únicos**,
**371 de la base conservados y 111 nuevos**. Todas las ejecuciones terminaron con
exit 0, sin errores, warnings, casos riesgosos, incompletos ni omitidos.
PHPUnit 9.6.34 en 4.5 y 11.5.55 en 5.x; estas últimas también fallan ante notices
y deprecaciones de PHPUnit.

Comando de suite desde cada raíz Moodle:

```sh
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite --fail-on-warning --fail-on-risky --fail-on-incomplete --fail-on-skipped
```

La pasada final usa además `--filter 'legacy_(csv|import|schema|ui)_test'`.

Otros resultados: PHPCS Moodle sin errores/advertencias; sintaxis de **98 archivos
PHP** PASS; instalación/inicialización PHPUnit en las tres versiones PASS; XMLDB,
upgrade real e índices PASS; diez parejas upgrade/savepoint ordenadas y coherentes
PASS; validación de estructura del plugin y git diff --check PASS. Revisión de
patrones de credenciales sin hallazgos. Servicios Google/OAuth, tareas, health y
workflow CI sin cambios funcionales. No se realizó push ni CI remoto de esta fase.

### Trazabilidad de los contratos solicitados

| Contratos | Evidencia local |
| --- | --- |
| 1–5: instalación, upgrade, índices, identidad única | legacy_schema_test: esquema real, drop/recreate por upgrade, clave única y ausencia de tareas |
| 6–28: formato, límites, texto, fecha, parte, visibilidad, URL | legacy_csv_test: proveedores válidos/hostiles, BOM/comillas, 5 MiB/5000 filas, timezone PHP distinta, hueco y repetición DST |
| 29–39: matching y duplicados | legacy_import_test: coincidencia exacta/ambigua, módulo/curso/modo real, ID explícito, título distinto, CSV/legacy/native y reimportación |
| 40–55: preview, autorización y atomicidad | legacy_import_test + legacy_schema_test: tablas sin cambios en preview, sesión/hash/token/TTL, POST/sesskey/capacidad, resolución alterada y rollback después del primer insert |
| 56–59: exportación segura | legacy_import_test: catálogo acotado, fórmulas incluso tras espacios, plantilla exacta y ausencia de metadatos Google |
| 60–79: UI y ojo | legacy_ui_test: HTML estudiante, filtros, atributos del enlace, ambas fuentes con IDs coincidentes, orden/paginación y acciones protegidas |
| 80–89: Privacy y borrado | legacy_schema_test + legacy_ui_test: metadata, actores/contextos, export mínimo, tres entradas de anonimización, fila/visibilidad intactas y borrado local |
| 90–105: regresión y límites | suite previa conservada; health, Meet, Calendar, COHOST, artifacts, discovery, rename, publicación y ojo nativo; diff de servicios Google/scopes/tareas/CI sin cambios |

Los casos de límites y seguridad usan valores sintéticos. La revisión estática de
alcance complementa PHPUnit; no sustituye el smoke visual ni el CI remoto pendiente.

Fuentes verificadas: lib/csvlib.class.php de instalaciones locales objetivo y
[API Cache Moodle](https://moodledev.io/docs/5.0/apis/subsystems/muc).

## Inventario del cambio

16 archivos nuevos:

- classes/event/legacy_recording_visibility_changed.php
- classes/event/legacy_recordings_imported.php
- classes/form/legacy_import_form.php
- classes/local/legacy/csv_export.php
- classes/local/legacy/csv_validator.php
- classes/local/legacy/importer.php
- classes/local/legacy/visibility.php
- classes/output/legacy_preview.php
- db/caches.php
- docs/PHASE6B.md
- legacy.php
- tests/fixtures/legacy.php
- tests/legacy_csv_test.php
- tests/legacy_import_test.php
- tests/legacy_schema_test.php
- tests/legacy_ui_test.php

20 archivos modificados:

- README.md
- classes/local/recording/actions.php
- classes/output/recording_diagnostics.php
- classes/output/recording_list.php
- classes/privacy/provider.php
- db/install.xml
- db/upgrade.php
- docs/ARCHITECTURE.md
- docs/ROADMAP.md
- lang/en/tupmeet.php
- lang/es/tupmeet.php
- lang/es_mx/tupmeet.php
- lib.php
- recordings.php
- settings.php
- tests/health_test.php
- tests/publication_upgrade_test.php
- tests/recording_policy_test.php
- tests/upgrade_test.php
- version.php

Sin eliminaciones. db/caches.php es una definición nueva exclusiva del importador;
no restaura ninguna clase, ruta o caché PoC.

## Smoke posterior (no ejecutado)

1. Actualizar staging 0.8.2-alpha -> 0.8.3-alpha con autorización; comprobar tabla.
2. Abrir Migración histórica como admin; rechazar docente/estudiante.
3. Descargar plantilla/catálogo, preparar externamente 2–3 referencias aprobadas.
4. Preview: revisar destinos, zonas, partes, warnings y visibilidad.
5. Corregir filas bloqueantes, revisar otra vez y confirmar explícitamente.
6. Docente: native + legacy juntas, conteo técnico y ojo.
7. Estudiante: solo visibles, sin metadatos de ocultas en HTML.
8. Ocultar/mostrar: desaparición/reaparición, sin cambiar permisos Drive.
9. Ver grabación: mismo archivo, nombre, ubicación, propietario y permisos.
10. Reimportar: duplicadas omitidas. Probar Parte 2 e ID con título cambiado.
11. Cambiar destino tras preview y probar vencimiento a 15 minutos.
12. Comprobar Privacy/health y registrar evidencia sin datos sensibles.

## Riesgos y límites

- Existencia, MIME y acceso Drive dependen del inventario externo validado.
- Ambigüedad de nombres se resuelve con ID del catálogo, nunca inferencia.
- Ocultar en Moodle no impide compartir enlaces entre lectores Drive autorizados.
- Preview ocupa hasta 5 MiB de datos temporales de sesión; solo uno activo.
- Un lote de 5000 filas mantiene locks durante su transacción; preferir lotes
  pequeños al convivir con trabajo normal y comprobar capacidad en staging.
- Horas DST ambiguas bloqueadas; se requiere revisar el inventario.
- Sin inventarios reales en Git, sin scopes nuevos ni Fase 7.
