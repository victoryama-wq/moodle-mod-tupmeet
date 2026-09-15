# Fase 1 — cuenta maestra institucional y OAuth2

## Alcance

Versión: `0.2.0-alpha` (`2026091500`). Base revisada: `codex/fase-0-estructura-plugin`, commit `68e6bf201946f5fc297d321729c1fa70a785ecd7`. Rama de trabajo: `codex/fase-1-cuenta-maestra-oauth2`.

Se revisaron todos los archivos versionados de la base, incluyendo los cinco documentos obligatorios, callbacks, formulario, páginas, XMLDB, capacidades, eventos, privacidad, idiomas e icono.

Hallazgos de Fase 0:

- Estructura coherente con un módulo de actividad: componente, versión mínima, tabla de instancias, formulario, callbacks, capacidades, evento de vista, idiomas e icono.
- `tupmeet_add_instance()` asignaba siempre cero: ahora asigna la predeterminada verificada.
- `tupmeet_update_instance()` permitía persistir un `accountid` recibido: ahora lo ignora.
- El proveedor de privacidad nulo y los textos de Fase 0 necesitaban actualizarse para los nuevos metadatos institucionales.
- XMLDB corregía automáticamente tres `CHAR NOT NULL DEFAULT=""` heredados. Se retiraron esos defaults del XML para reflejar el esquema realmente instalado; no se cambió la estructura efectiva de esos campos existentes.
- Se completaron cabeceras GPL, PHPDoc y formato de los archivos heredados para pasar el estándar Moodle completo, sin cambiar sus reglas de calendario, capacidades ni eventos.
- La validación de recurrencia usa `strtotime('today', ...)`, dependiente de la zona del servidor. Pendiente de la fase de programación; no se modificó su semántica.
- No hay backup/restore del módulo y `FEATURE_BACKUP_MOODLE2` devuelve `false`. Sigue siendo una limitación de la base.

## Flujo exacto de administración

1. En un Moodle de desarrollo/staging, instalar/actualizar el plugin y completar **Administración del sitio > Notificaciones**.
2. Preparar un issuer Google en **Administración del sitio > Servidor > Servicios OAuth 2**, usando la plantilla Google. Debe estar habilitado, configurado para servicios internos o para ambos usos y disponer de sus endpoints OpenID descubiertos.
3. Abrir **Administración del sitio > Plugins > Módulos de actividad > TUP Meet**.
4. En **Registrar cuenta**, indicar un nombre descriptivo y seleccionar el issuer. No se introducen credenciales ni un correo supuesto en TUP Meet.
5. Pulsar **Conectar / reconectar en Moodle**. Se abre la confirmación nativa del issuer; continuar, seleccionar la cuenta institucional de Google Workspace y conceder acceso. El callback y los tokens los gestiona Moodle.
6. Moodle termina en su listado de servicios OAuth2. Volver mediante el menú a **TUP Meet**; no hay retorno automático al plugin.
7. Pulsar **Verificar identidad Google**. El plugin autentica la cuenta de sistema y consulta userinfo. Debe mostrar el correo institucional realmente autorizado y la fecha de verificación.
8. Revisar ese correo. Solo entonces pulsar **Establecer como predeterminada**. Incluso la primera cuenta requiere esta selección explícita.
9. Para cambiar de cuatrimestre/cuenta, configurar otro issuer, registrar otra cuenta, conectar, verificar y establecerla como predeterminada. Las actividades previas conservan su `accountid`.
10. Opcionalmente deshabilitar la cuenta anterior para nuevas actividades. Conserva su identidad y autorización en Moodle; no borrar ni reutilizar su issuer.
11. Si expira/revoca la autorización, reconectar el mismo issuer con la **misma identidad Google**, volver a TUP Meet y verificar. Si se autoriza otra identidad o cambia el correo histórico, se rechaza su uso y se conserva el historial. Reconectar la original para recuperarlo.

Si se deshabilita la predeterminada, queda una pausa explícita: no se crean nuevas actividades hasta elegir otra habilitada y verificada. Las existentes se pueden seguir consultando/editando sin cambiar de propietario.

## Configuración manual Moodle / Google Cloud

- Crear o seleccionar un proyecto Google Cloud y configurar consentimiento y audiencia adecuados a la institución. Usar una cuenta Google Workspace autorizada por sus políticas.
- Crear credenciales OAuth para **Aplicación web**. Registrar exactamente la URL pública de Moodle seguida de `/admin/oauth2callback.php`. En Moodle 5.1, `public` es una ubicación de archivos, no un sufijo que deba añadirse automáticamente a la URL.
- Introducir client ID y client secret únicamente en el issuer nativo de Moodle. No enviarlos al repositorio, fixtures, documentación ni capturas.
- Usar scopes OpenID `openid profile email` para esta fase, y acceso offline con consentimiento según la plantilla Google. El endpoint userinfo debe ser OpenID, normalmente `https://openidconnect.googleapis.com/v1/userinfo`.
- Configurar **Dominios permitidos** del issuer si se quiere restringirlo a los dominios de la institución. No hay dominio ni cuenta institucional fija en el código.
- Mantener un issuer separado por cuenta; preferir issuers dedicados a TUP Meet para no reemplazar cuentas de sistema de repositorios u otros servicios. Moodle puede agregar scopes de otros plugins que compartan un issuer; TUP Meet no agrega scopes de Calendar, Meet o Drive.
- Mantener el cron de Moodle para su ciclo normal de renovación OAuth2 y revisar las políticas de autorización de Google Workspace. El plugin no introduce un cron propio.
- No se requiere habilitar Calendar, Meet ni Drive para implementar las funciones de esta fase.

Referencias primarias consultadas: [OAuth2 de Moodle 4.5](https://github.com/moodle/moodle/blob/MOODLE_405_STABLE/lib/classes/oauth2/api.php), [configuración Google en Moodle](https://docs.moodle.org/405/en/OAuth_2_Google_service), [identidad OpenID de Google](https://developers.google.com/identity/openid-connect/openid-connect).

## Persistencia y garantías

- `tupmeet_accounts.googlesub`: identificador estable Google, char(255) nullable, `NULL` hasta verificar.
- `tupmeet_accounts.timeverified`: fecha de última verificación correcta, entero, cero inicialmente.
- Upgrade XMLDB desde Fase 0; instalación nueva sincronizada.
- El upgrade exige verificar/seleccionar nuevamente las cuentas previamente existentes: limpia `isdefault` y establece `connectionstatus = pending`, sin borrar cuentas ni modificar `accountid` de actividades.
- Las actividades Fase 0 con `accountid = 0` siguen sin propietario asignado. No hay reasignación implícita ni migración en esta fase.
- Un único valor predeterminado por las operaciones del servicio, mediante lock Moodle y transacción. No depende de un índice único sobre un booleano, que también impediría múltiples filas no predeterminadas.
- Cualquier identidad ya verificada se conserva. Solo el método interno `delete_unused()` permite retirar registros deshabilitados, nunca verificados y sin referencias; no existe botón de eliminación.
- Tokens, secretos y client ID pertenecen al OAuth2 nativo de Moodle. Las tablas del plugin solo contienen metadatos.

## Validación reproducible

Las pruebas de `tests/` utilizan la base de datos, persistentes OAuth2, locks y transacciones de Moodle. Se simula únicamente la respuesta de Google/cliente remoto; las credenciales no funcionales de los issuers de prueba se generan en memoria para cada ejecución.

Desde un entorno Moodle de pruebas independiente, con el plugin en `mod/tupmeet` (o `public/mod/tupmeet` en 5.1):

```sh
composer install
php admin/tool/phpunit/cli/init.php --disable-composer
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite
```

En 5.1, el inicializador está en `public/admin/tool/phpunit/cli/init.php`; PHPUnit y Composer se ejecutan desde la raíz del checkout. Configurar una base/dataroot de pruebas dedicada antes de inicializar: el inicializador administra exclusivamente ese entorno desechable.

Cobertura prevista: primera predeterminada explícita, cuenta sin verificar, cambio/idempotencia de predeterminada, historial y edición de actividades, deshabilitación, borrado inseguro, issuer repetido/inválido/deshabilitado/no Google/incompleto, ausencia de cuenta de sistema, identidad inválida/no verificada/fuera de dominio, sustitución de identidad, errores OAuth sin mutaciones y upgrade con historial.

Resultados locales del 15 de septiembre de 2026, Windows, PHP 8.3.33 y MariaDB 10.11.14:

| Moodle | Revisión core | PHPUnit | Resultado |
| --- | --- | --- | --- |
| 4.5.14 | `ee32d48eb7b7c2dc32ea7312b6dd5c780485dd2e` | 9.6.34 | 24 pruebas, 63 aserciones, OK |
| 5.0.10 | `662f6cc8e1c19f420bebc855362e5db2dae0784f` | 11.5.55 | 24 pruebas, 63 aserciones, OK |
| 5.1.7 | `fc6ed2622475d3aefd7ac8cf62adbe7dc3eda4a2` | 11.5.55 | 24 pruebas, 63 aserciones, OK |

- Sintaxis PHP: 21 archivos, sin errores.
- Estándar oficial `moodlehq/moodle-cs` 3.7.0 / PHP_CodeSniffer 3.13.6: todos los PHP del repositorio, sin errores ni advertencias.
- En los cuatro archivos heredados que solo recibieron formato/PHPDoc (`index.php`, `mod_form.php`, evento de vista y `db/access.php`), se compararon los tokens PHP ejecutables con la base: idénticos, ignorando comentarios y espacios.
- XMLDB: instalación nueva mediante tablas desechables y upgrade desde la estructura Fase 0, con conservación de identidad histórica y cuentas sin asignar.
- Se corrigió la preparación inicial del test de upgrade para cargar `upgradelib.php`; después pasó la suite completa en las tres versiones.
- Los entornos de pruebas se crearon fuera del repositorio del plugin. Ningún archivo versionado de Moodle core fue modificado. En 4.5/5.1 se ajustó únicamente el campo nuevo en las bases desechables durante la revisión del esquema; las pruebas finales verifican tanto el XML final como el upgrade. Moodle 5.0 instaló el esquema final directamente.
- La preparación de Moodle produjo avisos OpenSSL del módulo core LTI en este Windows; se configuró `OPENSSL_CONF` para la ejecución final. TUP Meet no depende de LTI y sus pruebas finales no emitieron avisos.
- Revisión de secretos y alcance: ningún valor de credenciales institucionales ni token se incorporó al repositorio; ningún cliente Calendar/Meet/Drive se añadió.

Estas pruebas no equivalen a una autorización real de Google ni a una aceptación visual del administrador en staging. No se realizó deploy, push, merge ni publicación de release.

## Aceptación manual pendiente

- Completar la autorización real de Google en staging y comprobar el correo mostrado antes de elegir la primera predeterminada.
- Crear una actividad con A, seleccionar B y crear otra; comprobar que las dos conservan A/B respectivamente al editar.
- Revocar acceso en Google, verificar el error, reconectar A y verificar nuevamente.
- Reconectar deliberadamente un issuer con una cuenta distinta en staging: confirmar rechazo de identidad y conservación del historial; restaurar la cuenta original.
- Verificar navegación, acceso denegado para usuarios no administradores, POST/sesskey y textos ES/EN en la instalación de destino.
- Revisar la política institucional de retención de cuentas históricas. Renombrar el correo de una identidad histórica requiere un proceso explícito futuro; no se acepta silenciosamente.

**No se crean eventos Calendar, reuniones/enlaces Meet, grabaciones, transcripciones ni sincronizaciones. No se integra Drive ni se migra `mod_googlemeet`. La Fase 2 requiere revisión y aprobación explícita.**
