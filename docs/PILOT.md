# Plan de piloto institucional

**Plan pendiente de autorización y ejecución.** El smoke staging hasta Fase 6B fue aprobado por el propietario;
no sustituye el smoke del RC ni constituye un piloto productivo. No se inició piloto en Fase 7.

## Alcance y preparación

Recomendar **3–5 asignaturas durante 1–2 semanas**, con docentes distintos y sesiones simples, recurrentes
y sabatinas. Incluir grabación automática y al menos una actividad con histórico CSV validado si procede.
Mantener `mod_googlemeet` disponible; evitar migración masiva durante el piloto.

Asignar un responsable académico, uno Moodle y uno Workspace; acordar horario de soporte y revisión diaria
de Health/tareas. Antes de abrir el piloto: commit/CI/paquete aprobados, [instalación](PRODUCTION_INSTALL.md),
respaldo/restore ensayado, OAuth/licencia/políticas revisados y smoke RC por roles aceptado.
Distribuir [guía docente](TEACHER_GUIDE.md) y [runbook](ADMIN_RUNBOOK.md).

## Evidencia de éxito por sesión

Registrar resultado, fecha y observación en un inventario institucional protegido (no en fixtures del repo):

1. Space creado una sola vez, listo y con identidad conservada.
2. Calendar sincronizado con el mismo Meet e invitación docente.
3. Docente COHOST confirmado y capaz de gestionar la sesión sin depender de la presencia del organizer.
4. Alumno autorizado entra al Meet desde Moodle según política institucional.
5. Grabación automática inicia con la licencia y política acordadas.
6. `FILE_GENERATED` se descubre después del procesamiento, sin duplicados.
7. Nombre de archivo correcto, o error de rename registrado como no bloqueante con enlace utilizable.
8. Publicación automática funciona para nuevas grabaciones de la actividad configurada así.
9. Estudiante autorizado abre la grabación; sin ampliación improvisada de permisos Drive.
10. Ojo mostrar/ocultar funciona en servidor y conserva la decisión tras nuevas sincronizaciones.
11. Cron procesa y la cola vuelve a su nivel habitual entre sesiones; no hay crecimiento sostenido anormal.
12. Health sin errores persistentes sin causa/responsable identificado.
13. Uso habitual sin intervención técnica recurrente; incidentes aislados quedan documentados y resueltos.
14. Histórico legacy, si aplica, aparece en la actividad correcta, con fecha/parte/visibilidad correctas y sin duplicados.

Evaluar en todas las asignaturas y repetir en al menos dos ocurrencias de una serie. Observar la tendencia
diaria y el tiempo real de procesamiento; no imponer un SLA Google no medido ni definir éxito como cero errores absolutos.
Para aprobar 1.0: criterios revisados por responsables, incidentes cerrados o riesgo aceptado explícitamente,
sin problemas de asociación/permisos y con evidencia repetida de operación normal.

## Pausar nuevas actividades si

- Space falla repetidamente o aparecen resultados inciertos sin resolver.
- OAuth institucional falla o no se puede verificar la identidad histórica.
- Cron no procesa, las grabaciones no se descubren o el backlog crece sostenidamente.
- Permisos afectan a estudiantes o se asocia cualquier grabación a una actividad equivocada.
- Se necesitan correcciones manuales repetidas para operar una clase normal.

Pausar altas, avisar a responsables, conservar recursos existentes y seguir [ROLLBACK](ROLLBACK.md).
No borrar Spaces, eventos o MP4; no convertir un incidente en recreaciones automáticas.

## Incidentes y cierre

Recopilar versión, ID local de actividad, subsistema/estado, HTTP numérico disponible, fecha/hora/zona,
captura Health saneada y estado cron/tareas. No recopilar ni compartir tokens, cuerpos OAuth, Client Secret
o refresh tokens. No adjuntar CSV real a Git. Detallar impacto, mitigación, tiempo de recuperación y responsable.

Al cierre, separar: evidencia automatizada, smoke RC y resultados del piloto. Solo después se puede proponer
1.0.0; merge, deploy y release necesitan sus autorizaciones correspondientes. Attendance, transcripciones
recuperadas, multi-COHOST, analytics y backup/restore permanecen fuera de este piloto.
