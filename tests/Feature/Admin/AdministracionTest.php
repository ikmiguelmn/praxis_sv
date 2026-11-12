<?php

use App\Enums\Impacto;
use App\Enums\Rol;
use App\Enums\Urgencia;
use App\Models\ActividadMantenimiento;
use App\Models\CategoriaIncidencia;
use App\Models\EspacioFisico;
use App\Models\EventoAuditoria;
use App\Models\Incidencia;
use App\Models\Piso;
use App\Models\User;
use App\Services\CatalogoService;
use App\Services\ParametrosService;
use App\Support\Parametro;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->admin = $this->usuarioConRol(Rol::Administrador);
});

it('crea un usuario con múltiples roles y registra la acción en auditoría', function () {
    $respuesta = $this->comoRol($this->admin, Rol::Administrador)
        ->postJson('/api/v1/admin/usuarios', [
            'nombre' => 'Funcionario Nuevo',
            'email' => 'nuevo@ecci.edu.co',
            'password' => 'Clave*Segura1',
            'roles' => ['R02', 'R03'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.roles.*.codigo', ['R02', 'R03']);

    expect(EventoAuditoria::query()->where('tipo_evento', 'USUARIO_CREADO')->where('entidad_id', (string) $respuesta->json('data.id'))->where('usuario_id', $this->admin->id)->exists())->toBeTrue();
});

it('inactiva usuarios revocando sesiones y los excluye de técnicos disponibles', function () {
    $tecnico = User::factory()->tecnico('CERRAJERIA')->create();
    $tecnico->createToken('praxis-api');

    $this->comoRol($this->admin, Rol::Administrador)
        ->patchJson("/api/v1/admin/usuarios/{$tecnico->id}/estado", ['activo' => false, 'motivo' => 'Retiro de la institución'])
        ->assertOk()
        ->assertJsonPath('data.activo', false);

    expect($tecnico->tokens()->count())->toBe(0);

    $this->comoRol($this->usuarioConRol(Rol::Coordinador), Rol::Coordinador)
        ->getJson('/api/v1/tecnicos/disponibles')
        ->assertJsonCount(0, 'data');
});

it('crea un usuario solo con el rol R02 y audita el administrador, la fecha y el motivo del alta', function () {
    $this->freezeSecond();

    $respuesta = $this->comoRol($this->admin, Rol::Administrador)
        ->postJson('/api/v1/admin/usuarios', [
            'nombre' => 'Gestora Nueva',
            'email' => 'gestora@ecci.edu.co',
            'password' => 'Clave*Segura1',
            'roles' => ['R02'],
            'motivo' => 'Ingreso al equipo de gestión de incidencias',
        ])
        ->assertCreated()
        ->assertJsonPath('data.roles.*.codigo', ['R02']);

    expect(User::query()->findOrFail($respuesta->json('data.id'))->codigosRol())->toBe(['R02']);
    $evento = EventoAuditoria::query()->where('tipo_evento', 'USUARIO_CREADO')->sole();
    expect($evento)
        ->usuario_id->toBe($this->admin->id)
        ->motivo->toBe('Ingreso al equipo de gestión de incidencias')
        ->fecha_hora->toDateTimeString()->toBe(now()->toDateTimeString());
});

it('habilita en el menú las secciones de cada rol de un usuario con R02 y R03', function () {
    $usuario = $this->usuarioConRol(Rol::Gestor, Rol::Coordinador);

    $menuGestor = $this->comoRol($usuario, Rol::Gestor)->getJson('/api/v1/navegacion/menu');
    $menuCoordinador = $this->comoRol($usuario, Rol::Coordinador)->getJson('/api/v1/navegacion/menu');

    $menuGestor->assertOk()->assertJsonPath('rol_activo', 'R02');
    $menuCoordinador->assertOk()->assertJsonPath('rol_activo', 'R03');
    expect($menuGestor->json('data.*.ruta'))->toContain('/revision')
        ->and($menuCoordinador->json('data.*.ruta'))->toContain('/planificacion');
});

it('asigna los roles R02 y R03 a un usuario existente y audita el cambio con su motivo', function () {
    $usuario = $this->usuarioConRol(Rol::Reportante);

    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson("/api/v1/admin/usuarios/{$usuario->id}/roles", ['roles' => ['R02', 'R03'], 'motivo' => 'Apoyo temporal a coordinación'])
        ->assertOk()
        ->assertJsonPath('data.roles.*.codigo', ['R02', 'R03']);

    expect($usuario->refresh()->codigosRol())->toBe(['R02', 'R03']);
    $evento = EventoAuditoria::query()->where('tipo_evento', 'ROLES_ACTUALIZADOS')->sole();
    expect($evento)
        ->usuario_id->toBe($this->admin->id)
        ->motivo->toBe('Apoyo temporal a coordinación');
});

it('rechaza con 401 la sesión previa y con 403 el nuevo login de un usuario inactivado', function () {
    User::factory()->tecnico('CERRAJERIA')->create(['email' => 'retirado@ecci.edu.co', 'password' => Hash::make('Clave*Segura1')]);
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'retirado@ecci.edu.co', 'password' => 'Clave*Segura1'])->assertOk();
    $accessToken = $login->json('access_token');
    $refreshToken = $login->getCookie('praxis_refresh', false)->getValue();

    $this->comoRol($this->admin, Rol::Administrador)
        ->patchJson("/api/v1/admin/usuarios/{$login->json('usuario.id')}/estado", ['activo' => false, 'motivo' => 'Retiro de la institución'])
        ->assertOk();
    app('auth')->forgetGuards();
    $this->flushHeaders();

    $this->withToken($accessToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->flushHeaders()->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['email' => 'retirado@ecci.edu.co', 'password' => 'Clave*Segura1'])
        ->assertForbidden()
        ->assertJsonPath('detail', 'Usuario inactivo. Comuníquese con el administrador del sistema');
});

it('conserva la autoría de las 20 intervenciones históricas tras inactivar al técnico', function () {
    $tecnico = User::factory()->tecnico('CERRAJERIA')->create(['name' => 'Técnico Histórico']);
    $incidencias = Incidencia::factory()->count(20)->cerradaHace(10)->asignadaA($tecnico)->categoria('CERRAJERIA')->create();
    $incidencias->each(fn (Incidencia $incidencia) => ActividadMantenimiento::create([
        'incidencia_id' => $incidencia->id,
        'tecnico_ejecutor_id' => $tecnico->id,
        'fecha_inicio' => now()->subDays(11),
        'fecha_fin' => now()->subDays(10),
        'tiempo_invertido_minutos' => 60,
        'actividades_realizadas' => 'Cambio de cerradura.',
    ]));

    $this->comoRol($this->admin, Rol::Administrador)
        ->patchJson("/api/v1/admin/usuarios/{$tecnico->id}/estado", ['activo' => false, 'motivo' => 'Retiro de la institución'])
        ->assertOk();

    $this->assertModelExists($tecnico);
    expect(ActividadMantenimiento::query()->where('tecnico_ejecutor_id', $tecnico->id)->count())->toBe(20)
        ->and(Incidencia::query()->where('tecnico_asignado_id', $tecnico->id)->count())->toBe(20);
    $this->comoRol($this->admin, Rol::Administrador)
        ->getJson("/api/v1/incidencias/{$incidencias->first()->id}")
        ->assertOk()
        ->assertJsonPath('data.tecnico_asignado.name', 'Técnico Histórico')
        ->assertJsonPath('data.actividades.0.tecnico_ejecutor.name', 'Técnico Histórico');
});

it('impide quitar o inactivar al único administrador activo', function () {
    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson("/api/v1/admin/usuarios/{$this->admin->id}/roles", ['roles' => ['R01']])
        ->assertStatus(409)
        ->assertJsonPath('detail', 'Debe existir al menos un administrador activo en la plataforma.');

    $this->comoRol($this->admin, Rol::Administrador)
        ->patchJson("/api/v1/admin/usuarios/{$this->admin->id}/estado", ['activo' => false])
        ->assertStatus(409);
});

it('no permite eliminar físicamente usuarios', function () {
    $this->comoRol($this->admin, Rol::Administrador)
        ->deleteJson("/api/v1/admin/usuarios/{$this->admin->id}")
        ->assertStatus(405);
});

it('permite al coordinador solo lectura en administración', function () {
    $coordinador = $this->usuarioConRol(Rol::Coordinador);

    $this->comoRol($coordinador, Rol::Coordinador)->getJson('/api/v1/admin/usuarios')->assertOk();
    $this->comoRol($coordinador, Rol::Coordinador)
        ->postJson('/api/v1/admin/usuarios', ['nombre' => 'X', 'email' => 'x@ecci.edu.co', 'roles' => ['R01']])
        ->assertForbidden();
});

it('crea la jerarquía de ubicaciones, evita duplicados y la expone en el árbol activo', function () {
    $sede = $this->comoRol($this->admin, Rol::Administrador)->postJson('/api/v1/admin/ubicaciones/sedes', ['codigo' => 'NORTE', 'nombre' => 'Sede Norte'])->assertCreated()->json('data.id');
    $edificio = $this->comoRol($this->admin, Rol::Administrador)->postJson('/api/v1/admin/ubicaciones/edificios', ['sede_id' => $sede, 'codigo' => 'C', 'nombre' => 'Bloque C'])->json('data.id');
    $piso = $this->comoRol($this->admin, Rol::Administrador)->postJson('/api/v1/admin/ubicaciones/pisos', ['edificio_id' => $edificio, 'codigo' => 'P2', 'nombre' => 'Piso 2', 'nivel' => 2])->json('data.id');

    $this->comoRol($this->admin, Rol::Administrador)->getJson('/api/v1/catalogo/ubicaciones/arbol-activo')->assertJsonCount(1, 'data');

    $this->comoRol($this->admin, Rol::Administrador)->postJson('/api/v1/admin/ubicaciones/espacios', ['piso_id' => $piso, 'codigo' => 'LAB-204', 'nombre' => 'Laboratorio de Física 204'])->assertCreated();
    $this->comoRol($this->admin, Rol::Administrador)
        ->postJson('/api/v1/admin/ubicaciones/espacios', ['piso_id' => $piso, 'codigo' => 'LAB-204', 'nombre' => 'Otro nombre'])
        ->assertJsonValidationErrors(['codigo' => 'Esa ubicación ya se encuentra parametrizada.']);

    $this->comoRol($this->admin, Rol::Administrador)
        ->getJson('/api/v1/catalogo/ubicaciones/arbol-activo')
        ->assertJsonPath('data.0.edificios.0.pisos.0.espacios.0.nombre', 'Laboratorio de Física 204');
});

it('inactiva espacios con historial en lugar de borrarlos, conservando las incidencias antiguas', function () {
    $espacio = EspacioFisico::factory()->create();
    $incidencia = Incidencia::factory()->create(['espacio_fisico_id' => $espacio->id]);

    $this->comoRol($this->admin, Rol::Administrador)->deleteJson("/api/v1/admin/ubicaciones/espacios/{$espacio->id}")->assertStatus(409);
    $this->comoRol($this->admin, Rol::Administrador)->patchJson("/api/v1/admin/ubicaciones/espacios/{$espacio->id}/estado", ['activo' => false])->assertOk();

    $this->comoRol($this->admin, Rol::Administrador)
        ->getJson("/api/v1/incidencias/{$incidencia->id}")
        ->assertJsonPath('data.ubicacion.espacio.id', $espacio->id);
    expect(app(CatalogoService::class)->arbolUbicacionesActivas()[0]['edificios'][0]['pisos'][0]['espacios'])->toBe([])
        ->and(Piso::query()->count())->toBe(1);
});

it('actualiza la obligatoriedad de evidencia de una categoría con invalidación de caché', function () {
    $categoria = CategoriaIncidencia::query()->where('codigo', 'HIDROSANITARIA')->firstOrFail();
    app(CatalogoService::class)->categoriasActivas();

    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson("/api/v1/admin/categorias/{$categoria->id}", ['exige_evidencia_fotografica' => true])
        ->assertOk();

    expect(collect(app(CatalogoService::class)->categoriasActivas())->firstWhere('codigo', 'HIDROSANITARIA')['exige_evidencia_fotografica'])->toBeTrue()
        ->and(EventoAuditoria::query()->where('tipo_evento', 'CATEGORIA_ACTUALIZADA')->exists())->toBeTrue();
});

it('recalibra la matriz ITIL en caliente y audita valor anterior y nuevo', function () {
    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros', [
            'matriz' => [['impacto' => 'MEDIO', 'urgencia' => 'MEDIA', 'prioridad' => 'MEDIA']],
            'parametros' => [Parametro::VENTANA_REAPERTURA_DIAS_HABILES => 3],
        ])
        ->assertOk();

    expect(app(CatalogoService::class)->prioridadPara(Impacto::Medio, Urgencia::Media)->codigo)->toBe('MEDIA')
        ->and(app(ParametrosService::class)->entero(Parametro::VENTANA_REAPERTURA_DIAS_HABILES))->toBe(3);

    $evento = EventoAuditoria::query()->where('tipo_evento', 'PARAMETRO_ACTUALIZADO')->firstOrFail();
    expect($evento->datos)->toMatchArray(['valor_anterior' => '5', 'valor_nuevo' => '3'])
        ->and($evento->usuario_id)->toBe($this->admin->id);

    $this->comoRol($this->admin, Rol::Administrador)->getJson('/api/v1/admin/parametros/historial')->assertJsonCount(2, 'data');
});

it('rechaza valores negativos o no numéricos en los parámetros', function (mixed $valor) {
    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros', ['parametros' => [Parametro::LIMITE_WIP_TECNICO => $valor]])
        ->assertJsonValidationErrors(['parametros.'.Parametro::LIMITE_WIP_TECNICO => 'El parámetro debe ser un número entero positivo mayor a cero']);
})->with([-3, 'abc', 0]);

it('ajusta el SLA de una prioridad para las nuevas incidencias', function () {
    $this->comoRol($this->admin, Rol::Administrador)
        ->putJson('/api/v1/admin/parametros', ['sla' => [['codigo' => 'CRITICA', 'horas_primera_respuesta' => 1, 'horas_resolucion' => 4]]])
        ->assertOk()
        ->assertJsonFragment(['codigo' => 'CRITICA', 'horas_resolucion' => 4]);

    expect(app(CatalogoService::class)->prioridadPorCodigo('CRITICA')->horas_resolucion)->toBe(4);
});
