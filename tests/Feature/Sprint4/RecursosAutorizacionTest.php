<?php

use App\Enums\EstadoIncidencia;
use App\Enums\EstadoSolicitudRecursos;
use App\Enums\Rol;
use App\Models\EventoAuditoria;
use App\Models\Incidencia;
use App\Models\SolicitudRecursos;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| QA Sprint 4: E08 Recursos y autorizaciones (KAN-251, KAN-256, KAN-261)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->tecnico = User::factory()->tecnico('CERRAJERIA')->create();
    $this->coordinador = $this->usuarioConRol(Rol::Coordinador);
    $this->autorizador = $this->usuarioConRol(Rol::Autorizador);
});

function s4Solicitud(bool $requiereAutorizacion, float $costo = 350000, array $sobrescribir = []): array
{
    return array_merge([
        'decision_requerida' => 'Compra de cerradura de alta seguridad fuera de caja menor',
        'requiere_autorizacion' => $requiereAutorizacion,
        'items' => [['recurso_nombre' => 'Cerradura multipunto', 'cantidad' => 1, 'unidad' => 'und', 'costo_estimado' => $costo]],
    ], $sobrescribir);
}

function s4CrearSolicitud(bool $requiereAutorizacion, User $tecnico, User $coordinador, float $costo = 350000): array
{
    $incidencia = incidenciaConDiagnostico($tecnico);
    $id = test()->comoRol($coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/solicitudes-recursos", s4Solicitud($requiereAutorizacion, $costo))
        ->assertCreated()
        ->json('data.id');

    return [$incidencia->refresh(), $id];
}

// ---------------------------------------------------------------- KAN-251

it('[KAN-251] valida cada campo obligatorio de la solicitud con valores límite', function (array $cambio, string $campo) {
    $incidencia = incidenciaConDiagnostico($this->tecnico);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/solicitudes-recursos", array_replace_recursive(s4Solicitud(true), $cambio))
        ->assertJsonValidationErrors($campo);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnDiagnostico)
        ->and(SolicitudRecursos::query()->where('incidencia_id', $incidencia->id)->exists())->toBeFalse();
})->with([
    'decisión corta' => [['decision_requerida' => 'corta'], 'decision_requerida'],
    'cantidad cero' => [['items' => [['cantidad' => 0]]], 'items.0.cantidad'],
    'cantidad negativa' => [['items' => [['cantidad' => -1]]], 'items.0.cantidad'],
    'costo negativo' => [['items' => [['costo_estimado' => -10]]], 'items.0.costo_estimado'],
    'recurso sin nombre' => [['items' => [['recurso_nombre' => '']]], 'items.0.recurso_nombre'],
]);

it('[KAN-251] solo R03/R07 registran la solicitud de recursos', function (Rol $rol) {
    $incidencia = incidenciaConDiagnostico($this->tecnico);
    $usuario = $rol === Rol::Tecnico ? $this->tecnico : $this->usuarioConRol($rol);

    $this->comoRol($usuario, $rol)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/solicitudes-recursos", s4Solicitud(true))
        ->assertForbidden();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnDiagnostico);
})->with([
    'reportante' => [Rol::Reportante],
    'técnico' => [Rol::Tecnico],
    'autorizador' => [Rol::Autorizador],
]);

it('[KAN-251] exige incidencia En diagnóstico (E07) para solicitar recursos', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteInspeccion)->categoria('CERRAJERIA')->asignadaA($this->tecnico)->create();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/solicitudes-recursos", s4Solicitud(true))
        ->assertStatus(409);
});

it('[KAN-251] no se inicia la atención mientras la autorización siga pendiente (RN-029)', function () {
    [$incidencia] = s4CrearSolicitud(true, $this->tecnico, $this->coordinador);

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/iniciar-atencion")
        ->assertStatus(409);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteAutorizacion);
});

it('[KAN-251] la emergencia sin protocolo de riesgo se rechaza y no crea solicitud', function () {
    $incidencia = incidenciaConDiagnostico($this->tecnico);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/solicitudes-recursos", s4Solicitud(true, 100000, ['es_emergencia' => true]))
        ->assertUnprocessable();

    expect(SolicitudRecursos::query()->where('incidencia_id', $incidencia->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------- KAN-256

it('[KAN-256] el rechazo exige justificación y acción de rechazo', function () {
    [$incidencia, $solicitud] = s4CrearSolicitud(true, $this->tecnico, $this->coordinador);

    $this->comoRol($this->autorizador, Rol::Autorizador)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitud}/dictamen", ['resultado' => 'RECHAZADA'])
        ->assertJsonValidationErrors([
            'justificacion' => 'Debe documentar el motivo de la decisión (RN-028).',
            'accion_rechazo',
        ]);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteAutorizacion);
});

it('[KAN-256] el rechazo con suspensión exige condiciones de reanudación', function () {
    [$incidencia, $solicitud] = s4CrearSolicitud(true, $this->tecnico, $this->coordinador);

    $this->comoRol($this->autorizador, Rol::Autorizador)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitud}/dictamen", [
            'resultado' => 'RECHAZADA',
            'accion_rechazo' => 'SUSPENDER',
            'justificacion' => 'Inviable presupuestalmente en el periodo actual',
        ])
        ->assertJsonValidationErrors('condiciones_reanudacion');

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteAutorizacion);
});

it('[KAN-256] el dictamen rechaza resultados fuera de APROBADA/RECHAZADA', function () {
    [, $solicitud] = s4CrearSolicitud(true, $this->tecnico, $this->coordinador);

    $this->comoRol($this->autorizador, Rol::Autorizador)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitud}/dictamen", ['resultado' => 'TAL_VEZ'])
        ->assertJsonValidationErrors('resultado');
});

it('[KAN-256] el dictamen no lo emiten reportante ni técnico', function (Rol $rol) {
    [$incidencia, $solicitud] = s4CrearSolicitud(true, $this->tecnico, $this->coordinador);
    $usuario = $rol === Rol::Tecnico ? $this->tecnico : $this->usuarioConRol($rol);

    $this->comoRol($usuario, $rol)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitud}/dictamen", ['resultado' => 'APROBADA', 'recursos_disponibles' => true])
        ->assertForbidden();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteAutorizacion)
        ->and(SolicitudRecursos::query()->find($solicitud)->estado_solicitud)->toBe(EstadoSolicitudRecursos::PendienteAutorizacion);
})->with([
    'reportante' => [Rol::Reportante],
    'técnico' => [Rol::Tecnico],
]);

it('[KAN-256] un dictamen sobre una solicitud inexistente responde 404', function () {
    $this->comoRol($this->autorizador, Rol::Autorizador)
        ->postJson('/api/v1/solicitudes-recursos/999999/dictamen', ['resultado' => 'APROBADA'])
        ->assertNotFound();
});

it('[KAN-256] no se dictamina dos veces la misma solicitud', function () {
    [, $solicitud] = s4CrearSolicitud(true, $this->tecnico, $this->coordinador);

    $this->comoRol($this->autorizador, Rol::Autorizador)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitud}/dictamen", ['resultado' => 'APROBADA', 'recursos_disponibles' => true])
        ->assertOk();

    $this->comoRol($this->autorizador, Rol::Autorizador)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitud}/dictamen", ['resultado' => 'RECHAZADA', 'accion_rechazo' => 'CANCELAR', 'justificacion' => 'Intento de cambiar el dictamen'])
        ->assertStatus(409);
});

it('[KAN-256] el dictamen deja evento de auditoría con el autorizador y el motivo', function () {
    [$incidencia, $solicitud] = s4CrearSolicitud(true, $this->tecnico, $this->coordinador);

    $this->comoRol($this->autorizador, Rol::Autorizador)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitud}/dictamen", [
            'resultado' => 'RECHAZADA',
            'accion_rechazo' => 'DEVOLVER_DIAGNOSTICO',
            'justificacion' => 'El presupuesto no detalla el costo por unidad',
        ])
        ->assertOk();

    $evento = EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('tipo_evento', 'AUTORIZACION')->firstOrFail();
    expect($evento->usuario_id)->toBe($this->autorizador->id)
        ->and($evento->motivo)->toBe('El presupuesto no detalla el costo por unidad');
});

// ---------------------------------------------------------------- KAN-261

it('[KAN-261] confirmar sin detalles disponibles se rechaza y mantiene E09', function () {
    [$incidencia, $solicitud] = s4CrearSolicitud(false, $this->tecnico, $this->coordinador);
    $plan = ['fecha_programada' => now()->addDays(4)->toDateString(), 'hora_inicio' => '08:00', 'hora_fin' => '10:00'];

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->patchJson("/api/v1/incidencias/{$incidencia->id}/confirmar-recursos", $plan + ['detalles_disponibles' => []])
        ->assertJsonValidationErrors('detalles_disponibles');

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteRecursos)
        ->and(SolicitudRecursos::query()->find($solicitud)->estado_solicitud)->not->toBe(EstadoSolicitudRecursos::Disponible);
});

it('[KAN-261] no confirma recursos si la incidencia no está en E09', function () {
    [$incidencia] = s4CrearSolicitud(true, $this->tecnico, $this->coordinador);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->patchJson("/api/v1/incidencias/{$incidencia->id}/confirmar-recursos", [
            'fecha_programada' => now()->addDays(4)->toDateString(),
            'hora_inicio' => '08:00',
            'hora_fin' => '10:00',
            'todos_disponibles' => true,
        ])
        ->assertStatus(409);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteAutorizacion);
});

it('[KAN-261] el seguimiento exige un comentario de al menos 5 caracteres y rol R03/R07', function () {
    [, $solicitud] = s4CrearSolicitud(false, $this->tecnico, $this->coordinador);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitud}/seguimientos", ['comentario' => 'ok'])
        ->assertJsonValidationErrors('comentario');

    $this->comoRol($this->tecnico, Rol::Tecnico)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitud}/seguimientos", ['comentario' => 'Seguimiento de prueba válido'])
        ->assertForbidden();
});

it('[KAN-261] anular desde E09 exige motivo y lleva a Cancelada (E17)', function () {
    [$incidencia] = s4CrearSolicitud(false, $this->tecnico, $this->coordinador);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/anular", [])
        ->assertJsonValidationErrors('motivo');
    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteRecursos);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/anular", ['motivo' => 'El proveedor no puede abastecer el insumo requerido'])
        ->assertOk();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::Cancelada);
});

it('[KAN-261] el seguimiento se rechaza si la solicitud ya no está pendiente', function () {
    [$incidencia, $solicitud] = s4CrearSolicitud(false, $this->tecnico, $this->coordinador);
    $this->comoRol($this->coordinador, Rol::Coordinador)->patchJson("/api/v1/incidencias/{$incidencia->id}/confirmar-recursos", [
        'fecha_programada' => now()->addDays(4)->toDateString(),
        'hora_inicio' => '08:00',
        'hora_fin' => '10:00',
        'todos_disponibles' => true,
    ])->assertOk();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitud}/seguimientos", ['comentario' => 'Nota posterior a la confirmación'])
        ->assertStatus(409);
});
