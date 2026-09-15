<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Spanish strings for TUP Meet.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'TUP Meet';
$string['masteraccounts'] = 'Cuentas maestras institucionales';
$string['registeraccount'] = 'Registrar cuenta';
$string['accountdisplayname'] = 'Nombre de la cuenta';
$string['oauthissuer'] = 'Issuer OAuth2 de Moodle';
$string['googleemail'] = 'Correo institucional verificado';
$string['accountstate'] = 'Uso para actividades nuevas';
$string['lastverification'] = 'Última verificación correcta';
$string['connectaccount'] = 'Conectar / reconectar en Moodle';
$string['verifyaccount'] = 'Verificar identidad Google';
$string['setdefaultaccount'] = 'Establecer como predeterminada';
$string['disableaccount'] = 'Deshabilitar para actividades nuevas';
$string['enableaccount'] = 'Habilitar para actividades nuevas';
$string['accountenabled'] = 'Habilitada';
$string['accountdisabledlabel'] = 'Deshabilitada (histórica)';
$string['defaultaccount'] = 'Predeterminada';
$string['notverified'] = 'Pendiente de verificación';
$string['manageissuers'] = 'Administrar servicios OAuth2 de Moodle';
$string['issuerunavailable'] = 'Issuer no disponible';
$string['noavailableissuers'] = 'No hay issuers Google configurados y libres. Cree un issuer Google distinto en Moodle para cada cuenta institucional.';
$string['accountinstructions'] = 'Registre un nombre y un issuer, conecte su cuenta de sistema en Moodle, vuelva aquí y verifique. Revise el correo mostrado antes de seleccionar la predeterminada. Reconecte un issuer únicamente con su cuenta Google original; para reemplazar la cuenta, use otro issuer. Las fechas indican la última verificación correcta, no la vigencia actual de la autorización. Deshabilitar la predeterminada pausa la creación de actividades nuevas hasta seleccionar otra.';
$string['accountregistered'] = 'Cuenta registrada. Conéctela en Moodle, vuelva aquí y verifique su identidad Google.';
$string['accountupdated'] = 'Cuenta actualizada. Revise abajo su correo verificado y estado de predeterminada.';
$string['invalidissuer'] = 'Seleccione un issuer Google habilitado y configurado para servicios internos, con endpoints de autorización, token y userinfo OpenID.';
$string['connectionfailed'] = 'No se pudo autenticar la cuenta de sistema de Moodle. Revise la autorización, los permisos y la conexión de red; reconecte si es necesario. Se conservó el estado de la cuenta del plugin.';
$string['invalididentity'] = 'Google no devolvió una identidad Workspace verificada con identificador, correo y dominio, o el correo no pertenece a los dominios del issuer. Revise userinfo OpenID y autorice la cuenta institucional.';
$string['identitychanged'] = 'Este issuer está conectado a otra identidad Google o a otro correo. La identidad histórica no se modificó. Reconecte la cuenta original; registre las cuentas de reemplazo con otro issuer.';
$string['accountnotverified'] = 'Verifique la identidad Google y revise su correo antes de habilitar o seleccionar esta cuenta.';
$string['accountdisabled'] = 'Habilite esta cuenta verificada antes de seleccionarla como predeterminada.';
$string['accountbusy'] = 'Hay otra operación de cuentas en curso. Inténtelo de nuevo en unos momentos.';
$string['invalidaccountname'] = 'Indique un nombre de cuenta de 1 a 255 caracteres.';
$string['issuerinuse'] = 'Este issuer ya pertenece a una cuenta registrada. Use otro issuer para conservar la autorización histórica.';
$string['accountinuse'] = 'Solo se pueden eliminar registros deshabilitados, nunca verificados y sin uso. Las identidades institucionales verificadas se conservan.';
$string['nodefaultaccount'] = 'No hay una única cuenta predeterminada habilitada y verificada. Solicite al administrador configurar TUP Meet antes de crear actividades nuevas. Las actividades existentes conservan su propietario.';
$string['invalidrequest'] = 'Operación de cuentas no válida. Use los botones de la página de administración.';
$string['accountoperationfailed'] = 'No se pudo completar la operación. Revise la cuenta y la configuración OAuth2 de Moodle.';
$string['phase1notice'] = 'Fase 1: la administración de cuentas institucionales está disponible. No se crean eventos Calendar ni enlaces Meet y no se automatizan grabaciones.';
$string['privacy:metadata:accounts'] = 'Metadatos históricos de cuentas institucionales, independientes de las cuentas de usuario Moodle.';
$string['privacy:metadata:displayname'] = 'Nombre de la cuenta institucional indicado por el administrador.';
$string['privacy:metadata:googleemail'] = 'Correo institucional Google verificado.';
$string['privacy:metadata:googlesub'] = 'Identificador estable OpenID de la cuenta Google.';
$string['privacy:metadata:timeverified'] = 'Fecha de la última verificación correcta de identidad.';
$string['privacy:metadata:oauth2'] = 'Moodle core administra la autorización OAuth2 y el ciclo de vida de los tokens.';
$string['modulename'] = 'TUP Meet';
$string['modulenameplural'] = 'TUP Meet';
$string['pluginadministration'] = 'Administración de TUP Meet';
$string['tupmeet:addinstance'] = 'Añadir una actividad TUP Meet';
$string['tupmeet:view'] = 'Ver una actividad TUP Meet';
$string['tupmeet:manage'] = 'Administrar una actividad TUP Meet';
$string['meetingname'] = 'Nombre de la reunión';
$string['scheduling'] = 'Programación';
$string['startdatetime'] = 'Fecha y hora de inicio';
$string['enddatetime'] = 'Fecha y hora de finalización';
$string['isrecurring'] = 'Reunión recurrente';
$string['recurrenceinterval'] = 'Repetir cada (semanas)';
$string['recurrencedays'] = 'Días de repetición';
$string['recurrenceuntil'] = 'Repetir hasta';
$string['meetsettings'] = 'Google Meet';
$string['autorecord'] = 'Grabar automáticamente';
$string['autotranscript'] = 'Generar transcripción automáticamente';
$string['publicationmode'] = 'Publicación de grabaciones';
$string['publicationmanual'] = 'Requiere aprobación del docente';
$string['publicationautomatic'] = 'Publicar automáticamente';
$string['errormissingname'] = 'Indique un nombre para la reunión.';
$string['errorendbeforestart'] = 'La fecha/hora de finalización debe ser posterior al inicio.';
$string['errorrecurrenceinterval'] = 'El intervalo de repetición debe estar entre 1 y 999 semanas.';
$string['errorrecurrenceday'] = 'Seleccione al menos un día para la reunión recurrente.';
$string['errorrecurrenceuntil'] = 'La fecha final de recurrencia no puede ser anterior al inicio.';
$string['timezone'] = 'Zona horaria de la reunión';
$string['nextsession'] = 'Próxima sesión';
$string['nosession'] = 'No hay sesiones próximas';
$string['joinmeet'] = 'Entrar a Google Meet';
$string['preferencesonly'] = 'La grabación, transcripción y publicación se guardan solo como preferencias. TUP Meet todavía no activa la grabación, transcripción ni publicación automática.';
$string['syncpending'] = 'La reunión se está preparando. El enlace de Google Meet todavía no está disponible. Recarga esta página en unos momentos; Moodle reintentará automáticamente.';
$string['syncerror'] = 'No se pudo sincronizar la reunión. El enlace de Google Meet no está disponible. El docente puede reintentar; es posible que el administrador deba reconectar la cuenta institucional.';
$string['synclegacy'] = 'Esta actividad todavía no está programada en Calendar. El docente debe revisar y guardar su programación.';
$string['retrysync'] = 'Reintentar sincronización';
$string['calendarfailed'] = 'Falló la sincronización con Google Calendar. Revisa la autorización institucional y vuelve a intentar.';
$string['invalidschedule'] = 'La programación de la reunión no es válida.';
$string['invalidrequest'] = 'El identificador del envío no es válido. Abre nuevamente el formulario de la actividad.';
$string['duplicatesubmission'] = 'Este envío ya fue guardado. Abre la actividad TUP Meet existente desde el curso.';
$string['errorstartweekday'] = 'La fecha de la primera reunión debe coincidir con uno de los días seleccionados.';
$string['privacy:metadata:calendarname'] = 'El título de la reunión se envía al Google Calendar institucional.';
$string['privacy:metadata:calendarintro'] = 'La descripción de la actividad se envía al Google Calendar institucional.';
$string['privacy:metadata:calendarschedule'] = 'Las fechas, horas, zona horaria y recurrencia se envían a Google Calendar.';
$string['privacy:metadata:googlecalendar'] = 'Las reuniones institucionales se almacenan en Google Calendar. No se envían identidades de usuarios Moodle ni listas de inscripciones.';
