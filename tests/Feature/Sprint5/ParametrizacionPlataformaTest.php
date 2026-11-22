<?php

use App\Enums\EstadoIncidencia;
use App\Enums\Rol;
use App\Models\CategoriaIncidencia;
use App\Models\Edificio;
use App\Models\EspacioFisico;
use App\Models\EventoAuditoria;
use App\Models\Incidencia;
use App\Models\Piso;
use App\Models\User;
use App\Services\ParametrosService;
use App\Support\Parametro;
use Carbon\CarbonImmutable;

/**
 * QA Sprint 5 - E11 Administración de la plataforma.
 * KAN-321 (HU-11.1), KAN-326 (HU-11.2) y KAN-331 (HU-11.3).
 * Complementa tests/Feature/Admin/AdministracionTest.php.
 */
beforeEach(function () {
    $this->admin = $this->usuarioConRol(Rol::Administrador);
    $this->coordinador = $this->usuarioConRol(Rol::Coordinador);
});

/** Datos mínimos de radicación para un espacio y una categoría. */
function radicacionSprint5(EspacioFisico $espacio, string $categoria, array $extra = []): array
{
    $espacio->loadMissing('piso.edificio');
    $categoriaPrincipal = CategoriaIncidencia::query()->where('codigo', $categoria)->firstOrFail();

    return array_merge([
        'sede_id' => $espacio->piso->edificio->sede_id,
        'edificio_id' => $espacio->piso->edificio_id,
        'piso_id' => $espacio->piso_id,
        'espacio_fisico_id' => $espacio->id,
        'categoria_id' => $categoriaPrincipal->id,
        'subcategoria_id' => $categoriaPrincipal->subcategorias()->value('id'),
        'descripcion' => 'La tubería del lavamanos presenta una fuga constante de agua.',
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| KAN-321 - HU-11.1 Catálogo jerárquico de ubicaciones
|--------------------------------------------------------------------------
*/

it('[KAN-321] rechaza un espacio con el mismo nombre en el mismo piso y lo permite en otro piso (Escenario 2)', function () {
    $piso1 = Piso::factory()->create();
    $piso2 = Piso::factory()->for(Edificio::query()->findOrFail($piso1->edificio_id))->create();
    EspacioFisico::factory()->for($piso1)->create(['codigo' => 'A-101', 'nombre' => 'Aula 101']);

    $this->comoRol($this->admin, Rol::Administrador)
        ->postJson('/api/v1/admin/ubicaciones/espacios', ['piso_id' => $piso1->id, 'codigo' => 'A-101B', 'nombre' => 'Aula 101'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nombre');

    $this->comoRol($this->admin, Rol::Administrador)
        ->postJson('/api/v1/admin/ubicaciones/espacios', ['piso_id' => $piso2->id, 'codigo' => 'A-101', 'nombre' => 'Aula 101'])
        ->assertCreated();

    expect(EspacioFisico::query()->where('nombre', 'Aula 101')->count())->toBe(2);
});

it('[KAN-321] el coordinador consulta las ubicaciones pero no puede crearlas, editarlas ni inactivarlas', function () {
    $espacio = EspacioFisico::factory()->create();

    $this->comoRol($this->coordinador, Rol::Coordinador)->getJson('/api/v1/admin/ubicaciones')->assertOk();
    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->postJson('/api/v1/admin/ubicaciones/sedes', ['codigo' => 'SUR', 'nombre' => 'Sede Sur'])
        ->assertForbidden();
    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->putJson("/api/v1/admin/ubicaciones/espacios/{$espacio->id}", ['nombre' => 'Otro nombre'])
        ->assertForbidden();
    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->patchJson("/api/v1/admin/ubicaciones/espacios/{$espacio->id}/estado", ['activo' => false])
        ->assertForbidden();

    expect($espacio->refresh()->activo)->toBeTrue();
});

it('[KAN-321] el espacio inactivo deja de aceptar reportes y vuelve a aceptarlos al reactivarse (Escenario 3)', function () {
    $espacio = EspacioFisico::factory()->create();
    $reportante = $this->usuarioConRol(Rol::Reportante);

    $this->comoRol($this->admin, Rol::Administrador)
        ->patchJson("/api/v1/admin/ubicaciones/espacios/{$espacio->id}/estado", ['activo' => false])
        ->assertOk();
    $this->comoRol($reportante, Rol::Reportante)
        ->postJson('/api/v1/incidencias/radicar', radicacionSprint5($espacio, 'CERRAJERIA'))
        ->assertJsonValidationErrors('espacio_fisico_id');

    $this->comoRol($this->admin, Rol::Administrador)
        ->patchJson("/api/v1/admin/ubicaciones/espacios/{$espacio->id}/estado", ['activo' => true])
        ->assertOk();
    $this->comoRol($reportante, Rol::Reportante)
        ->postJson('/api/v1/incidencias/radicar', radicacionSprint5($espacio, 'CERRAJERIA'))
        ->assertCreated();
});

it('[KAN-321] audita la edición de una ubicación con el valor anterior y el nuevo', function () {
    $espacio = EspacioFisico::factory()->create(['nombre' => 'Aula 204']);

    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson("/api/v1/admin/ubicaciones/espacios/{$espacio->id}", ['nombre' => 'Laboratorio de Física 204'])
        ->assertOk();

    $evento = EventoAuditoria::query()->where('tipo_evento', 'UBICACION_ACTUALIZADA')->sole();
    expect($evento->usuario_id)->toBe($this->admin->id)
        ->and($evento->datos['anterior']['nombre'])->toBe('Aula 204')
        ->and($evento->datos['nuevo']['nombre'])->toBe('Laboratorio de Física 204');
});

/*
|--------------------------------------------------------------------------
| KAN-326 - HU-11.2 Categorías y reglas de evidencia y confirmación
|--------------------------------------------------------------------------
*/

it('[KAN-326] al activar la foto obligatoria de una categoría, la radicación sin foto se rechaza y con foto se acepta (Escenario 1)', function () {
    $categoria = CategoriaIncidencia::query()->where('codigo', 'HIDROSANITARIA')->firstOrFail();
    $categoria->subcategorias()->update(['exige_evidencia_fotografica' => false]);
    $espacio = EspacioFisico::factory()->create();
    $reportante = $this->usuarioConRol(Rol::Reportante);

    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson("/api/v1/admin/categorias/{$categoria->id}", ['exige_evidencia_fotografica' => true])
        ->assertOk();

    $this->comoRol($reportante, Rol::Reportante)
        ->postJson('/api/v1/incidencias/radicar', radicacionSprint5($espacio, 'HIDROSANITARIA'))
        ->assertJsonValidationErrors('evidencias');

    $evidencias = $this->subirEvidencias($reportante, Rol::Reportante, 1);
    $this->comoRol($reportante, Rol::Reportante)
        ->postJson('/api/v1/incidencias/radicar', radicacionSprint5($espacio, 'HIDROSANITARIA', ['evidencias' => $evidencias]))
        ->assertCreated();
});

it('[KAN-326] la regla de confirmación de la categoría decide si la verificación pasa a E14 o cierra en E15 (Escenario 2)', function (string $codigo, bool $requiereConfirmacion, string $estadoEsperado) {
    $categoria = CategoriaIncidencia::query()->where('codigo', $codigo)->firstOrFail();
    $tecnico = User::factory()->tecnico($codigo)->create();
    $verificador = $this->usuarioConRol(Rol::Verificador);

    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson("/api/v1/admin/categorias/{$categoria->id}", ['requiere_confirmacion_cierre' => $requiereConfirmacion])
        ->assertOk();

    $incidencia = incidenciaPendienteVerificacion($tecnico, $codigo);

    $this->comoRol($verificador, Rol::Verificador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/verificar", ['criterios' => [['criterio' => 'Funciona correctamente', 'cumple' => true]]])
        ->assertOk()
        ->assertJsonPath('data.estado.codigo', $estadoEsperado);
})->with([
    'climatización pasa a requerir confirmación' => ['CLIMATIZACION', true, 'E14'],
    'pintura deja de cerrar directo' => ['PINTURA', true, 'E14'],
    'cerrajería deja de requerir confirmación' => ['CERRAJERIA', false, 'E15'],
]);

it('[KAN-326] el plazo de aclaraciones configurado gobierna la cancelación automática (Escenario 3)', function () {
    $local = fn (string $fecha) => CarbonImmutable::parse($fecha, config('praxis.zona_horaria'))->utc();

    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros-proceso', ['plazo_aclaracion_dias_habiles' => 2])
        ->assertOk();
    $this->comoRol($this->admin, Rol::Administrador)
        ->getJson('/api/v1/admin/parametros-proceso')
        ->assertOk()
        ->assertJsonFragment(['plazo_aclaracion_dias_habiles' => 2]);

    // Lunes 23/11/2026 10:00 hora local; 2 días hábiles vencen el miércoles 25/11 a las 10:00.
    $this->travelTo($local('2026-11-23 10:00:00'));
    $reportante = $this->usuarioConRol(Rol::Reportante);
    $incidencia = Incidencia::factory()->for($reportante, 'creador')->enEstado(EstadoIncidencia::EnRevision)->create();
    $this->comoRol($this->usuarioConRol(Rol::Gestor), Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/solicitar-aclaracion", ['informacion_requerida' => 'Indique el número exacto del aula.'])
        ->assertOk();

    $this->travelTo($local('2026-11-24 16:00:00'));
    $this->artisan('praxis:vencer-aclaraciones')->assertSuccessful();
    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteInformacion);

    $this->travelTo($local('2026-11-26 10:00:00'));
    $this->artisan('praxis:vencer-aclaraciones')->assertSuccessful();
    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::Cancelada);
});

it('[KAN-326] solo el administrador modifica las categorías', function () {
    $categoria = CategoriaIncidencia::query()->where('codigo', 'CERRAJERIA')->firstOrFail();

    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->putJson("/api/v1/admin/categorias/{$categoria->id}", ['exige_evidencia_fotografica' => true])
        ->assertForbidden();

    expect($categoria->refresh()->exige_evidencia_fotografica)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| KAN-331 - HU-11.3 Parámetros del sistema
|--------------------------------------------------------------------------
*/

it('[KAN-331] la matriz recalibrada se aplica a las nuevas clasificaciones (Escenario 1)', function () {
    $gestor = $this->usuarioConRol(Rol::Gestor);

    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros', ['matriz' => [['impacto' => 'MEDIO', 'urgencia' => 'MEDIA', 'prioridad' => 'ALTA']]])
        ->assertOk();

    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::EnRevision)->create();
    $this->comoRol($gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/clasificar", ['procedente' => true, 'impacto' => 'MEDIO', 'urgencia' => 'MEDIA'])
        ->assertOk();

    expect($incidencia->refresh()->prioridad->codigo)->toBe('ALTA');
});

it('[KAN-331] el nuevo SLA se usa para calcular el vencimiento de las nuevas incidencias críticas (Escenario 2)', function () {
    $local = fn (string $fecha) => CarbonImmutable::parse($fecha, config('praxis.zona_horaria'))->utc();
    $gestor = $this->usuarioConRol(Rol::Gestor);

    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros', ['sla' => [['codigo' => 'CRITICA', 'horas_primera_respuesta' => 1, 'horas_resolucion' => 4]]])
        ->assertOk();

    $this->travelTo($local('2026-11-24 09:00:00'));
    $incidencia = Incidencia::factory()->enEstado(EstadoIncidencia::EnRevision)->create(['created_at' => now()]);
    $this->comoRol($gestor, Rol::Gestor)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/clasificar", ['procedente' => true, 'impacto' => 'ALTO', 'urgencia' => 'ALTA'])
        ->assertOk();

    $incidencia->refresh();
    expect($incidencia->prioridad->codigo)->toBe('CRITICA')
        ->and($incidencia->fecha_limite_resolucion->equalTo($local('2026-11-24 13:00:00')))->toBeTrue();
});

it('[KAN-331] no aplica de forma retroactiva la nueva matriz a incidencias ya clasificadas', function () {
    $clasificada = Incidencia::factory()->enEstado(EstadoIncidencia::EnAtencion)->prioridad('BAJA')->create(['impacto' => 'MEDIO', 'urgencia' => 'MEDIA']);

    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros', ['matriz' => [['impacto' => 'MEDIO', 'urgencia' => 'MEDIA', 'prioridad' => 'ALTA']]])
        ->assertOk();

    expect($clasificada->refresh()->prioridad->codigo)->toBe('BAJA');
});

it('[KAN-331] rechaza horas SLA en cero o negativas con el mensaje de entero positivo (Escenario 3)', function (mixed $horas) {
    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros', ['sla' => [['codigo' => 'CRITICA', 'horas_primera_respuesta' => 1, 'horas_resolucion' => $horas]]])
        ->assertJsonValidationErrors(['sla.0.horas_resolucion' => 'El parámetro debe ser un número entero positivo mayor a cero']);
})->with([0, -4]);

it('[KAN-331] rechaza parámetros desconocidos sin aplicar ningún cambio', function () {
    $antes = app(ParametrosService::class)->entero(Parametro::LIMITE_WIP_TECNICO);

    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros', ['parametros' => ['PARAMETRO_INVENTADO' => 3, Parametro::LIMITE_WIP_TECNICO => $antes + 1]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parametros');

    expect(app(ParametrosService::class)->entero(Parametro::LIMITE_WIP_TECNICO))->toBe($antes);
});

it('[KAN-331] audita el cambio de la ventana de reapertura de 3 a 5 con autor, fecha, valor anterior y nuevo (Escenario 4)', function () {
    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros', ['parametros' => [Parametro::VENTANA_REAPERTURA_DIAS_HABILES => 3]])
        ->assertOk();
    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros', ['parametros' => [Parametro::VENTANA_REAPERTURA_DIAS_HABILES => 5]])
        ->assertOk();

    $historial = collect($this->comoRol($this->admin, Rol::Administrador)
        ->getJson('/api/v1/admin/parametros/historial')
        ->assertOk()
        ->json('data'));
    $cambio = $historial->first(fn (array $evento) => ($evento['datos']['parametro'] ?? null) === Parametro::VENTANA_REAPERTURA_DIAS_HABILES
        && (string) $evento['datos']['valor_anterior'] === '3');

    expect($cambio)->not->toBeNull()
        ->and((string) $cambio['datos']['valor_nuevo'])->toBe('5')
        ->and($cambio['usuario']['id'])->toBe($this->admin->id)
        ->and($cambio['fecha_hora'])->not->toBeNull();
});

it('[KAN-331] solo el administrador puede modificar los parámetros del sistema', function () {
    $this->comoRol($this->coordinador, Rol::Coordinador)->getJson('/api/v1/admin/parametros')->assertOk();
    $this->comoRol($this->coordinador, Rol::Coordinador)
        ->putJson('/api/v1/admin/parametros', ['parametros' => [Parametro::LIMITE_WIP_TECNICO => 9]])
        ->assertForbidden();
});
