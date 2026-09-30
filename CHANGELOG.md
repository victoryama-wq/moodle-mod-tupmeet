# Changelog

## 1.0.0 — 2026-09-29

Primera versión estable de TUP Meet, promoción de **0.9.0-rc2** sin cambios funcionales.
Metadata: `2026092900`, `MATURITY_STABLE`; requisito Moodle `2024100700` conservado.
Sin nuevo paso de upgrade, cambios de esquema/datos, scopes ni workflow CI.

### Validación y cierre

- CI de RC2 aprobado: [TUP Meet CI #35802454671](https://github.com/victoryama-wq/moodle-mod-tupmeet/actions/runs/35802454671),
  QUALITY PASS y Moodle 4.5/5.0/5.1: 494 pruebas; 8065/8066/8066 aserciones, respectivamente.
  Esta evidencia corresponde a RC2, no a un CI remoto nuevo de 1.0.0.
- Smoke productivo de RC2 aprobado por el propietario, incluyendo instalación/upgrade,
  Activity Chooser, favoritos/Destacados e información del módulo.
- Piloto institucional de **3 asignaturas reales** completado correctamente, sin incidencias
  funcionales bloqueantes detectadas. [Cierre del piloto](docs/PILOT.md): alcance probado y evidencia declarada.
- Habilitado para despliegue progresivo; esta promoción no ejecuta despliegue ni publica release GitHub.

### Funcionalidad estable conservada

- OAuth2 nativo Moodle y cuenta maestra institucional configurable con cuentas históricas.
- Meet-first, Calendar nativo, recurrencia, docente COHOST y artifactConfig en el mismo Space.
- Grabación automática, discovery de conferenceRecords/recordings y metadata `FILE_GENERATED`.
- Drive rename de grabaciones nativas, publicación automática/manual y visibilidad local.
- Health, tareas/cron, Activity Chooser con favoritos e información en los tres idiomas.
- Migración histórica mediante el CSV existente: preview, confirmación e idempotencia;
  referencias locales sin mover, renombrar, descargar ni cambiar permisos de los archivos históricos.

La migración y el retiro posterior de `mod_googlemeet` son procedimientos operativos separados;
no se desarrolla un migrador automático ni se modifica ese plugin.
Las [limitaciones conocidas](docs/KNOWN_LIMITATIONS.md) se conservan.

## 0.9.0-rc1 — 2026-09-22

Release Candidate para preparar un piloto autorizado. Feature freeze respecto de 0.8.3-alpha:
metadata de release/madurez, auditoría, pruebas y documentación; sin nuevas funciones, scopes ni cambios DB.

### Funcionalidad consolidada

- **OAuth/cuentas:** APIs nativas Moodle, cuenta institucional configurable, identidad verificada,
  predeterminada única e historial inmutable por actividad; tokens fuera de tablas del plugin.
- **Meet-first:** un Space permanente por actividad/serie, protección conservadora de resultados inciertos,
  reintento seguro ante 429 y enlace disponible aunque falle Calendar.
- **Calendar/recurrencia:** evento nativo con el mismo Meet, invitación docente, edición sin reemplazar
  identidades y fecha final semanal inclusiva con hora local y conversión UTC explícita.
- **COHOST/artifacts:** docente validado y bloqueado, estados independientes; grabación y preferencia
  de transcripción automática conservadas. No recuperación de transcripciones.
- **Grabaciones/Drive:** discovery de conferenceRecords/recordings, paginación y polling acotados,
  metadata `FILE_GENERATED`, rename idempotente del nombre; sin contenido, permisos ni movimientos Drive.
- **Publicación académica/UX:** automática o manual, ojo local, filtrado servidor, cronología nativa+legacy,
  próxima sesión y diagnóstico reservado a gestores.
- **Operación/hardening:** Health local, locks/revisiones/leases, reintentos controlados, errores saneados,
  PoC eliminada y guías de instalación, rollback, incidentes y piloto.
- **Histórico CSV:** preview de sesión, confirmación revalidada, matching exacto, deduplicación/idempotencia,
  tabla separada y atribución/privacidad; sin consultas ni cambios Google al importar.

### Preparación RC

`2026092201`, `MATURITY_RC`; esquema se mantiene en `2026092200`.
Matriz Moodle 4.5/5.0/5.1 con PHP 8.3. Se conserva la suite base de 482 casos y se amplía
la comprobación de instalación, actualización metadata-only y guardas de endpoints.
Evidencia y límites: [Fase 7](docs/PHASE7.md). Validación remota del RC aprobada:
[TUP Meet CI #35785884626](https://github.com/victoryama-wq/moodle-mod-tupmeet/actions/runs/35785884626) PASS,
QUALITY y 487 pruebas por Moodle (4.5: 8018 aserciones; 5.0/5.1: 8019 cada una).
El smoke en Moodle productivo y el piloto siguen pendientes; sin deploy, instalación en producción ni release publicado.
