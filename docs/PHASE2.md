# Fase 2 — Calendar y enlace Google Meet

## Alcance y estado

Base aprobada: codex/fase-1-cuenta-maestra-oauth2, commit 4ea481547e41aa6fcd72e477df7f2198c5b96573.
Rama: codex/fase-2-calendar-meet.
Versión: **2026091501**, release interna **0.3.0-alpha**.

Este documento conserva el informe técnico de la implementación inicial. La evidencia posterior del smoke Moodle 4.5 / Workspace y el hardening `2026091502 / 0.3.1-alpha` se registran en [PHASE2_SMOKE.md](PHASE2_SMOKE.md). No se modifica Moodle core, mod_googlemeet ni producción.

Esta fase crea eventos Calendar y solicita su enlace Meet. No llama a Meet REST API ni Drive API. Grabación, transcripción y publicación automática siguen siendo preferencias sin activación.

## Flujo exacto

1. El administrador completa la configuración indicada abajo y verifica la cuenta institucional.
2. El docente crea una actividad TUP Meet: nombre, descripción, inicio/fin y, opcionalmente, intervalo semanal, días y última fecha.
3. Los controles muestran explícitamente la zona horaria. La primera fecha debe pertenecer a uno de los días elegidos cuando hay recurrencia.
4. Moodle guarda la actividad con la única cuenta predeterminada habilitada y verificada. Guarda una clave de envío, el ID estable reservado para Calendar, la revisión y una tarea de sincronización en la misma transacción.
5. Después del commit, un observador nativo de Moodle intenta inmediatamente la sincronización. Se comprueba otra vez la identidad histórica mediante OAuth2/OpenID.
6. El servicio consulta el ID en el calendario **primary** de esa cuenta. Si aún no existe, inserta el evento con **conferenceDataVersion=1**, **hangoutsMeet** y un requestId estable.
7. Se guardan el enlace validado y su código. La vista muestra la próxima sesión, programación, recurrencia y **Entrar a Google Meet**.
8. Google puede generar la conferencia de forma asíncrona. Se muestra un estado pendiente y cron reintenta. Se debe recargar la página para ver el enlace.
9. Si falla la autorización o Calendar, se muestra un error sin presentar un enlace disponible. El docente puede pulsar **Reintentar sincronización** después de corregir la causa. Usa POST, sesskey y la capacidad moodle/course:manageactivities.

Guardar y volver al curso también ejecuta el observador. No es necesario entrar manualmente a Calendar. Los clientes internos que llamen directamente a tupmeet_add_instance reciben persistencia y tarea durable; el ciclo normal de módulos de Moodle aporta el observador inmediato.

## Configuración manual en Moodle y Google Cloud

1. En el proyecto Google Cloud del issuer, habilitar **Google Calendar API**. No hacen falta Meet REST API ni Drive API para esta fase.
2. Revisar la pantalla de consentimiento y las políticas de acceso OAuth del dominio Workspace. El issuer utiliza una aplicación OAuth web y la URI de retorno indicada por Moodle.
3. En **Administración del sitio > Servidor > Servicios OAuth 2**, mantener el issuer Google habilitado para servicios internos, con OpenID/email y acceso sin conexión nativos. Introducir credenciales únicamente en Moodle.
4. En **Administración del sitio > Plugins > Módulos de actividad > TUP Meet**, registrar el issuer para esa cuenta. Un reemplazo requiere otro issuer; no reconectar el anterior con otra identidad.
5. Después del registro, conectar o reconectar la cuenta de sistema desde Moodle para consentir el nuevo scope Calendar. Una autorización anterior puede no incluirlo. También aplica a las cuentas históricas cuyos eventos deban actualizarse.
6. Volver a TUP Meet, verificar el correo mostrado y seleccionar explícitamente una cuenta habilitada como predeterminada.
7. Mantener activo el cron de Moodle, recomendado cada minuto, con tareas ad hoc. El plugin no instala un cron externo.
8. Configurar una zona IANA en Moodle/usuario, por ejemplo America/Cancun, y revisar la programación histórica después de actualizar.
9. Confirmar que Workspace permite Calendar y conferencias Meet a esa cuenta.

No se almacenan access token, refresh token, client secret ni client ID en tablas propias. No se registran cuerpos de errores de Google.

## Scope y documentación oficial

Scope adicional único:

**https://www.googleapis.com/auth/calendar.events.owned**

Permite crear, consultar y modificar eventos de calendarios que posee la cuenta. Se usa su calendario principal. El scope calendar.app.created solo cubre calendarios secundarios creados por la aplicación. La conferencia se solicita con Calendar conferenceData; no requiere scopes de Meet REST.

El callback oficial tupmeet_oauth2_system_scopes devuelve este scope exclusivamente si el issuer es Google y su ID aparece en tupmeet_accounts. Incluye cuentas históricas deshabilitadas para nuevas actividades. No altera otros issuers Google.

Fuentes oficiales consultadas:

- [Scopes de Calendar](https://developers.google.com/workspace/calendar/api/auth).
- [Events.insert: permisos, ID suministrado y conferenceDataVersion](https://developers.google.com/workspace/calendar/api/v3/reference/events/insert).
- [Events.get](https://developers.google.com/workspace/calendar/api/v3/reference/events/get).
- [Events.patch](https://developers.google.com/workspace/calendar/api/v3/reference/events/patch).
- [Creación asíncrona de conferencias](https://developers.google.com/workspace/calendar/api/guides/create-events).

Se utiliza PATCH para modificar solamente los campos programados por Moodle, conservando invitados, otros campos ajenos y conferenceData. Tiene mayor coste de cuota que UPDATE; evita reemplazar campos externos que el plugin no administra.

## Zona horaria y recurrencia

- Los selectores nativos reciben una zona explícita resuelta con core_date; no se usa strtotime('today', ...).
- Se guarda la zona efectiva del usuario/Moodle al crear. Al editar se conserva la zona de la reunión aunque el editor tenga otra.
- Inicio y fin se envían como RFC3339 con desplazamiento y timeZone IANA. Los valores locales son instantes Unix.
- Una serie usa un único evento y una RRULE semanal: INTERVAL, BYDAY, WKST=MO y UNTIL.
- La fecha final incluye hasta las 23:59:59 del día en la zona guardada, convertidas a UTC. El 12/12/2026 en Cancún termina en **20261213T045959Z**.
- La próxima sesión utiliza semanas ancladas al lunes y aritmética de fechas local, incluyendo cambios de horario estacional.
- DTSTART debe coincidir con BYDAY para evitar series indefinidas conforme a RFC5545.
- Se admiten intervalos de 1 a 999 semanas.

## Idempotencia y consistencia

La actividad confirmada es el registro durable de la operación; no se necesita una tabla de tokens o solicitudes con datos de usuarios.

| Caso | Comportamiento |
| --- | --- |
| Falla el guardado local antes del commit | No se llama a Google; actividad y tarea se revierten juntas. |
| Google devuelve error | Se conserva dueño e ID reservado; estado de error y reintento con backoff nativo. |
| Timeout después de crear en Google | GET del mismo ID; verificar la marca privada y actualizar ese evento. |
| Google responde bien pero falla guardar la respuesta local | El estado confirmado anterior sigue pendiente; la tarea recupera el mismo evento. |
| Reenvío del formulario | La clave conservada y su índice único impiden otra actividad. Se indica abrir la existente. |
| Conflicto 409 al insertar | GET del mismo ID; solo se acepta si coincide la marca de correlación. |
| Edición durante una llamada | Una respuesta antigua no confirma la revisión nueva; otra tarea aplica el último estado. |
| ConferenceData pendiente o ausente | No hay botón funcional; se consulta o repara sobre el mismo evento. |
| Generación de conferencia rechazada | Corregir permisos y reintentar explícitamente: nueva revisión/requestId sobre el mismo evento. |
| Eliminación Moodle | Solo se borra el registro local; las tareas terminan al detectar su ausencia. Nada se borra en Google. |

El ID es un hash hexadecimal válido para Calendar, derivado del identificador de sitio y de 256 bits aleatorios de la clave de envío. Ambos se guardan antes del primer HTTP. La marca privada tupmeet evita adoptar eventos ajenos. Son identificadores, no secretos.

Un bloqueo Moodle por actividad serializa la reconciliación. Las escrituras de respuesta se condicionan a syncversion. Las tareas incluyen la revisión para que una tarea en ejecución no absorba una edición posterior.

Los llamadores programáticos deben conservar y reutilizar creationkey al reintentar una creación. Si omiten la clave, cada invocación representa una nueva actividad. Abrir voluntariamente un formulario nuevo representa otra actividad.

No hay borrados externos para rollback ni atomicidad distribuida: un evento puede estar temporalmente por delante del estado visible local; la actividad y tarea permiten reconciliarlo.

## Base de datos y actualización

db/install.xml y db/upgrade.php añaden a tupmeet:

| Campo | Tipo | Función |
| --- | --- | --- |
| timezone | char(100), UTC por defecto | Zona de la programación |
| creationkey | char(64), NULL, índice único | Evitar doble envío |
| syncversion | char(32), legacy por defecto | Revisión del estado deseado |
| syncstatus | char(12), legacy por defecto | legacy, pending, error, ready |

Se reutilizan calendareventid, meeturi, meetingcode y lastsync. meetspacename permanece NULL: conferenceId de Calendar no es un recurso spaces de Meet REST.

La actualización no reinicia la verificación/predeterminada de Fase 1. Conserva cuentas, accountid y timestamps. Las actividades antiguas quedan legacy sin crear eventos automáticamente.

Fases previas no guardaban la zona del autor: el upgrade utiliza la zona del sitio. El docente debe revisar la fecha final de recurrencia y programación antes del primer guardado que programe Calendar. No se reasignan actividades con accountid=0; requieren una futura decisión explícita de migración.

## Pruebas

Se conservan las garantías de Fase 1 y se ejercitan servicios reales, tablas, transacciones, locks, tareas y controles de fecha Moodle. Solo se simulan adquisición de cliente OAuth/userinfo y frontera HTTP de Calendar. Las cuentas y respuestas son sintéticas.

El caso de fallo local posterior a Google provoca un fallo real de escritura en el esquema desechable y lo restaura, sin simular el repositorio de datos.

Resultados locales del 15/09/2026, con PHP 8.3.33 y MariaDB 10.11.14:

| Moodle | Suite | Observador nativo añadido al cierre | Total verificado |
| --- | --- | --- | --- |
| 4.5.14 LTS | 51 pruebas / 236 aserciones | 1 prueba / 3 aserciones | 52 / 239, OK |
| 5.0.10 | 51 pruebas / 237 aserciones | 1 prueba / 3 aserciones | 52 / 240, OK |
| 5.1.7 | 51 pruebas / 237 aserciones | 1 prueba / 3 aserciones | 52 / 240, OK |

Comandos en cada checkout Moodle:

~~~sh
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite --filter test_native_observer_after_commit
~~~

La segunda ejecución valida la prueba incorporada después de iniciar la matriz completa. Confirma que el observador real no se ejecuta dentro de la transacción exterior y sí después del commit. Las 24 pruebas originales de Fase 1 se conservan; se ajustó únicamente una actividad sintética para darle fechas válidas antes de editarla.

También pasaron sintaxis PHP en 32 archivos, Moodle Coding Standard sin errores ni advertencias, validación XMLDB de instalación nueva sin reparaciones y upgrades reales desde Fase 0 y Fase 1 en las bases desechables. No se detectaron patrones de credenciales en el repositorio. Los tres checkouts de core permanecen sin cambios versionados.

La preparación local corrigió un valor por defecto vacío detectado por XMLDB; el esquema final utiliza legacy. Los snapshots de PHPUnit se actualizaron con XMLDB y las utilidades nativas, fuera del repositorio del plugin. La prueba del formulario se ejecuta con los campos estándar completos que envía Moodle.

## Inventario de archivos de esta fase

Nuevos (12):

- classes/local/google/calendar_service.php
- classes/local/meeting/meeting_manager.php
- classes/local/meeting/schedule.php
- classes/observer.php
- classes/task/sync_meeting.php
- db/events.php
- docs/PHASE2.md
- retry.php
- tests/calendar_transport_test.php
- tests/meeting_manager_test.php
- tests/mod_form_test.php
- tests/schedule_test.php

Modificados (15): AGENTS.md, README.md, classes/privacy/provider.php, db/install.xml, db/upgrade.php, docs/ARCHITECTURE.md, docs/ROADMAP.md, lang/en/tupmeet.php, lang/es/tupmeet.php, lib.php, mod_form.php, tests/account_manager_test.php, tests/upgrade_test.php, version.php y view.php.

No se eliminan archivos. No se modifican account_manager ni oauth_client_factory de Fase 1.

## Riesgos y pendientes de aceptación

- El responsable reportó smoke real satisfactorio de OAuth, creación Calendar, Meet, edición y recurrencia en Cancún; véase [la evidencia y pendientes específicos](PHASE2_SMOKE.md). No consta aceptación manual de edición con cuentas históricas ni revocación/reconexión.
- La conferencia puede ser asíncrona. Cron debe funcionar; los fallos permanentes requieren corregir la causa.
- Las ediciones afectan toda la serie; no una ocurrencia ni “esta y las siguientes”.
- No se recuperan automáticamente eventos borrados manualmente en Google después de una sincronización confirmada.
- No se importan ediciones Google hacia Moodle. Guardar en Moodle sobrescribe los campos de agenda que administra el plugin.
- Se conservan recursos externos al eliminar Moodle; su retención/limpieza requiere un proceso institucional.
- Sin copia/restauración Moodle ni migración legacy. Una copia de producción debe mantener Google desautorizado para no actuar sobre los mismos eventos.
- Pruebas sobre PHP 8.3 y MariaDB locales; no sustituyen el smoke institucional ni cubren todos los motores de base de datos.
- No se activa grabación/transcripción mediante Meet REST, ni sincronización/publicación de grabaciones o Drive API.
