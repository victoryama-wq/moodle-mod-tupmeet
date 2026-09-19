# Fase 5 — experiencia académica y publicación local

## Base y evidencia

- Rama: codex/fase-5-ui-publication.
- Base: eb238103722d24dfdc178bbaaad1e73d3ce1a51c, Fase 4.
- Versión: **2026091901 / 0.8.0-alpha**.
- El responsable comunicó smoke real Fase 4 aprobado: discovery automático de
  conferenceRecords/recordings, FILE_GENERATED, fileId/exportUri, rename del MP4,
  cron/scheduled/adhoc y carpeta intacta, sin sincronización manual.
  Es evidencia del responsable; no son llamadas Google de esta implementación.
- Fase 5 se valida localmente. CI remoto y smoke de esta nueva interfaz quedan
  pendientes de autorización y ejecución separadas.

## Arquitectura y acceso

view.php conserva require_login(course, true, cm), mod/tupmeet:view y completion.
La matrícula, disponibilidad, acceso al curso y al módulo los aplica Moodle.
activity_view compone tres bloques sin HTTP ni colas durante render:

1. session_summary: próxima sesión o sesión en curso, fechas y horas locales,
   recurrencia y enlace Meet validado con la misma regla de disponibilidad.
2. recording_list: tabla académica y paginación local, 50 filas por página.
3. technical_panel: details/summary, solo con moodle/course:manageactivities.
   Incluye meeting_status y recording_diagnostics, que conserva el catálogo técnico
   anterior y sus acciones POST de sincronización/rename.

El alumno consulta SQL con studentvisible = 1, tanto para el conteo como para las
filas. No se filtran después de generar HTML. No se seleccionan nombres técnicos,
originalfilename ni estados HTTP para la tabla académica. El fileId se utiliza
internamente para validar exportUri; no se exporta como campo del template.
El enlace visible contiene necesariamente el ID que forma parte de su URL.
Las grabaciones ocultas no llegan al template ni afectan la paginación.

Los templates reciben proyecciones explícitas, no objetos completos de actividad
o grabación. Los datos de texto se escapan mediante Mustache. El HTML sin escapar
se limita a iconos, paginación y diagnóstico producido por APIs Moodle.

## Próxima sesión

Se reutiliza schedule::next_session sin modificar recurrencia ni UNTIL.
La fecha completa y las horas usan la timezone guardada. El fin se calcula con
la duración local del horario, respetando DST. En sesiones que cruzan medianoche
se muestra también la fecha de finalización de esa sesión.

Se muestran días de repetición en orden lunes-domingo y el intervalo semanal.
“Finaliza” usa directamente recurrenceuntil local, nunca el RRULE UTC ni Google.
Cuando no existe próxima sesión aparece el mensaje correspondiente; el historial
de grabaciones permanece. La zona no ocupa una fila técnica para estudiantes.

El botón Meet abre nueva pestaña con noopener noreferrer. Space ready permite
entrar aunque Calendar tenga error, tal como en Fase 3.4.

## Tabla académica y accesibilidad

Encabezados: Video, Sesión, Horario, Estado, Grabación y, para gestores, Estudiantes.
Orden: inicio real de conferencia descendente; segmentos en orden real ascendente.
Parte 2/Parte 3 reutiliza el partnumber persistido por Fase 4. No se renumera.

- STARTED: En curso, sin enlace.
- ENDED: Procesando grabación…, sin enlace.
- FILE_GENERATED: Disponible y Ver grabación, si exportUri es válida.
- Rename error no bloquea un enlace válido.
- Los gestores ven todas las filas y el nombre académico observado o previsto.
- El alumno no recibe nombres originales, HTTP, estados de sincronización,
  diagnósticos COHOST/artifacts, OAuth ni controles técnicos.
- No se implementa duración del video; no es necesaria para publicar.

Iconos nativos comprobados en Moodle 4.5: pix/f/video.svg,
pix/t/hide.svg y pix/t/show.svg. Se usan pix_icon y el helper Mustache pix.
No hay Font Awesome hardcodeado ni emojis.

El ojo representa la acción disponible:
visible -> t/hide -> Ocultar grabación a estudiantes;
oculta -> t/show -> Mostrar grabación a estudiantes.
Formularios POST, botones de al menos 44px, title y aria-label con sesión;
texto Visible/Oculta para no depender de color. Foco visible y operación por teclado.
Los enlaces explican que abren nueva pestaña; target=_blank y
rel=noopener noreferrer.

Tabla semántica con caption, encabezados de fila/columna y roles preservados.
A menos de 768px las filas se apilan; los encabezados siguen accesibles a lectores
de pantalla. CSS exclusivamente bajo .mod-tupmeet; sin !important, paleta propia
ni modificaciones al tema. Colores de botones/contraste heredados de Moodle.
No se añade JavaScript. Validar también con el Boost Union concreto de staging
y su lector de pantalla; las comprobaciones locales no certifican todos los temas.

## Publicación y esquema

Se añaden tres campos en tupmeet_recordings, sin tablas nuevas:

| Campo | XMLDB | Significado |
| --- | --- | --- |
| studentvisible | int(1), NOT NULL, default 0 | Visibilidad académica Moodle |
| visibilitymodified | int(10), NOT NULL, default 0 | Último cambio manual real |
| visibilityuserid | int(10), NOT NULL, default 0 | Último usuario que cambió visibilidad |

Índices studentlist(tupmeetid,studentvisible) y visibilityuserid.
No se añade FK a user: 0 es el marcador sin autor/anonimizado y la eliminación
de usuarios no debe bloquearse ni borrar una grabación institucional compartida.

Formulario nuevo y creación normal sin preferencia explícita: automatic.
Las actividades existentes conservan su publicationmode; la edición carga su
valor persistido. El default SQL histórico manual se conserva como fallback
conservador para inserciones que no usan el servicio normal.

Discovery inicializa studentvisible solo al insertar:
automatic -> 1; manual (o valor desconocido) -> 0.
Lee la preferencia actual bajo el bloqueo de la fila de actividad.
No inicializa el autor ni la fecha manual.
Los updates posteriores omiten expresamente los tres campos de visibilidad.
Cambiar publicationmode no recorre ni sobrescribe las grabaciones existentes.
Ocultar STARTED/ENDED persiste cuando el mismo registro pasa a FILE_GENERATED.

### Upgrade

install.xml, upgrade.php y savepoint 2026091901 están sincronizados.
El upgrade agrega campos/índices e inicializa las grabaciones históricas:
automatic -> visibles; manual -> ocultas. Autor y fecha manual empiezan en cero.
No cambia preferencias de actividad, IDs, exportUri, filenames, propietarios ni
estados de Google. No realiza HTTP ni encola tareas.

El administrador debe conocer que actualizar publica en Moodle el historial de
las actividades que ya tenían automatic. Es la política solicitada para esta fase.

## Mutaciones, concurrencia y auditoría

recordings.php solo recibe módulo, acción y recordingid local.
actions::execute exige manageactivities, POST, contexto correspondiente y sesskey.
show/hide determinan el valor en servidor; se ignoran fileId, exportUri, filename
o studentvisible enviados por el navegador. La fila debe pertenecer a la actividad.

Se utiliza meeting:{id}, compartido con discovery, y transacción corta.
Solo se actualizan los tres campos de visibilidad; no se invoca cliente Google,
rename, ni ninguna cola. Show/hide explícitos son idempotentes: repetir el mismo
estado no altera trazabilidad ni genera otro evento.
Si el worker posee el lock se informa que se reintente, sin esperar a Google.

recording_visibility_changed registra objectid local, contexto de módulo,
userid actor, activityid local y visible 0/1. No incluye fileId, URL, filename
ni snapshot remoto en logs. Moodle administra el historial de eventos mediante
su propio subsistema de logging y su política de privacidad/retención.

## Privacidad

Se declaran los tres campos nuevos en metadata.
get_contexts_for_userid y get_users_in_context consideran el último actor de
visibilidad, además del coorganizador ya existente.

La exportación por usuario/contexto aprobado incluye únicamente sus filas de
atribución: ID local, visibilidad, userid y fecha. No incluye enlaces Drive ni
otros autores. La eliminación individual, por lista o por contexto pone autor
y fecha a cero, conservando studentvisible y los metadatos compartidos.
Discovery no puede restaurar una atribución borrada desde un snapshot viejo.

Referencia: [Privacy API Moodle 4.5](https://moodledev.io/docs/4.5/apis/subsystems/privacy).
Templates/iconos: [Templates Moodle](https://moodledev.io/docs/5.0/guides/templates).

## Drive y límite institucional aceptado

Moodle decide qué enlace mostrar, **no modifica ACL de Drive**.
Se conserva la política institucional de dominio con permiso Lector.
Si alguien obtiene el enlace y lo comparte con otro miembro del dominio que ya
tiene permiso, ese usuario puede acceder directamente a Drive aunque Moodle
no le muestre la grabación. Ocultar no revoca enlaces ya obtenidos.
Esta fase no implementa DRM ni ACL por curso.

Fase 5 no añade llamadas Google ni cambios a permissions, parents, owner, name,
folder o contenido. El rename de Fase 4 permanece operativo sin modificaciones
a su servicio, gestor ni algoritmo. Los nombres congelados no se regeneran
por publicar, ocultar, editar preferencias o cambiar el título de la actividad.

Scopes de Fase 4 intactos: calendar.events.owned, meetings.space.settings,
meetings.space.created, meetings.space.readonly y drive.metadata.
No hay nuevos consentimientos/scopes por Fase 5.
Se conservan las condiciones OAuth/Workspace de la instalación existente.

No modifica Space, Calendar native, attendees/sendUpdates, COHOST, artifactConfig,
polling, backoff, accountid, borrados ni PoC. No modifica core ni mod_googlemeet.
No MP4 en Moodle, descarga, proxy, sharing individual, transcripciones,
subtítulos ni Fase 6.

## Smoke staging posterior — pendiente

1. Instalar el paquete autorizado y ejecutar la actualización Moodle.
2. Confirmar campos de visibilidad y política inicial de las actividades históricas.
3. Como estudiante matriculado: abrir actividad, revisar próxima sesión/horario,
   recurrencia/fecha final local y botón Meet. No debe ver diagnóstico técnico.
4. Revisar Grabaciones de clase: solo visibles; STARTED/ENDED sin enlace;
   FILE_GENERATED abre Drive en pestaña nueva. Un fallo rename no bloquea.
5. Como docente gestor: ver todas las filas, abrir Administración técnica y
   comprobar que sync/rename y retries previos siguen disponibles.
6. Ocultar una grabación con el ojo, recargar como alumno y confirmar ausencia.
7. Mostrarla otra vez y comprobar que vuelve. Inspeccionar evento local.
8. Ocultar una grabación en procesamiento y dejar que discovery la actualice:
   debe permanecer oculta. Cambiar publicationmode tampoco debe sobrescribirla.
9. Crear/grabar una sesión nueva con automatic: al ser detectada aparece visible;
   FILE_GENERATED incorpora el enlace sin publicación manual.
10. Con manual, la nueva grabación permanece oculta hasta mostrarla con el ojo.
11. Probar una serie con varios segmentos: fechas reales, orden reciente primero
    y Parte 2/Parte 3.
12. Verificar en Drive que carpeta, permisos de dominio, propietario y contenido
    siguen intactos; solo el rename ya existente de Fase 4 puede cambiar el nombre.
13. En Boost Union a 320px/390px y desktop: sin overflow académico, botones y ojo
    operables, navegación por teclado, foco visible, encabezados y etiquetas.
14. Comprobar acceso Moodle restringido por matrícula/disponibilidad; validar
    también la limitación aceptada de enlaces compartidos fuera de Moodle.
15. Registrar evidencia sin credenciales, tokens, correos ni enlaces privados.

## Validación local

Entorno local aislado: PHP 8.3.33 y MariaDB 10.11.14; sin Google real.
Instalación XMLDB nueva y suite completa `mod_tupmeet_testsuite`:

| Moodle | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| 4.5.14 | 356 | 7715 | PASS |
| 5.0.10 | 356 | 7716 | PASS |
| 5.1.7 | 356 | 7716 | PASS |

Después de los últimos ajustes de privacidad y presentación se volvió a ejecutar
el conjunto `publication|recording_ui|meeting_status|privacy`: 59 pruebas y
1150 aserciones, PASS en cada una de las tres versiones. PHPCS final también PASS.

Se conservan los 310 casos anteriores y se añaden 46 casos, incluidos conjuntos parametrizados.
La suite cubre instalación, upgrade desde Fase 4, política inicial,
decisiones individuales preservadas durante discovery, autorización POST,
sesskey, pertenencia a actividad, eventos, privacidad, filtrado servidor,
presentación por rol, paginación, fechas locales y regresiones Google simuladas.
La anonimización de autoría utiliza el mismo lock de actividad que las acciones
manuales y la cola de rename, evitando que una instantánea concurrente reponga
datos personales eliminados.

También pasan sintaxis de los 85 archivos PHP, PHPCS Moodle sin errores ni
warnings, validación estructural CI, ocho savepoints ordenados y
`git diff --check`. El escaneo de patrones de credenciales no encontró hallazgos.
Workflow CI, servicios Google, tareas, recurrencia, rename/polling, Space,
COHOST y artifactConfig conservan sus archivos respecto de Fase 4.

Revisión visual local: HTML generado por Moodle con datos sintéticos y estilos
Boost, navegador Edge headless, recursos externos bloqueados. Ocho combinaciones
estudiante/docente a 320, 390, 768 y 1280 px sin overflow horizontal; controles
de ojo de al menos 44 px. Comprobación de foco del ojo y apertura por teclado
del panel nativo. Los iconos de archivo se sustituyeron únicamente en el visor
offline por su SVG local equivalente; el plugin utiliza la API nativa Moodle.
Esto no sustituye el smoke autenticado con Boost Union en staging, pendiente.
La validación remota CI también queda pendiente de autorización de push.

### Inventario de archivos

Nuevos (11):

- `classes/event/recording_visibility_changed.php`
- `classes/output/activity_view.php`
- `classes/output/recording_diagnostics.php`
- `classes/output/session_summary.php`
- `docs/PHASE5.md`
- `styles.css`
- `templates/recordings_table.mustache`
- `templates/session_summary.mustache`
- `templates/technical_panel.mustache`
- `tests/publication_test.php`
- `tests/publication_upgrade_test.php`

Modificados (23):

- `README.md`
- `classes/local/meeting/meeting_manager.php`
- `classes/local/recording/actions.php`
- `classes/local/recording/recording_manager.php`
- `classes/output/meeting_status.php`
- `classes/output/recording_list.php`
- `classes/privacy/provider.php`
- `db/install.xml`
- `db/upgrade.php`
- `docs/ARCHITECTURE.md`
- `docs/ROADMAP.md`
- `lang/en/tupmeet.php`
- `lang/es/tupmeet.php`
- `lang/es_mx/tupmeet.php`
- `mod_form.php`
- `recordings.php`
- `tests/cohost_identity_test.php`
- `tests/recording_policy_test.php`
- `tests/recording_ui_test.php`
- `tests/recordings_test.php`
- `tests/upgrade_test.php`
- `version.php`
- `view.php`
