<?php

use App\Enums\EstadoIncidencia;
use App\Enums\Rol;
use App\Models\ActividadMantenimiento;
use App\Models\Diagnostico;
use App\Models\EventoAuditoria;
use App\Models\Incidencia;
use App\Models\NotificacionPlataforma;
use App\Models\Suspension;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| QA Sprint 4: E07 Inspección, diagnóstico, ejecución y suspensión
| (KAN-231, KAN-236, KAN-241, KAN-246)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->tecnico = User::factory()->tecnico('CERRAJERIA')->create();
    $this->otroTecnico = User::factory()->tecnico('CERRAJERIA')->create();
    $this->coordinador = $this->usuarioConRol(Rol::Coordinador);
});

function s4EnInspeccion(User $tecnico): Incidencia
{
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteInspeccion)->categoria('CERRAJERIA')->asignadaA($tecnico)->create();
    test()->comoRol($tecnico, Rol::Tecnico)->postJson("/api/v1/incidencias/{$incidencia->id}/iniciar-inspeccion")->assertOk();

    return $incidencia->refresh();
}

function s4Diagnostico(array $sobrescribir = []): array
{
    return array_merge([
        'hallazgos' => 'Cilindro desgastado y pasador roto.',
        'descripcion_problema' => 'Desgaste del mecanismo por uso continuo.',
        'intervencion_propuesta' => 'Reemplazo del cilindro completo.',
    ], $sobrescribir);
}

// ---------------------------------------------------------------- KAN-231

it('[KAN-231] el técnico no asignado no puede registrar el diagnóstico', function () {
    $incidencia = s4EnInspeccion($this->tecnico);

    $this->comoRol($this->otroTecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/diagnostico", s4Diagnostico())
        ->assertForbidden()
        ->assertJsonPath('detail', 'Solo el técnico responsable asignado puede registrar la inspección o la intervención de esta incidencia.');

    expect(Diagnostico::query()->where('incidencia_id', $incidencia->id)->value('estado'))->toBe(Diagnostico::EN_INSPECCION);
});

it('[KAN-231] el diagnóstico solo se registra con la incidencia En diagnóstico (E07)', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteInspeccion)->categoria('CERRAJERIA')->asignadaA($this->tecnico)->create();

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/diagnostico", s4Diagnostico())
        ->assertStatus(409);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteInspeccion);
});

it('[KAN-231] el diagnóstico completo conserva hallazgos y propuesta y mantiene E07 para revisión', function () {
    $incidencia = s4EnInspeccion($this->tecnico);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/diagnostico", s4Diagnostico())
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', 'E07');

    $diagnostico = Diagnostico::query()->where('incidencia_id', $incidencia->id)->firstOrFail();
    expect($diagnostico->estado)->toBe(Diagnostico::REGISTRADO)
        ->and($diagnostico->incidencia_confirmada)->toBeTrue()
        ->and($diagnostico->hallazgos)->toBe('Cilindro desgastado y pasador roto.')
        ->and($diagnostico->intervencion_propuesta)->toBe('Reemplazo del cilindro completo.')
        ->and(EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('tipo_evento', 'DIAGNOSTICO')->where('usuario_id', $this->tecnico->id)->exists())->toBeTrue();
});

it('[KAN-231] rechaza hallazgos o propuesta con menos de 10 caracteres', function (string $campo) {
    $incidencia = s4EnInspeccion($this->tecnico);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/diagnostico", s4Diagnostico([$campo => 'corto']))
        ->assertJsonValidationErrors($campo);

    expect(Diagnostico::query()->where('incidencia_id', $incidencia->id)->value('estado'))->toBe(Diagnostico::EN_INSPECCION);
})->with(['hallazgos', 'descripcion_problema', 'intervencion_propuesta']);

it('[KAN-231] roles distintos de R04/R07 no pueden iniciar la inspección', function (Rol $rol) {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteInspeccion)->categoria('CERRAJERIA')->asignadaA($this->tecnico)->create();

    $this->comoRol($this->usuarioConRol($rol), $rol)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/iniciar-inspeccion")
        ->assertForbidden();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteInspeccion);
})->with([
    'reportante' => [Rol::Reportante],
    'coordinador' => [Rol::Coordinador],
    'autorizador' => [Rol::Autorizador],
]);

// ---------------------------------------------------------------- KAN-236

it('[KAN-236] el descarte exige hallazgos y una justificación de al menos 20 caracteres', function () {
    $incidencia = s4EnInspeccion($this->tecnico);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/descartar-por-inspeccion", [])
        ->assertJsonValidationErrors(['hallazgos', 'justificacion']);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/descartar-por-inspeccion", ['hallazgos' => 'Operación normal del elemento.', 'justificacion' => 'muy corta'])
        ->assertJsonValidationErrors('justificacion');

    expect(Diagnostico::query()->where('incidencia_id', $incidencia->id)->value('estado'))->toBe(Diagnostico::EN_INSPECCION);
});

it('[KAN-236] el técnico no asignado no puede descartar por inspección', function () {
    $incidencia = s4EnInspeccion($this->tecnico);
    $evidencias = $this->subirEvidencias($this->otroTecnico, Rol::Tecnico);

    $this->comoRol($this->otroTecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/descartar-por-inspeccion", [
            'hallazgos' => 'El tomacorriente funciona con normalidad.',
            'justificacion' => 'Se midió voltaje estable durante la visita técnica.',
            'evidencias' => $evidencias,
        ])
        ->assertForbidden();

    expect(Diagnostico::query()->where('incidencia_id', $incidencia->id)->value('incidencia_confirmada'))->not->toBeFalse();
});

it('[KAN-236] el descarte no acepta evidencias subidas por otro usuario', function () {
    $incidencia = s4EnInspeccion($this->tecnico);
    $ajenas = $this->subirEvidencias($this->otroTecnico, Rol::Tecnico);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/descartar-por-inspeccion", [
            'hallazgos' => 'El tomacorriente funciona con normalidad.',
            'justificacion' => 'Se midió voltaje estable durante la visita técnica.',
            'evidencias' => $ajenas,
        ])
        ->assertStatus(422);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnDiagnostico);
});

it('[KAN-236] el cierre por no confirmación notifica al reportante', function () {
    $reportante = $this->usuarioConRol(Rol::Reportante);
    $incidencia = Incidencia::factory()->for($reportante, 'creador')->enEstado(EstadoIncidencia::PendienteInspeccion)->categoria('CERRAJERIA')->asignadaA($this->tecnico)->create();
    $this->comoRol($this->tecnico, Rol::Tecnico)->postJson("/api/v1/incidencias/{$incidencia->id}/iniciar-inspeccion")->assertOk();
    $evidencias = $this->subirEvidencias($this->tecnico, Rol::Tecnico);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/descartar-por-inspeccion", [
            'hallazgos' => 'El tomacorriente funciona con normalidad.',
            'justificacion' => 'Se midió voltaje estable durante la visita técnica.',
            'evidencias' => $evidencias,
        ])
        ->assertOk();

    $this->comoRol($this->coordinador, Rol::Coordinador)->postJson("/api/v1/incidencias/{$incidencia->id}/cerrar-no-confirmada")->assertOk();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::Cerrada)
        ->and(NotificacionPlataforma::query()->where('usuario_destino_id', $reportante->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- KAN-241

it('[KAN-241] no inicia atención si la incidencia no está programada (E10)', function () {
    $incidencia = incidenciaConDiagnostico($this->tecnico);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/iniciar-atencion")
        ->assertStatus(409);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnDiagnostico)
        ->and(ActividadMantenimiento::query()->where('incidencia_id', $incidencia->id)->exists())->toBeFalse();
});

it('[KAN-241] el técnico no asignado no puede iniciar la atención', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::Programada)->asignadaA($this->tecnico)->create();

    $this->comoRol($this->otroTecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/iniciar-atencion")
        ->assertForbidden();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::Programada);
});

it('[KAN-241] valida tiempo y materiales con valores límite', function (array $cuerpo, string $campo) {
    $incidencia = incidenciaEnAtencion($this->tecnico);
    $evidencias = $this->subirEvidencias($this->tecnico, Rol::Tecnico);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/registrar-intervencion", $cuerpo + ['actividades_realizadas' => 'Se cambió la chapa del aula.', 'tiempo_invertido_minutos' => 30, 'evidencias' => $evidencias])
        ->assertJsonValidationErrors($campo);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnAtencion);
})->with([
    'tiempo cero' => [['tiempo_invertido_minutos' => 0], 'tiempo_invertido_minutos'],
    'tiempo negativo' => [['tiempo_invertido_minutos' => -5], 'tiempo_invertido_minutos'],
    'cantidad cero' => [['materiales' => [['nombre' => 'Chapa', 'cantidad' => 0, 'unidad' => 'und']]], 'materiales.0.cantidad'],
    'material sin unidad' => [['materiales' => [['nombre' => 'Chapa', 'cantidad' => 1]]], 'materiales.0.unidad'],
]);

it('[KAN-241] no vincula evidencias subidas por otro usuario a la intervención', function () {
    $incidencia = incidenciaEnAtencion($this->tecnico);
    $ajenas = $this->subirEvidencias($this->otroTecnico, Rol::Tecnico);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/registrar-intervencion", ['actividades_realizadas' => 'Se cambió la chapa del aula.', 'tiempo_invertido_minutos' => 30, 'evidencias' => $ajenas])
        ->assertStatus(422);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnAtencion);
});

it('[KAN-241] el técnico no asignado no puede registrar la intervención', function () {
    $incidencia = incidenciaEnAtencion($this->tecnico);
    $evidencias = $this->subirEvidencias($this->otroTecnico, Rol::Tecnico);

    $this->comoRol($this->otroTecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/registrar-intervencion", ['actividades_realizadas' => 'Se cambió la chapa del aula.', 'tiempo_invertido_minutos' => 30, 'evidencias' => $evidencias])
        ->assertForbidden();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnAtencion);
});

// ---------------------------------------------------------------- KAN-246

it('[KAN-246] la suspensión exige causal, motivo y condiciones de al menos 10 caracteres', function () {
    $incidencia = incidenciaEnAtencion($this->tecnico);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/suspender", [])
        ->assertJsonValidationErrors([
            'causal',
            'motivo_detallado' => 'Debe documentar la causa de la suspensión (RN-031).',
            'condiciones_reanudacion' => 'Debe indicar las condiciones requeridas para reanudar (D07).',
        ]);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/suspender", ['causal' => 'FUERZA_MAYOR', 'motivo_detallado' => 'corto', 'condiciones_reanudacion' => 'corto'])
        ->assertJsonValidationErrors(['motivo_detallado', 'condiciones_reanudacion']);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnAtencion)
        ->and(Suspension::query()->where('incidencia_id', $incidencia->id)->exists())->toBeFalse();
});

it('[KAN-246] roles sin permiso (R01, R05) no pueden suspender', function (Rol $rol) {
    $incidencia = incidenciaEnAtencion($this->tecnico);

    $this->comoRol($this->usuarioConRol($rol), $rol)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/suspender", [
            'causal' => 'FUERZA_MAYOR',
            'motivo_detallado' => 'Corte general de energía en el campus',
            'condiciones_reanudacion' => 'Restablecimiento del suministro eléctrico',
        ])
        ->assertForbidden();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnAtencion);
})->with([
    'reportante' => [Rol::Reportante],
    'autorizador' => [Rol::Autorizador],
]);

it('[KAN-246] la reanudación valida destino y observación', function () {
    $incidencia = incidenciaEnAtencion($this->tecnico);
    $this->comoRol($this->tecnico, Rol::Tecnico)->postJson("/api/v1/incidencias/{$incidencia->id}/suspender", [
        'causal' => 'FUERZA_MAYOR',
        'motivo_detallado' => 'Corte general de energía en el campus',
        'condiciones_reanudacion' => 'Restablecimiento del suministro eléctrico',
    ])->assertOk();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/reanudar", ['estado_destino' => 'E12', 'observacion' => 'Suministro restablecido por la empresa'])
        ->assertJsonValidationErrors('estado_destino');

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/reanudar", ['estado_destino' => 'E11', 'observacion' => 'corto'])
        ->assertJsonValidationErrors('observacion');

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::Suspendida);
});

it('[KAN-246] no reanuda una incidencia que no está suspendida', function () {
    $incidencia = incidenciaEnAtencion($this->tecnico);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/reanudar", ['estado_destino' => 'E11', 'observacion' => 'Intento de reanudar sin suspensión'])
        ->assertStatus(409);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnAtencion);
});

it('[KAN-246] la suspensión audita causa, condiciones y usuario', function () {
    $incidencia = incidenciaEnAtencion($this->tecnico);

    $this->comoRol($this->tecnico, Rol::Tecnico)->postJson("/api/v1/incidencias/{$incidencia->id}/suspender", [
        'causal' => 'FUERZA_MAYOR',
        'motivo_detallado' => 'Corte general de energía en el campus',
        'condiciones_reanudacion' => 'Restablecimiento del suministro eléctrico',
    ])->assertOk();

    $suspension = Suspension::query()->where('incidencia_id', $incidencia->id)->firstOrFail();
    expect($suspension->causal)->toBe('FUERZA_MAYOR')
        ->and($suspension->condiciones_reanudacion)->toBe('Restablecimiento del suministro eléctrico')
        ->and(EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('estado_nuevo', 'E18')->where('usuario_id', $this->tecnico->id)->exists())->toBeTrue();
});
