<?php

use App\Enums\EstadoIncidencia;
use App\Enums\Rol;
use App\Models\AsignacionIncidencia;
use App\Models\CategoriaIncidencia;
use App\Models\EventoAuditoria;
use App\Models\Incidencia;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| QA Sprint 4: E06 Planificación y asignación (KAN-216, KAN-221, KAN-226)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->coordinador = $this->usuarioConRol(Rol::Coordinador);
    $this->cerrajero = User::factory()->tecnico('CERRAJERIA')->create();
});

function s4PorAsignar(string $prioridad = 'MEDIA'): Incidencia
{
    return Incidencia::factory()->enEstado(EstadoIncidencia::PendienteAsignacion)->categoria('CERRAJERIA')->prioridad($prioridad)->create();
}

function s4CargaTecnico(User $tecnico, int $cantidad): void
{
    Incidencia::factory()->count($cantidad)->enEstado(EstadoIncidencia::PendienteInspeccion)->asignadaA($tecnico)->create();
}

function s4Franja(int $dias = 2): array
{
    return ['fecha_programada' => now()->addDays($dias)->toDateString(), 'hora_inicio' => '08:00', 'hora_fin' => '11:00'];
}

// ---------------------------------------------------------------- KAN-216

it('[KAN-216] rechaza asignar a roles distintos de R03/R07 sin cambiar el estado', function (Rol $rol) {
    $incidencia = s4PorAsignar();
    $usuario = $rol === Rol::Tecnico ? $this->cerrajero : $this->usuarioConRol($rol);

    $this->comoRol($usuario, $rol)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/asignar", ['tecnico_id' => $this->cerrajero->id])
        ->assertForbidden();

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteAsignacion)
        ->and($incidencia->tecnico_asignado_id)->toBeNull();
})->with([
    'reportante R01' => [Rol::Reportante],
    'técnico R04' => [Rol::Tecnico],
    'autorizador R05' => [Rol::Autorizador],
]);

it('[KAN-216] no asigna una incidencia que no está en Pendiente de asignación (E05)', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::EnDiagnostico)->categoria('CERRAJERIA')->create(['tecnico_asignado_id' => null]);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/asignar", ['tecnico_id' => $this->cerrajero->id])
        ->assertStatus(409);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnDiagnostico);
});

it('[KAN-216] audita técnico, coordinador e instrucciones iniciales de la asignación (D10)', function () {
    $incidencia = s4PorAsignar();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/asignar", ['tecnico_id' => $this->cerrajero->id, 'instrucciones' => 'Llevar juego de cilindros'])
        ->assertOk();

    $evento = EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('tipo_evento', 'ASIGNACION')->firstOrFail();
    $asignacion = AsignacionIncidencia::query()->where('incidencia_id', $incidencia->id)->where('es_vigente', true)->firstOrFail();

    expect($evento->usuario_id)->toBe($this->coordinador->id)
        ->and($evento->estado_anterior)->toBe('E05')
        ->and($evento->estado_nuevo)->toBe('E06')
        ->and($evento->datos['tecnico_id'])->toBe($this->cerrajero->id)
        ->and($asignacion->instrucciones)->toBe('Llevar juego de cilindros')
        ->and($asignacion->created_at)->not->toBeNull();
});

it('[KAN-216] rechaza una fecha de visita tentativa en el pasado', function () {
    $incidencia = s4PorAsignar();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/asignar", ['tecnico_id' => $this->cerrajero->id, 'fecha_visita_tentativa' => now()->subDays(2)->toDateString()])
        ->assertJsonValidationErrors('fecha_visita_tentativa');

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteAsignacion);
});

// ---------------------------------------------------------------- KAN-221

it('[KAN-221] el límite WIP es exacto: con 4 casos activos asigna y con 5 bloquea', function () {
    $conCuatro = User::factory()->tecnico('CERRAJERIA')->create();
    s4CargaTecnico($conCuatro, 4);
    $permitida = s4PorAsignar();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$permitida->id}/asignar", ['tecnico_id' => $conCuatro->id])
        ->assertOk()
        ->assertJsonPath('data.excepcion_wip', false);

    $bloqueada = s4PorAsignar();
    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$bloqueada->id}/asignar", ['tecnico_id' => $conCuatro->id])
        ->assertUnprocessable()
        ->assertJsonPath('detail', 'El técnico ha alcanzado el límite de 5 órdenes activas. Seleccione otro especialista disponible o espere liberación de carga');

    expect($bloqueada->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteAsignacion);
});

it('[KAN-221] la lista de disponibles marca como no disponible al técnico saturado', function () {
    s4CargaTecnico($this->cerrajero, 5);
    $categoria = CategoriaIncidencia::query()->where('codigo', 'CERRAJERIA')->value('id');

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson("/api/v1/tecnicos/disponibles?categoria_id={$categoria}")
        ->assertOk()
        ->assertJsonPath('data.0.carga_actual', 5)
        ->assertJsonPath('data.0.disponible', false);
});

it('[KAN-221] la excepción WIP no aplica a prioridades no críticas', function () {
    s4CargaTecnico($this->cerrajero, 5);
    $alta = s4PorAsignar('ALTA');

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$alta->id}/asignar", ['tecnico_id' => $this->cerrajero->id])
        ->assertUnprocessable();

    expect(EventoAuditoria::query()->where('tipo_evento', 'EXCEPCION_WIP')->exists())->toBeFalse();
});

it('[KAN-221] la reasignación conserva el historial y deja una sola asignación vigente', function () {
    $nuevo = User::factory()->tecnico('CERRAJERIA')->create();
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteInspeccion)->categoria('CERRAJERIA')->asignadaA($this->cerrajero)->create();
    AsignacionIncidencia::create([
        'incidencia_id' => $incidencia->id,
        'tecnico_asignado_id' => $this->cerrajero->id,
        'asignado_por_id' => $this->coordinador->id,
        'es_vigente' => true,
        'excepcion_wip' => false,
        'created_at' => now(),
    ]);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/asignar", ['tecnico_id' => $nuevo->id, 'motivo_reasignacion' => 'Incapacidad médica del titular'])
        ->assertOk();

    $asignaciones = AsignacionIncidencia::query()->where('incidencia_id', $incidencia->id)->get();
    expect($asignaciones)->toHaveCount(2)
        ->and($asignaciones->where('es_vigente', true))->toHaveCount(1)
        ->and($asignaciones->firstWhere('es_vigente', true)->tecnico_asignado_id)->toBe($nuevo->id)
        ->and($asignaciones->firstWhere('es_vigente', false)->tecnico_asignado_id)->toBe($this->cerrajero->id);
});

it('[KAN-221] no permite reasignar al mismo técnico responsable', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteInspeccion)->categoria('CERRAJERIA')->asignadaA($this->cerrajero)->create();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/asignar", ['tecnico_id' => $this->cerrajero->id, 'motivo_reasignacion' => 'Intento de relevo sin cambio'])
        ->assertJsonValidationErrors(['tecnico_id' => 'El técnico seleccionado ya es el responsable de la incidencia.']);
});

// ---------------------------------------------------------------- KAN-226

it('[KAN-226] rechaza una franja con hora de fin anterior o igual al inicio', function (string $inicio, string $fin) {
    $tecnico = $this->cerrajero;
    $incidencia = incidenciaConDiagnostico($tecnico);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/programar", ['fecha_programada' => now()->addDay()->toDateString(), 'hora_inicio' => $inicio, 'hora_fin' => $fin])
        ->assertJsonValidationErrors('hora_fin');

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnDiagnostico);
})->with([
    'fin anterior' => ['10:00', '09:00'],
    'fin igual' => ['10:00', '10:00'],
]);

it('[KAN-226] rechaza programar con fecha pasada', function () {
    $incidencia = incidenciaConDiagnostico($this->cerrajero);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/programar", ['fecha_programada' => now()->subDay()->toDateString(), 'hora_inicio' => '08:00', 'hora_fin' => '10:00'])
        ->assertJsonValidationErrors('fecha_programada');
});

it('[KAN-226] programar, devolver y cerrar por no confirmación exigen R03/R07', function (string $ruta, array $cuerpo) {
    $incidencia = incidenciaConDiagnostico($this->cerrajero);

    foreach ([[Rol::Tecnico, $this->cerrajero], [Rol::Reportante, $this->usuarioConRol(Rol::Reportante)]] as [$rol, $usuario]) {
        $this->comoRol($usuario, $rol)->postJson("/api/v1/incidencias/{$incidencia->id}/{$ruta}", $cuerpo)->assertForbidden();
    }

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnDiagnostico);
})->with([
    'programar' => ['programar', fn () => s4Franja()],
    'devolver-diagnostico' => ['devolver-diagnostico', ['observaciones' => 'Detalle el calibre de la tubería afectada']],
]);

it('[KAN-226] la devolución con observaciones demasiado cortas se rechaza y conserva E07', function () {
    $incidencia = incidenciaConDiagnostico($this->cerrajero);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/devolver-diagnostico", ['observaciones' => 'corto'])
        ->assertJsonValidationErrors('observaciones');

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/devolver-diagnostico", [])
        ->assertJsonValidationErrors(['observaciones' => 'Las observaciones son obligatorias.']);

    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnDiagnostico);
});

it('[KAN-226] reprogramar exige un motivo de al menos 10 caracteres', function () {
    $incidencia = incidenciaConDiagnostico($this->cerrajero);
    $this->comoRol($this->coordinador, Rol::Coordinador)->postJson("/api/v1/incidencias/{$incidencia->id}/programar", s4Franja())->assertOk();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->patchJson("/api/v1/incidencias/{$incidencia->id}/reprogramar", s4Franja(4) + ['motivo' => 'corto'])
        ->assertJsonValidationErrors('motivo');

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->patchJson("/api/v1/incidencias/{$incidencia->id}/reprogramar", s4Franja(4))
        ->assertJsonValidationErrors('motivo');

    expect(EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('tipo_evento', 'REPROGRAMACION')->exists())->toBeFalse();
});

it('[KAN-226] no vuelve a programar una incidencia ya programada', function () {
    $incidencia = incidenciaConDiagnostico($this->cerrajero);
    $this->comoRol($this->coordinador, Rol::Coordinador)->postJson("/api/v1/incidencias/{$incidencia->id}/programar", s4Franja())->assertOk();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/programar", s4Franja(5))
        ->assertStatus(409);
});
