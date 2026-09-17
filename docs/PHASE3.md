# Fase 3 — configuración automática de artefactos Meet

## Alcance y base

- Base aprobada: `codex/fase-2-calendar-meet`, `be987aff85ef59e0fbe637f19b31765d3b339199` (`0.3.1-alpha`).
- Rama de implementación: `codex/fase-3-meet-auto-artifacts`.
- Versión: **2026091700 / 0.4.0-alpha**, `MATURITY_ALPHA`.
- Implementación y validación local; push, CI remoto y smoke Google de esta fase pendientes de autorización/ejecución. No hay merge, deploy ni release.

`autorecord` y `autotranscript` ahora se aplican al Space existente de Google Meet. No se crean Spaces mediante Meet REST. Calendar sigue creando el evento y la conferencia como en Fase 2. No cambian el transporte Calendar, las reglas de recurrencia, `UNTIL` ni los servicios de cuentas históricas.

**No se recuperan/listan grabaciones ni transcripciones.** Tampoco se implementan Drive API, publicación, participantes/asistencia, Smart Notes ni migración legacy. `publicationmode` continúa como preferencia sin activación.

## Documentación oficial revisada el 17/09/2026

- [Space y configuración de artefactos](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces): recurso permanente `name`, campos de configuración y valores `ON`/`OFF`.
- [spaces.get](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces/get): consulta por nombre o alias de código y scope `meetings.space.settings`. El código puede expirar/reutilizarse; no es identidad permanente.
- [spaces.patch](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces/patch): cuerpo Space y máscara de campos. Una máscara `*` puede modificar/borrar configuración ajena; no se utiliza.
- [Administración de espacios](https://developers.google.com/workspace/meet/api/guides/manage-meeting-spaces).

La API describe que grabación/transcripción automáticas comienzan cuando entra alguien con el privilegio correspondiente. Activarlas **no inicia una grabación a una hora programada**, no garantiza que la política/licencia permita generar el artefacto y no prueba que ya exista un archivo. La interfaz del plugin explica esa diferencia. El plugin no inicia ni finaliza conferencias.

## Arquitectura y flujo

```text
Guardar actividad
  -> transacción Moodle: preferencias + revisiones + tarea durable
  -> commit
  -> Calendar: crear/editar el evento y obtener Meet URI/code
  -> transacción corta: Calendar ready + tarea Meet
  -> commit
  -> Meet: GET Space
  -> persistir el nombre permanente validado
  -> comparar artifactConfig
  -> PATCH solo si difiere
  -> confirmar el estado Meet de esa revisión
```

| Capa | Responsabilidad |
| --- | --- |
| `local/google/meet_service.php` | OAuth nativo, GET/PATCH, validación del Space, comparación de preferencias, máscara restringida y errores seguros. |
| `local/meeting/meet_config_manager.php` | Revisión independiente, identidad persistida antes de PATCH, límite de intentos, lock y escrituras condicionales. |
| `task/sync_meet_config.php` | Reintento durable de una revisión; descarta tareas antiguas sin HTTP. |
| `local/meeting/meeting_manager.php` | Conserva Calendar; guarda trabajo Meet al confirmar disponibilidad. Una edición exclusiva de preferencias conserva Calendar ready y no lo consulta. |
| `observer.php` | Intenta Calendar y luego Meet después del commit. Las tareas sobreviven a una interrupción del proceso web. |
| `output/meeting_status.php` / `view.php` | Solo presentan estado persistido; los estudiantes conservan el enlace y no ven diagnósticos de artefactos. |
| `retry.php` | POST + sesskey + capacidad `moodle/course:manageactivities`; reintento explícito de los valores guardados. |

Se eligen **dos tareas** para aislar los fallos, las revisiones y el presupuesto de reintentos. La tarea Calendar no se marca fallida por una autorización o política Meet. Ningún servicio hace HTTP dentro de una transacción abierta. Las dos sincronizaciones usan el mismo lock Moodle `meeting:{id}` para evitar operar simultáneamente sobre una actividad; las ediciones pueden guardar una revisión nueva durante HTTP.

## Identidad y llamadas

Primer uso con una conferencia Calendar disponible:

```http
GET https://meet.googleapis.com/v2/spaces/{meetingCode}
```

La respuesta inicial debe contener `name` válido y el mismo `meetingCode`/`meetingUri` que Calendar confirmó. Se exige `^spaces/[A-Za-z0-9_-]+$` y un máximo de 255 caracteres para el nombre persistido. Se guarda `meetspacename` **antes** de intentar el PATCH: si falla el permiso o se pierde su respuesta, el siguiente intento usa el recurso permanente.

En operaciones posteriores:

```http
GET https://meet.googleapis.com/v2/{meetspacename}
PATCH https://meet.googleapis.com/v2/{meetspacename}?updateMask=...
```

La respuesta debe conservar exactamente ese nombre. Un nombre inválido, redireccionado o distinto provoca un error seguro; no se vuelve al alias para buscar otro Space. Ni el formulario ni sus llamadores pueden suministrar otro `accountid` o identificador Google. La cuenta histórica se verifica mediante OpenID en cada adquisición del cliente, incluso si ya no está habilitada para actividades nuevas.

Máscara, enviada como parámetro de URL codificado:

```text
config.artifactConfig.recordingConfig.autoRecordingGeneration,config.artifactConfig.transcriptionConfig.autoTranscriptionGeneration
```

Cuerpo de ejemplo para grabación activada y transcripción desactivada:

```json
{
  "name": "spaces/SyntheticExample",
  "config": {
    "artifactConfig": {
      "recordingConfig": {"autoRecordingGeneration": "ON"},
      "transcriptionConfig": {"autoTranscriptionGeneration": "OFF"}
    }
  }
}
```

Se envían ambos campos explícitamente (`0 -> OFF`, `1 -> ON`). No se envían `accessType`, moderación, miembros, entry points, asistencia, Smart Notes ni otros campos. Si el GET devuelve los dos valores deseados, se omite PATCH. Una propiedad ausente o no reconocida no se interpreta como OFF: se solicita el valor explícito y se exige que la respuesta del PATCH confirme ambos valores. No se usa `spaces.create`, DELETE ni endpoints de artefactos.

## Estados y esquema

Campos nuevos en `tupmeet`, sincronizados entre `install.xml`, upgrade y savepoint **2026091700**:

| Campo | Tipo/default | Significado |
| --- | --- | --- |
| `meetconfigstatus` | char(12), `unconfigured` | Estado independiente de configuración Meet. |
| `meetconfigversion` | char(32), `legacy` | Revisión aleatoria renovada al guardar/reintentar. |
| `meetconfigmodified` | int(10), 0 | Última confirmación correcta de la revisión actual; se reinicia al guardar. |
| `meetconfigattempts` | int(4), 0 | Intentos consumidos por la revisión. |

Se reutiliza `meetspacename`; no se crean tablas ni columnas de credenciales. No se modifican core ni `mod_googlemeet`.

Estados:

- `unconfigured`: actividad histórica; las preferencias nunca se aplicaron con esta fase. Requiere guardar/reintentar explícitamente después de reautorizar.
- `pending`: revisión guardada, esperando Calendar/Meet.
- `ready`: ambos valores fueron confirmados. **Ambos OFF también son ready**, y cada opción se presenta como desactivada; no equivale a error ni a grabación existente.
- `error`: no se pudo confirmar la configuración. Puede haber un reintento automático pendiente o haberse agotado el presupuesto.

El upgrade conserva actividades, eventos, enlaces, códigos, Space previamente guardado, preferencias y cuentas. No añade tareas ni realiza llamadas Google. Esta decisión evita activar retroactivamente grabaciones que en Fase 2 solo eran preferencias informativas. La inicialización de una actividad nueva sí genera una revisión pendiente.

`syncstatus` conserva exclusivamente el significado Calendar. Por ejemplo, `syncstatus=ready` y `meetconfigstatus=error` permite entrar a la reunión. Solo docentes con capacidad de administrar actividades y administradores reciben mensajes/configuración/reintento de artefactos. Consultar la vista no sincroniza nada.

## Reintentos, concurrencia y errores

- Máximo **5 intentos Meet por revisión**, contando el intento inmediato del observador; el contador se guarda antes de HTTP para contar también procesos interrumpidos.
- Cron reintenta la **misma tarea** con el backoff nativo de Moodle. El worker no crea otra tarea en cada fallo. La clave de deduplicación contiene actividad y revisión.
- Al agotar el presupuesto queda `error`, se retira esa tarea y el docente puede reintentar después de corregir la causa. Guardar/reintentar crea una revisión nueva con presupuesto nuevo. La espera por un lock usa el backoff nativo sin consumir intentos HTTP.
- Una tarea con revisión obsoleta o actividad eliminada termina sin HTTP. Si Calendar no está listo, la tarea termina; la confirmación posterior de Calendar encola el trabajo Meet vigente atómicamente.
- Si la edición ocurre durante GET, la comprobación de revisión evita aplicar un PATCH obsoleto. Si ocurre durante PATCH, la respuesta antigua no puede marcar la nueva revisión ready; la tarea nueva lee y reconcilia el Space. No existe atomicidad distribuida: puede haber una ventana breve con valores anteriores en Google.
- La recuperación tras timeout o fallo local posterior a PATCH consulta el nombre permanente y compara valores, evitando un segundo PATCH si Google ya aceptó el cambio.
- Timeout de conexión 5 s y total 15 s por solicitud; no se siguen redirecciones. El intento inmediato puede prolongar la petición web; una interrupción conserva la tarea durable.
- Autorización vencida, permiso insuficiente, licencia/política, feature no disponible, 404, 429, 5xx, JSON incorrecto o configuración no confirmada quedan como error Meet seguro. No se borran el enlace ni IDs y no se cambia Calendar ready.
- No se guardan ni muestran cuerpos de errores, tokens o excepciones anidadas de Google. El mensaje docente indica revisar autorización, licencia y políticas, sin inferir una causa concreta que Google no haya confirmado de forma segura.

Las cuentas históricas deshabilitadas siguen usando su propio issuer. Una cuenta predeterminada nueva solo afecta actividades nuevas. Una edición de las preferencias de una serie recurrente actualiza el mismo Space; sus conferencias posteriores comparten esa configuración. No se modifican ocurrencias individuales.

## OAuth y configuración manual pendiente

Scopes adicionales finales, exclusivamente para issuers Google registrados en TUP Meet (incluidos históricos):

```text
https://www.googleapis.com/auth/calendar.events.owned
https://www.googleapis.com/auth/meetings.space.settings
```

Moodle conserva sus scopes nativos de identidad. No se añade `meetings.space.created`, readonly, Drive ni ningún scope de recuperación de artefactos. El plugin no almacena access/refresh tokens, client IDs o secretos; usa el cliente de cuenta de sistema de Moodle.

Para el smoke, el administrador debe:

1. Habilitar **Google Meet REST API** en el mismo proyecto Cloud que usa el issuer y mantener Calendar API habilitada.
2. Revisar licencia Workspace y políticas de grabación/transcripción aplicables a la cuenta organizadora y a quien entrará con privilegios.
3. Actualizar el plugin en staging cuando se autorice y completar Notificaciones; mantener cron/tareas ad hoc activos.
4. En **Administración del sitio > Servidor > Servicios OAuth 2**, reconectar la cuenta de sistema del issuer y consentir `meetings.space.settings` además de Calendar y los scopes de identidad.
5. Volver a **Plugins > Módulos de actividad > TUP Meet** y verificar nuevamente la identidad institucional original. Repetir el consentimiento para cuentas históricas que deban configurar sus espacios; no sustituir su identidad por la predeterminada actual.
6. Revisar y guardar cada actividad histórica que deba adoptar la automatización, o aplicar sus valores guardados desde el botón de reintento.

No se hizo configuración real de Cloud, consentimiento ni operación Google en esta implementación.

## Procedimiento de smoke real (pendiente)

Usar un curso y reuniones de staging, con participantes informados. Registrar fecha, versión, resultado y tipo de cuenta/licencia sin correos, IDs OAuth, tokens ni URLs privadas.

1. Crear reunión con grabación ON/transcripción OFF; verificar Calendar, acceso Meet y confirmación de configuración independiente.
2. Comprobar las opciones en Google Meet. Ingresar con alguien que tenga privilegios y observar su comportamiento nativo; no deducir generación por el texto ready de Moodle.
3. Cambiar a grabación OFF/transcripción ON y luego ambos OFF/ambos ON. Verificar que evento, enlace y Space se conservan y que solo cambian esas opciones.
4. Crear serie sabatina America/Cancun y verificar las opciones en dos conferencias de la misma serie. Mantener el límite local inclusivo validado en Fase 2.
5. En una cuenta de prueba sin permiso Meet o con política restrictiva, verificar que el enlace sigue funcionando y el docente ve un error seguro; el estudiante no debe ver detalles administrativos.
6. Corregir la autorización y reintentar; comprobar que la configuración converge sobre el mismo Space y que cron no acumula tareas por cada fallo.
7. Cambiar de cuenta predeterminada y deshabilitar la anterior para nuevas actividades; editar una actividad histórica y verificar que sigue usando al organizador original.
8. Confirmar que Moodle no presenta listados, enlaces a archivos, transcripciones, participantes ni publicaciones obtenidas de APIs posteriores.

## Validación automatizada

Validación local del **17/09/2026**, con PHP **8.3.33** / MariaDB **10.11.14** y la suite completa. Se conservan los **58 casos previos** y se añaden **33**. Los nuevos casos ejecutan servicios reales, XMLDB, locks, transacciones, tareas y capacidades. Solo se sustituyen adquisición del cliente OAuth y respuestas HTTP de identidad/Calendar/Meet; no se simulan las máquinas de estados ni hay Google real.

| Moodle | PHPUnit | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- | --- |
| 4.5.14 LTS | 9.6.34 | 91 | 794 | OK |
| 5.0.10 | 11.5.55 | 91 | 795 | OK |
| 5.1.7 | 11.5.55 | 91 | 795 | OK |

Comando en cada raíz Moodle:

```sh
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite --fail-on-warning --fail-on-risky --fail-on-incomplete --fail-on-skipped
```

También pasan sintaxis de **40 archivos PHP**, PHPCS Moodle sin errores/advertencias, `git diff --check`, estructura del módulo, XMLDB de instalación nueva y upgrades desde Fases 0/1/2. El verificador de savepoints confirma los tres bloques ordenados y coherentes. Los checkouts de core no tienen cambios versionados. El workflow CI permanece intacto; estos resultados son locales, no una ejecución remota de Actions.

La primera matriz detectó un fixture visual sin issuer válido. Se corrigió ese dato sintético, se verificó la prueba de capacidades y se repitió la matriz completa con éxito. No se cambió lógica funcional para ocultar el fallo.

Cobertura: resolución y persistencia del Space, reutilización sin código, cuatro combinaciones ON/OFF, máscara exacta, opciones ajenas conservadas, ausencia de creación de Spaces, comparación previa, timeouts y fallo local tras PATCH, revisión obsoleta y concurrencia, presupuesto y deduplicación, cuentas históricas, serie recurrente, estados separados y permisos de presentación, scopes delimitados e instalación/upgrades Fases 0/1/2.

## Riesgos y pendientes

- CI remoto y smoke institucional **no ejecutados para esta fase**. Las pruebas locales no validan una licencia, política o consentimiento reales.
- Ready significa última configuración confirmada; no hay vigilancia periódica de cambios manuales posteriores en Google. Guardar/reintentar vuelve a comparar.
- Las reuniones históricas sin Space persistido dependen del alias en su primera resolución. Un alias ya expirado/reutilizado requiere revisión administrativa; no se migra ni reasigna automáticamente un Space conocido.
- Las modificaciones afectan el espacio compartido por la serie completa. Cualquier reutilización manual del enlace fuera de esa serie comparte también las opciones de ese Space.
- Una API que omita los campos gestionados o no confirme su cambio no se considera configurada: queda error y requiere revisión.
- Se conservan los límites previos de copia/restauración, cambios externos y retención descritos en [Fase 2](PHASE2.md). Las copias de producción deben seguir sin autorización Google para evitar actuar sobre recursos originales.
- Grabaciones/transcripciones, su almacenamiento, recuperación y publicación quedan para fases posteriores expresamente autorizadas.
