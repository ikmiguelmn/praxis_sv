<?php

use App\Enums\EstadoIncidencia;
use App\Enums\Rol;
use App\Models\EspacioFisico;
use App\Models\Incidencia;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * QA Sprint 5 - E10 Trazabilidad y consulta.
 * KAN-301 (HU-10.1), KAN-306 (HU-10.2), KAN-311 (HU-10.3) y KAN-316 (HU-10.4).
 * Complementa tests/Feature/Transversal/ConsolaYSlaTest.php, AnaliticaTest.php y
 * tests/Feature/Base/AuditoriaInmutableTest.php.
 */
beforeEach(function () {
    $this->coordinador = $this->usuarioConRol(Rol::Coordinador);
    $this->gestor = $this->usuarioConRol(Rol::Gestor);
});

/** Instante en la zona horaria operativa (la jornada hábil se calcula ahí). */
function horaLocal(string $fecha): CarbonImmutable
{
    return CarbonImmutable::parse($fecha, config('praxis.zona_horaria'))->utc();
}

/*
|--------------------------------------------------------------------------
| KAN-301 - HU-10.1 Consola general con filtros combinados
|--------------------------------------------------------------------------
*/

it('[KAN-301] ordena la consola por fecha de reporte descendente por defecto (Escenario 1)', function () {
    $antigua = Incidencia::factory()->create(['created_at' => now()->subDays(3)]);
    $reciente = Incidencia::factory()->create(['created_at' => now()->subHour()]);
    $intermedia = Incidencia::factory()->create(['created_at' => now()->subDay()]);

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson('/api/v1/consola/incidencias')
        ->assertOk()
        ->assertJsonPath('data.0.id', $reciente->id)
        ->assertJsonPath('data.1.id', $intermedia->id)
        ->assertJsonPath('data.2.id', $antigua->id);
});

it('[KAN-301] expone las columnas operativas de cada fila (Escenario 1)', function () {
    $tecnico = User::factory()->tecnico('CERRAJERIA')->create(['name' => 'Técnico Consola']);
    Incidencia::factory()->enEstado(EstadoIncidencia::EnAtencion)->asignadaA($tecnico)->prioridad('ALTA')->categoria('CERRAJERIA')->create();

    $fila = $this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson('/api/v1/consola/incidencias')
        ->assertOk()
        ->json('data.0');

    expect($fila['codigo_ticket'])->toMatch('/^TKT-\d{4}-\d{6}$/')
        ->and($fila['estado']['codigo'])->toBe('E11')
        ->and($fila['prioridad']['codigo'])->toBe('ALTA')
        ->and($fila['categoria']['codigo'])->toBe('CERRAJERIA')
        ->and($fila['ubicacion'])->not->toBeNull()
        ->and($fila['tecnico_asignado']['name'])->toBe('Técnico Consola')
        ->and($fila['fecha_registro'])->not->toBeNull()
        ->and($fila)->toHaveKey('fecha_cierre')
        ->and($fila['tiempo_atencion']['en_curso'])->toBeTrue();
});

it('[KAN-301] pagina en servidor con 10, 25 y 50 registros', function (int $porPagina) {
    Incidencia::factory()->count(55)->create();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson("/api/v1/consola/incidencias?per_page={$porPagina}")
        ->assertOk()
        ->assertJsonCount($porPagina, 'data')
        ->assertJsonPath('meta.per_page', $porPagina)
        ->assertJsonPath('meta.total', 55);
})->with([10, 25, 50]);

it('[KAN-301] busca por código de radicado exacto (Escenario 3)', function () {
    $buscada = Incidencia::factory()->create();
    Incidencia::factory()->count(3)->create();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson('/api/v1/consola/incidencias?q='.$buscada->codigo_ticket)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $buscada->id);
});

it('[KAN-301] permite la consola a gestor, coordinador y verificador', function (Rol $rol) {
    Incidencia::factory()->count(2)->create();

    $this->comoRol($this->usuarioConRol($rol), $rol)
        ->getJson('/api/v1/consola/incidencias')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
})->with([
    'gestor (R02)' => [Rol::Gestor],
    'coordinador (R03)' => [Rol::Coordinador],
    'verificador (R06)' => [Rol::Verificador],
]);

it('[KAN-301] rechaza con 403 y sin datos al reportante que invoca la consola directamente (Escenario 5)', function () {
    Incidencia::factory()->count(2)->create();

    $respuesta = $this->comoRol($this->usuarioConRol(Rol::Reportante), Rol::Reportante)
        ->getJson('/api/v1/consola/incidencias')
        ->assertForbidden();

    expect($respuesta->json())->not->toHaveKey('data');
});

/*
|--------------------------------------------------------------------------
| KAN-306 - HU-10.2 Auditoría inmutable y línea de tiempo
|--------------------------------------------------------------------------
*/

it('[KAN-306] registra la transición con los campos de D10 y el motivo íntegro (Escenarios 1 y 2)', function () {
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::EnRevision)->create();
    $pregunta = 'Indique el número exacto del aula y adjunte una foto del daño.';

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/solicitar-aclaracion", ['informacion_requerida' => $pregunta])
        ->assertOk();

    $eventos = collect($this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson("/api/v1/incidencias/{$incidencia->id}/historial")
        ->assertOk()
        ->assertJsonPath('alcance', 'COMPLETO')
        ->json('data'));

    $transicion = $eventos->firstWhere('estado_nuevo.codigo', 'E02');
    expect($transicion)->not->toBeNull()
        ->and($transicion['id'])->not->toBeNull()
        ->and($transicion['incidencia_id'])->toBe($incidencia->id)
        ->and($transicion['tipo_evento'])->not->toBeEmpty()
        ->and($transicion['fecha_hora'])->not->toBeNull()
        ->and($transicion['usuario']['id'])->toBe($this->gestor->id)
        ->and($transicion['rol'])->toBe('R02')
        ->and($transicion['estado_anterior']['codigo'])->toBe('E03')
        ->and($transicion['motivo'])->toContain($pregunta)
        ->and($transicion)->toHaveKey('evidencias');
});

it('[KAN-306] presenta los eventos en orden cronológico', function () {
    $reportante = $this->usuarioConRol(Rol::Reportante);
    $incidencia = Incidencia::factory()->for($reportante, 'creador')->enEstado(EstadoIncidencia::EnRevision)->create();

    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/solicitar-aclaracion", ['informacion_requerida' => 'Indique el número exacto del aula.'])
        ->assertOk();
    $this->travel(5)->minutes();
    $this->comoRol($reportante, Rol::Reportante)
        ->postJson("/api/v1/mis-solicitudes/{$incidencia->id}/aclaraciones", ['respuesta' => 'Es el aula 302 del bloque A.'])
        ->assertOk();

    $fechas = collect($this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson("/api/v1/incidencias/{$incidencia->id}/historial")
        ->json('data'))->pluck('fecha_hora');

    expect($fechas->all())->toBe($fechas->sort()->values()->all());
});

it('[KAN-306] muestra al reportante solo la secuencia de estados, sin registros forenses ni autores internos (Escenario 5)', function () {
    $reportante = $this->usuarioConRol(Rol::Reportante);
    $incidencia = Incidencia::factory()->for($reportante, 'creador')->enEstado(EstadoIncidencia::EnRevision)->prioridad('MEDIA')->create();
    $justificacion = 'Nota interna: el técnico titular está de vacaciones';

    $this->comoRol($this->gestor, Rol::Gestor)
        ->patchJson("/api/v1/incidencias/{$incidencia->id}/prioridad", ['impacto' => 'ALTO', 'urgencia' => 'ALTA', 'justificacion' => $justificacion])
        ->assertOk();
    $this->comoRol($this->gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/solicitar-aclaracion", ['informacion_requerida' => 'Indique el número exacto del aula.'])
        ->assertOk();

    $respuesta = $this->comoRol($reportante, Rol::Reportante)
        ->getJson("/api/v1/incidencias/{$incidencia->id}/historial")
        ->assertOk()
        ->assertJsonPath('alcance', 'PUBLICO');

    $eventos = collect($respuesta->json('data'));
    expect($eventos)->not->toBeEmpty()
        ->and($eventos->pluck('categoria')->unique()->values()->all())->toBe(['ESTADO'])
        ->and($eventos->pluck('usuario')->filter()->all())->toBe([])
        ->and(json_encode($respuesta->json()))->not->toContain('vacaciones');

    $completo = collect($this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson("/api/v1/incidencias/{$incidencia->id}/historial")
        ->json('data'));
    expect($completo->count())->toBeGreaterThan($eventos->count());
});

it('[KAN-306] oculta con 404 el historial de una incidencia ajena al reportante', function () {
    $incidencia = Incidencia::factory()->create();

    $this->comoRol($this->usuarioConRol(Rol::Reportante), Rol::Reportante)
        ->getJson("/api/v1/incidencias/{$incidencia->id}/historial")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| KAN-311 - HU-10.3 Semáforo SLA
|--------------------------------------------------------------------------
*/

/**
 * Incidencia en atención con un plazo de 10 horas hábiles: martes 24/11/2026 de 8:00 a 18:00 (hora local).
 */
function incidenciaConPlazoDeUnDia(): Incidencia
{
    return Incidencia::factory()->enEstado(EstadoIncidencia::EnAtencion)->prioridad('ALTA')->create([
        'created_at' => horaLocal('2026-11-24 08:00:00'),
        'fecha_limite_resolucion' => horaLocal('2026-11-24 18:00:00'),
    ]);
}

it('[KAN-311] clasifica en los límites exactos del 75 % y del 100 % del plazo', function (string $instante, string $estado) {
    $incidencia = incidenciaConPlazoDeUnDia();
    $this->travelTo(horaLocal($instante));

    $fila = collect($this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson('/api/v1/consola/incidencias')
        ->assertOk()
        ->json('data'))->firstWhere('id', $incidencia->id);

    expect($fila['sla']['estado'])->toBe($estado);
})->with([
    '74,8 % en plazo' => ['2026-11-24 15:29:00', 'EN_PLAZO'],
    '75 % próxima a vencer' => ['2026-11-24 15:30:00', 'PROXIMA_A_VENCER'],
    '100 % aún próxima a vencer' => ['2026-11-24 18:00:00', 'PROXIMA_A_VENCER'],
    'más del 100 % vencida' => ['2026-11-25 08:06:00', 'VENCIDA'],
]);

it('[KAN-311] informa las horas hábiles restantes y las horas de retraso (Escenarios 1 y 3)', function () {
    $incidencia = incidenciaConPlazoDeUnDia();

    $this->travelTo(horaLocal('2026-11-24 10:00:00'));
    $enPlazo = collect($this->comoRol($this->coordinador, Rol::Coordinador)->getJson('/api/v1/consola/incidencias')->json('data'))->firstWhere('id', $incidencia->id);
    expect($enPlazo['sla']['minutos_restantes'])->toBe(480);

    $this->travelTo(horaLocal('2026-11-25 09:00:00'));
    $vencida = collect($this->comoRol($this->coordinador, Rol::Coordinador)->getJson('/api/v1/consola/plazos?estado_sla=VENCIDA')->json('data'))->firstWhere('id', $incidencia->id);
    expect($vencida['sla']['estado'])->toBe('VENCIDA')
        ->and((float) $vencida['sla']['horas_retraso'])->toBe(1.0);
});

it('[KAN-311] congela el SLA al cerrar por verificación y graba el cumplimiento en la ficha (Escenario 4)', function () {
    $tecnico = User::factory()->tecnico('PINTURA')->create();
    $aTiempo = incidenciaPendienteVerificacion($tecnico, 'PINTURA', ['fecha_limite_resolucion' => now()->addDay()]);
    $tarde = incidenciaPendienteVerificacion($tecnico, 'PINTURA', ['fecha_limite_resolucion' => now()->subDay()]);
    $verificador = $this->usuarioConRol(Rol::Verificador);

    foreach ([$aTiempo, $tarde] as $incidencia) {
        $this->comoRol($verificador, Rol::Verificador)
            ->postJson("/api/v1/incidencias/{$incidencia->id}/verificar")
            ->assertJsonPath('data.estado.codigo', 'E15');
    }

    expect($aTiempo->refresh()->sla_resolucion_cumplido)->toBeTrue()
        ->and($tarde->refresh()->sla_resolucion_cumplido)->toBeFalse()
        ->and($aTiempo->fecha_cierre)->not->toBeNull();

    $this->travel(30)->days();
    $filas = collect($this->comoRol($this->coordinador, Rol::Coordinador)->getJson('/api/v1/consola/incidencias?estado=E15')->json('data'));
    expect($filas->firstWhere('id', $aTiempo->id)['sla']['estado'])->toBe('CUMPLIDA')
        ->and($filas->firstWhere('id', $tarde->id)['sla']['estado'])->toBe('INCUMPLIDA');
});

it('[KAN-311] restringe la vista de plazos al reportante y al técnico', function (Rol $rol) {
    $usuario = $rol === Rol::Tecnico ? User::factory()->tecnico('CERRAJERIA')->create() : $this->usuarioConRol($rol);

    $this->comoRol($usuario, $rol)->getJson('/api/v1/consola/plazos')->assertForbidden();
})->with([
    'reportante (R01)' => [Rol::Reportante],
    'técnico (R04)' => [Rol::Tecnico],
]);

/*
|--------------------------------------------------------------------------
| KAN-316 - HU-10.4 Tablero analítico
|--------------------------------------------------------------------------
*/

it('[KAN-316] los totales del tablero coinciden con el total de la consola para el mismo universo (Escenario 1)', function () {
    Incidencia::factory()->count(3)->enEstado(EstadoIncidencia::EnAtencion)->create();
    Incidencia::factory()->count(2)->enEstado(EstadoIncidencia::Registrada)->create();
    Incidencia::factory()->enEstado(EstadoIncidencia::Cerrada)->create(['created_at' => now()->subHours(5), 'fecha_cierre' => now()]);

    $metricas = $this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson('/api/v1/analitica/metricas-basicas?periodo=mes')
        ->assertOk()
        ->json('data');
    $consola = $this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson('/api/v1/consola/incidencias?fecha_desde='.now()->startOfMonth()->toDateString())
        ->json('meta.total');

    $porEstado = collect($metricas['por_estado'])->pluck('total', 'codigo');
    expect($metricas['total_incidencias'])->toBe($consola)
        ->and($porEstado['E11'])->toBe(3)
        ->and($porEstado['E01'])->toBe(2)
        ->and($porEstado['E15'])->toBe(1);
});

it('[KAN-316] marca una falla recurrente solo con 3 o más reportes de la misma categoría y espacio en 30 días (Escenario 3)', function (int $recientes, int $antiguos, bool $esRecurrente) {
    $laboratorio = EspacioFisico::factory()->create(['nombre' => 'Laboratorio de Química 201']);
    Incidencia::factory()->count($recientes)->categoria('HIDROSANITARIA')->create(['espacio_fisico_id' => $laboratorio->id, 'created_at' => now()->subDays(5)]);
    Incidencia::factory()->count($antiguos)->categoria('HIDROSANITARIA')->create(['espacio_fisico_id' => $laboratorio->id, 'created_at' => now()->subDays(31)]);
    Incidencia::factory()->categoria('CERRAJERIA')->create(['espacio_fisico_id' => $laboratorio->id, 'created_at' => now()->subDays(2)]);

    $recurrentes = collect($this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson('/api/v1/analitica/metricas-basicas')
        ->assertOk()
        ->json('data.fallas_recurrentes.data'))
        ->where('espacio', 'Laboratorio de Química 201');

    expect($recurrentes->where('categoria', 'Hidrosanitaria')->isNotEmpty())->toBe($esRecurrente)
        ->and($recurrentes->where('categoria', 'Cerrajería')->isEmpty())->toBeTrue();
})->with([
    '2 reportes: no recurrente' => [2, 0, false],
    '3 reportes: recurrente' => [3, 0, true],
    '2 recientes + 1 de hace 31 días: no recurrente' => [2, 1, false],
]);

it('[KAN-316] recalcula los indicadores según el periodo seleccionado (Escenario 4)', function () {
    $this->travelTo(horaLocal('2026-11-20 12:00:00'));
    Incidencia::factory()->create(['created_at' => now()->subDays(2)]);
    Incidencia::factory()->create(['created_at' => now()->subDays(15)]);
    Incidencia::factory()->create(['created_at' => now()->subMonths(3)]);

    $total = fn (string $periodo) => $this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson("/api/v1/analitica/metricas-basicas?periodo={$periodo}")
        ->assertOk()
        ->json('data.total_incidencias');

    expect($total('semana'))->toBe(1)
        ->and($total('mes'))->toBe(2)
        ->and($total('semestre'))->toBe(3);
});

it('[KAN-316] calcula el MTTR como media aritmética de las incidencias cerradas en el periodo (Escenario 2)', function () {
    foreach ([10, 12, 14, 16, 19] as $horas) {
        Incidencia::factory()->enEstado(EstadoIncidencia::Cerrada)->create(['created_at' => now()->subHours($horas), 'fecha_cierre' => now()]);
    }

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson('/api/v1/analitica/metricas-basicas?periodo=rango&desde='.now()->subDays(2)->toDateString().'&hasta='.now()->toDateString())
        ->assertOk()
        ->assertJsonPath('data.mttr.incidencias_cerradas', 5)
        ->assertJsonPath('data.mttr.texto', 'Tiempo promedio: 14.2 horas');
});

it('[KAN-316] rechaza periodos no válidos y rangos invertidos con 422', function (string $consulta) {
    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->getJson("/api/v1/analitica/metricas-basicas?{$consulta}")
        ->assertUnprocessable();
})->with([
    'periodo desconocido' => ['periodo=trimestre'],
    'rango sin fechas' => ['periodo=rango'],
    'rango invertido' => ['periodo=rango&desde=2026-11-20&hasta=2026-11-01'],
]);

it('[KAN-316] restringe el tablero al reportante y al técnico', function (Rol $rol) {
    $usuario = $rol === Rol::Tecnico ? User::factory()->tecnico('CERRAJERIA')->create() : $this->usuarioConRol($rol);

    $this->comoRol($usuario, $rol)->getJson('/api/v1/analitica/metricas-basicas')->assertForbidden();
})->with([
    'reportante (R01)' => [Rol::Reportante],
    'técnico (R04)' => [Rol::Tecnico],
]);
