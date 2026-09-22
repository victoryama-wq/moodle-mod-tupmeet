# Runbook de administración

Entrada: **Administración del sitio → Plugins → Módulos de actividad → TUP Meet**:
**Cuentas maestras**, **Estado del sistema**, **Migración histórica**.
Solo usuarios con `moodle/site:config`. Un docente editor gestiona su actividad, no estas páginas globales.
Health no realiza probes externos ni repara automáticamente. La sección técnica de cada actividad es para gestores.

| Incidente | Revisión y acción existente |
|---|---|
| No se creó Meet | Revisar Google Meet/Space en actividad y Health, cuenta histórica, autorización y cron. `pending` con 429 espera backoff; no reenviar repetidamente. `error` definitivo: corregir causa y usar reintento de Space. `uncertain`/`creating` interrumpido: revisión manual y soporte; no SQL, no segundo Space, no retry ciego |
| Error Calendar | Comprobar OAuth del propietario histórico, Calendar API/políticas y docente matriculado/correo original. Tras corregir causa, usar **Reintentar sincronización** de la actividad. Calendar tiene backoff Moodle sin límite propio; observar si el error persiste. Si Space está listo, entrar al mismo Meet sigue disponible |
| Error COHOST | Revisar docente seleccionado: activo, matriculado, con gestión del curso y mismo correo. Comprobar licencia/política Workspace. Usar reintento de coorganizador tras corregir la causa; máximo cinco intentos por revisión. No cambiar el docente bloqueado, no borrar miembros. Modelo Calendar histórico: asignación automática no disponible |
| Artifacts sin configurar | Revisar OAuth, scopes de settings, licencia/políticas de grabación/transcripción y preferencias guardadas. Corregir y reintentar sincronización de la actividad. Estados independientes: no crear otro Meet |
| Grabación no aparece | Health → discovery/cron/tareas, luego sección técnica → **Sincronizar grabaciones**. Verificar que la sesión terminó y Google terminó de procesar. `STARTED`/`ENDED` aún no son archivo disponible. No buscar carpetas Drive desde el plugin. Límites de retención hacen importante atender una interrupción prolongada |
| Rename en error | El MP4/enlace puede seguir disponible. Revisar `drive.metadata`, políticas y capacidad de renombrar del propietario histórico; usar **Reintentar renombrado** en la fila técnica una vez corregido. No descargar/re-subir/mover el archivo |
| Estudiante no ve grabación | Confirmar acceso al curso/actividad, ojo y `studentvisible`. La preferencia automatic/manual inicializa nuevas filas; cambiarla no republica retroactivamente todo el historial. En legacy revisar el valor visible del CSV y el ojo. Si ve el enlace pero Drive deniega apertura, revisar política externa institucional, no ampliar permisos desde TUP Meet |
| OAuth expiró | Cuentas maestras → conectar/reconectar el issuer **de esa cuenta histórica**, autorizar la misma cuenta de sistema, volver y verificar correo/identidad. Si es una cuenta diferente, registrar otro issuer/cuenta para nuevas actividades; nunca reasignar las antiguas. Reintentar solo operaciones afectadas |

## Cron y cola

- Cron CLI cada minuto; `discover_recordings` cada cinco minutos, lotes de 25 descubrimientos y 25 renames.
- Health avisa ejecución de discovery de más de 15 minutos, tareas vencidas de más de 30 minutos y
  más de 1000 pendientes. Son señales operativas, no objetivos de capacidad ni una prueba de caída Google.
- Consultar las páginas nativas de tareas/registros para distinguir espera, ejecución y backoff.
- Discovery/rename: cinco intentos por revisión, leases de 30 minutos y cooldown manual de 60 segundos.
  COHOST/artifacts: cinco intentos por revisión. Space 429: reintento seguro espaciado, sin fallo permanente de cuota.
- No borrar tareas ni resetear estados directamente en DB. Un worker viejo se descarta por revisión;
  una tarea duplicada no autoriza una segunda creación de Space.
- Si el backlog crece o se requieren intervenciones repetidas, aplicar [pausa del piloto](PILOT.md) y [contención](ROLLBACK.md).

## Información para soporte

Enviar por canal autorizado: versión plugin/Moodle, ID local de actividad (distinguir `tupmeet.id` y `cmid`
de la URL), subsistema/estado, HTTP numérico si está disponible, fecha/hora y zona, captura Health saneada,
última ejecución y estado de cron/tareas. HTTP `0`/guion significa no disponible, no respuesta 200.
Ocultar nombres/correos/URLs cuando no sean necesarios. No adjuntar tokens, cuerpos OAuth, Client Secret,
refresh tokens, dumps completos ni CSV institucional al repositorio o tickets públicos.

No hay auto-repair global. No ejecutar un retry como prueba si aún no se corrigió la causa.
