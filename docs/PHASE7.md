# Fase 7 — Release Candidate y preparación del piloto

## Alcance, base y evidencia

- Base exacta: `codex/fase-6b-legacy-csv`, `ce2408587bc6068adcf53d837dc09ef75e8589b4`, árbol limpio al iniciar.
- Rama de trabajo: `codex/fase-7-release-candidate`.
- Versión: **2026092201 / 0.9.0-rc1 / MATURITY_RC**. La constante vale 150 en los tres cores probados.
- Feature freeze: no cambio de lógica funcional, DB, scopes, privacidad, UI, tareas ni workflow CI.
  No se identificó en esta auditoría un bug reproducible que requiriera una excepción al freeze.
- Las cinco tablas y los diez pasos históricos de upgrade permanecen intactos; el último es `2026092200`.
  El actualizador de Moodle avanza la metadata de versión después del upgrade sin cambios del plugin.

**Evidencia separada:** la base 6B tiene CI remoto aprobado
([ejecución 35767723395](https://github.com/victoryama-wq/moodle-mod-tupmeet/actions/runs/35767723395),
482 pruebas por Moodle). El propietario confirmó smoke staging aprobado hasta 6B, incluyendo CSV,
idempotencia, publicación y cronología combinada. Fase 7 verifica el RC localmente con datos sintéticos;
CI remoto del RC, smoke RC y piloto no se ejecutan por inferencia de esas aprobaciones previas.

## Auditoría de regresión

| Área | Código y contrato conservado | Cobertura existente/prueba RC |
|---|---|---|
| Cuentas/OAuth | `account_manager`, `oauth_client_factory`, callback en `lib.php`: issuer Google de servicios, identidad `sub`/correo verificado/Workspace, default bajo lock, `accountid` histórico fijo, reconectar misma identidad; cinco scopes actuales | `account_manager_test`, `oauth_client_factory_test`, `meeting_manager_test`, snapshot RC |
| Space | `space_service`, `space_manager`: checkpoint antes de POST fuera de transacción, lock `meeting:id`, revisión, canonical name; transporte ambiguo → uncertain sin segundo POST; 429 definitivo → backoff | `meet_first_test` |
| Calendar | `calendar_service`, `meeting_manager`: Meet-first sin createRequest, mismo Space/URI, attendee validado, sendUpdates=all, GET confirma IDs/marcador/revisión; edición conserva identidades | `meet_first_test`, `meeting_manager_test`, `calendar_transport_test` |
| Recurrencia | `schedule`: semanal, zona guardada, UNTIL inclusivo a la hora local del DTSTART convertido a UTC, sin fin artificial de día | `schedule_test`, pruebas Cancún/DST/nocturnas |
| COHOST | `cohost_identity`/`cohost_manager`/`member_service`: docente activo matriculado y autorizado, selección bloqueada, listar/crear/verificar, sin delete ni escritura automática en modelo Calendar histórico | `cohost_identity_test`, `cohost_test`, `meet_first_test` |
| Artifacts | `meet_service`/`meet_config_manager`: grabación y transcripción conservan defaults, PATCH solo dos hojas artifactConfig, estado propio sin bloquear Meet | `meet_config_test`, `meet_first_test` |
| Discovery | `recording_service`/`recording_manager`/`polling`: Space permanente, paginación acotada y tokens repetidos rechazados, conferenceRecords/recordings monotónicos, FILE_GENERATED y destino validados | `recordings_test`, `recording_policy_test`, `health_test` |
| Rename | `drive_metadata_service`/`rename_manager`: GET metadata y PATCH exclusivamente `name`, checkpoint original, mismo fileId/padres comprobados, no permisos ni media | `recordings_test`, `recording_policy_test` |
| Publicación | `recording_list`/`actions`: SQL filtra ocultas antes del conteo/paginación en ambos orígenes; automatic/manual inicializa nuevas filas; decisiones manuales conservadas | `publication_test`, `recording_ui_test`, `legacy_ui_test` |
| CSV | `csv_validator`/`importer`: UTF-8/5 MiB/5000 filas, exactitud tras espacios, destino Meet-first/ID explícito, cache sesión15min, confirmación revalida bajo locks/transacción, duplicados omitidos | `legacy_csv_test`, `legacy_import_test`, `legacy_schema_test`, `legacy_ui_test` |
| Privacy | `provider`: identidades y atribución, exportación acotada y borrado de atribución sin cambiar visibilidad ni recursos externos | Tests Privacy incluidos en `cohost_test`, `publication_test`, `legacy_ui_test` |
| Health | `health_service`/`health_dashboard`: consultas locales acotadas, estados/cron/cola sin probes Google, sin tablas nuevas ni reparación global | `health_test` |
| Instalación/upgrade RC | Maturity y metadata instaladas, campos/índices/capabilities/task/cache/privacy; actualizador core con snapshots íntegros | `release_candidate_test`; cadena previa en `upgrade_test`, `publication_upgrade_test`, `legacy_schema_test` |

Los identificadores usados por tests son sintéticos; no existe OAuth institucional conectado en las pruebas.
Los gestores persistentes no aceptan un correo arbitrario del navegador ni cambian propietarios durante edición.
Los errores externos saneados no exponen cuerpos OAuth. No hay llamadas destructivas implícitas.

## Matriz definitiva de endpoints

Todas las rutas reales requieren sesión Moodle y guardas antes del contenido/acción protegida.
El contexto y capability se comprueban incluso por URL directa; ocultar un botón no es una barrera de seguridad.

| Endpoint | Rol/capability | Método | Sesskey | Contexto | HTTP externo en solicitud | Mutación |
|---|---|---|---|---|---|---|
| `index.php` | Acceso al curso mediante `require_course_login`, instancias visibles filtradas por core | GET de listado | No | Curso | No | No mutación de negocio; Moodle puede registrar sesión/acceso |
| `view.php` | `mod/tupmeet:view` y acceso al módulo/curso | GET de lectura | No | Módulo | No | Evento viewed y completion nativos; no sincronización ni cambio de recursos |
| `recordings.php` sync/rename/hide/show/hidelegacy/showlegacy | `mod/tupmeet:view` y `moodle/course:manageactivities`, pertenencia de grabación al módulo | POST obligatorio; no es una página de lectura | Sí, en `actions::execute` y visibilidad legacy | Módulo | No en solicitud; sync/rename encolan HTTP posterior | Cola o visibilidad/atribución/evento local |
| `retry.php` | `moodle/course:manageactivities` y acceso al módulo | POST obligatorio | Sí antes de dispatch | Módulo | **Sí**, puede reconciliar inmediatamente tras guardar y también dejar tarea durable | Desired state/revisión y reconciliación explícita; uncertain no se desbloquea |
| `accounts.php` lectura | `moodle/site:config` | GET | No | Sistema | No | No mutación de cuenta |
| `accounts.php` registro/verificar/default/habilitar/deshabilitar | `moodle/site:config` | POST; Moodle form para registro | Sí; form nativo o guarda de acción | Sistema | Verificar/default/habilitar usan OAuth userinfo; registrar/deshabilitar son locales | Metadata de cuentas bajo lock; nunca accountid histórico |
| Conectar/reconectar desde accounts | `moodle/site:config`; flujo delegado a core | Enlace al flujo nativo OAuth | Confirmación/state/callback de Moodle core | Sistema | Sí, al completar autorización futura | Tokens administrados por core; no almacenamiento propio |
| `health.php` | `moodle/site:config` | GET | No | Sistema | No | Lectura local únicamente |
| `legacy.php` lectura/template/catalog | `moodle/site:config` | GET | No | Sistema | No | Lectura/export; una preview vencida puede limpiar cache de sesión, no tablas de grabaciones |
| `legacy.php` preview | `moodle/site:config` | POST por Moodle form + servicio | Sí | Sistema | No | Solo cache sesión TTL900; no filas de negocio |
| `legacy.php` confirm | `moodle/site:config` | POST verificado dentro de importer | Sí | Sistema | No | Inserciones legacy y evento de conteos, revalidación de datos guardados en servidor |

No hay lectura pública anónima que cambie recursos del plugin. “Solo lectura” no promete cero escrituras
de infraestructura Moodle: sesión, logging/completion y cache de sesión son excepciones explícitas anteriores.
Los endpoints de lectura pueden recibir métodos distintos sin realizar acciones: las mutaciones requieren POST.
`lib.php`, `mod_form.php`, `settings.php`, `version.php`, `db/*.php`, clases, tests y fixtures no son rutas
de aplicación independientes; Moodle los carga como callbacks/definiciones. La PoC ya no tiene endpoint.
Los scripts de auditoría son CLI y no están registrados como páginas web Moodle.

### Roles y pruebas

| Perfil estándar | Académico | Panel técnico/visibilidad/retry | Accounts/Health/CSV global |
|---|---|---|---|
| Estudiante matriculado | Sí, filas visibles | No | No |
| Docente editor del curso | Sí | Sí, en sus módulos autorizados | No |
| Administrador o rol con siteconfig concedido | Según acceso | Según capabilities | Sí |

`manager` nominal no basta: las páginas globales comprueban `moodle/site:config` en sistema.
La suite comprueba guards de las siete rutas, permisos reales de Moodle y acciones GET/POST/sesskey/contexto
en servicios, además de filtrado/escape de salida. Las comprobaciones HTTP locales complementarias se
registran en resultados; no sustituyen una prueba por roles en el staging institucional.

## Revisión de cron/tareas

| Trabajo | Protección/revisión | Retry y límites conservados |
|---|---|---|
| `discover_recordings` | Programada `*/5`; dispatch local, máximo 25 actividades y 25 renames por invocación | SQL de vencimientos, sin HTTP directo ni bucle recursivo inmediato |
| `sync_space` | Dedupe de tarea, lock meeting, revisión/status y checkpoint previo al POST | Normal inmediatamente elegible; 429 con not-before exponencial/jitter y backoff core; uncertain/error retiran tarea, solo rechazo definitivo permite retry explícito |
| `sync_meeting` | Evento ID/marcador/revisión estables; lock y escritura condicional, tareas viejas sin efecto | **Sin presupuesto finito del plugin**, backoff Moodle. Riesgo persistente documentado y monitorizado |
| `sync_cohost` | Dedupe por actividad/revisión; lock, identidad revalidada, listado y verificación antes/después | Cinco intentos por revisión, backoff core; sin members.delete |
| `sync_meet_config` | Dedupe/revisión/lock; no cambia canonical Space | Cinco intentos por revisión, backoff core |
| `sync_recordings` | Dedupe/lock/revisión/owner/Space, lease de 30 min, cooldown manual60s | Cinco intentos por revisión; not-before60/120/240/480s ante fallos transitorios más backoff core; polling adaptativo mínimo5min |
| `rename_recording` | Dedupe/lock meeting y revisión/filename/fileId; lease30min/cooldown60s | Cinco intentos por revisión; retry transitorio espaciado, definitivo en error/skipped; GET permite recuperar respuesta perdida |

HTTP ocurre fuera de transacciones. Tareas con registro eliminado o revisión obsoleta se retiran;
callbacks guardan estado+cola antes del HTTP. La deduplicación corresponde a una revisión: ediciones sucesivas
pueden dejar tareas viejas que cron descarta, no se afirma un máximo global independiente del uso.
No se halló un loop inmediato ni fan-out ilimitado dentro de los dispatchers auditados. Los límites de lote
y revisiones no equivalen a prueba de carga masiva. Health advierte cron >15min, vencidas >30min o pendientes >1000.

## Secretos, referencias y paquete

Se revisaron patrones de credenciales, bearer/authorization, correos, dominios, IDs/URLs Drive/Meet,
referencias específicas de staging, loopback, archivos CSV y artefactos locales.

- No se encontraron credenciales reales, CSV institucional ni IDs/URLs privados versionados.
- Correos literales: `example.invalid`; URLs hostiles `.example`/`.invalid` son casos negativos.
  IDs Drive literales aparecen en tests con valores Synthetic/Fixture/File/Private y equivalentes sintéticos.
- Nombres de institución en copyright y evidencia general intencional no son una cuenta OAuth.
- `authorization` es un nombre de endpoint/core o texto; tokens mencionados en documentación/tests de
  no-exposición no son valores de autenticación. Los valores `moodle` del workflow son credenciales
  públicas de un contenedor CI desechable, no secretos institucionales.
- Loopback solo en CI/instrucciones de reproducibilidad local, no como destino productivo.
  No se identificaron URLs de staging incrustadas en producción que requirieran anonimización.
- PoC ausente de rutas/clases/tests de ejecución. Documentación histórica se conserva identificada como tal.
- `tools/audit_package.py`: auditoría de blobs HEAD o worktree, rutas prohibidas, patrones de alta confianza
  y enlaces relativos. No crea ZIP, no ejecuta Moodle, no consulta red; los patrones no demuestran por sí
  solos ausencia absoluta de todo posible secreto. La revisión humana clasifica fixtures y referencias.

## Reproducción de validaciones

En entornos **desechables** con DB y dataroot PHPUnit distintos de los reales y plugin enlazado:

```sh
# Desde el directorio web Moodle; --drop destruye solo el entorno PHPUnit configurado.
# Confirmar antes phpunit_prefix/phpunit_dataroot y que no hay otra suite usando ese entorno.
php admin/tool/phpunit/cli/util.php --drop
php admin/tool/phpunit/cli/util.php --install
```

En Moodle5.1 esos scripts están bajo `public/admin/...`, mientras `vendor/bin/phpunit` y `phpunit.xml`
están en la raíz del checkout. No copiar un config real para ejecutar comandos destructivos de tests.
Desde cada raíz Moodle:

```sh
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite --fail-on-warning --fail-on-risky --fail-on-incomplete --fail-on-skipped
```

Con PHPUnit11 también se usaron `--fail-on-notice --fail-on-phpunit-deprecation`.
Desde el plugin con las herramientas instaladas fuera del repositorio:

```sh
php /ruta/herramientas/phpcs --standard=moodle --extensions=php --warning-severity=1 .
php /ruta/moodle-plugin-ci savepoints .
php /ruta/moodle-plugin-ci validate --moodle=/ruta/moodle .
git -c core.autocrlf=false diff --check
python3 tools/audit_package.py --worktree
```

Sintaxis: `php -l` de cada PHP del inventario, incluidos tests nuevos.
No se generan ZIP ni artefactos de tests dentro del plugin.

### Resultados locales del RC

Suite completa con transportes Google simulados, PHP 8.3.33 y MariaDB 10.11.14:

| Moodle | PHPUnit | Pruebas | Aserciones | Resultado |
|---|---|---:|---:|---|
| 4.5.14, build 20260914 | 9.6.34 | 487 | 8018 | PASS |
| 5.0.10, build 20260914 | 11.5.55 | 487 | 8019 | PASS |
| 5.1.7, build 20260914 | 11.5.55 | 487 | 8019 | PASS |

Se conservaron los 482 casos de la base y se añadieron cinco: tres rutas al proveedor de guardas
y dos pruebas RC de manifest instalado y preservación íntegra mediante el actualizador real de Moodle.
Las suites se ejecutaron con las opciones estrictas anteriores; no hubo fallos, warnings ni tests omitidos.
La diferencia de una aserción entre Moodle 4.5 y 5.x no cambia el inventario de casos.

Instalaciones PHPUnit desde cero: **PASS en 4.5/5.0/5.1**, tablas del prefijo de pruebas eliminadas e
instaladas de nuevo mediante core; sin configuración Google real. Pruebas RC enfocadas: **12/204 por versión**.
El test de upgrade inserta propietarios históricos y actividades meet/calendar/legacy, nativas ocultas/visibles,
renames en error y referencias legacy; compara **todos los campos de todas las filas** en cinco tablas
tras `upgrade_plugins_modules`, y comprueba versión final y ausencia de nuevas tareas del plugin.

HTTP local Moodle 4.5: **31/31 comprobaciones PASS**, con usuarios sintéticos estudiante/docente editor/
rol con siteconfig, sitio desechable sin issuer, cuenta maestra ni tareas del plugin. Las siete URLs anónimas
redirigen a login; accounts/health/legacy deniegan a estudiante y docente y permiten al rol siteconfig.
Se verificaron catálogo académico, ausencia de URL oculta en HTML, POST sin permiso, GET de mutación,
sesskey inválida y hide/show legacy con efecto visible en otra sesión de estudiante.
Las excepciones nativas se identificaron por tipo/código, no suponiendo HTTP403: el core en modo debug
local respondió HTTP500 a algunos rechazos previstos. No fue un error de negocio ni un bypass.

El servidor PHP Windows requirió un router externo por sus junctions: se copiaron las siete rutas sin
cambiar sus bytes (hashes comparados), usando config/lib de enlace al mismo core y plugin. No se parcheó
ningún endpoint para la prueba. Servidor cerrado al terminar; toda petición limitada a loopback y
redirecciones externas bloqueadas. No se siguieron enlaces Drive/Meet.

Auditoría de fuentes/paquete: **135 archivos / 86 enlaces relativos PASS**, más **12 casos negativos
sintéticos PASS** (env, dependencias, DB/CSV, configuración/resultados PHPUnit, cache, PoC, secreto ficticio
y enlace roto). No se creó ZIP. PHPCS: cero errores/warnings; sintaxis: **99 PHP PASS**;
estructura del plugin y **10 savepoints ordenados/coincidentes PASS**.

La primera instalación de los entornos encontró warnings en LTI de Moodle core porque el PHP Windows
no tenía `openssl.cnf` localizado. Se identificó como problema del entorno, separado del plugin;
el chequeo de generación/export OpenSSL pasa al definir `OPENSSL_CONF` para el proceso.
Se repitió la instalación completa desde bases PHPUnit limpias con esa configuración: **exit 0 en las
tres versiones, sin warnings, deprecated, errores fatales ni reparación XMLDB en los registros finales**.
Tras esa reinstalación se repitieron las pruebas RC/endpoints: **12 pruebas / 204 aserciones PASS por versión**.
No se modificó Moodle core ni código productivo para resolverlo. En Windows, los procesos PHP CLI de
esta validación requieren `OPENSSL_CONF` apuntando a un `openssl.cnf` válido de la instalación local.
Este requisito de entorno no añade configuración ni archivos al plugin.

## Documentación entregada y siguientes autorizaciones

[CHANGELOG](../CHANGELOG.md), [PRODUCTION_INSTALL](PRODUCTION_INSTALL.md), [ROLLBACK](ROLLBACK.md),
[KNOWN_LIMITATIONS](KNOWN_LIMITATIONS.md), [ADMIN_RUNBOOK](ADMIN_RUNBOOK.md),
[TEACHER_GUIDE](TEACHER_GUIDE.md), [PILOT](PILOT.md), [README](../README.md) y [ROADMAP](ROADMAP.md).
Las notas de fases históricas separan estado original de CI/smoke posteriores, sin reescribir esa historia.

Pendientes: revisión del commit RC, push/CI remoto autorizado, paquete autorizado, smoke RC y piloto
institucional autorizado. Riesgos abiertos: Calendar persistente, resultados Space inciertos, permisos Drive
fuera de Moodle, retención/procesamiento/licencias y falta de backup/restore por actividad, detallados en limitaciones.
No se efectuaron merge, push, deploy, release, piloto, CSV real, llamadas autenticadas a Google ni cambios Drive.
