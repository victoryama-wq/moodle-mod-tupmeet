# Fase 3.4 — Meet-first para actividades nuevas

Versión: `2026091801` / `0.6.0-alpha`.
Base: `195e14076e491945207c0dc1afc11f68a8d98b0d`.
Rama: `codex/fase-3-4-meet-first-production`.

## Evidencia y alcance

El responsable reportó un smoke real de la PoC en Moodle staging + Google Workspace:

- Space creado mediante Meet REST: PASS.
- COHOST creado y verificado: PASS.
- artifactConfig aplicado: PASS.
- Calendar native reutilizó el mismo meetingUri, sin segundo Meet: PASS.
- Docente COHOST entró sin la cuenta organizadora: PASS.
- La grabación automática comenzó y quedó en Drive del organizador institucional, identificado por el responsable como GTU REGIÓN SUR.

Es evidencia manual comunicada por el responsable. No son llamadas realizadas durante esta implementación ni una garantía universal de licencias/políticas Workspace. El nuevo flujo integrado todavía requiere smoke propio en staging; no se ha desplegado ni ejecutado contra Google real.

## Modelo persistente

Se añaden siete campos a `tupmeet`, sin tablas nuevas ni datos personales nuevos:

| Campo | XMLDB | Finalidad |
| --- | --- | --- |
| provisionmode | char(8), calendar | `legacy`, `calendar` o `meet`; no editable desde formulario |
| spacestatus | char(12), pending | `pending`, `creating`, `ready`, `error`, `uncertain` |
| spaceversion | char(32), legacy | Revisión aleatoria independiente del horario, Calendar y artefactos |
| spaceattempts | int(10), 0 | Número acumulado de intentos POST marcados antes del envío |
| spacemodified | int(10), 0 | Última transición del estado Space |
| spacenextattempt | int(10), 0 | Límite inferior para reintentar un 429 explícito |
| spacehttpstatus | int(3), 0 | Solo HTTP normalizado; 0 si desconocido/transporte |

El upgrade `2026091801` clasifica `syncstatus=legacy` como `legacy` y todas las demás filas existentes como `calendar`. No infiere origen a partir de un nombre Space, no toca accountid/identificadores/cohost manual, no ejecuta HTTP y no encola reconciliaciones. El valor predeterminado SQL `calendar` es conservador para filas históricas o inserciones internas; el servicio normal de creación fija siempre `meet`, ignorando valores suministrados por el cliente. Los campos Space de filas históricas no activan creación.

`meetspacename=spaces/{id}` es la identidad permanente. Se validan nombre canónico, URI HTTPS Google Meet y código consistente. Un alias con forma de meetingCode se rechaza como identidad permanente. URI y código se conservan para entrada/representación Calendar; nunca sustituyen la identidad canónica.

## Orden y transacciones

1. La transacción Moodle guarda horario, preferencias, propietario histórico, docente validado y revisiones.
2. En esa transacción se encola únicamente `sync_space`; un rollback también elimina el trabajo.
3. Tras commit, el observador intenta trabajar inmediatamente; cron conserva la recuperación duradera.
4. `sync_space` toma `meeting:{id}`, comprueba revisión/estado y autoriza con el accountid histórico.
5. Antes del único POST marca `creating`, incrementa intentos y guarda fecha, fuera de transacciones.
6. Tras respuesta válida, una transacción guarda identidad + `ready` y encola tres tareas independientes: COHOST, artifactConfig y Calendar.
7. El observador también intenta esos tres servicios por separado. Una excepción no impide los siguientes. Todos comparten el bloqueo por actividad y hacen HTTP fuera de transacciones.

Calendar, artefactos y COHOST tienen sus propias revisiones; una edición de horario no invalida un resultado de COHOST/artefactos del mismo Space. Las escrituras locales condicionadas rechazan revisiones obsoletas. Cambiar cuenta predeterminada no cambia ninguna actividad ni la autenticación de sus tareas.

## Creación no idempotente y resultados inciertos

[spaces.create](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces/create) documenta `POST /v2/spaces` y el scope `meetings.space.created`, pero no requestId ni clave de idempotencia. Por ello no existe garantía de exactamente una creación remota frente a un resultado desconocido. Esta fase prefiere detenerse a producir un duplicado.

- `pending`: elegible para primer intento inmediato o reintento tras un 429 confirmado.
- `creating`: marca duradera previa al POST; no implica que Google haya respondido.
- `ready`: identidad válida confirmada y guardada.
- `error`: fallo previo al envío o rechazo HTTP definitivo permitido: 400/401/403/404/405/422. Solo acción explícita puede renovar la revisión y reintentar después de corregir la causa.
- `uncertain`: timeout, fallo de transporte, respuesta incompleta/inválida, 408/409/5xx u otra respuesta no inequívoca. También se usa si otro worker encuentra `creating` después de que el proceso anterior perdió su lock.

No se reintenta `uncertain` automáticamente ni mediante el botón de reintento. Recargar, editar, cron y tareas duplicadas no generan otro Space. Un 429 acompañado de error de transporte tampoco autoriza reintento: no es una respuesta definitiva completa. No se guardan cuerpos remotos ni texto sensible de excepciones.

Un administrador debe revisar el resultado remoto y conservar evidencia antes de decidir una recuperación. Esta fase no incorpora adopción manual de Space ni reset de `uncertain`; cualquier procedimiento de recuperación deberá autorizarse y comprobar que no duplica recursos. No recrear la actividad para intentar resolver un timeout. Si se perdió la respuesta después de crear Google el Space, puede quedar un recurso remoto huérfano sin nombre conocido por Moodle.

## Cuotas

No hay límite fijo de una o dos reuniones por minuto ni espera para la creación normal. La exclusión es por actividad, no una cola global serial.

Solo un HTTP 429 completo devuelve el estado a `pending` con espera exponencial de 30 segundos a una hora más jitter de 0–30 segundos. Se conserva el contador de intentos y no se marca fallo permanente. Moodle aplica además el backoff nativo de la tarea cuando no se pudo completar; nunca se envía antes de `spacenextattempt`. Si varios usuarios crean simultáneamente y Google limita cuota, las tareas sobreviven y reintentan posteriormente. Ver [límites Meet](https://developers.google.com/workspace/meet/api/guides/limits).

## Calendar nativo, invitaciones y recurrencia

Para `meet`, el POST de evento incluye exclusivamente el modelo que aceptó la PoC:

- `conferenceId = meetingcode`.
- `conferenceSolution.key.type = hangoutsMeet`.
- Un entryPoint de video igual exactamente a `meeturi`.
- Ningún `conferenceData.createRequest`; ninguna signature inventada.
- `conferenceDataVersion=1` y `sendUpdates=all`.
- Attendee con el correo validado del mismo USER ID elegido como COHOST. El formulario no tiene autoridad para enviar otro correo.

Tras insertar/actualizar, un GET exige ID del evento, correlación local, revisión, conferenceId, tipo, único video entryPoint y attendee correctos. Si existe hangoutLink, debe coincidir. Solo entonces Calendar queda `ready`. Un ID preasignado estable y la revisión privada permiten recuperar una respuesta perdida por GET sin crear otro evento ni reenviar la invitación. Las ediciones preservan asistentes/RSVP remotos existentes y no vuelven a enviar conferenceData. Un evento confirmado eliminado remotamente no se recrea implícitamente.

RRULE conserva el modelo anterior, incluida la fecha final inclusiva local. Hay un Space, un COHOST principal, una serie Calendar y N ocurrencias; nunca un Space por ocurrencia. Calendar no puede borrar ni reemplazar los identificadores Meet locales aunque falle o devuelva otra conferencia.

## Edición, errores e historia

- Nombre, descripción, fechas, horario y recurrencia modifican Calendar; no crean Space ni cambian sus identificadores.
- autorecord/autotranscript reconcilian el mismo Space con la máscara de campos existente. Defaults conservados: grabación activada y transcripción desactivada.
- El docente queda bloqueado después de seleccionar; no se sustituye automáticamente ni se elimina Member.
- `calendar`: continúa el flujo Calendar-first y configuración de artefactos, conservando el enlace histórico. Sus tareas COHOST antiguas se retiran sin escrituras remotas; la interfaz explica la limitación observada de los Spaces Calendar-created. No se borra un COHOST manual existente.
- `legacy`: mantiene comportamiento previo y no se aprovisiona un Space.
- Borrar una actividad elimina solo registros locales; no se elimina Space, evento, Member ni grabación.

Para `meet`, entrar depende de Space listo con identidad/URI válida, incluso si Calendar sigue pendiente o falla. Los gestores ven estados independientes y aviso administrativo; estudiantes solo disponibilidad/enlace, sin diagnósticos HTTP, identidad docente ni botones de reintento.

La metadata de privacidad añade el correo docente enviado a Calendar como attendee. Se reutiliza el dato personal ya declarado `cohostemail`; los siete campos nuevos son técnicos. Credenciales y tokens permanecen en OAuth2 de Moodle.

## PoC y configuración manual pendiente

La página y caché PoC siguen aisladas, solo para comparación administrativa en staging. Ningún servicio normal usa su caché ni importa sus clases. **Retirar o deshabilitar la PoC antes de un release de producción.**

Para staging: mantener habilitadas Calendar API y Meet API, issuer Google de Moodle con conexión institucional verificada y scopes ya utilizados por la PoC (`calendar.events.owned`, `meetings.space.created`, `meetings.space.settings` y el readonly histórico). Reautorizar mediante Moodle si no fueron consentidos. Mantener issuer/cuenta de cada propietario histórico, cron activo, docentes activos matriculados con correo Workspace correcto y políticas/licencias compatibles con COHOST y artefactos. Las invitaciones se solicitan con sendUpdates=all; su entrega/aceptación efectiva depende de Google y las preferencias del destinatario y requiere smoke.

No se cambió workflow CI. No hay llamadas Google reales en PHPUnit. Los resultados automatizados se registran al completar la validación local; no equivalen a validación remota ni smoke integrado.

Fuera de alcance: Fase 4, recuperación de conferencias/grabaciones/transcripciones, Drive API, descarga MP4, publicación, migración de actividades, merge, deploy y release.

## Cobertura de regresión

Se conservan todos los métodos de prueba de la base (186 casos). Los fixtures Calendar-first de `meeting_manager_test` y `meet_config_test` se identifican explícitamente como históricos; no se añade una opción pública para crear actividades antiguas. Las expectativas COHOST usan Space ready y su revisión propia, y ahora incluyen GET de confirmación tras escribir.

| Contrato solicitado | Evidencia automatizada |
| --- | --- |
| Nuevas actividades Meet-first, cola inmediata y rollback | meet_first_test: new_activity_and_atomic_task, space_task_rollback |
| Clasificación calendar/legacy sin migración | upgrade_test: phase33_upgrade_classifies_without_migration; fresh_install_schema |
| Un solo Space, nombre canónico, URI/código, tareas dependientes | meet_first_test: create_once_and_queue_dependents, code_is_not_permanent_identity |
| Timeout, transporte parcial, 5xx, respuesta inválida, proceso interrumpido | meet_first_test: uncertain_never_retries (8 casos), interrupted_creating_requires_review |
| Cuota 429 con backoff y reintento manual solo de rechazo definitivo | meet_first_test: quota_backoff_then_success, definitive_rejection_manual_retry |
| Lock, revisión obsoleta, edición concurrente, confirmación DB perdida | meet_first_test: shared_lock_contention, space_revision_guards, schedule_edit_during_space_post, space_confirmation_database_failure |
| COHOST y artefactos independientes de Calendar | meet_first_test: independent_children_and_preference_edits, child_error_preserves_space; cohost_test/meet_config_test |
| Calendar native sin createRequest/signature, attendee y sendUpdates | meet_first_test: native_calendar_series_and_invitation; inspección de cada HTTP real del servicio bajo transporte simulado |
| GET confirma ID, conferenceId, tipo, URI, único video y attendee | meet_first_test: calendar_get_must_confirm_same_meet (6 casos) |
| Calendar timeout y edición concurrente conservan Space y evento | meet_first_test: calendar_lost_response_recovers_by_get, calendar_stale_reply_and_task |
| Nombre/horario/recurrencia editan solo Calendar | meet_first_test: calendar_edit_preserves_all_meet_identity |
| Grabación/transcripción modifican el mismo Space | meet_first_test: independent_children_and_preference_edits; combinaciones originales en meet_config_test |
| Sin Space/members.create en calendar; conservación de histórico | meet_first_test: historical_mode_disables_new_writes; meeting_manager_test y upgrade_test |
| Accountid y autenticación históricos | meet_first_test: historical_owner_is_preserved; regresiones originales de cuentas y Calendar |
| Correo de docente validado en servidor | meet_first_test: attendee_identity_changed_stops_calendar; cohost_identity_test |
| Enlace con Calendar error y diagnósticos privados | meeting_status_test: meet_first_join_and_uncertain_visibility |
| Sin HTTP en transacciones ni eliminación Google/Member | Asersiones de transporte en meet_first_test, no_space_http_in_transaction, delete_never_deletes_external_resources y regresiones originales |
| PoC aislada y tres traducciones completas | poc_test original y language_test; inspección de dependencias normales sin referencias a caché/clases PoC |

## Archivos de la fase

Nuevos:

- `classes/local/google/space_exception.php`
- `classes/local/google/space_service.php`
- `classes/local/meeting/provisioning.php`
- `classes/local/meeting/space_manager.php`
- `classes/task/sync_space.php`
- `docs/PHASE3_4.md`
- `tests/meet_first_test.php`

Modificados:

- `README.md`
- `classes/local/google/calendar_service.php`
- `classes/local/google/member_service.php`
- `classes/local/meeting/cohost_manager.php`
- `classes/local/meeting/meet_config_manager.php`
- `classes/local/meeting/meeting_manager.php`
- `classes/observer.php`
- `classes/output/meeting_status.php`
- `classes/privacy/provider.php`
- `classes/task/sync_meeting.php`
- `db/install.xml`
- `db/upgrade.php`
- `docs/ARCHITECTURE.md`
- `docs/PHASE3_3_POC.md`
- `lang/en/tupmeet.php`
- `lang/es/tupmeet.php`
- `lang/es_mx/tupmeet.php`
- `mod_form.php`
- `retry.php`
- `tests/cohost_identity_test.php`
- `tests/cohost_test.php`
- `tests/meet_config_test.php`
- `tests/meeting_manager_test.php`
- `tests/meeting_status_test.php`
- `tests/upgrade_test.php`
- `version.php`

## Resultados locales — 18/09/2026

PHP 8.3.33 y MariaDB 10.11.14; bases PHPUnit desechables, sin Google real.

| Moodle | PHPUnit | Casos | Aserciones | Resultado |
| --- | --- | ---: | ---: | --- |
| 4.5.14 LTS | 9.6.34 | 225 | 3842 | PASS |
| 5.0.10 | 11.5.55 | 225 | 3843 | PASS |
| 5.1.7 | 11.5.55 | 225 | 3843 | PASS |

Suite completa: `vendor/bin/phpunit --testsuite mod_tupmeet_testsuite --fail-on-warning --fail-on-risky --fail-on-incomplete --fail-on-skipped`; en PHPUnit 11 se añadieron `--fail-on-notice --display-notices`. Son 186 casos de regresión conservados y 39 casos adicionales. Ningún método test original fue eliminado o renombrado.

- Sintaxis: 61 archivos PHP, PASS.
- PHPCS Moodle: cero errores y cero warnings.
- Estructura: validador oficial Moodle Plugin CI, PASS.
- XMLDB: instalación nueva y upgrades de todas las fases, incluidos clasificación de filas y preservación histórica, PASS en las tres versiones.
- Savepoints: seis bloques y llamadas coincidentes, ordenados, PASS.
- `git diff --check`: PASS.
- CI heredado (`.github/workflows/ci.yml`, `docs/CI.md`): sin cambios respecto de la base.
- Revisión de credenciales: sin patrones de credenciales reales detectados; datos de pruebas sintéticos y sin tokens nuevos persistidos por el plugin.

Los fallos durante desarrollo estuvieron en fixtures/expectativas que se ajustaron al nuevo contrato (identidad OAuth sintética, revisión COHOST independiente, GET de confirmación y bloqueo no reentrante en pruebas). Los resultados de la tabla son las ejecuciones completas posteriores corregidas. No se ejecutó GitHub Actions ni un smoke Google del flujo integrado en esta fase local.

Comprobación posterior de los últimos ajustes de UI, idiomas y fixture de fallo DB: 6 pruebas / 51 aserciones adicionales por versión (4.5, 5.0 y 5.1), todas PASS. Estos conteos son ejecuciones focalizadas adicionales, no se suman como casos nuevos a las 225 pruebas de la suite.
