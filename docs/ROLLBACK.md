# Contención y rollback

Moodle **no soporta downgrade automático de la base de datos de un plugin**. Incluso sin cambios XMLDB
en 0.9.0-rc1, la versión instalada queda en `2026092201`; copiar 0.8.3 sobre ella provoca detección de downgrade.
No editar manualmente `config_plugins.version`, no ejecutar SQL inverso y no mezclar snapshots de fechas diferentes.

## Respaldo necesario

Antes de actualizar, conservar un snapshot coherente de DB Moodle, `moodledata`, código completo, `config.php`
y todos los plugins. Documentar punto de restauración, duración, responsable y prueba de recuperación.
El respaldo de directorio TUP Meet por sí solo no revierte metadata, datos, otras actividades ni recursos Google.

## Decisión según el momento

| Situación | Acción segura |
|---|---|
| Se copiaron archivos, pero Moodle aún no ejecutó upgrade y no hubo actividad | Reponer el directorio anterior completo desde respaldo y purgar cachés; comprobar que la versión DB sigue siendo la previa |
| Upgrade ejecutado; todavía no se creó actividad productiva | Preferir hotfix con versión superior, o restauración completa y coherente del snapshot previo bajo mantenimiento |
| Ya se crearon Spaces/eventos/grabaciones o se importó/publicó historial | Contener primero; evaluar pérdida de operaciones Moodle posteriores al snapshot y reconciliación manual de recursos externos antes de restaurar |
| Solo se desea revertir código después del upgrade | No instalar una versión menor sobre la DB actual; preparar un hotfix compatible, probado y versionado hacia delante |

Una restauración completa de Moodle **no revierte Google**: Spaces, eventos, miembros, archivos y nombres ya
modificados pueden seguir existiendo. Perder la asociación local no autoriza recrearlos ni eliminarlos.
Conservar evidencia administrativa protegida para evitar duplicados; nunca publicarla en el repositorio.

## Contención del piloto

1. Detener creación/edición de nuevas actividades TUP Meet y comunicar la pausa a los responsables.
2. Mantener `mod_googlemeet` disponible como alternativa institucional existente; no modificarlo ni migrar recursos automáticamente.
3. Ocultar o poner fuera de uso las actividades piloto afectadas si hace falta. Ocultar Moodle no revoca enlaces Drive/Meet ya compartidos.
4. Capturar versión, hora/zona, estados Health y tareas. Si se requiere detener todas las llamadas externas,
   el operador debe pausar tanto cron/workers como guardados/reintentos de actividades: deshabilitar solo el dispatcher
   no detiene las tareas ad hoc ya encoladas ni los observadores inmediatos.
5. Respaldar el estado actual completo antes de cualquier recuperación, además del snapshot previo.
6. Decidir con el responsable entre **hotfix probado** y **restore completo del snapshot preinstalación**.
   Estimar y aprobar la pérdida de cambios de todo Moodle desde ese snapshot, no solo TUP Meet.
7. Para restore: mantenimiento, workers detenidos, restaurar el conjunto coherente, permisos, cachés,
   comprobar versiones y asociaciones. Revisar recursos Google supervivientes con el administrador sin crear nuevos por impulso.
8. Reanudar solo tras validar integridad, OAuth de las identidades históricas, roles y cola; dejar constancia del incidente.

No borrar Spaces, eventos Calendar, miembros, MP4 ni permisos automáticamente. No existe auto-repair global
ni restauración nativa de una actividad TUP Meet desde backup de curso. Véanse [limitaciones](KNOWN_LIMITATIONS.md).
