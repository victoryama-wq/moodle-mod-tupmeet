# TUP Meet (`mod_tupmeet`)

Actividad Moodle para clases por Google Meet con propietario institucional configurable,
Calendar, coorganizador docente y una cronología académica de grabaciones nuevas e históricas.

## Estado actual

**0.9.0-rc1 · 2026092201 · MATURITY_RC.** Fase 7 prepara el piloto con feature freeze:
auditoría, validaciones y documentación; sin nuevas funciones ni cambios de esquema respecto de 0.8.3-alpha.

La base Fase 6B cuenta con CI remoto aprobado y smoke real de staging confirmado por el propietario,
incluyendo Meet-first, artifacts, grabaciones/publicación y CSV idempotente.
La validación remota del RC está aprobada: [TUP Meet CI #35785884626](https://github.com/victoryama-wq/moodle-mod-tupmeet/actions/runs/35785884626)
PASS en QUALITY y PHPUnit Moodle 4.5/5.0/5.1; resultados completos en [Fase 7](docs/PHASE7.md).
El smoke en Moodle productivo y el piloto siguen pendientes. No hubo deploy, instalación en producción
ni release publicado; este RC no es una publicación estable 1.0.

## Funciones disponibles

- Cuenta maestra mediante OAuth2 **nativo de Moodle**: identidad verificada, predeterminada única,
  reconexión y cuentas históricas. Cada actividad conserva su `accountid` original.
- Nuevas actividades **Meet-first**: un Space permanente y mismo enlace para toda la serie;
  estado independiente de Calendar, COHOST y artifacts. Resultado ambiguo de creación: revisión manual.
- Evento Calendar nativo con el mismo Meet, docente attendee y `sendUpdates=all`.
  Edición de horario/título/recurrencia conserva identidades. Repetición semanal con fecha final inclusiva y zona guardada.
- Un docente COHOST validado en servidor y bloqueado tras guardar. Grabación automática y preferencia
  de transcripción con estados independientes (sin recuperación de transcripciones).
- Descubrimiento de conferenceRecords/recordings y metadata `FILE_GENERATED`, polling acotado y
  rename del nombre del MP4. Sin descarga, cambio de propietario, carpetas o permisos Drive.
- Publicación automática/manual, ojo mostrar/ocultar local, filtrado de visibilidad en servidor,
  próxima sesión y UI académica separada del diagnóstico técnico.
- Estado del sistema local para administración: cuenta configurada, estados, cron y tareas.
- Migración CSV administrativa: preview, confirmación revalidada, matching exacto, deduplicación,
  tabla histórica separada y cronología native + legacy; no realiza llamadas Google.
- Idiomas `en`, `es` y `es_mx`; Privacy para identidades y atribución de acciones locales.

## Requisitos

Moodle **4.5 LTS, 5.0 o 5.1**, PHP **8.3** en la matriz probada, HTTPS y cron CLI cada minuto.
Validación local con MariaDB 10.11. Google Workspace con licencia/política compatible, cuenta institucional
y APIs **Google Calendar**, **Google Meet REST** y **Google Drive** habilitadas.
No se hardcodea una cuenta institucional ni se almacenan tokens en tablas propias.

Scopes actuales (sin ampliación en RC): `calendar.events.owned`, `meetings.space.settings`,
`meetings.space.created`, `meetings.space.readonly`, `drive.metadata`, además de identidad nativa Moodle.
La [guía de instalación](docs/PRODUCTION_INSTALL.md) incluye las URI completas, callback y conexión.

## Instalación y administración

Seguir [PRODUCTION_INSTALL](docs/PRODUCTION_INSTALL.md): respaldo coherente, ventana de mantenimiento,
**reemplazo limpio** de `tupmeet/`, Notificaciones/upgrade, cachés, OAuth/identidad, cron, Health y smoke autorizado.
En Moodle 5.1 el directorio web es normalmente `public/`; respetar el layout del sitio.
No sobreponer archivos que puedan dejar rutas PoC antiguas.

Administración del sitio → Plugins → Módulos de actividad → TUP Meet:
**Cuentas maestras**, **Estado del sistema**, **Migración histórica**.
Cambiar la cuenta predeterminada afecta únicamente a actividades nuevas.

## Seguridad y límites

- Administración global: `moodle/site:config`; gestión de actividad: `moodle/course:manageactivities`.
  Mutaciones por POST + sesskey, contexto y pertenencia comprobados en servidor.
- Estudiantes no reciben filas ocultas ni diagnóstico técnico. Visibilidad Moodle **no equivale** a permisos Drive.
- Tokens/client credentials se administran en Moodle core, nunca en el repositorio o tablas TUP Meet.
- No borrado externo al eliminar actividad; no retry ciego de `spaces.create` incierto.
- Calendar conserva backoff Moodle sin presupuesto finito propio; requiere seguimiento operativo.
- Sin backup/restore nativo de actividad, attendance, analytics, multi-COHOST ni recuperación de transcripciones.

Impacto y mitigación: [KNOWN_LIMITATIONS](docs/KNOWN_LIMITATIONS.md).
La [matriz de endpoints y auditoría](docs/PHASE7.md) distingue permisos, HTTP y escrituras locales de Moodle.

## Documentación vigente

| Documento | Uso |
|---|---|
| [CHANGELOG](CHANGELOG.md) | Funcionalidad consolidada del RC |
| [PRODUCTION_INSTALL](docs/PRODUCTION_INSTALL.md) | Instalación, actualización y OAuth |
| [ROLLBACK](docs/ROLLBACK.md) | Contención y recuperación coherente, sin downgrade DB |
| [KNOWN_LIMITATIONS](docs/KNOWN_LIMITATIONS.md) | Riesgos y mitigaciones |
| [ADMIN_RUNBOOK](docs/ADMIN_RUNBOOK.md) | Diagnóstico y controles existentes |
| [TEACHER_GUIDE](docs/TEACHER_GUIDE.md) | Operación cotidiana docente |
| [PILOT](docs/PILOT.md) | Plan y criterios, todavía sin ejecución |
| [PHASE7](docs/PHASE7.md) | Auditoría, pruebas y evidencia del RC |
| [ARCHITECTURE](docs/ARCHITECTURE.md) | Límites e invariantes |
| [ROADMAP](docs/ROADMAP.md) | RC → piloto autorizado → posible 1.0 |
| [CI](docs/CI.md) | QUALITY y matriz Moodle |
| [PHASE6B](docs/PHASE6B.md) | Contrato y límites de CSV histórico |

Los documentos `PHASE*` conservan evidencia del momento original; sus notas de cierre distinguen validaciones
posteriores. No interpretar un “pendiente” histórico como estado actual ni un CI de la base como CI del RC.

## Validación y empaquetado reproducible

Suite: `mod_tupmeet_testsuite` sobre las tres versiones Moodle, sin Google real.
Se conservan los **482 casos base**, más cobertura RC de metadata/instalación y guardas de rutas.
Los comandos y resultados están en [PHASE7](docs/PHASE7.md).

Auditar fuentes sin crear ZIP (Python 3 + Git):

```sh
python3 tools/audit_package.py --worktree
python3 tools/audit_package.py --ref HEAD
```

El primer comando incluye cambios pendientes no ignorados; el segundo lee blobs del commit indicado.
Verifican rutas, patrones de secretos y enlaces relativos; la revisión humana de fixtures y secretos sigue siendo necesaria.
La Fase 7 **no genera paquete**. Un empaquetado posterior autorizado usará exclusivamente un commit aprobado,
raíz `tupmeet/`, revisión del contenido y SHA256; excluir `.git`, dependencias locales, datos, secretos y resultados de tests.

## Licencia

GNU GPL v3 o posterior, según encabezados del código.
