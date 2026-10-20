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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Google Meet attendance interface and authorization.
 *
 * @package mod_googlemeet
 * @copyright 2026 MindFree
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['attendance'] = 'Asistencia';
$string['attendanceenabled'] = 'Activar seguimiento de asistencia';
$string['attendancethreshold'] = 'Tiempo requerido en cada clase (%)';
$string['attendancelategrace'] = 'Margen para llegada tardía';
$string['attendanceconnectionnotice'] = 'Guarde la actividad y conecte la cuenta organizadora desde el informe de asistencia. Solo se registran clases futuras.';
$string['completionattendance'] = 'Requerir asistencia en este porcentaje de clases elegibles';
$string['completionattendance_desc'] = 'Asistir al menos al {$a}% de las clases elegibles';
$string['attendanceinvalidcompletion'] = 'Active la asistencia y seleccione un porcentaje entre 1 y 100.';
$string['attendanceidentity'] = 'Mi identidad de Google';
$string['attendanceidentitynotice'] = 'Vincule explícitamente su propia identidad de Google. La vinculación estudiantil solicita solo identidad, correo y perfil. Desconectar no elimina asistencias históricas ni asignaciones manuales.';
$string['attendanceconnect'] = 'Conectar mi cuenta de Google';
$string['attendancedisconnect'] = 'Desconectar mi identidad';
$string['attendanceorganizer'] = 'Conectar cuenta organizadora';
$string['attendanceauthrequired'] = 'El organizador debe conectar o reconectar su propia cuenta de Google para sincronizar la asistencia.';
$string['attendanceidentityused'] = 'Esta identidad de Google ya está vinculada a otra cuenta Moodle.';
$string['attendanceunavailable'] = 'La asistencia está desactivada o no tiene acceso a este informe.';
$string['attendancereasonrequired'] = 'Debe indicar un motivo para este cambio.';
$string['attendanceinvalidstudent'] = 'Seleccione un estudiante de la nómina elegible de la clase.';
$string['attendancependingnotice'] = 'Las identidades de Google sin resolver y las importaciones incompletas permanecen pendientes. La asistencia se confirma después del cierre de la clase y de resolver los datos requeridos.';
$string['attendancesync'] = 'Solicitar sincronización de asistencia';
$string['attendanceexport'] = 'Descargar CSV';
$string['attendancestate'] = 'Sincronización: {$a}';
$string['attendanceuserid'] = 'ID de usuario';
$string['attendancestudent'] = 'Estudiante';
$string['attendanceminutes'] = 'Minutos efectivos';
$string['attendancepercentage'] = 'Asistencia a clase (%)';
$string['attendancestatus'] = 'Estado';
$string['attendanceintervals'] = 'Conexiones e intervalos';
$string['attendancelate'] = 'Llegada tardía';
$string['attendanceearly'] = 'Salida anticipada';
$string['attendancenodata'] = 'Aún no hay registros de asistencia disponibles.';
$string['attendancemap'] = 'Asignar participante a estudiante';
$string['attendanceexternal'] = 'Participante externo (excluido)';
$string['attendancereason'] = 'Motivo (obligatorio)';
$string['attendancecancel'] = 'Cancelar clase indicando motivo';
$string['attendancestatusattended'] = 'Presente';
$string['attendancestatusabsent'] = 'Ausente';
$string['attendancestatuspending'] = 'Pendiente';
$string['attendancestatuscancelled'] = 'Cancelada (excluida)';
$string['attendancesummaryattended'] = 'Presentes';
$string['attendancesummaryabsent'] = 'Ausentes';
$string['attendancesummarypending'] = 'Pendientes';
$string['attendancesummarypeak'] = 'Máximo de participantes simultáneos';
$string['attendancesummaryaverage'] = 'Promedio de participantes simultáneos';
$string['resetattendance'] = 'Eliminar historial de asistencia del curso (conservar identidades de Google)';
$string['attendancedispatch'] = 'Programar sincronización de asistencia de Google Meet';
$string['privacy:metadata:attendance'] = 'Registros de asistencia y decisiones de identidad de participantes.';
$string['privacy:metadata:attendance:identifier'] = 'Identificador de Google o del recurso importado.';
$string['privacy:metadata:attendance:userid'] = 'Usuario Moodle vinculado a la asistencia o que realiza una revisión.';
$string['privacy:metadata:attendance:time'] = 'Fecha y hora de conexión, clase, nómina o revisión.';
$string['privacy:metadata:attendance:reason'] = 'Motivo registrado para una revisión de asistencia.';
$string['privacy:metadata:google'] = 'Google recibe la autorización del organizador e identificadores de conferencias Meet para consultar asistencia. La vinculación solicita información de identidad del perfil de Google.';
$string['googlemeet:viewattendance'] = 'Ver todos los registros de asistencia';
$string['googlemeet:viewownattendance'] = 'Ver asistencia propia y vincular identidad';
$string['googlemeet:manageattendance'] = 'Gestionar sincronización y revisión de identidad';

$string['attendancecourse'] = 'Asistencia del curso';
$string['attendanceclasses'] = 'Clases elegibles';
$string['attendancenoaccount'] = 'No ha vinculado su identidad de Google. Sus resultados pueden quedar pendientes hasta resolver su identidad.';
$string['attendancetotalminutes'] = 'Minutos totales de conexión';

$string['attendancehistorylocked'] = 'Esta serie de asistencia ya comenzó. Cree otra actividad para cambiar la sala, el organizador o el servicio OAuth.';
$string['attendancesummarymeanseconds'] = 'Permanencia efectiva media (minutos)';
$string['attendancelastsync'] = 'Última sincronización';
$string['attendancesyncstatepending'] = 'Pendiente';
$string['attendancesyncstateresolved'] = 'Importada';
$string['attendancesyncstateerror'] = 'Requiere reintento';
$string['attendancesyncstateauthrequired'] = 'Requiere conexión del organizador';
$string['attendancesyncfailed'] = 'El último refresco falló. Los registros importados se conservan; reconecte al organizador si corresponde y solicite otra sincronización.';
$string['attendancemappingauto'] = 'Cuenta vinculada';
$string['attendancemappingmanual'] = 'Revisado por docente';
$string['attendancemappingpending'] = 'Identidad pendiente';
$string['attendancemappingexternal'] = 'Participante externo';
