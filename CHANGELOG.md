# Changelog

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
Evidencia y límites: [Fase 7](docs/PHASE7.md). RC pendiente de validación remota propia y smoke/piloto autorizados.
