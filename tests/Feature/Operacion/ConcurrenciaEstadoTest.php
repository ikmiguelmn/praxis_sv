<?php

use App\Enums\EstadoIncidencia;
use App\Enums\EtapaEvidencia;
use App\Enums\Rol;
use App\Exceptions\ReglaNegocioException;
use App\Exceptions\TransicionNoPermitidaException;
use App\Models\ActividadMantenimiento;
use App\Models\Diagnostico;
use App\Models\EventoAuditoria;
use App\Models\Evidencia;
use App\Models\Incidencia;
use App\Models\PlanIntervencion;
use App\Models\User;
use App\Models\Verificacion;
use App\Services\EjecucionService;
use App\Services\MaquinaEstados;
use App\Services\PlanificacionService;
use App\Services\VerificacionService;
use App\Support\ContextoRol;
use Illuminate\Support\Facades\DB;

it('rechaza con 409 una programación sobre un modelo obsoleto sin crear plan ni eventos de auditoría', function () {
    $coordinador = $this->usuarioConRol(Rol::Coordinador);
    $tecnico = User::factory()->tecnico('ELECTRICO')->create();
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::EnDiagnostico)->asignadaA($tecnico)->create();

    Diagnostico::create([
        'incidencia_id' => $incidencia->id,
        'tecnico_id' => $tecnico->id,
        'fecha_inicio_inspeccion' => now()->subHour(),
        'fecha_fin_diagnostico' => now(),
        'incidencia_confirmada' => true,
        'hallazgos' => 'Falla confirmada en el tablero eléctrico.',
        'descripcion_problema' => 'Breakers dañados.',
        'intervencion_propuesta' => 'Reemplazo completo de breakers.',
        'estado' => Diagnostico::REGISTRADO,
    ]);

    // Simula otra petición concurrente que ya cambió el estado en base de datos.
    DB::table('incidencias')->where('id', $incidencia->id)->update(['estado_codigo' => EstadoIncidencia::Suspendida->value]);

    app(ContextoRol::class)->establecer(Rol::Coordinador);

    try {
        app(PlanificacionService::class)->programar($incidencia, $coordinador, [
            'fecha_programada' => now()->addDay()->toDateString(),
            'hora_inicio' => '09:00',
            'hora_fin' => '11:00',
        ]);
        $this->fail('Debió lanzar una excepción de conflicto (409).');
    } catch (ReglaNegocioException $excepcion) {
        expect($excepcion->status)->toBe(409);
    }

    expect(PlanIntervencion::query()->where('incidencia_id', $incidencia->id)->count())->toBe(0)
        ->and(EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->count())->toBe(0)
        ->and($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::Suspendida);
});

it('rechaza con 409 iniciar inspección sobre un modelo obsoleto sin crear diagnóstico ni auditoría', function () {
    $tecnico = User::factory()->tecnico('ELECTRICO')->create();
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteInspeccion)->asignadaA($tecnico)->create();

    DB::table('incidencias')->where('id', $incidencia->id)->update(['estado_codigo' => EstadoIncidencia::Suspendida->value]);

    app(ContextoRol::class)->establecer(Rol::Tecnico);

    try {
        app(EjecucionService::class)->iniciarInspeccion($incidencia, $tecnico);
        $this->fail('Debió lanzar TransicionNoPermitidaException (409).');
    } catch (TransicionNoPermitidaException $excepcion) {
        expect($excepcion->status)->toBe(409);
    }

    expect(Diagnostico::query()->where('incidencia_id', $incidencia->id)->count())->toBe(0)
        ->and(EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->count())->toBe(0);
});

it('rechaza con 409 verificar una incidencia cuyo modelo quedó obsoleto sin crear verificación ni auditoría', function () {
    $verificador = $this->usuarioConRol(Rol::Verificador);
    $tecnico = User::factory()->tecnico('ELECTRICO')->create();
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::PendienteVerificacion)->asignadaA($tecnico)->create();

    ActividadMantenimiento::create([
        'incidencia_id' => $incidencia->id,
        'tecnico_ejecutor_id' => $tecnico->id,
        'fecha_inicio' => now()->subHours(2),
        'fecha_fin' => now()->subHour(),
        'tiempo_invertido_minutos' => 60,
        'actividades_realizadas' => 'Cambio de luminaria completo.',
    ]);

    Evidencia::create([
        'incidencia_id' => $incidencia->id,
        'etapa' => EtapaEvidencia::Ejecucion->value,
        'nombre_original' => 'evidencia.png',
        'disco' => 'local',
        'ruta_storage' => 'incidencias/'.$incidencia->id.'/evidencia.png',
        'mime_type' => 'image/png',
        'tamano_bytes' => 100,
        'hash_sha256' => hash('sha256', 'evidencia'),
        'usuario_carga_id' => $tecnico->id,
        'created_at' => now(),
    ]);

    DB::table('incidencias')->where('id', $incidencia->id)->update(['estado_codigo' => EstadoIncidencia::Cerrada->value]);

    app(ContextoRol::class)->establecer(Rol::Verificador);

    try {
        app(VerificacionService::class)->aprobar($incidencia, $verificador, ['observaciones' => 'Conforme']);
        $this->fail('Debió lanzar una excepción de conflicto (409).');
    } catch (ReglaNegocioException $excepcion) {
        expect($excepcion->status)->toBe(409);
    }

    expect(Verificacion::query()->where('incidencia_id', $incidencia->id)->count())->toBe(0)
        ->and(EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->count())->toBe(0);
});

it('lanza TransicionNoPermitidaException (409) en MaquinaEstados::transicionar si estado_codigo cambió en la base de datos', function () {
    $gestor = $this->usuarioConRol(Rol::Gestor);
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::Registrada)->create();

    DB::table('incidencias')->where('id', $incidencia->id)->update(['estado_codigo' => EstadoIncidencia::Cancelada->value]);

    app(ContextoRol::class)->establecer(Rol::Gestor);

    try {
        app(MaquinaEstados::class)->transicionar($incidencia, EstadoIncidencia::EnRevision, $gestor, 'Inicio de revisión.');
        $this->fail('Debió lanzar TransicionNoPermitidaException (409).');
    } catch (TransicionNoPermitidaException $excepcion) {
        expect($excepcion->status)->toBe(409);
    }

    expect(EventoAuditoria::query()->where('incidencia_id', $incidencia->id)->count())->toBe(0)
        ->and($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::Cancelada);
});
