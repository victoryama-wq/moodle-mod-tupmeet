# Fase 3.2.1 — diagnóstico de miembros en Spaces de Calendar

## Base, evidencia y alcance

- Base: `codex/fase-3-2-cohost`, `8d709ecc1796ff12c02df5a82ef82dcfc5311cfd`.
- Rama local: `codex/fase-3-2-1-calendar-space-members`.
- Versión: **2026091702 / 0.4.2-alpha**, `MATURITY_ALPHA`.
- Revisión documental: 18/09/2026. No se ejecutó Google real durante este desarrollo.
- Smoke reportado por el responsable: Calendar/Meet ready, grabación automática ready, docente Moodle
  seleccionado correctamente, cohoststatus error, Space originado mediante Google Calendar.
- Ese error histórico no permite identificar el endpoint ni concluir una causa: el código anterior
  descartaba etapa y HTTP status al convertir los fallos en `cohostfailed`.
- Trabajo exclusivamente local. Sin push, merge, deploy, release ni inicio de Fase 4.

## Scopes y límites documentados

Scopes adicionales finales, exclusivamente para issuers Google registrados en TUP Meet, también históricos:

```text
https://www.googleapis.com/auth/calendar.events.owned
https://www.googleapis.com/auth/meetings.space.settings
https://www.googleapis.com/auth/meetings.space.created
https://www.googleapis.com/auth/meetings.space.readonly
```

El callback nativo mantiene los scopes de identidad de Moodle y no modifica issuers ajenos al plugin.
No se almacenan tokens ni credenciales propias. El nuevo scope no habilita recuperación de artefactos en el código.

Referencias oficiales revisadas:

- [members.list](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces.members/list):
  acepta created o readonly.
- [Meeting spaces overview](https://developers.google.com/workspace/meet/api/guides/meeting-spaces-overview):
  la tabla de casos de uso considera created + readonly para consultar otras opciones previas de Spaces
  creados por aplicaciones externas, como Google Calendar.
- [members.create](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces.members/create):
  documenta created para la escritura. Readonly no prueba autorización de creación.

**El siguiente smoke determinará si Google permite members.create en el Space originado en Calendar.**
No se presupone éxito ni rechazo. Un LIST exitoso seguido de CREATE 403 debe conservar la reunión y
dejar cohoststatus=error, etapa=create, HTTP=403. No existe workaround automático ni cambio de arquitectura.

## Diagnóstico mínimo y persistente

La excepción interna `local/google/cohost_exception` transporta solamente dos valores normalizados.
El mensaje sigue siendo fijo/localizable; no encadena la excepción original. `cohost_manager` persiste:

| Campo nuevo en tupmeet | XMLDB / default | Significado |
| --- | --- | --- |
| cohosterrorstage | char(12), NOT NULL, unknown | identity, space, list, create, patch o unknown |
| cohosthttpstatus | int(3), NOT NULL, 0 | Código HTTP normalizado; 0 significa no disponible |

Se aceptan enteros HTTP 100–599 o su representación exacta de tres dígitos; otros tipos, texto añadido,
valores fuera de rango y números parciales se convierten en 0. No se infiere un estado HTTP a partir del
código o texto de una excepción. Un timeout no hereda el estado del GET previo. Un cuerpo inválido con
HTTP exitoso puede conservar, por ejemplo, 200: el HTTP no confirma por sí solo una membresía válida.

- identity: validación del docente o adquisición/verificación de la autorización del propietario.
- space: URI/Space inválido o fallo al resolver el Space mediante el servicio existente de Fase 3.
- list/create/patch: operación de miembros que falla, incluida la validación de su respuesta.
- unknown: error no clasificable sin interpretar datos sensibles.

El servicio de resolución de Fase 3 permanece intacto y no expone HTTP status; sus fallos se registran
como space/0. Los fallos de validación fuera del transporte también pueden tener HTTP=0. Después de
POST 409 se conserva la reconciliación existente: relistar y exigir COHOST. Un fallo de ese relist se
identifica como list; no se reutiliza 409 como si fuera su respuesta.

Nunca se persisten ni se presentan cuerpos, headers, tokens, URLs, excepciones completas o textos Google.
La vista solo muestra etiquetas traducidas y el entero permitido, bajo `moodle/course:manageactivities`.
Los estudiantes conservan el botón de acceso y no reciben el diagnóstico. La presentación vuelve a
normalizar los campos por defensa ante valores históricos/manipulados; un error anterior aparece como unknown/0.

El diagnóstico se limpia al guardar/reintentar, al confirmar ready y al borrar los datos personales aprobados.
La API de privacidad declara/exporta ambos campos y los limpia sin retirar la marca `cohostlocked`.
Se elige persistencia porque el intento puede ejecutarse en cron y el administrador consultarlo más tarde;
un mensaje temporal no serviría para diagnosticar ese caso.

## Upgrade y comportamiento preservado

`install.xml`, `upgrade.php`, savepoint y `version.php` usan **2026091702**. El upgrade añade los dos campos
con unknown/0, sin cambiar estados anteriores, docentes, intentos, propietarios, eventos o Spaces, sin HTTP
y sin encolar tareas. No añade tablas ni intenta reconstruir la causa de fallos históricos.

Se conserva exactamente el flujo: LIST → COHOST existente: ready; otro rol: PATCH role; ausente: POST COHOST;
POST 409: relistar y converger. No se llama a spaces.create ni members.delete.

Calendar, artifactConfig, grabación/transcripción, accountid, meeturi y la lógica existente de meetspacename
permanecen intactos. Los errores cohost no invalidan sus estados ready. MAX_ATTEMPTS sigue siendo 5 por
revisión. La primera selección permanece bloqueada aunque falle o se agote el presupuesto; nunca se sustituye
el docente mediante reconexión o retry.

## Próximo smoke autorizado en staging

Este procedimiento está pendiente; no es evidencia de éxito ni autorización para desplegar ahora.

1. Instalar la nueva versión en staging y completar Notificaciones/upgrade cuando se autorice.
2. Mantener Calendar API y Meet REST API habilitadas y revisar los scopes permitidos en Google Cloud/Workspace.
3. Desde administración TUP Meet, abrir la reconexión de la cuenta de sistema del issuer histórico correspondiente.
   Consentir los cuatro scopes adicionales y verificar la misma identidad institucional original.
4. Abrir la actividad fallida como gestor; comprobar mismo docente bloqueado, enlace y estados Calendar/artefactos.
5. Usar **Reintentar configuración del coorganizador** (target=cohost). No editar la selección ni reintentar Calendar.
6. Comprobar ready o anotar exclusivamente la etapa permitida y el entero HTTP, sin datos sensibles.
7. Si LIST funciona pero CREATE devuelve 403, registrar create/403 y cohoststatus=error. Conservar evento,
   Space, Meet URI, accountid y artifactConfig; detener el smoke y someter el resultado a revisión, sin workaround.
8. Si llega a ready, verificar en Google el rol del mismo docente y después repetir la comprobación de
   las funciones automáticas. Ready no prueba que se hayan generado grabaciones.
9. Confirmar que el alumno puede abrir Meet y no ve etapas, estado HTTP ni reintentos administrativos.

## Validación local

Se conservan las 143 pruebas de la base y se añaden pruebas de scopes aislados, diagnóstico LIST/CREATE/PATCH,
identidad/Space/unknown, sanitización HTTP, timeout sin estado obsoleto, relist tras 409, conservación de todos
los campos ajenos al diagnóstico, retry del mismo docente bloqueado, privacidad, UI por capacidades y upgrade.
La frontera HTTP simulada verifica que ninguna escritura cree Spaces ni elimine miembros.

Resultados locales del 18/09/2026, PHP 8.3.33 y MariaDB 10.11.14, Windows, bases PHPUnit desechables:

| Moodle | PHPUnit | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- | --- |
| 4.5.14 | 9.6.34 | 159 | 2069 | PASS |
| 5.0.10 | 11.5.55 | 159 | 2070 | PASS |
| 5.1.7 | 11.5.55 | 159 | 2070 | PASS |

Comando desde cada raíz Moodle, después de inicializar su entorno PHPUnit con el esquema nuevo:

```sh
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite --fail-on-warning --fail-on-risky --fail-on-incomplete --fail-on-skipped
```

Se añadieron 16 casos y se conservaron todos los métodos de prueba originales. Instalación nueva y upgrades
están incluidos en la suite, incluida la preservación campo por campo desde 0.4.1-alpha.
PHPCS Moodle: cero errores/advertencias. Sintaxis: 48 archivos PHP correctos. Estructura del módulo validada
con el motor upstream Moodle Plugin CI para 4.5; cinco bloques/savepoints ordenados y coincidentes.
`git diff --check`: PASS. Revisión de firmas de secretos: sin coincidencias reales.
Workflow CI, Calendar y servicios de configuración de artefactos: sin diferencias respecto de la base.

La validación remota de esta revisión y el nuevo smoke Google continúan pendientes de autorización.
