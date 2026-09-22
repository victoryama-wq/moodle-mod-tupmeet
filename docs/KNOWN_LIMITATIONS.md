# Limitaciones conocidas del RC

| Limitación | Impacto y mitigación |
|---|---|
| Calendar no tiene presupuesto finito propio de reintentos | Usa backoff nativo de Moodle; un error sostenido puede dejar una tarea recurrente. Observar Health/tareas, corregir OAuth/API/política y usar el reintento explícito de la actividad. No cambiar esta política sin validación específica |
| Visibilidad Moodle no es una ACL por curso en Drive | `studentvisible` filtra enlaces y conteos en el servidor, pero no concede/revoca lectura del archivo. Definir políticas Drive institucionales y probar con estudiante real autorizado |
| Enlace compartido dentro del dominio | Si Drive lo permite, puede abrirse fuera de Moodle, incluso después de ocultar la fila. No presentar el ojo como revocación de permisos externos |
| Rename requiere `drive.metadata`, scope restringido | Revisar consentimiento, políticas y requisitos Google aplicables. El código solo modifica `name`; no descarga contenido ni cambia propietarios, permisos o carpetas. Un error de rename no invalida el enlace de grabación |
| Retención limitada de `conferenceRecords` | Google indica eliminación del recurso 30 días después del fin de conferencia. Mantener cron y revisar interrupciones pronto. La retención de esos metadatos no equivale a la del MP4 en Drive; el plugin conserva lo ya descubierto |
| Sin backup/restore nativo de actividad | `FEATURE_BACKUP_MOODLE2=false`: no prometer clonación/restauración de TUP Meet mediante backup de curso. Mantener respaldo completo coherente de Moodle y procedimiento de [rollback](ROLLBACK.md) |
| Sin eliminación externa automática | Eliminar una actividad limpia datos locales, no Spaces/eventos/miembros/archivos. La retención o eliminación externa requiere proceso institucional separado y autorizado |
| Sin recuperación de transcripciones | La preferencia de transcripción automática se conserva y configura en Meet; no se recuperan ni publican documentos de transcripción |
| Sin asistencia/participantes | No se generan reportes de asistencia ni se consulta participantes. No utilizar el listado de grabaciones como evidencia de asistencia |
| CSV no verifica existencia remota | Preview/import no llaman a Drive: una URL sintácticamente válida puede apuntar a un archivo inexistente o inaccesible. Validar inventario antes de importar |
| CSV requiere preparación externa | Nombres/fechas/zonas/destinos deben estar validados. Matching exacto tras normalizar espacios, sin fuzzy; `tupmeetid` explícito prevalece y solo se admite destino Meet-first. Revisar preview y advertencias; no importar datos institucionales en pruebas |
| `spaces.create` sin clave de idempotencia documentada | Timeout, transporte incierto o respuesta ambigua producen `uncertain`; no hay retry automático ni botón que lo desbloquee. Revisión manual del administrador y soporte; no cambiar estados por SQL ni crear un segundo Space para resolverlo |
| Un solo COHOST principal bloqueado | Usuario/correo quedan vinculados a la actividad. Un cambio de docente/correo requiere decisión operativa; no borrar/reemplazar miembros automáticamente. El modelo Calendar histórico no recibe nuevas escrituras COHOST |
| Cuenta histórica fija | Cambiar la predeterminada solo afecta nuevas actividades. Hay que mantener acceso al issuer/cuenta histórica; reconectar con otra identidad se rechaza |
| Dependencia de licencia y procesamiento Google | OAuth concedido no garantiza disponibilidad de artifacts ni tiempo de procesamiento. Verificar licencia/políticas y esperar `FILE_GENERATED`; no prometer publicación inmediata |
| Health es local | No prueba OAuth vigente ni Google en vivo. Sus fechas son evidencia de operaciones anteriores; el contador de visibilidad se refiere a grabaciones nativas. Validar histórico legacy en su catálogo académico |
| Límites operativos deliberados | Discovery/rename seleccionan lotes de 25; paginación Google acotada y leases protegen la cola. Grandes cargas pueden tardar; este RC no certifica carga masiva ni cuotas institucionales |
| Matriz validada acotada | Moodle 4.5/5.0/5.1, PHP 8.3 y MariaDB 10.11. Otros motores, versiones y temas necesitan validación propia; RC no es aún 1.0 estable |

Fuentes oficiales para las condiciones externas: [scopes Drive](https://developers.google.com/workspace/drive/api/guides/api-specific-auth)
y [retención de conferenceRecords](https://developers.google.com/workspace/meet/api/reference/rest/v2/conferenceRecords).
Estas condiciones pueden cambiar; revisarlas antes de una instalación. El comportamiento local auditado se detalla en [Fase 7](PHASE7.md).
