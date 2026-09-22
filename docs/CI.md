# Integración continua de TUP Meet

## Estado vigente

El workflow heredado se conserva sin cambios en Fase 7. La última base aprobada, Fase 6B
(`ce2408587bc6068adcf53d837dc09ef75e8589b4`), completó
[Actions 35767723395](https://github.com/victoryama-wq/moodle-mod-tupmeet/actions/runs/35767723395)
con QUALITY y PHPUNIT 4.5/5.0/5.1 exitosos: 482 pruebas por versión.
El RC tiene validación local propia; su CI remoto espera un push autorizado.
“Primer push pendiente” y 0.2.0-alpha más abajo corresponden exclusivamente a la incorporación original
de infraestructura; no describen el estado vigente. Véase [PHASE7](PHASE7.md).

## Alcance y estado inicial

El workflow [TUP Meet CI](../.github/workflows/ci.yml) valida `mod_tupmeet` con datos desechables y la frontera Google simulada de Fase 1. Esta incorporación cambia infraestructura y documentación; conserva la lógica, OAuth, esquema XMLDB y versión `2026091500` / `0.2.0-alpha`.

Base de esta incorporación: `codex/fase-1-cuenta-maestra-oauth2`, SHA `4ea481547e41aa6fcd72e477df7f2198c5b96573`. Esa revisión no tenía infraestructura GitHub Actions que conservar.

**El funcionamiento remoto queda pendiente del primer push autorizado.** Las validaciones locales del YAML y de las herramientas no equivalen a una ejecución de GitHub Actions. No se incluye badge hasta confirmar el workflow remoto.

## Cuándo se ejecuta

- `push` a ramas `codex/**`.
- `pull_request` con destino `main`; GitHub prueba su referencia de integración.
- `workflow_dispatch` manual. GitHub solo habilita este evento cuando el workflow existe en la rama predeterminada; el primer push a esta rama se verifica mediante el evento `push`.

`concurrency` cancela ejecuciones anteriores del mismo workflow, evento y rama. Push y PR conservan grupos separados para que un evento no cancele la validación del otro. No hay ejecución programada ni trigger `pull_request_target`.

## Arquitectura

| Job | Ejecuciones | Comprobaciones |
| --- | --- | --- |
| `QUALITY` | Una, timeout 15 minutos | Espacios/finales de línea, sintaxis PHP, estándar Moodle con PHPCS y coherencia de savepoints de upgrade. |
| `PHPUNIT` | Tres, timeout 35 minutos por entorno | Checkout limpio de Moodle, instalación del plugin, preparación de PHPUnit y ejecución exclusiva de `mod_tupmeet_testsuite`. Valida también la estructura del plugin una vez, en 4.5. |

Los jobs son independientes; `fail-fast: false` permite conocer el resultado de las tres versiones aunque una falle. Ninguna comprobación usa `continue-on-error`. QUALITY usa las herramientas directamente sobre `plugin/`, sin base de datos ni instalación de Moodle.

El chequeo de espacios compara el árbol versionado completo con un árbol vacío (`git diff --check`), para detectar también defectos preexistentes y funcionar con un checkout superficial sin depender de SHAs del evento.

### Matriz

| Moodle | Referencia upstream | PHP | Base de datos | Ubicación del plugin |
| --- | --- | --- | --- | --- |
| 4.5 | `MOODLE_405_STABLE` | 8.3 | MariaDB 10.11.14 | `moodle/mod/tupmeet` |
| 5.0 | `MOODLE_500_STABLE` | 8.3 | MariaDB 10.11.14 | `moodle/mod/tupmeet` |
| 5.1 | `MOODLE_501_STABLE` | 8.3 | MariaDB 10.11.14 | `moodle/public/mod/tupmeet` |

Se usa Ubuntu 24.04. Las ramas estables de Moodle se resuelven de nuevo en cada ejecución para comprobar sus parches vigentes; el log registra el SHA exacto de core. PHP queda fijado a la familia 8.3 y su parche se registra en el log. No se afirma reproducibilidad binaria entre ejecuciones: cambian los parches de PHP/Moodle y la imagen del runner. Para investigar un fallo histórico, conservar el SHA de core y las versiones registradas.

MariaDB se fija a `10.11.14` y al digest de su imagen oficial indicado en el YAML. Se conserva el parche usado en la validación local de Fase 1 para aislar la incorporación de CI; no se presenta como el parche más reciente. Su actualización dentro de 10.11 debe revisarse y pasar los tres entornos.

### Herramientas y versiones

- `moodlehq/moodle-plugin-ci` **4.5.11**, versión exacta, instalada con el lock distribuido por upstream.
- Composer **2.10.3**.
- `actions/checkout` **v6.0.2**, `actions/cache` **v5.0.3** y `shivammathur/setup-php` **2.37.2**, fijadas por SHA completo, con etiquetas anotadas en el YAML.
- `phplint`: PHP Parallel Lint; `phpcs`: estándar oficial Moodle, sin exclusiones y con `--max-warnings 0`. No se declara un análisis PHPCompatibility independiente: el ruleset Moodle CS 3.7.0 no lo activa.
- `savepoints`: verificador de Moodle local_ci; `validate`: comprobación de archivos, callbacks, cadenas, capacidades y tablas exigidos al módulo.
- El lock de Plugin CI 4.5.11 utilizado localmente resuelve Moodle CS 3.7.0, PHP_CodeSniffer 3.13.6, PHP Parallel Lint 1.4.0 y local_ci 1.1.4. PHPUnit se instala desde las dependencias de cada rama de Moodle, sin imponer una versión incompatible entre 4.5 y 5.x.

`--no-scripts` al crear Plugin CI evita instalar las dependencias npm de local_ci, que solo necesitan sus tareas JavaScript. Se conservan los plugins Composer necesarios para registrar el estándar PHPCS. El instalador de Moodle Plugin CI sí prepara las dependencias Composer/npm de Moodle y selecciona Node mediante su `.nvmrc`; `--no-plugin-node` omite únicamente dependencias Node del plugin. No se ejecutan Grunt lint, Behat ni cobertura en esta primera suite.

### Moodle 5.1 y PHPUnit

Plugin CI detecta `public/version.php`, obtiene las ubicaciones de plugins mediante las APIs de componentes de Moodle y resuelve las utilidades de pruebas bajo el directorio público. El soporte de esta estructura existe desde Plugin CI 4.5.8.

`install` clona core, genera el `config.php` local, crea la base y los dataroots desechables, instala el plugin y las dependencias, y ejecuta las utilidades PHPUnit `--install` y `--buildconfig`. No se parchean archivos de core. El workflow verifica explícitamente las rutas de la matriz y que la configuración generada contenga la suite requerida.

Composer, `vendor/bin/phpunit` y `phpunit.xml` permanecen en la raíz del checkout de Moodle para las tres versiones. El comando se ejecuta desde esa raíz:

```sh
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite --fail-on-warning --fail-on-risky --fail-on-incomplete --fail-on-skipped
```

## Aislamiento, credenciales y caché

- Permiso único del workflow: `contents: read`. Checkout usa `persist-credentials: false`.
- Cada entrada de la matriz obtiene su propio servicio MariaDB, sin volúmenes persistentes. El healthcheck oficial exige conexión e inicialización de InnoDB antes de comenzar los pasos.
- Base `moodle`, usuario `root`, contraseña ficticia `moodle`, host `127.0.0.1`, puerto `3306`. Root se limita al contenedor efímero: el instalador upstream ejecuta `CREATE DATABASE`, por lo que la imagen no recibe `MARIADB_DATABASE` ni una base precreada. No son credenciales institucionales.
- PHPUnit utiliza el prefijo y dataroot de pruebas generados por Plugin CI; no hay conexiones a bases existentes ni a producción.
- Solo se cachea `composer config --global cache-files-dir`: archivos descargados de paquetes. Las claves separan QUALITY y cada rama Moodle, PHP y versiones de Composer/Plugin CI. No se cachean `vendor/`, configuraciones, dataroots, bases, credenciales o tokens.
- No se requieren GitHub Secrets de Google ni credenciales institucionales. No se imprimen variables de entorno completas ni se sube `config.php` como artefacto.
- Los fixtures generan valores aleatorios no funcionales para los issuers y usan correos `example.invalid`. Las URLs Google en los fixtures son metadatos: `verify`, `get_system_client` y `get_raw_userinfo` se sustituyen por mocks en las rutas que consultarían al proveedor. No se ejecutan autorización, descubrimiento, renovación de tokens ni llamadas Google reales.
- El runner necesita Internet para descargar herramientas y dependencias. No se instala un firewall de salida: mantener la frontera simulada al ampliar las pruebas es parte de su contrato.
- No hay comandos de push, merge, release ni deploy en el workflow.

## Pruebas equivalentes localmente

Usar Linux/WSL con PHP 8.3, Composer 2.10.3, Git, cliente `mysql`, npm y NVM, las extensiones e INI del workflow y la locale `en_AU.UTF-8`. No reutilizar instalaciones, bases, dataroots o directorios de otra fase. En Windows, configurar el clon con `core.autocrlf=false` antes del checkout para conservar LF y evitar falsos errores PHPCS.

Desde un directorio de trabajo nuevo y desechable, copiar/clonar esta revisión como `plugin/`. Para QUALITY:

```sh
composer create-project --no-interaction --no-dev --prefer-dist --no-scripts moodlehq/moodle-plugin-ci ci 4.5.11
git -C plugin diff --check "$(git -C plugin hash-object -t tree /dev/null)" HEAD
php ci/bin/moodle-plugin-ci phplint ./plugin
php ci/bin/moodle-plugin-ci phpcs --max-warnings 0 ./plugin
php ci/bin/moodle-plugin-ci savepoints ./plugin
```

Para cada versión, usar otro directorio nuevo con `plugin/` y `ci/` preparados como arriba y un contenedor propio. Este ejemplo usa el puerto local 3307 para evitar el servicio habitual de desarrollo:

```sh
docker run --detach --rm --name tupmeet-ci-db \
  -e MARIADB_ROOT_PASSWORD=moodle -p 127.0.0.1:3307:3306 \
  --health-cmd='healthcheck.sh --connect --innodb_initialized' \
  --health-interval=10s --health-timeout=5s --health-retries=10 \
  --health-start-period=30s \
  mariadb:10.11.14@sha256:dbe56e20372fc6d6b8e0e396866ba89c4c7f128c38c4f59aaa54d957db95790c

# Esperar hasta que docker inspect muestre healthy; no continuar si queda unhealthy.
docker inspect --format '{{.State.Health.Status}}' tupmeet-ci-db

export DB=mariadb DB_HOST=127.0.0.1 DB_PORT=3307
export DB_NAME=moodle DB_USER=root DB_PASS=moodle
export MOODLE_BRANCH=MOODLE_405_STABLE
export NVM_DIR="$HOME/.nvm"
php ci/bin/moodle-plugin-ci install --plugin ./plugin --no-plugin-node
php ci/bin/moodle-plugin-ci validate --moodle ./moodle ./plugin
git -C moodle rev-parse HEAD
cd moodle
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite --fail-on-warning --fail-on-risky --fail-on-incomplete --fail-on-skipped

# Al terminar, retirar solo el contenedor creado para estas pruebas.
docker stop tupmeet-ci-db
```

Repetir secuencialmente con `MOODLE_500_STABLE` y `MOODLE_501_STABLE`, siempre en directorios nuevos y recreando el contenedor. La inicialización directa en un Moodle de pruebas ya configurado se describe también en [PHASE1.md](PHASE1.md#validación-reproducible): `admin/tool/phpunit/cli/init.php` en 4.5/5.0 y `public/admin/tool/phpunit/cli/init.php` en 5.1.

## Interpretación de resultados y límites

**PASS** requiere QUALITY y las tres entradas PHPUNIT en verde. Un fallo de instalación, healthcheck, descarga o ruta es un fallo de infraestructura; un error de estilo/savepoints/estructura señala el archivo correspondiente; una aserción PHPUnit fallida señala un contrato del plugin o una incompatibilidad con esa versión. Conservar el primer error útil y el SHA de core antes de reintentar. Cancelado, omitido o sin pruebas ejecutadas no cuenta como PASS.

La base documenta 24 pruebas y 63 aserciones en cada versión; usar esa cifra como referencia inicial y revisar cualquier cambio inesperado. No convertir errores en éxito con `continue-on-error`, filtros que oculten pruebas o modificaciones funcionales dentro de esta tarea.

CI no valida consentimiento Google, permisos reales de Workspace, expiración/revocación real, interfaz visual, staging, rendimiento, seguridad exhaustiva, backups, producción ni servicios futuros Calendar/Meet/Drive. **Google real debe probarse posteriormente en staging** con el procedimiento y las credenciales administradas fuera del repositorio. Tampoco se cubren PostgreSQL, otras versiones PHP ni una matriz completa de actualizaciones históricas.

### Validación local de esta incorporación

- Revisión de instrucciones, arquitectura, Fase 1, los tres archivos de pruebas, fixtures, generador, versión e instalación/upgrade XMLDB.
- Sintaxis PHP: 21 archivos, PASS con PHP 8.3.33.
- PHPCS Moodle: 21 archivos, cero errores y advertencias; savepoints: PASS. La primera ejecución PHPCS detectó CRLF del checkout Windows; se repitió con LF, sin modificar contenido versionado del plugin.
- YAML y expresiones GitHub Actions: actionlint 1.7.12, PASS. Parseo adicional con Symfony YAML y comprobación de triggers, matriz, rutas, permisos, timeouts y pins: PASS.
- Estructura de módulo: motor de validación upstream para Moodle 4.5 ejecutado sin arrancar Moodle, PASS. Revisión de caché y ausencia de secretos; `git diff --check`, PASS.
- No se ejecutó aquí la matriz Linux/MariaDB ni GitHub Actions. Los resultados PHPUnit anteriores de Fase 1 siguen siendo evidencia separada, no un resultado remoto de este workflow.

### Verificación después del push autorizado

1. Publicar únicamente `codex/infra-ci-moodle` y abrir la pestaña Actions del repositorio; confirmar evento `push` y SHA del commit enviado.
2. Comprobar cuatro resultados: QUALITY y PHPUNIT 4.5, 5.0 y 5.1. Revisar healthcheck, instalación, versiones/SHA de core, rutas verificadas y salida real de la suite (inicialmente 24 pruebas / 63 aserciones).
3. Verificar una segunda ejecución para comprobar restauración de caché; un push posterior mientras otra ejecución está activa debe cancelarla dentro del grupo correspondiente.
4. Cuando se autorice un PR hacia `main`, comprobar el evento `pull_request` y sus cuatro resultados sobre la referencia de integración. No hacer merge para probar el CI inicial.
5. `workflow_dispatch` se podrá verificar cuando el workflow forme parte de la rama predeterminada mediante el proceso autorizado. Hasta entonces, su ausencia en la UI no invalida el trigger de push.
6. Solo después de confirmar las ejecuciones, evaluar badge y checks requeridos en protección de ramas. No se cambian esas configuraciones en esta tarea.

## Referencias upstream consultadas

- [Moodle Plugin CI: changelog y soporte de public desde 4.5.8](https://moodlehq.github.io/moodle-plugin-ci/CHANGELOG.html).
- [Plantilla GitHub Actions de Plugin CI 4.5.11](https://github.com/moodlehq/moodle-plugin-ci/blob/4.5.11/gha.dist.yml) y [comandos documentados](https://moodlehq.github.io/moodle-plugin-ci/CLI.html).
- [Instalador PHPUnit 4.5.11](https://github.com/moodlehq/moodle-plugin-ci/blob/4.5.11/src/Installer/TestSuiteInstaller.php) y [resolución de directorios Moodle](https://github.com/moodlehq/moodle-plugin-ci/blob/4.5.11/src/Bridge/Moodle.php).
- Requisitos oficiales: [Moodle 4.5](https://moodledev.io/general/releases/4.5), [Moodle 5.0](https://moodledev.io/general/releases/5.0), [Moodle 5.1](https://moodledev.io/general/releases/5.1).
- [checkout v6.0.2](https://github.com/actions/checkout/tree/v6.0.2), [cache v5.0.3](https://github.com/actions/cache/tree/v5.0.3), [setup-php 2.37.2](https://github.com/shivammathur/setup-php/tree/2.37.2).
- [MariaDB 10.11.14](https://mariadb.com/docs/release-notes/community-server/10.11/10.11.14), [healthcheck de la imagen oficial](https://mariadb.com/kb/en/using-healthcheck-sh/) y [condición de workflow_dispatch](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#workflow_dispatch).
