# Instalación y actualización — 0.9.0-rc1

Esta guía prepara una instalación autorizada; la Fase 7 no despliega ni inicia el piloto.
RC no significa versión estable 1.0. Consulte primero [limitaciones](KNOWN_LIMITATIONS.md),
[rollback](ROLLBACK.md) y [criterios del piloto](PILOT.md).

## Requisitos y responsables

- Moodle 4.5 LTS, 5.0 o 5.1; PHP 8.3 en la matriz probada. No se declara validación de otras versiones.
- HTTPS, reloj correcto, zona horaria institucional explícita (por ejemplo `America/Cancun`),
  base de datos soportada por Moodle y cron CLI del sitio cada minuto.
- Administrador con `moodle/site:config`, operador de infraestructura y administrador Google Workspace.
- Cuenta institucional Workspace con licencia y políticas que permitan COHOST, grabación automática
  y, si se usa, transcripción automática. Los permisos OAuth no sustituyen una licencia.
- Calendar API, Google Meet REST API y Google Drive API habilitadas en el proyecto OAuth.
- Acceso del servidor a los servicios OAuth y APIs; no incluir credenciales en el directorio del plugin.

## Secuencia de instalación o actualización

1. Registrar versión Moodle/PHP/plugin, SHA del paquete aprobado, responsable y ventana de mantenimiento.
   Revisar el resultado de CI del **mismo commit** y validar el checksum del paquete cuando se autorice generarlo.
2. Respaldar juntos DB Moodle, `moodledata`, código completo, `config.php` y plugins. Verificar que se pueden
   restaurar y proteger el respaldo: puede contener secretos administrados por Moodle y datos personales.
3. Detener altas y ediciones de actividades durante la ventana. Activar mantenimiento y coordinar la pausa
   de cron/workers para que no haya procesos antiguos escribiendo mientras se sustituye código o actualiza DB.
4. Respaldar y retirar el directorio anterior del árbol servido por web. Copiar un directorio **limpio**
   `tupmeet/` a `mod/tupmeet/` (Moodle 4.5/5.0) o `public/mod/tupmeet/` (layout Moodle 5.1).
   No sobreponer versiones indiscriminadamente: podrían sobrevivir páginas PoC eliminadas.
   No tocar `mod_googlemeet`, Moodle core ni `config.php`.
5. Confirmar una sola carpeta `tupmeet`, con `version.php` directamente dentro; no `tupmeet/tupmeet`.
   Comprobar versión `2026092201`, release `0.9.0-rc1`, madurez `MATURITY_RC` y ausencia de PoC.
6. Aplicar propietario/permisos conforme al despliegue de Moodle: código legible por PHP y no escribible
   por usuarios web. Mantener `moodledata` fuera del árbol público; no usar permisos universales de escritura.
7. Entrar como administrador en **Administración del sitio → Notificaciones** y ejecutar la actualización.
   Alternativa del operador: `php admin/cli/upgrade.php --non-interactive` desde el directorio web Moodle
   (en 5.1, `public/`). Este comando actualiza componentes pendientes del sitio: verificar el inventario antes.
8. Confirmar `mod_tupmeet = 2026092201`. Desde 0.8.3 no hay nuevo paso XMLDB ni actualización de filas:
   Moodle actualiza su metadata de versión. Ante errores, detenerse y aplicar [rollback](ROLLBACK.md).
9. Purgar cachés desde **Administración del sitio → Desarrollo → Purgar todas las cachés**,
   o `php admin/cli/purge_caches.php` desde el mismo directorio web.
10. Configurar o revisar OAuth según la siguiente sección. En una actualización conservar los registros
    históricos de cuentas/issuers: no reemplazarlos por otra identidad.
11. Reactivar cron/workers con la versión nueva y retirar mantenimiento cuando las comprobaciones locales sean correctas.
    El programador del sistema ejecuta `php admin/cli/cron.php` cada minuto como el usuario del servicio.
    Revisar también **Servidor → Tareas → Tareas programadas / Tareas ad hoc / Registros de tareas**.
12. Abrir **Plugins → Módulos de actividad → TUP Meet → Estado del sistema**.
    Confirmar versión, cuenta predeterminada única, `discover_recordings` habilitada y ejecución reciente.
    El panel es local: no certifica OAuth activo ni conectividad Google en vivo.
13. Solo bajo autorización de smoke: crear una actividad controlada, confirmar Space/Calendar/COHOST/artifacts,
    invitación docente, mismo enlace Meet, sesión/recurrencia/fecha final y grabación de prueba.
    Verificar `FILE_GENERATED`, nombre del archivo y publicación. No duplicar una actividad incierta para probar suerte.
14. Comprobar con cuentas autorizadas de docente y estudiante: entrada a Meet, tabla académica, abrir grabación,
    ojo mostrar/ocultar, ausencia de panel técnico y páginas administrativas para estudiantes.
    Abrir un enlace requiere además permisos Drive ya definidos por la institución.
15. Revisar cola y Health después de cron, registrar evidencia sin secretos y decidir si se autoriza el piloto.
    Una instalación exitosa no sustituye este smoke ni inicia automáticamente el piloto.

## Google Cloud y OAuth nativo de Moodle

1. En Google Cloud, habilitar las tres APIs indicadas y configurar consentimiento, audiencia institucional
   y políticas de acceso aprobadas por Workspace. Revisar requisitos aplicables al scope restringido de Drive.
2. Crear/configurar el cliente OAuth web. La URI de redirección debe coincidir exactamente con
   `https://<host-moodle>/<ruta-si-existe>/admin/oauth2callback.php`, usando el `wwwroot` productivo real.
   No añadir `/public` salvo que sea parte real de la URL pública. La ruta de disco de 5.1 no cambia por sí sola la URL.
   Consulte el [servicio Google OAuth2 de Moodle](https://docs.moodle.org/39/en/OAuth_2_Google_service).
3. En **Administración del sitio → Servidor → Servicios OAuth 2**, crear un servicio **Google** habilitado,
   disponible para servicios internos (no solo login). Introducir Client ID/Secret únicamente en la configuración
   protegida de Moodle. Revisar endpoints nativos e identidad; restringir dominios si corresponde a la política institucional.
4. Abrir **Plugins → Módulos de actividad → TUP Meet → Cuentas maestras**.
   Registrar nombre descriptivo y seleccionar el issuer Google. Un issuer se utiliza por un solo registro de cuenta.
5. Desde **Conectar/reconectar**, completar la conexión de **cuenta de sistema** administrada por Moodle
   con la cuenta institucional prevista. Registrar la cuenta antes de autorizar permite que Moodle solicite los scopes del plugin.
6. Volver a Cuentas maestras y pulsar **Verificar identidad Google**; comprobar el correo mostrado.
   La identidad requiere correo verificado y cuenta Workspace; `sub` y correo quedan vinculados al registro.
7. Establecerla como predeterminada. Solo una habilitada puede serlo; la verificación sola no la convierte en predeterminada.
8. Para cambiar de propietario de **futuras** actividades, crear otro issuer/conexión y registro de cuenta,
   verificarlo y cambiar la predeterminada. Deshabilitar la anterior solo para nuevas reuniones conserva su historia.
   Mantener su autorización mientras sus actividades necesiten sincronizarse. No reconectar un issuer histórico con otra persona.

### Scopes actuales: no añadir otros para este RC

| Scope | Uso del plugin |
|---|---|
| `https://www.googleapis.com/auth/calendar.events.owned` | Evento/serie del propietario histórico e invitación docente |
| `https://www.googleapis.com/auth/meetings.space.settings` | Configuración de grabación/transcripción automática |
| `https://www.googleapis.com/auth/meetings.space.created` | Creación del Space y COHOST del modelo Meet-first |
| `https://www.googleapis.com/auth/meetings.space.readonly` | Lectura de recursos Meet y metadatos de conferencias/grabaciones |
| `https://www.googleapis.com/auth/drive.metadata` | Leer metadata y renombrar únicamente `name` del MP4; nunca descargar contenido |

Se suman los scopes de identidad administrados por Moodle para el servicio Google. El callback del plugin
solo agrega estos cinco scopes a issuers Google registrados en TUP Meet, incluidos los históricos.
Tras cambiar consentimiento/scopes, reconectar la cuenta de sistema y verificar la misma identidad.
No habilitar scopes de permisos, contenido completo de Drive o delegación de dominio como solución improvisada.

## Checklist de aceptación antes del piloto

- [ ] Respaldos completos y procedimiento de restauración comprobado.
- [ ] Código limpio sin PoC; versión RC y actualización sin warnings ni reparación XMLDB.
- [ ] APIs, licencia, políticas, callback y cuenta de sistema revisados; identidad/predeterminada correctas.
- [ ] Cron cada minuto y `discover_recordings` cada cinco minutos; cola con avance observado.
- [ ] Smoke del RC y acceso por roles aprobados por el responsable.
- [ ] [Runbook](ADMIN_RUNBOOK.md), [guía docente](TEACHER_GUIDE.md) y [plan de piloto](PILOT.md) entregados.

Estos pasos son instrucciones para una operación futura autorizada; no constituyen evidencia de ejecución.
