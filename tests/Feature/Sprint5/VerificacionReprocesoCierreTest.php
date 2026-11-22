<?php

use App\Enums\EstadoIncidencia;
use App\Enums\Rol;
use App\Models\EventoAuditoria;
use App\Models\Incidencia;
use App\Models\NotificacionPlataforma;
use App\Models\Prioridad;
use App\Models\User;
use App\Models\Verificacion;
use Carbon\CarbonImmutable;

/**
 * QA Sprint 5 - E09 Verificación y cierre.
 * KAN-266 (HU-9.1), KAN-271 (HU-9.2) y KAN-276 (HU-9.3).
 * Complementa tests/Feature/Operacion/VerificacionCierreTest.php con los casos negativos,
 * límites y reglas de inmutabilidad que esa suite no cubre.
 */
beforeEach(function () {
    $this->tecnico = User::factory()->tecnico('CERRAJERIA', 'PINTURA')->create();
    $this->verificador = $this->usuarioConRol(Rol::Verificador);
    $this->coordinador = $this->usuarioConRol(Rol::Coordinador);
});

function criteriosConformes(): array
{
    return ['criterios' => [['criterio' => 'La puerta cierra correctamente', 'cumple' => true]], 'observaciones' => 'Trabajo conforme a lo planificado.'];
}

function rechazoCompleto(): array
{
    return ['motivo_rechazo' => 'La filtración de agua persiste en la unión', 'correccion_requerida' => 'Reemplazar el empaque y resellar la unión'];
}

/*
|--------------------------------------------------------------------------
| KAN-266 - HU-9.1 Verificación técnica y segregación de funciones
|--------------------------------------------------------------------------
*/

it('[KAN-266] guarda la evaluación con criterios, observaciones y verificador, y audita E12 -> E14 (Escenario 1)', function () {
    $incidencia = incidenciaPendienteVerificacion($this->tecnico);

    $this->comoRol($this->verificador, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/verificar", criteriosConformes())
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', 'E14');

    $verificacion = Verificacion::query()->where('incidencia_id', $incidencia->id)->sole();
    expect($verificacion->verificador_id)->toBe($this->verificador->id)
        ->and($verificacion->resultado_conforme)->toBeTrue()
        ->and($verificacion->criterios_evaluados)->toBe([['criterio' => 'La puerta cierra correctamente', 'cumple' => true]])
        ->and($verificacion->observaciones)->toBe('Trabajo conforme a lo planificado.');

    $evento = EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('estado_nuevo', 'E14')->sole();
    expect($evento->estado_anterior)->toBe('E12')
        ->and($evento->usuario_id)->toBe($this->verificador->id)
        ->and($evento->rol_codigo)->toBe('R06');
});

it('[KAN-266] solo verifica incidencias en E12 y no crea evaluaciones fuera de ese estado', function (EstadoIncidencia $estado) {
    $incidencia = incidenciaPendienteVerificacion($this->tecnico);
    $incidencia->forceFill(['estado_codigo' => $estado])->save();

    $this->comoRol($this->verificador, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/verificar", criteriosConformes())
        ->assertStatus(409)
        ->assertJsonPath('detail', 'Solo se pueden verificar incidencias Pendientes de verificación (E12).');

    expect($incidencia->refresh()->estado_codigo)->toBe($estado)
        ->and(Verificacion::query()->where('incidencia_id', $incidencia->id)->exists())->toBeFalse();
})->with([
    'en atención (E11)' => [EstadoIncidencia::EnAtencion],
    'en corrección (E13)' => [EstadoIncidencia::EnCorreccion],
    'pendiente de confirmación (E14)' => [EstadoIncidencia::PendienteConfirmacion],
    'cerrada (E15)' => [EstadoIncidencia::Cerrada],
]);

it('[KAN-266] rechaza con 403 la verificación de roles distintos al verificador sin cambiar el estado', function (Rol $rol) {
    $incidencia = incidenciaPendienteVerificacion($this->tecnico);
    $usuario = $rol === Rol::Tecnico ? $this->tecnico : $this->usuarioConRol($rol);

    $this->comoRol($usuario, $rol)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/verificar", criteriosConformes())
        ->assertForbidden();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteVerificacion)
        ->and(Verificacion::query()->where('incidencia_id', $incidencia->id)->exists())->toBeFalse();
})->with([
    'técnico ejecutor (R04)' => [Rol::Tecnico],
    'coordinador (R03)' => [Rol::Coordinador],
    'gestor (R02)' => [Rol::Gestor],
    'reportante (R01)' => [Rol::Reportante],
]);

it('[KAN-266] aplica la segregación D03 cuando la prioridad es Crítica aunque no sea riesgo inmediato (Escenario 3)', function () {
    $multiRol = User::factory()->tecnico('CERRAJERIA')->conRoles(Rol::Verificador)->create();
    $incidencia = Incidencia::query()->findOrFail(incidenciaPendienteVerificacion($multiRol)->id);
    $incidencia->forceFill(['es_riesgo_inmediato' => false])->save();
    $incidencia->prioridad()->associate(Prioridad::query()->where('codigo', 'CRITICA')->firstOrFail())->save();

    $this->comoRol($multiRol, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/verificar", criteriosConformes())
        ->assertForbidden()
        ->assertJsonPath('detail', 'Por control de calidad en trabajos de riesgo, el verificador debe ser una persona distinta al técnico ejecutor (D03)');

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteVerificacion);
});

it('[KAN-266] permite al técnico con rol verificador certificar su propio trabajo de riesgo bajo (la regla D03 solo aplica a riesgo alto)', function () {
    $multiRol = User::factory()->tecnico('CERRAJERIA')->conRoles(Rol::Verificador)->create();
    $incidencia = incidenciaPendienteVerificacion($multiRol, atributos: ['es_riesgo_inmediato' => false]);
    $incidencia->prioridad()->associate(Prioridad::query()->where('codigo', 'BAJA')->firstOrFail())->save();

    $this->comoRol($multiRol, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/verificar", criteriosConformes())
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', 'E14');
});

it('[KAN-266] sin evidencias obligatorias no aprueba, no cambia el estado ni guarda la evaluación (Escenario 4)', function () {
    $incidencia = incidenciaPendienteVerificacion($this->tecnico);
    $incidencia->evidencias()->getQuery()->toBase()->delete();

    $this->comoRol($this->verificador, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/verificar", criteriosConformes())
        ->assertUnprocessable()
        ->assertJsonPath('regla', 'RN-033');

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteVerificacion)
        ->and(Verificacion::query()->where('incidencia_id', $incidencia->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| KAN-271 - HU-9.2 Rechazo por defectos y reproceso
|--------------------------------------------------------------------------
*/

it('[KAN-271] exige motivo y corrección: sin ellos responde 422 y la incidencia sigue en E12 (Escenario 2)', function (array $datos, string $campo, string $mensaje) {
    $incidencia = incidenciaPendienteVerificacion($this->tecnico);

    $this->comoRol($this->verificador, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/rechazar-verificacion", $datos)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$campo => $mensaje]);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteVerificacion)
        ->and(Verificacion::query()->where('incidencia_id', $incidencia->id)->exists())->toBeFalse();
})->with([
    'sin motivo' => [['correccion_requerida' => 'Reemplazar el empaque y resellar la unión'], 'motivo_rechazo', 'Debe registrar el motivo del rechazo (RN-035).'],
    'motivo en blanco' => [['motivo_rechazo' => '   ', 'correccion_requerida' => 'Reemplazar el empaque y resellar la unión'], 'motivo_rechazo', 'Debe registrar el motivo del rechazo (RN-035).'],
    'corrección en blanco' => [['motivo_rechazo' => 'La filtración de agua persiste', 'correccion_requerida' => ''], 'correccion_requerida', 'Debe especificar la corrección técnica requerida (RN-035).'],
]);

it('[KAN-271] guarda la evaluación negativa con motivo y corrección y la audita como E12 -> E13 (Escenario 1)', function () {
    $incidencia = incidenciaPendienteVerificacion($this->tecnico);

    $this->comoRol($this->verificador, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/rechazar-verificacion", rechazoCompleto())
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', 'E13');

    $verificacion = Verificacion::query()->where('incidencia_id', $incidencia->id)->sole();
    expect($verificacion->resultado_conforme)->toBeFalse()
        ->and($verificacion->motivo_rechazo)->toBe('La filtración de agua persiste en la unión')
        ->and($verificacion->correccion_requerida)->toBe('Reemplazar el empaque y resellar la unión');

    $evento = EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('estado_nuevo', 'E13')->sole();
    expect($evento->estado_anterior)->toBe('E12')
        ->and($evento->motivo)->toBe('La filtración de agua persiste en la unión');
});

it('[KAN-271] conserva las verificaciones de cada ciclo de reproceso sin sobrescribirlas', function () {
    $incidencia = incidenciaPendienteVerificacion($this->tecnico);

    $this->comoRol($this->verificador, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/rechazar-verificacion", rechazoCompleto())
        ->assertOk();

    // Segundo ciclo: la corrección vuelve a quedar pendiente de verificación con su propia actividad y evidencia.
    $segundoCiclo = incidenciaPendienteVerificacion($this->tecnico);
    $segundoCiclo->actividades()->update(['incidencia_id' => $incidencia->id]);
    $segundoCiclo->evidencias()->getQuery()->toBase()->update(['incidencia_id' => $incidencia->id]);
    $incidencia->refresh()->forceFill(['estado_codigo' => EstadoIncidencia::PendienteVerificacion])->save();

    $this->comoRol($this->verificador, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/rechazar-verificacion", [
            'motivo_rechazo' => 'La unión sigue goteando después del resellado',
            'correccion_requerida' => 'Cambiar el tramo completo de tubería',
        ])
        ->assertOk();

    $verificaciones = Verificacion::query()->where('incidencia_id', $incidencia->id)->orderBy('id')->get();
    expect($verificaciones)->toHaveCount(2)
        ->and($verificaciones[0]->motivo_rechazo)->toBe('La filtración de agua persiste en la unión')
        ->and($verificaciones[1]->motivo_rechazo)->toBe('La unión sigue goteando después del resellado')
        ->and($incidencia->refresh()->numero_reprocesos)->toBe(2);
});

it('[KAN-271] la corrección programada (E13 -> E10) reaparece en la bandeja del técnico asignado (Escenario 3)', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::EnCorreccion)->asignadaA($this->tecnico)->categoria('CERRAJERIA')->create();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/planificar-correccion", ['fecha_programada' => now()->addDay()->toDateString(), 'hora_inicio' => '10:00', 'hora_fin' => '12:00'])
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', 'E10');

    $tareas = $this->comoRol($this->tecnico, Rol::Tecnico)->getJson('/api/v1/tecnico/tareas')->assertOk()->json();
    expect(json_encode($tareas))->toContain($incidencia->codigo_ticket);
});

it('[KAN-271] solo el coordinador planifica la corrección y solo desde E13', function () {
    $enCorreccion = Incidencia::factory()->enEstado(EstadoIncidencia::EnCorreccion)->asignadaA($this->tecnico)->create();
    $pendiente = incidenciaPendienteVerificacion($this->tecnico);
    $plan = ['fecha_programada' => now()->addDay()->toDateString(), 'hora_inicio' => '10:00', 'hora_fin' => '12:00'];

    $this->comoRol($this->verificador, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$enCorreccion->id}/planificar-correccion", $plan)
        ->assertForbidden();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$pendiente->id}/planificar-correccion", $plan)
        ->assertStatus(409)
        ->assertJsonPath('regla', 'RN-036');

    expect($enCorreccion->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnCorreccion)
        ->and($pendiente->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteVerificacion);
});

it('[KAN-271] en E13 ningún usuario puede cerrar el caso: confirmación, verificación y cierre administrativo se rechazan (Escenario 4)', function () {
    $reportante = $this->usuarioConRol(Rol::Reportante);
    $incidencia = Incidencia::factory()->for($reportante, 'creador')->enEstado(EstadoIncidencia::EnCorreccion)->asignadaA($this->tecnico)->create();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/cierre-administrativo")
        ->assertStatus(409)
        ->assertJsonPath('detail', 'No se puede cerrar la incidencia: existen correcciones obligatorias pendientes (RN-037).');

    $this->comoRol($reportante, Rol::Reportante)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/confirmar", ['calificacion' => 5])
        ->assertStatus(409);

    $this->comoRol($this->verificador, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/verificar", criteriosConformes())
        ->assertStatus(409);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnCorreccion)
        ->and($incidencia->fecha_cierre)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| KAN-276 - HU-9.3 Confirmación, cierre formal y cierre administrativo
|--------------------------------------------------------------------------
*/

it('[KAN-276] acepta las calificaciones límite 1 y 5 y rechaza 0 sin cerrar la incidencia', function (int $calificacion, bool $valida) {
    $reportante = $this->usuarioConRol(Rol::Reportante);
    $incidencia = Incidencia::factory()->for($reportante, 'creador')->enEstado(EstadoIncidencia::PendienteConfirmacion)->create();

    $respuesta = $this->comoRol($reportante, Rol::Reportante)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/confirmar", ['calificacion' => $calificacion, 'comentario' => 'Reparación concluida satisfactoriamente']);

    if ($valida) {
        $respuesta->assertOk();
        expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::Cerrada)
            ->and($incidencia->calificacion_servicio)->toBe($calificacion)
            ->and($incidencia->fecha_confirmacion)->not->toBeNull();
    } else {
        $respuesta->assertJsonValidationErrors('calificacion');
        expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteConfirmacion);
    }
})->with([
    'mínimo válido 1' => [1, true],
    'máximo válido 5' => [5, true],
    'cero inválido' => [0, false],
]);

it('[KAN-276] exige motivo al rechazar la solución y no la devuelve a corrección sin él (Escenario 2)', function () {
    $reportante = $this->usuarioConRol(Rol::Reportante);
    $incidencia = Incidencia::factory()->for($reportante, 'creador')->enEstado(EstadoIncidencia::PendienteConfirmacion)->create();

    $this->comoRol($reportante, Rol::Reportante)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/rechazar-conformidad", ['motivo' => ''])
        ->assertJsonValidationErrors(['motivo' => 'El motivo es obligatorio.']);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteConfirmacion);
});

it('[KAN-276] graba el rechazo del reportante en auditoría y alerta al coordinador (Escenario 2)', function () {
    $reportante = $this->usuarioConRol(Rol::Reportante);
    $incidencia = Incidencia::factory()->for($reportante, 'creador')->enEstado(EstadoIncidencia::PendienteConfirmacion)->create();

    $this->comoRol($reportante, Rol::Reportante)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/rechazar-conformidad", ['motivo' => 'El aire acondicionado sigue sin enfriar'])
        ->assertOk();

    $evento = EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('estado_nuevo', 'E13')->sole();
    expect($evento->estado_anterior)->toBe('E14')
        ->and($evento->motivo)->toBe('El aire acondicionado sigue sin enfriar')
        ->and($evento->usuario_id)->toBe($reportante->id)
        ->and(NotificacionPlataforma::query()->where('usuario_destino_id', $this->coordinador->id)->where('tipo', 'CONFORMIDAD_RECHAZADA')->exists())->toBeTrue();
});

it('[KAN-276] congela el expediente cerrado: no admite otra confirmación, rechazo ni cambio de prioridad (RN-042)', function () {
    $reportante = $this->usuarioConRol(Rol::Reportante);
    $incidencia = Incidencia::factory()->for($reportante, 'creador')->enEstado(EstadoIncidencia::PendienteConfirmacion)->prioridad('MEDIA')->create();

    $this->comoRol($reportante, Rol::Reportante)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/confirmar", ['calificacion' => 5, 'comentario' => 'Reparación concluida satisfactoriamente'])
        ->assertOk();
    $cerrada = $incidencia->refresh()->only(['estado_codigo', 'calificacion_servicio', 'prioridad_id', 'fecha_cierre']);

    $this->comoRol($reportante, Rol::Reportante)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/confirmar", ['calificacion' => 1, 'comentario' => 'Cambio de opinión'])
        ->assertStatus(409);
    $this->comoRol($reportante, Rol::Reportante)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/rechazar-conformidad", ['motivo' => 'Quiero reabrir por esta vía'])
        ->assertStatus(409);
    $this->comoRol($this->usuarioConRol(Rol::Gestor), Rol::Gestor)
        ->patchJson("/api/v1/incidencias/{$incidencia->id}/prioridad", ['impacto' => 'ALTO', 'urgencia' => 'ALTA', 'justificacion' => 'Cambio posterior al cierre del caso'])
        ->assertStatus(409);

    expect($incidencia->refresh()->only(['estado_codigo', 'calificacion_servicio', 'prioridad_id', 'fecha_cierre']))->toEqual($cerrada);
});

it('[KAN-276] habilita el cierre por inactividad solo después de 5 días hábiles completos en E14 (Escenario 3)', function () {
    // Horas locales de la zona operativa (jornada hábil 8:00-18:00). Lunes 23/11/2026, sin festivos hasta el 01/12/2026.
    $local = fn (string $fecha) => CarbonImmutable::parse($fecha, config('praxis.zona_horaria'))->utc();
    $this->travelTo($local('2026-11-23 10:00:00'));
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteConfirmacion)->create(['fecha_ultimo_cambio_estado' => now()]);

    $this->travelTo($local('2026-11-27 10:00:00')); // 4 días hábiles
    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/cierre-administrativo")
        ->assertUnprocessable()
        ->assertJsonPath('regla', 'HU-9.3');

    $this->travelTo($local('2026-11-30 10:00:00')); // exactamente 5 días hábiles: aún no "más de 5"
    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/cierre-administrativo")
        ->assertUnprocessable();

    $this->travelTo($local('2026-12-01 10:00:00')); // 6 días hábiles
    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/cierre-administrativo")
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', 'E15');

    $incidencia->refresh();
    expect($incidencia->es_cierre_administrativo)->toBeTrue()
        ->and($incidencia->fecha_cierre)->not->toBeNull()
        ->and(EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('estado_nuevo', 'E15')->value('usuario_id'))->toBe($this->coordinador->id);
});

it('[KAN-276] el reportante no puede ejecutar el cierre administrativo', function () {
    $reportante = $this->usuarioConRol(Rol::Reportante);
    $incidencia = Incidencia::factory()->for($reportante, 'creador')->enEstado(EstadoIncidencia::PendienteConfirmacion)->create(['fecha_ultimo_cambio_estado' => now()->subDays(30)]);

    $this->comoRol($reportante, Rol::Reportante)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/cierre-administrativo")
        ->assertForbidden();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteConfirmacion);
});

it('[KAN-276] rechaza forzar el cierre desde E11 con el mensaje de RN-037 (Escenario 4)', function () {
    $incidencia = incidenciaEnAtencion($this->tecnico);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/cierre-administrativo")
        ->assertStatus(409)
        ->assertJsonPath('detail', 'No se puede cerrar una incidencia con verificaciones técnicas obligatorias pendientes (RN-037).');

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnAtencion);
});
