# Fase 3.4.1 — límite inclusivo de recurrencia a la hora de inicio

## Base y alcance

- Rama: `codex/fase-3-4-meet-first-production`.
- Base: `36d3f6e3bc7c8b9eec917e860836fbe9dec7b4bc`, `2026091801 / 0.6.0-alpha`.
- Nueva versión: `2026091802 / 0.6.1-alpha`.
- El responsable aprobó el smoke real Meet-first: reunión simple y recurrente, Calendar native, attendee docente, COHOST, artifactConfig y conservación del mismo Meet. Es evidencia manual reportada; este hardening no repite operaciones Google.

Solo cambia la representación de `UNTIL` en el payload de calendario. No cambia el esquema ni hay migración, savepoint nuevo o resincronización masiva. Se conservan Spaces, provisionmode, estados, COHOST, artifactConfig, conferenceData, attendees, sendUpdates, OAuth y PoC. Fase 4 no forma parte de este trabajo.

## Causa y fórmula

Antes: `fecha local(recurrenceuntil) + 23:59:59 → UTC`.

Ahora, `schedule::recurrence_until()`: `fecha local(recurrenceuntil) + H:i:s local(DTSTART) → UTC`, usando siempre la zona guardada. Se emite `Ymd\THis\Z`. No se copia el offset del primer evento ni se utiliza la zona predeterminada de PHP.

Ejemplo de Cancún, inicio 19/09/2026 a las 17:00, último día permitido 03/10/2026:

| Representación | Local America/Cancun | UNTIL UTC |
| --- | --- | --- |
| Anterior | 03/10/2026 23:59:59 -05:00 | `20261004T045959Z` |
| Nueva | 03/10/2026 17:00:00 -05:00 | `20261003T220000Z` |

[RFC5545 §3.3.10](https://www.rfc-editor.org/rfc/rfc5545#section-3.3.10) establece un límite inclusivo y exige UTC para un DTSTART con referencia de zona. La sesión del 03/10 a las 17:00 coincide exactamente con UNTIL y sigue incluida. Si la fecha final no pertenece a BYDAY, sigue siendo un límite superior y la última sesión es la válida anterior; no se añade una sesión en esa fecha.

No cambian FREQ, INTERVAL, BYDAY, WKST ni la frecuencia semanal. La serie sabatina conserva exactamente 19/09, 26/09 y 03/10. No aparece 04/10, incluso en la prueba adicional donde el domingo está seleccionado.

`next_session()` permanece intacto: examina inicios a la hora local normal hasta la fecha final inclusiva. Su límite interno de fin del día no se transmite a Google; para estas series de hora fija conserva las mismas sesiones que el nuevo UNTIL. La finalización de una sesión nocturna puede ocurrir después del límite de inicio sin invalidarla.

## DST y límites de la representación

El offset se resuelve al fijar la hora en la fecha final, dentro de la zona real. Nueva York mantiene las 10:00 locales: antes del cambio de otoño son 14:00Z; el 01/11/2026 son 15:00Z. En primavera, el 08/03/2026 son 14:00Z, aunque el primer domingo tenía offset -05:00. Se conserva además la cobertura nocturna en ambos cambios DST.

Una reunión de Cancún a las 23:30:15 del 03/10 termina su límite de inicio en `20261004T043015Z`. **No se garantiza igualdad entre fecha UTC y fecha local**, ni un texto específico de la interfaz de Google. El cambio elimina el fin artificial del día, no el cambio de fecha causado por una conversión UTC legítima. Las pruebas DST cubren horas locales existentes; no añaden una política nueva para horas inexistentes o ambiguas durante el cambio de reloj.

La observación visual reportada es consistente con que Calendar muestre el día UTC del antiguo límite; no se inspeccionó el algoritmo interno de su interfaz. La etiqueta nueva queda pendiente de smoke cuando se autorice instalar el paquete. Eventos ya sincronizados no se reescriben por actualizar el plugin: el nuevo formato se aplica en el siguiente payload de creación o actualización que corresponda al flujo existente.

## Pruebas

Se conservan los 225 casos previos, actualizando solo expectativas relacionadas con el antiguo fin del día, y se añaden siete casos:

1. Cancún: sábados 19/09, 26/09 y 03/10, último inicio incluido exactamente.
2. Fecha final viernes no seleccionado: conserva el sábado anterior.
3. Sábado y domingo seleccionados: 03/10 incluido, 04/10 excluido.
4. Sesión nocturna con segundos y fin al día siguiente: cruza fecha UTC correctamente.
5. Nueva York antes del cambio DST de otoño.
6. Nueva York en el cambio a horario estándar.
7. Nueva York en el cambio a horario de verano.

Cada caso comprueba el RRULE completo, ambas zonas del payload, el inicio intacto, la fecha/hora local del límite, la enumeración finita mediante `next_session()` y la exclusión del siguiente inicio seleccionado. Se ejecutan con PHP configurado temporalmente en Pacific/Auckland para demostrar independencia de la zona predeterminada. No hay llamadas Google reales.

## Resultados locales

Ejecutados el 18/09/2026 con PHP 8.3.33 y MariaDB 10.11.14, en tres bases PHPUnit locales aisladas:

| Moodle | PHPUnit | Pruebas | Aserciones | Resultado |
| --- | --- | ---: | ---: | --- |
| 4.5.14 LTS | 9.6.34 | 232 | 3960 | PASS |
| 5.0.10 | 11.5.55 | 232 | 3961 | PASS |
| 5.1.7 | 11.5.55 | 232 | 3961 | PASS |

Comando completo en cada checkout:

```sh
php vendor/bin/phpunit --testsuite mod_tupmeet_testsuite --fail-on-warning --fail-on-risky --fail-on-incomplete --fail-on-skipped
```

En PHPUnit 11 se añadieron `--fail-on-notice --display-notices`. Las tres ejecuciones terminaron con código 0. No se omitieron pruebas ni se modificaron los tests de instalación/upgrade históricos.

- PHPCS Moodle, todos los PHP del plugin: cero errores y cero advertencias.
- Sintaxis: 61 archivos PHP, PASS.
- Savepoints históricos: seis bloques/calls coincidentes y ordenados, PASS; sin cambios DB.
- `git diff --check`: PASS.
- CI heredado y todos los componentes funcionales fuera de `schedule.php`: sin modificaciones.

La inicialización requirió añadir el PHP local al PATH del proceso para que los subprocesos lo encontraran. Tras ese ajuste de entorno, la preparación y las suites completas pasaron; no se modificó Moodle core ni configuración de staging.

Los resultados locales no implican validación remota ni instalación en staging. No hubo push, merge, deploy, release ni llamadas Google reales. La etiqueta de Calendar con el nuevo formato queda pendiente de validación manual autorizada.
