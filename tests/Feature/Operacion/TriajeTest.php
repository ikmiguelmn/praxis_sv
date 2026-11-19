<?php

use App\Enums\EstadoIncidencia;
use App\Enums\EstadoSolicitudRecursos;
use App\Enums\Rol;
use App\Models\Diagnostico;
use App\Models\EventoAuditoria;
use App\Models\HistorialCambioPrioridad;
use App\Models\Incidencia;
use App\Models\NotificacionPlataforma;
use App\Models\SolicitudRecursos;
use App\Models\User;
use App\Support\ResultadoCierre;

beforeEach(function () {
    $this->gestor = $this->usuarioConRol(Rol::Gestor);
});

it('inicia la revisión de una incidencia registrada (E01 -> E03) registrando gestor y fecha', function () {
    $incidencia = Incidencia::factory()->create();

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/iniciar-revision")
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', 'E03');

    $evento = EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('tipo_evento', 'INICIO_REVISION')->firstOrFail();
    expect($evento->usuario_id)->toBe($this->gestor->id)->and($evento->rol_codigo)->toBe('R02')->and($evento->estado_anterior)->toBe('E01');
});

it('rechaza transiciones fuera de la matriz D07 con 409 Problem Details', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteAsignacion)->create();

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/iniciar-revision")
        ->assertStatus(409)
        ->assertJsonPath('regla', 'D07')
        ->assertJsonPath('estado_actual', 'E05');
});

it('impide que roles no autorizados ejecuten el triaje', function () {
    $incidencia = Incidencia::factory()->create();

    $this->comoRol($this->usuarioConRol(Rol::Tecnico), Rol::Tecnico)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/iniciar-revision")
        ->assertForbidden();
});

it('calcula la prioridad con la matriz Impacto x Urgencia y remite a coordinación (E04 -> E05)', function () {
    $coordinador = $this->usuarioConRol(Rol::Coordinador);
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::EnClasificacion)->create();

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/clasificar", ['procedente' => true, 'impacto' => 'ALTO', 'urgencia' => 'ALTA'])
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', 'E05')
        ->assertJsonPath('data.prioridad.codigo', 'CRITICA');

    $incidencia->refresh();
    expect($incidencia->impacto)->toBe('ALTO')
        ->and($incidencia->fecha_limite_resolucion)->not->toBeNull()
        ->and(NotificacionPlataforma::query()->where('usuario_destino_id', $coordinador->id)->where('tipo', 'PENDIENTE_ASIGNACION')->exists())->toBeTrue();
});

it('bloquea la remisión sin impacto ni urgencia', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::EnClasificacion)->create();

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/clasificar", ['procedente' => true])
        ->assertJsonValidationErrors(['impacto' => 'Debe seleccionar el impacto institucional.', 'urgencia' => 'Debe seleccionar la urgencia operativa.']);
});

it('declara no procedente con justificación mínima de 20 caracteres (E16) y notifica al reportante', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::EnRevision)->create();

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/clasificar", ['procedente' => false, 'motivo' => 'Es de TI'])
        ->assertJsonValidationErrors(['motivo' => 'La justificación de no procedencia debe tener al menos 20 caracteres.']);

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/clasificar", ['procedente' => false, 'motivo' => 'El caso no corresponde a planta física; remitido a soporte de TI'])
        ->assertJsonPath('data.estado.codigo', 'E16');

    $incidencia->refresh();
    expect($incidencia->resultado_final_cierre)->toBe(ResultadoCierre::NO_PROCEDENTE)
        ->and(NotificacionPlataforma::query()->where('usuario_destino_id', $incidencia->usuario_creador_id)->where('tipo', 'NO_PROCEDENTE')->exists())->toBeTrue();
});

it('registra valor anterior, nuevo, autor y justificación en cada cambio de prioridad (RN-020)', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteAsignacion)->prioridad('MEDIA')->create();

    $this->comoRol($this->gestor, Rol::Gestor)
        ->patchJson("/api/v1/incidencias/{$incidencia->id}/prioridad", ['impacto' => 'ALTO', 'urgencia' => 'MEDIA', 'justificacion' => 'Afecta a todo el bloque de laboratorios'])
        ->assertOk()
        ->assertJsonPath('data.prioridad.codigo', 'ALTA');

    $historial = HistorialCambioPrioridad::query()->with(['prioridadAnterior', 'prioridadNueva'])->firstOrFail();
    expect($historial->prioridadAnterior->codigo)->toBe('MEDIA')
        ->and($historial->prioridadNueva->codigo)->toBe('ALTA')
        ->and($historial->usuario_id)->toBe($this->gestor->id)
        ->and($historial->justificacion)->toBe('Afecta a todo el bloque de laboratorios');
});

it('activa el protocolo de emergencia: exige acciones adoptadas, fija prioridad crítica y salta a la cola de asignación', function () {
    $coordinador = $this->usuarioConRol(Rol::Coordinador);
    $incidencia = Incidencia::factory()->create();

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/evaluacion-riesgo", ['tiene_riesgo_inmediato' => true, 'nivel_riesgo' => 'INMEDIATO'])
        ->assertJsonValidationErrors(['acciones_adoptadas' => 'Debe registrar las acciones preventivas o de contención adoptadas antes de activar el protocolo (RN-018).']);

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/evaluacion-riesgo", [
            'tiene_riesgo_inmediato' => true,
            'nivel_riesgo' => 'INMEDIATO',
            'acciones_adoptadas' => 'Acordonamiento de la zona y corte del suministro eléctrico.',
        ])
        ->assertOk()
        ->assertJsonPath('data.protocolo_activado', true)
        ->assertJsonPath('data.prioridad.codigo', 'CRITICA')
        ->assertJsonPath('data.estado.codigo', 'E05');

    expect($incidencia->refresh()->protocolo_emergencia_activo)->toBeTrue()
        ->and(NotificacionPlataforma::query()->where('usuario_destino_id', $coordinador->id)->where('tipo', 'RIESGO_INMEDIATO')->exists())->toBeTrue()
        ->and(EventoAuditoria::query()->where('tipo_evento', 'PROTOCOLO_EMERGENCIA_ACTIVADO')->where('usuario_id', $this->gestor->id)->exists())->toBeTrue();
});

it('registra la evaluación sin riesgo inmediato y la incidencia sigue su curso normal', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::EnRevision)->create();

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/evaluacion-riesgo", ['tiene_riesgo_inmediato' => false, 'nivel_riesgo' => 'BAJO'])
        ->assertJsonPath('data.protocolo_activado', false)
        ->assertJsonPath('data.estado.codigo', 'E03');
});

it('sugiere duplicados, vincula el reporte a la principal y lo cierra conservando su origen (RN-013, RN-014)', function () {
    $principal = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteAsignacion)->create();
    $nueva = Incidencia::factory()->enEstado(EstadoIncidencia::EnRevision)->create([
        'espacio_fisico_id' => $principal->espacio_fisico_id,
        'categoria_id' => $principal->categoria_id,
        'descripcion' => $principal->descripcion,
        'posible_duplicado' => true,
    ]);

    $this->comoRol($this->gestor, Rol::Gestor)
        ->getJson("/api/v1/incidencias/{$nueva->id}/posibles-duplicados")
        ->assertOk()
        ->assertJsonPath('data.0.id', $principal->id)
        ->assertJsonPath('data.0.criterios.misma_ubicacion', true);

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$nueva->id}/vincular-duplicado", ['incidencia_principal_id' => $principal->id, 'observaciones' => 'Mismo daño en la misma aula'])
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', 'E15');

    $nueva->refresh();
    expect($nueva->incidencia_principal_id)->toBe($principal->id)
        ->and($nueva->resultado_final_cierre)->toBe(ResultadoCierre::VINCULADA_POR_DUPLICIDAD)
        ->and($nueva->descripcion)->toBe($principal->descripcion)
        ->and($principal->seguidores()->whereKey($nueva->usuario_creador_id)->exists())->toBeTrue();
});

it('impide vincular una incidencia consigo misma o con una no procedente', function () {
    $nueva = Incidencia::factory()->enEstado(EstadoIncidencia::EnRevision)->create();
    $noProcedente = Incidencia::factory()->enEstado(EstadoIncidencia::NoProcedente)->create();

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$nueva->id}/vincular-duplicado", ['incidencia_principal_id' => $nueva->id])
        ->assertJsonValidationErrors('incidencia_principal_id');

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$nueva->id}/vincular-duplicado", ['incidencia_principal_id' => $noProcedente->id])
        ->assertJsonValidationErrors('incidencia_principal_id');

    expect($nueva->refresh()->estado_codigo)->toBe(EstadoIncidencia::EnRevision);
});

it('descarta la sugerencia de duplicado y continúa la revisión independiente', function () {
    $nueva = Incidencia::factory()->enEstado(EstadoIncidencia::EnRevision)->create(['posible_duplicado' => true]);

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$nueva->id}/descartar-duplicado", ['observaciones' => 'Son dos lámparas distintas'])
        ->assertOk();

    expect($nueva->refresh()->posible_duplicado)->toBeFalse();
});

it('desactiva el protocolo de emergencia con motivo, audita, notifica y vuelve a exigir autorización (RN-029)', function () {
    $coordinador = $this->usuarioConRol(Rol::Coordinador);
    $tecnico = User::factory()->tecnico('ELECTRICO')->create();
    $incidencia = Incidencia::factory()
        ->enEstado(EstadoIncidencia::EnDiagnostico)
        ->asignadaA($tecnico)
        ->prioridad('CRITICA')
        ->create(['es_riesgo_inmediato' => true, 'protocolo_emergencia_activo' => true]);

    $diagnostico = Diagnostico::create([
        'incidencia_id' => $incidencia->id,
        'tecnico_id' => $tecnico->id,
        'fecha_inicio_inspeccion' => now()->subHour(),
        'fecha_fin_diagnostico' => now(),
        'incidencia_confirmada' => true,
        'hallazgos' => 'Riesgo controlado tras corte eléctrico.',
        'descripcion_problema' => 'Requiere cambio de tablero.',
        'intervencion_propuesta' => 'Reemplazo de tablero.',
        'estado' => Diagnostico::REGISTRADO,
    ]);

    SolicitudRecursos::create([
        'incidencia_id' => $incidencia->id,
        'diagnostico_id' => $diagnostico->id,
        'solicitante_id' => $coordinador->id,
        'decision_requerida' => 'Aprobar compra de tablero.',
        'requiere_autorizacion' => true,
        'es_emergencia' => false,
        'estado_solicitud' => EstadoSolicitudRecursos::PendienteAutorizacion,
    ]);

    // Sin motivo -> 422
    $this->comoRol($coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/desactivar-protocolo", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');

    // Rol no permitido (R02 Gestor) -> 403
    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/desactivar-protocolo", [
            'motivo' => 'Intento de desactivación por rol no autorizado.',
        ])
        ->assertForbidden();

    // Desactivación válida por R03 -> 200
    $this->comoRol($coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/desactivar-protocolo", [
            'motivo' => 'Riesgo controlado y zona asegurada; continúa flujo normal.',
        ])
        ->assertOk()
        ->assertJsonPath('data.protocolo_emergencia_activo', false);

    $incidencia->refresh();
    expect($incidencia->protocolo_emergencia_activo)->toBeFalse()
        ->and($incidencia->prioridad->codigo)->toBe('CRITICA')
        ->and(EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->where('tipo_evento', 'PROTOCOLO_EMERGENCIA_DESACTIVADO')->exists())->toBeTrue()
        ->and(NotificacionPlataforma::query()->where('usuario_destino_id', $this->gestor->id)->where('tipo', 'PROTOCOLO_EMERGENCIA_DESACTIVADO')->exists())->toBeTrue()
        ->and(NotificacionPlataforma::query()->where('usuario_destino_id', $coordinador->id)->where('tipo', 'PROTOCOLO_EMERGENCIA_DESACTIVADO')->exists())->toBeTrue();

    // Si ya no está activo -> 409
    $this->comoRol($coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/desactivar-protocolo", [
            'motivo' => 'Segundo intento cuando ya fue desactivado.',
        ])
        ->assertStatus(409);

    // Tras desactivar, la solicitud de recursos pendiente vuelve a bloquear la programación (RN-029)
    $this->comoRol($coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/programar", [
            'fecha_programada' => now()->addDay()->toDateString(),
            'hora_inicio' => '09:00',
            'hora_fin' => '11:00',
        ])
        ->assertStatus(409)
        ->assertJsonPath('regla', 'RN-029');
});

it('desactiva automáticamente el protocolo de emergencia al llegar a un estado terminal', function () {
    $coordinador = $this->usuarioConRol(Rol::Coordinador);
    $incidencia = Incidencia::factory()
        ->enEstado(EstadoIncidencia::PendienteRecursos)
        ->prioridad('CRITICA')
        ->create(['es_riesgo_inmediato' => true, 'protocolo_emergencia_activo' => true]);

    $this->comoRol($coordinador, Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/anular", [
            'motivo' => 'Se determinó reemplazo total del edificio fuera de mantenimiento.',
        ])
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', 'E17');

    $evento = EventoAuditoria::query()
        ->where('incidencia_id', $incidencia->id)
        ->where('tipo_evento', 'CANCELACION_ADMINISTRATIVA')
        ->firstOrFail();

    expect($incidencia->refresh()->protocolo_emergencia_activo)->toBeFalse()
        ->and($evento->datos['protocolo_emergencia_desactivado'] ?? null)->toBeTrue();
});
