<?php

use App\Enums\EstadoIncidencia;
use App\Enums\EstadoSolicitudRecursos;
use App\Enums\Rol;
use App\Models\EventoAuditoria;
use App\Models\SolicitudRecursos;
use App\Models\User;

it('muestra al reportante solo Inicio, Nuevo reporte, Mis solicitudes y Ayuda', function () {
    $this->comoRol($this->usuarioConRol(Rol::Reportante), Rol::Reportante)
        ->getJson('/api/v1/navegacion/menu')
        ->assertOk()
        ->assertJsonPath('rol_activo', 'R01')
        ->assertJsonPath('data.*.etiqueta', ['Inicio', 'Nuevo reporte', 'Mis solicitudes', 'Ayuda']);
});

it('conmuta el menú en caliente para un usuario multi-rol según X-Active-Role', function () {
    $usuario = $this->usuarioConRol(Rol::Reportante, Rol::Verificador);

    $this->comoRol($usuario, Rol::Verificador)
        ->getJson('/api/v1/navegacion/menu')
        ->assertOk()
        ->assertJsonPath('rol_activo', 'R06')
        ->assertJsonFragment(['ruta' => '/verificacion']);

    $this->comoRol($usuario, Rol::Verificador)
        ->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.rol_activo.codigo', 'R06')
        ->assertJsonCount(2, 'data.roles');
});

it('rechaza un X-Active-Role que no está asignado al usuario', function () {
    $this->comoRol($this->usuarioConRol(Rol::Reportante), Rol::Administrador)
        ->getJson('/api/v1/navegacion/menu')
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json');

    expect(EventoAuditoria::query()->where('tipo_evento', 'ROL_ACTIVO_NO_ASIGNADO')->exists())->toBeTrue();
});

it('responde 403 y audita el intento cuando el rol activo no tiene permiso sobre la operación', function () {
    $tecnico = $this->usuarioConRol(Rol::Tecnico);

    $this->comoRol($tecnico, Rol::Tecnico)
        ->postJson('/api/v1/solicitudes-recursos/1/dictamen', ['resultado' => 'APROBADA'])
        ->assertForbidden()
        ->assertJsonPath('status', 403);

    expect(EventoAuditoria::query()->where('tipo_evento', 'ACCESO_DENEGADO')->where('usuario_id', $tecnico->id)->exists())->toBeTrue();
});

it('rechaza con 403 el dictamen de un técnico sin cambiar la solicitud y audita actor, rol y ruta', function () {
    $tecnico = User::factory()->tecnico('CERRAJERIA')->create();
    $incidencia = incidenciaConDiagnostico($tecnico);
    $solicitudId = $this->comoRol($this->usuarioConRol(Rol::Coordinador), Rol::Coordinador)
        ->postJson("/api/v1/incidencias/{$incidencia->id}/solicitudes-recursos", [
            'decision_requerida' => 'Compra de cerradura de alta seguridad fuera de caja menor',
            'requiere_autorizacion' => true,
            'es_emergencia' => false,
            'items' => [['recurso_nombre' => 'Cerradura multipunto', 'cantidad' => 1, 'unidad' => 'und', 'costo_estimado' => 350000]],
        ])
        ->assertCreated()
        ->json('data.id');

    $this->comoRol($tecnico, Rol::Tecnico)
        ->postJson("/api/v1/solicitudes-recursos/{$solicitudId}/dictamen", ['resultado' => 'APROBADA', 'recursos_disponibles' => true, 'justificacion' => 'Intento forzado desde el rol técnico'])
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json');

    $solicitud = SolicitudRecursos::query()->findOrFail($solicitudId);
    expect($solicitud)
        ->estado_solicitud->toBe(EstadoSolicitudRecursos::PendienteAutorizacion)
        ->autorizador_id->toBeNull()
        ->fecha_dictamen->toBeNull();
    expect($incidencia->refresh()->estado_codigo)->toBe(EstadoIncidencia::PendienteAutorizacion);
    $evento = EventoAuditoria::query()->where('tipo_evento', 'ACCESO_DENEGADO')->sole();
    expect($evento)
        ->usuario_id->toBe($tecnico->id)
        ->rol_codigo->toBe('R04')
        ->datos->toMatchArray(['ruta' => "api/v1/solicitudes-recursos/{$solicitudId}/dictamen", 'metodo' => 'POST']);
});

it('expone el nombre y el rol activo del usuario para el header del shell', function () {
    $usuario = User::factory()->conRoles(Rol::Reportante)->create(['name' => 'Laura Reportante']);

    $this->comoRol($usuario, Rol::Reportante)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.nombre', 'Laura Reportante')
        ->assertJsonPath('data.rol_activo.codigo', 'R01');
});

it('rechaza con 403 al reportante que invoca directamente secciones no autorizadas', function (string $ruta) {
    $this->comoRol($this->usuarioConRol(Rol::Reportante), Rol::Reportante)
        ->getJson($ruta)
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json');
})->with([
    'administración de usuarios' => ['/api/v1/admin/usuarios'],
    'autorizaciones pendientes' => ['/api/v1/autorizaciones/pendientes'],
]);

it('exige autenticación en los endpoints protegidos', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('title', 'No autenticado.');
});
