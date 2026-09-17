# Fase 3.2 — docente coorganizador automático

## Base y alcance

- Base aprobada: `codex/fase-3-meet-auto-artifacts`, `096d6bb22679fdb63c63681388f711fd9ba17f2f`.
- Rama: `codex/fase-3-2-cohost`.
- Versión: **2026091701 / 0.4.1-alpha**, `MATURITY_ALPHA`.
- Desarrollo y pruebas locales. CI remoto y smoke de esta fase pendientes; sin push, merge, deploy ni release.

La cuenta maestra institucional histórica sigue siendo organizadora y propietaria del evento/Meet. El docente es un miembro `COHOST`, no un nuevo propietario. No se modifica `accountid`, no se crea otro Meet y no se transfiere la propiedad de archivos. Calendar sigue siendo el origen de la reunión.

**Fase 3 mantiene grabación y transcripción automáticas**, controles visibles, defaults `autorecord=1` / `autotranscript=0`, configuración `artifactConfig`, estados independientes, recurrencia y publicación como preferencia. No se recuperan grabaciones/transcripciones, no se transfieren archivos, no se usa Drive API y no se implementan asistencia, Smart Notes o migración legacy.

## Referencias oficiales revisadas el 17/09/2026

- [Recurso Member v2](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces.members).
- [members.list](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces.members/list).
- [members.create](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces.members/create).
- [members.patch](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces.members/patch).
- [Grabación automática y entrada del host/cohost desde Web](https://support.google.com/meet/answer/9308681?hl=en).

El endpoint oficial v2 permite miembros con `email` y `role=COHOST`; create/patch requieren `meetings.space.created`. List devuelve `members` y `nextPageToken`; se consulta con `pageSize=500`. El PATCH utiliza **solo `updateMask=role`**.

El scope tiene una limitación de alcance que debe verificarse sobre nuestros Spaces nacidos mediante Calendar. La implementación conserva exactamente las APIs documentadas: **no incorpora `spaces.create` ni alternativas no documentadas**. Una denegación sobre un Space de Calendar se registra como estado de error seguro; no se interpreta como autorización para cambiar la arquitectura. El smoke debe detenerse y reportarla.

Google indica que las funciones automáticas esperan la entrada del host o un cohost desde la Web. Por ello, la cuenta maestra **no necesita entrar si un COHOST válido entra desde la Web**, siempre sujeto a licencia, permisos y políticas Workspace. Esto no garantiza generación ni afirma que ya exista una grabación. TUP Meet no cambia las políticas de la organización ni la configuración de administración de anfitriones. La distribución automática que Google haga de sus artefactos se rige por sus propias opciones; el plugin no modifica permisos de Drive.

## Selección e identidad docente

`Docente coorganizador` envía exclusivamente un **USER ID Moodle**. El servidor vuelve a resolver el usuario, su email y sus permisos al guardar la primera selección y antes de cada intento/escritura remota. Cualquier `cohostemail`, nombre de Member, estado o cuenta enviado por el navegador se ignora.

Criterios, comprobados en contexto del curso:

- Usuario existente, no eliminado, no suspendido y no invitado.
- Matrícula activa, no suspendida ni expirada, en un método de matriculación activo.
- Capacidad `moodle/course:manageactivities`, sin conceder elegibilidad automáticamente por ser administrador del sitio.
- Email Moodle no vacío y válido según `validate_email`.

El selector muestra nombre completo y correo, con el ID como valor. Un docente actual elegible se preselecciona en actividades nuevas. Un administrador/coordinador sin matrícula docente elegible debe elegir a un docente. **La creación exige selección también en el servicio**, no solo en el formulario. Los llamadores internos deben suministrar `course` y `cohostuserid`; no se inventa un docente predeterminado.

No se impone un dominio institucional hardcodeado ni una restricción nueva de dominio en el plugin. La elegibilidad Moodle no prueba que el correo tenga una cuenta Google compatible; Google decidirá permisos/licencia/dominio. La autorización institucional del owner sigue comprobándose por el servicio OAuth existente.

Se conserva un snapshot del email para evitar redirigir privilegios si cambia después el perfil Moodle. Si el email cambia, o el docente deja de ser elegible, se detiene la reconciliación con un error seguro. No se cambia el snapshot ni se promueve otra dirección automáticamente. No existe monitoreo periódico de perfiles o revocación automática.

### Política de reemplazo

La primera selección guardada queda **inmutable**, incluso antes de confirmación remota. Es deliberadamente más restrictivo que bloquear solo al llegar a ready: un POST pudo completarse aunque su respuesta se perdiera, y una edición podría coincidir con una solicitud en vuelo.

- No se cambia ni se vacía silenciosamente la selección guardada.
- La primera selección en una actividad histórica sin cohost sí está permitida.
- La actualización usa comparación atómica de la revisión para evitar dos selecciones concurrentes.
- La corrección de una selección equivocada requiere revisión administrativa y una futura acción explícita; esta fase no ofrece un reemplazo que acumule privilegios.
- **Nunca se llama a `spaces.members.delete`.** Borrar una actividad o sus datos personales tampoco elimina miembros Google.

## Arquitectura

| Componente | Responsabilidad |
| --- | --- |
| `local/meeting/cohost_identity` | Opciones del selector, default y validación autoritativa de elegibilidad. |
| `local/google/member_service` | OAuth histórico, resolución del Space si falta, list/create/patch, paginación y validación segura. |
| `local/meeting/cohost_manager` | Selección inmutable, estado/revisión propios, lock, persistencia condicional y presupuesto. |
| `task/sync_cohost` | Reintento durable independiente con backoff Moodle. |
| `meeting_manager` / `observer` | Persisten intención sin HTTP y encolan al confirmar Calendar; intento inmediato después del commit. |
| `mod_form` / `meeting_status` / `retry.php` | Selección, presentación para gestores y POST protegido con sesskey/capacidad. |
| `privacy/provider` | Metadata, descubrimiento, exportación y borrado de identidad docente local. |

```text
Guardar usuario Moodle validado
  -> intención/revisión local en transacción
  -> Calendar ready y tarea cohost se confirman atómicamente
  -> después del commit: lock meeting:{id}
  -> comprobar elegibilidad y email guardado
  -> si falta meetspacename: resolver mediante el código Calendar y persistir
  -> members.list sobre el Space permanente (todas las páginas)
  -> email existente + COHOST: confirmar, sin POST/PATCH
  -> email existente + ROLE_UNSPECIFIED: PATCH únicamente role
  -> email ausente: POST email del snapshot + COHOST
  -> validar respuesta y confirmar la misma revisión
```

Si existe `meetspacename`, no se vuelve a depender del código. Se exige `^spaces/[A-Za-z0-9_-]+$`; los nombres de Member deben pertenecer exactamente a ese padre y cumplir `spaces/{space}/members/{member}` con segmentos seguros y máximo 255 caracteres. Un recurso ajeno, email distinto, JSON incorrecto o role sin confirmar deja error.

Después de un POST 409 se vuelve a listar y se exige email coincidente + COHOST. Una respuesta perdida se recupera con list en el siguiente intento, sin duplicar miembros intencionalmente. Se rechazan emails coincidentes ambiguos y ciclos de paginación. Máximo 20 páginas de hasta 500 miembros por listado; superar ese límite falla de forma segura.

La tarea cohost se encola al quedar Calendar ready, aunque artifactConfig esté pendiente o en error. Puede resolver el Space por sí misma usando la validación existente de Fase 3; **no cambia ni necesita confirmar artifactConfig**. Así, una licencia que impida transcripción no bloquea permanentemente la asignación del docente.

## Esquema y estados

No hay nuevas tablas. `install.xml`, `upgrade.php`, versión y savepoint **2026091701** incluyen:

| Campo | Tipo / default | Motivo |
| --- | --- | --- |
| `cohostuserid` | int(10), 0 | Referencia estable al docente Moodle. |
| `cohostemail` | char(100), NULL | Snapshot mínimo del email autoritativo. |
| `cohostmembername` | char(255), NULL | Member confirmado dentro del Space. |
| `cohoststatus` | char(12), unconfigured | Estado independiente. |
| `cohostversion` | char(32), legacy | Revisión que invalida tareas/respuestas antiguas. |
| `cohostattempts` | int(4), 0 | Presupuesto por revisión. |
| `cohostmodified` | int(10), 0 | Confirmación de la revisión actual. |
| `cohostlocked` | int(1), 0 | Selección ya fijada; sobrevive como marca no personal tras borrado de privacidad. |

Estados: **unconfigured** (sin elección), **pending** (intención guardada), **ready** (COHOST confirmado) y **error** (no confirmado). Ready es la última confirmación, no vigilancia continua de cambios externos.

El upgrade deja todas las actividades históricas `unconfigured`, sin inferir docentes, encolar trabajo ni hacer HTTP. Conserva íntegramente todos los datos de Fase 3.

Una serie conserva **1 Space / 1 Member principal / N conferencias**. No se crea un miembro por ocurrencia; guardar/reintentar vuelve a comprobar el mismo email en el mismo Space. La permanencia efectiva en sesiones futuras debe comprobarse en el smoke, especialmente si alguien cambia roles manualmente en Google.

## Fallos, reintentos y concurrencia

- Estado y revisión separados de `syncstatus` y `meetconfigstatus`; los errores de miembros no los modifican ni borran URI/IDs.
- El botón Join sigue dependiendo únicamente de Calendar ready y un URI Meet válido. Los alumnos no ven identidades docentes ni errores administrativos de cohost.
- Máximo **5 intentos por revisión**, incluido el inmediato. Cada intento cuenta antes de HTTP. La espera por lock no consume intentos de red.
- Tarea deduplicada por actividad/revisión; backoff nativo sobre la misma tarea, sin autoencolar otra en cada fallo. Al agotarse el presupuesto queda error y se requiere reintento explícito.
- `Reintentar configuración del coorganizador` usa `target=cohost`: renueva solo esa revisión y conserva Calendar y artifactConfig ya confirmados. El retry general conserva su comportamiento anterior y añade el intento cohost.
- El lock compartido `meeting:{id}` serializa Calendar, artefactos y miembros. Las escrituras comparan revisión cohost y Calendar. Las tareas obsoletas terminan sin HTTP.
- Se revalida el docente antes de la escritura remota. Una edición durante GET bloquea el POST/PATCH obsoleto; durante una escritura, la respuesta anterior no confirma la revisión nueva. El siguiente intento reconcilia.
- No hay transacción distribuida con Google: un privilegio puede haberse aplicado antes de un timeout, una cancelación local o un borrado de privacidad. Por eso no se habilita reemplazo silencioso ni se promete revocación.
- HTTP fuera de transacciones; timeout 15 s, conexión 5 s, sin redirecciones. El intento inmediato y los listados extensos pueden prolongar el guardado; cron conserva el trabajo si se interrumpe.
- 401/403, licencia/política, 404, 429, 5xx, timeout o datos inconsistentes producen un mensaje fijo sanitizado. No se almacenan ni registran cuerpos Google, tokens o excepciones anidadas. El plugin no diagnostica una causa específica solo por un estado genérico.

## Privacidad

El USER ID permanece en Moodle; únicamente el email se transmite a Meet para configurar la membresía. Se exportan ID, snapshot, Member, estado y fecha de confirmación en el contexto de la actividad. No se exportan credenciales ni la identidad del organizador como datos personales del docente.

El borrado aprobado elimina ID/email/Member y reinicia el estado local, invalidando tareas mediante una nueva revisión. Conserva `cohostlocked=1` si hubo selección, para impedir que el borrado autorice un segundo cohost. No cambia organizador, evento, Space o artefactos. Los permisos remotos existentes requieren un procedimiento administrativo explícito independiente, también si el docente se desmatricula o se suspende. No se guarda información personal en los datos de las tareas: solo actividad y revisión.

## OAuth y configuración manual para smoke

Scopes adicionales finales **solo para issuers Google registrados en TUP Meet**, incluidas cuentas históricas:

```text
https://www.googleapis.com/auth/calendar.events.owned
https://www.googleapis.com/auth/meetings.space.settings
https://www.googleapis.com/auth/meetings.space.created
```

Moodle conserva sus scopes de identidad y administra los tokens. No se añaden scopes Drive ni se guardan credenciales en el plugin.

Cuando se autorice la actualización de staging:

1. Instalar la versión y ejecutar Notificaciones/upgrade; mantener cron activo.
2. Mantener Calendar API y Meet REST API habilitadas en el mismo proyecto Google Cloud.
3. Reconectar la cuenta de sistema del issuer Moodle y consentir los tres scopes. Repetir para propietarios históricos que lo requieran.
4. Verificar nuevamente la identidad institucional original en la administración TUP Meet.
5. Revisar licencias, permisos de grabación/transcripción y políticas de cohosts/administración de anfitriones en Workspace. No se ajustan desde código.
6. Confirmar que el docente tiene matrícula activa, capacidad docente y el correo Moodle correspondiente a su cuenta Google.

## Smoke real pendiente

Usar datos de staging sin incluir correos, códigos, URLs privadas ni credenciales en el reporte:

1. Crear una actividad con docente seleccionado y las preferencias deseadas.
2. Confirmar que la cuenta maestra sigue siendo organizer/propietaria y que no cambió `accountid`.
3. Confirmar estado cohost ready y presencia del docente como COHOST en el Space.
4. **Si Google deniega members.create por el origen Calendar del Space, detener la prueba y reportar el error sanitizado.** No migrar a spaces.create ni alterar el diseño.
5. La cuenta maestra no entra; el docente entra usando su cuenta seleccionada desde navegador Web.
6. Observar grabación automática; con `autotranscript=ON`, observar también transcripción. No dar por creados archivos desde el estado ready de Moodle.
7. Comprobar que evento, Meet URI y Space se mantienen.
8. Repetir en dos sesiones de una serie; confirmar el mismo miembro y las opciones de Fase 3.
9. Verificar que una restricción de members API mantiene Join y las opciones de artefactos ya confirmadas, y que el alumno no ve errores administrativos.
10. Reintentar solo cohost después de corregir autorización y verificar que no aparecen duplicados.

## Validación local

Validación local completada el **17/09/2026**, con **PHP 8.3.33 / MariaDB 10.11.14**:

| Moodle | PHPUnit | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- | --- |
| 4.5.14 LTS | 9.6.34 | 143 | 1394 | OK |
| 5.0.10 | 11.5.55 | 143 | 1395 | OK |
| 5.1.7 | 11.5.55 | 143 | 1395 | OK |

Comando desde cada raíz Moodle:

```sh
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite --fail-on-warning --fail-on-risky --fail-on-incomplete --fail-on-skipped
```

Se conservan las **91 pruebas anteriores** y se añaden **52 casos**. PHPUnit ejecuta servicios, persistencia, permisos y tareas reales, sustituyendo únicamente adquisición del cliente OAuth y la frontera HTTP. No se hacen llamadas reales Google. Los generadores anteriores ahora suministran docentes elegibles para el requisito obligatorio de creación.

Cobertura nueva: selector/default, administrador no preseleccionado, usuarios inexistentes/eliminados/suspendidos/no matriculados/sin capacidad, matrícula suspendida/expirada y email inválido; rechazo de POST manipulado; scopes restringidos; list paginado, roles, POST/PATCH exactos, respuestas inválidas, recursos ajenos, 409, timeout, duplicados y ciclos; identidad permanente sin código; propietarios históricos; serie real; presupuesto, deduplicación, lock compartido, tarea obsoleta, concurrencia, transacciones y retry exclusivo; privacidad, visibilidad y upgrade desde Fase 3. Se conservan las pruebas previas de grabación, transcripción y recurrencia.

También pasan **PHPCS sin errores ni advertencias**, sintaxis de **46 archivos PHP**, `git diff --check`, validación de estructura, XMLDB de instalación y upgrades. Los cuatro bloques de upgrade/savepoint coinciden y están ordenados. Core Moodle, CI, servicios de cuentas, transporte Calendar, recurrencia y servicio/manager de artifactConfig permanecen sin cambios.

La primera matriz detectó tres problemas de fixtures: comparación estricta de IDs numéricos devueltos como texto por la API de privacidad, prueba de contención usando el lock reentrante de MariaDB en la misma conexión y formulario anterior sin el nuevo docente obligatorio. Se corrigieron los fixtures (contención con el backend nativo de locks por registro), los tres casos pasaron individualmente en las tres versiones y después pasó la matriz completa. No se cambió la lógica de Fase 3 para obtener esos resultados.

CI heredado no se modifica. CI remoto y el comportamiento real sobre Spaces originados en Calendar quedan pendientes de una autorización posterior.
