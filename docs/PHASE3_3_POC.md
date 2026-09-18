# Fase 3.3 — PoC Meet-first

**EXPERIMENTAL / STAGING ONLY.** Versión `2026091800` / `0.5.0-alpha-poc`.
Base: `60bf2482a56318ff15e20c5bcf1146f6aebe2020` (Fase 3.2.1).
Rama: `codex/fase-3-3-meet-first-poc`.

> **Actualización Fase 3.4:** el responsable reportó smoke real satisfactorio de Space, COHOST, artefactos y Calendar native; ver [evidencia y flujo actual](PHASE3_4.md). El contenido siguiente conserva el diseño y estado original de la PoC. Sigue aislada para comparación en staging y deberá retirarse o deshabilitarse antes del release de producción.

## Motivo y evidencia

El responsable reportó `create / 403` al intentar añadir el COHOST al Space creado por Calendar. Es evidencia del smoke anterior, no un resultado reproducido por PHPUnit ni una demostración de que todos esos Spaces sean incompatibles. Se prueba ahora un Space creado directamente por Meet con la aplicación autorizada.

El flujo normal Calendar-first conserva sus servicios, formulario, tareas, actividades, cohost y configuración de artefactos. La PoC no es una nueva modalidad de actividad y no implementa recuperación de grabaciones, Drive ni Fase 4. Su resultado real sigue pendiente de smoke; los mocks no demuestran aceptación de Google.

## Arquitectura y autorización

- Página separada `poc_meet_first.php`, enlazada en Administración del sitio > Plugins > Módulos de actividad > TUP Meet — PoC Meet-first (SOLO STAGING), junto a la administración normal de TUP Meet. Solo administradores reales del sitio, autenticados; una capacidad delegada no basta.
- `classes/form/poc_setup_form.php`: preparación local con curso y USER ID de docente elegible, fechas y reconocimiento de staging. Consultar la página o cargar docentes no llama a Google.
- `classes/local/poc/experiment.php`: pasos ordenados, POST, sesskey, bloqueo Moodle por administrador y comprobación del identificador de ejecución. Rechaza transacciones DB activas.
- `classes/local/poc/service.php`: transporte aislado con Moodle OAuth2 nativo, endpoints oficiales, tiempos máximos y sin redirecciones. Hereda únicamente el algoritmo de artefactos existente, sin modificarlo.
- `failure.php`: mensaje fijo y HTTP normalizado; sin cuerpo remoto, cabeceras, excepción anterior ni datos de depuración.

Se exige exactamente una cuenta maestra habilitada, predeterminada y verificada. La fábrica OAuth vuelve a verificar su identidad. El docente se resuelve con `cohost_identity` desde el curso/USER ID; un correo o ID de Google enviado por el navegador no tiene autoridad. Cada paso revalida docente y cuenta. Cambiar el predeterminado o el correo durante la ejecución bloquea operaciones posteriores.

No hay correos institucionales hardcodeados, secretos ni tokens nuevos. No se crea issuer ni se solicitan credenciales en esta página. Los tokens siguen exclusivamente bajo Moodle OAuth2.

## Persistencia temporal y privacidad

Se usa la caché nativa de aplicación `mod_tupmeet/meet_first_poc`, con garantía de datos, separada por ID de administrador, TTL de 24 horas y límite absoluto de 24 horas para ejecutar pasos. No hay tablas nuevas ni cambios a `install.xml`/`upgrade.php`; el incremento de versión permite a Moodle descubrir la caché y las cadenas nuevas.

Guarda curso/USER ID, ID de cuenta, hash SHA256 del correo del docente para detectar cambios, horario/zona, ID aleatorio de ejecución, IDs de eventos preasignados, proyección segura del Space/Member y estado/HTTP de cada etapa. El hash sigue siendo dato vinculado al docente, no anonimización. La metadata de privacidad declara la caché y el envío del docente como invitado de B2. La caché no es archivo histórico ni registro de auditoría: copiar los IDs necesarios antes de vaciarla o purgar cachés. Su vencimiento no garantiza borrado físico inmediato del backend; para eliminación local inmediata, vaciar el experimento o purgar esta caché en Moodle. Los datos Google se limpian por separado.

Nunca se escriben IDs PoC en `tupmeet` ni se modifican `tupmeet_accounts` o tareas normales. Antes de cada escritura remota se persiste una marca de intento. Una recarga, doble clic o respuesta incierta no repite esa escritura en la misma ejecución. `members.verify` es una lectura explícita repetible. No hay cron ni reintentos automáticos. La garantía depende de conservar la caché; iniciar otra ejecución es otra decisión administrativa, no recuperación automática.

## A1 — Space mínimo

```http
POST https://meet.googleapis.com/v2/spaces
Content-Type: application/json

{}
```

Scope de creación: `https://www.googleapis.com/auth/meetings.space.created`.
Se valida el nombre canónico `spaces/{id}`, rechazando rutas, alias que coincidan con el meeting code y URLs ajenas a `https://meet.google.com/`. La identidad permanente es `space.name`, nunca el código. Si viene `meetingCode`, debe coincidir con la URI. Solo se conserva la configuración básica permitida (`accessType`, `entryPointAccess`), no la respuesta completa.

## A2 / A3 — COHOST

```http
POST https://meet.googleapis.com/v2/spaces/{id}/members
Content-Type: application/json

{"email":"<correo resuelto del docente Moodle>","role":"COHOST"}
```

El POST y la verificación son botones distintos. A3 usa `members.list` paginado y acotado; exige email exacto sin distinción de mayúsculas, rol `COHOST` y Member bajo el mismo Space. Un HTTP exitoso sin esos datos no es PASS. Si A2 tuvo timeout después de crear el miembro, A3 puede confirmarlo sin repetir POST. **POC A = PASS** requiere Space confirmado y A3 aprobado.

## A4 — Artefactos en el mismo Space

Tras A3, reutiliza `meet_service::synchronize` con `autorecord=1`, `autotranscript=0`, el nombre canónico recién creado y su URI. Conserva comparación, máscara de PATCH y confirmación existentes: `autoRecordingGeneration=ON`, `autoTranscriptionGeneration=OFF`. No toca preferencias ni estados de actividades normales. Configurado no significa que ya exista una grabación; aplican licencias, políticas e ingreso de participantes con privilegios.

## B1 — Representación nativa, aceptación no garantizada

Se crea un evento experimental en `primary` de la cuenta maestra, con ID preasignado, título fijo, inicio/fin y zona Moodle explícita. La PoC no admite recurrencia. Se usa `conferenceDataVersion=1`, sin invitados y `sendUpdates=none` en B1.

```json
{
  "conferenceData": {
    "conferenceId": "<código de diez letras con guiones de meetingUri>",
    "conferenceSolution": {"key": {"type": "hangoutsMeet"}},
    "entryPoints": [{"entryPointType": "video", "uri": "<meetingUri del Space A1>"}]
  }
}
```

Se prueban exclusivamente esos campos documentados. **No se envía `conferenceData.createRequest`, no se genera ni inventa `signature`**. Calendar genera la firma y debe conservarse al copiar datos de conferencia existentes; esta prueba no dispone de una conferencia Calendar de origen que copiar. Que los campos estén documentados no garantiza que Calendar acepte esta combinación para un Space Meet-first.

Después del INSERT, un GET debe confirmar el mismo ID de evento, `hangoutsMeet`, conferenceId, exactamente un entryPoint de video igual a la URI original y, si existe, el mismo `hangoutLink`. No basta HTTP 2xx. El ID confirmado se guarda antes del GET; también se muestra el ID preasignado si la respuesta del INSERT se pierde.

Resultado: `NATIVE_CALENDAR = PASS` o `UNSUPPORTED / NOT_CONFIRMED`, con etapa `FAIL` y HTTP normalizado si está disponible. Un 401/403/timeout **no demuestra incompatibilidad general**; significa que esta ejecución no confirmó B1. No se vuelve a intentar B1.

## B2 — Fallback separado y explícito

Solo tras FAIL de B1 y éxito de A4 aparece el botón alternativo. Exige confirmar que se revisaron los posibles recursos de B1 y que se desea enviar la invitación.

- Nuevo ID de evento experimental, título fijo y mismo horario.
- **Sin `conferenceData`**, sin `createRequest` y sin firma.
- `location = meetingUri` del Space A1.
- `attendees = [{"email": "<docente validado>"}]` y `sendUpdates=all`.
- GET de confirmación: mismo ID, location exacta, invitado correcto, ningún conferenceData/hangoutLink inesperado.

`FALLBACK_CALENDAR = PASS` confirma esos datos por API y que se solicitó enviar actualizaciones; **no confirma recepción de correo ni entrega efectiva**, que requieren revisión humana. La aplicación nunca solicita una segunda conferencia. Si políticas de Calendar producen otra, la verificación falla: detener el smoke y revisar manualmente, no eliminar automáticamente.

## Diagnóstico y límites

Tabla por etapa: `spaces.create`, `members.create`, `members.verify`, `artifactConfig`, `calendar.native`, `calendar.fallback`; estados `NOT_RUN`, `PASS`, `FAIL`. HTTP 0 se representa como no disponible. No se guardan cuerpos, emails de respuesta, tokens, cabeceras Authorization ni stack traces. Los errores de validación semántica pueden mostrar HTTP no disponible aun tras una respuesta 2xx.

- API de miembros, licencias Workspace, políticas y consentimiento deben permitir COHOST. El experimento no elude restricciones ni prueba por sí solo propiedad contractual de la cuenta.
- Un timeout puede haber creado recursos. No repetir escritura; inspeccionar Google con los IDs disponibles. Si se perdió A1, puede no existir un nombre fiable recuperable desde Moodle: revisar la cuenta antes de iniciar otra ejecución.
- No se pasa un Space externo en el formulario ni se importa una reunión real previa.
- Una caché purgada o vencida pierde el seguimiento local. No usar esta arquitectura temporal como base productiva sin otro diseño autorizado.
- Un evento B1 puede existir aunque falle la confirmación. No enviar B2 hasta revisarlo. No se garantiza que ningún tercero o política cree conferencias; se valida el resultado observable.

## Smoke exacto posterior, exclusivamente staging

1. Instalar esta versión en staging mediante el proceso autorizado y completar Notificaciones. Verificar cuenta maestra predeterminada y su identidad en TUP Meet. No cambiar cuentas históricas.
2. En el issuer Google existente, confirmar Calendar API y Meet REST API habilitadas, scopes actuales de TUP Meet (incluido `meetings.space.created` y los scopes de miembros de Fase 3.2.1) y autorización vigente. Reconectar en Moodle si faltan scopes, usando la misma cuenta institucional. No guardar credenciales en el repositorio.
3. Confirmar licencias/políticas y usuario Moodle docente elegible en un curso de staging. Revisar en Moodle que su correo sea el previsto; seleccionarlo por USER ID. No introducir el correo en código.
4. Entrar como administrador real: Administración del sitio > Plugins > Módulos de actividad > TUP Meet — PoC Meet-first (SOLO STAGING). Comprobar `EXPERIMENTAL / STAGING ONLY`.
5. Introducir ID del curso y cargar docentes (solo lectura local). Seleccionar docente, inicio/fin futuros, reconocer staging y preparar ejecución local. Los selectores usan la zona del usuario Moodle; la tabla muestra la zona institucional y los instantes equivalentes. Usar `America/Cancun` en ambas para el smoke institucional.
6. Pulsar `spaces.create` (A1). Registrar name, URI/código y configuración básica seguros. Confirmar PASS y cuenta propietaria en Google. No usar Calendar aún.
7. Pulsar `members.create` (A2), luego `members.verify` (A3). Confirmar docente COHOST y `POC A = PASS`. Si falla, registrar únicamente etapa/HTTP y detener pasos dependientes; una verificación explícita puede resolver un A2 incierto.
8. Pulsar `artifactConfig` (A4). Confirmar PASS y ON/OFF. Verificar comportamiento real de grabación con participantes autorizados según licencia; no se recupera la grabación desde Moodle.
9. Pulsar `calendar.native` (B1) una sola vez. Si PASS, abrir el evento y comprobar que el enlace coincide **exactamente** con la URI A1 y no hay dos Meet. Registrar aceptación real o fallo seguro.
10. Si B1 falla, no insistir. Revisar en Google si existe el evento preasignado. Revisar y limpiar manualmente recursos no deseados antes de continuar. Marcar el reconocimiento y pulsar `calendar.fallback` (B2) una sola vez.
11. Confirmar evento fallback, docente invitado, recepción real de invitación, URI visible en ubicación y enlace utilizable. Verificar que no aparece otra conferencia y que el docente conserva COHOST en el Space original.
12. Registrar resultados A/B y limitaciones sin correos, URLs privadas, tokens ni cuerpos. Guardar IDs para limpieza en un registro administrativo privado, no en el repositorio.

## Limpieza manual

La tabla muestra el Space, Member si fue confirmado y los IDs de eventos intentados; un ID intentado no prueba existencia remota. En la cuenta propietaria, localizar los eventos `TUP Meet PoC - native/fallback` por horario e ID; revisar invitaciones y eliminar manualmente los eventos de prueba cuando corresponda. Revisar permisos del Space desde las herramientas oficiales disponibles y retirar acceso experimental manualmente; no asumir que eliminar Calendar elimina el Space o sus miembros. Si la consola no ofrece la operación, seguir el procedimiento administrativo oficial de Workspace. No se implementa `spaces.delete`, `members.delete` ni borrado de eventos.

Después de registrar y limpiar lo necesario, usar el botón para vaciar la ejecución local con su reconocimiento explícito. Ese botón borra **solo caché Moodle**, nunca Google. La caducidad tampoco limpia Google.

## Validación local

Pruebas sin Google real: éxito A1–A4/B1, COHOST confirmado, nombres/URI inválidos, B1 rechazado o alterado y fallback, invitado server-side, ausencia de createRequest/firma/DELETE, respuestas sensibles descartadas, timeout y no repetición, orden de pasos, GET/sesskey, solo administradores, separación entre administradores, expiración, propietario y docente cambiantes, actividades/cuentas/tareas normales intactas.

Validación local del 18/09/2026, PHP 8.3.33 / MariaDB 10.11.14. Suite completa `mod_tupmeet_testsuite`, incluyendo regresiones de Fases 2/3/3.2/3.2.1:

| Moodle | PHPUnit | Pruebas | Aserciones | Resultado final |
| --- | --- | ---: | ---: | --- |
| 4.5.14 | 9.6.34 | 186 | 2869 | PASS |
| 5.0.10 | 11.5.55 | 186 | 2870 | PASS |
| 5.1.7 | 11.5.55 | 186 | 2870 | PASS |

Los 27 casos nuevos de PoC también pasaron aisladamente en Moodle 4.5 (800 aserciones). En el desarrollo se corrigió el rechazo explícito de sesskey vacío y se capturaron con `redirectMessages()` las bienvenidas de matriculación de los fixtures, que habían causado un aviso y un error de limpieza en la primera regresión. Se repitió después toda la matriz con éxito, sin avisos, errores, pruebas omitidas ni riesgosas; en PHPUnit 11 se añadió `--fail-on-notice` a los controles estrictos del CI.

PHPCS Moodle completo: PASS. Sintaxis de los 55 archivos PHP: PASS. Estructura del plugin, cinco savepoints ordenados y pruebas XMLDB de instalación/upgrades: PASS. Revisión de diff y patrones de credenciales: sin hallazgos. Los servicios, tareas, formulario normal, esquema y workflow CI permanecen idénticos a la base. CI heredado se conserva; sin push no hay nueva validación remota y no se ejecutó Google real.

## Referencias oficiales

- [Meet spaces.create](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces/create).
- [Meet members.create](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces.members/create) y [members.list](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces.members/list).
- [Calendar Events: conferenceData, conferenceId y signature](https://developers.google.com/workspace/calendar/api/v3/reference/events).
- [Calendar events.insert: conferenceDataVersion y sendUpdates](https://developers.google.com/workspace/calendar/api/v3/reference/events/insert).
- [Fase 3: artefactos](PHASE3.md) y [Fase 3.2.1: scopes/diagnóstico](PHASE3_2_1.md).
