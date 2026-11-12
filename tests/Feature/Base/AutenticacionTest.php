<?php

use App\Enums\Rol;
use App\Models\EventoAuditoria;
use App\Models\TokenRecuperacion;
use App\Models\User;
use App\Notifications\RestablecerPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

function crearUsuarioLogin(Rol $rol = Rol::Tecnico, array $atributos = []): User
{
    return User::factory()->conRoles($rol)->create(array_merge(['email' => 'tecnico@ecci.edu.co', 'password' => Hash::make('Clave*Segura1')], $atributos));
}

/**
 * Solicita la recuperación para el usuario y devuelve el token enviado en el correo.
 * Requiere que la prueba haya llamado antes a Notification::fake().
 */
function tokenDeRecuperacionPara(User $usuario): string
{
    test()->postJson('/api/v1/auth/solicitar-recuperacion', ['email' => $usuario->email])->assertOk();

    return Notification::sent($usuario, RestablecerPasswordNotification::class)->last()->token;
}

it('autentica, emite token de 15 minutos y refresh token HttpOnly y redirige al deep-link solicitado', function () {
    crearUsuarioLogin();

    $respuesta = $this->postJson('/api/v1/auth/login', [
        'email' => 'tecnico@ecci.edu.co',
        'password' => 'Clave*Segura1',
        'return_url' => '/mis-tareas/105',
    ]);

    $respuesta->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('expires_in', 900)
        ->assertJsonPath('redirect_to', '/mis-tareas/105')
        ->assertJsonPath('usuario.rol_activo.codigo', 'R04')
        ->assertCookie('praxis_refresh', null, false);

    expect($respuesta->getCookie('praxis_refresh', false)->isHttpOnly())->toBeTrue();
});

it('redirige a la ruta por defecto del rol cuando el returnUrl no es una ruta interna', function () {
    crearUsuarioLogin(Rol::Autorizador);

    $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1', 'return_url' => '//sitio-malicioso.com'])
        ->assertOk()
        ->assertJsonPath('redirect_to', '/autorizaciones');
});

it('responde con un mensaje genérico ante correo inexistente o contraseña incorrecta y audita el fallo', function (string $email, string $password) {
    crearUsuarioLogin();

    $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('detail', 'Credenciales de acceso inválidas');

    expect(EventoAuditoria::query()->where('tipo_evento', 'LOGIN_FALLIDO')->count())->toBe(1);
})->with([
    'contraseña incorrecta' => ['tecnico@ecci.edu.co', 'otra-clave'],
    'correo no registrado' => ['nadie@ecci.edu.co', 'Clave*Segura1'],
]);

it('bloquea la cuenta 15 minutos tras 5 intentos fallidos consecutivos', function () {
    $usuario = crearUsuarioLogin();

    foreach (range(1, 5) as $intento) {
        $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'incorrecta'])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1'])
        ->assertStatus(423)
        ->assertJsonPath('detail', 'Cuenta bloqueada temporalmente por seguridad. Intente más tarde');

    expect($usuario->refresh()->bloqueado_hasta->diffInMinutes(now(), true))->toBeGreaterThan(14);

    $this->travel(16)->minutes();

    $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1'])->assertOk();
});

it('deniega el acceso a usuarios inactivos con credenciales correctas', function () {
    crearUsuarioLogin(atributos: ['activo' => false]);

    $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1'])
        ->assertForbidden()
        ->assertJsonPath('detail', 'Usuario inactivo. Comuníquese con el administrador del sistema');
});

it('rota el refresh token y revoca la familia si se reutiliza un token ya usado', function () {
    crearUsuarioLogin();
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1']);
    $primero = $login->getCookie('praxis_refresh', false)->getValue();

    $refresco = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $primero])->assertOk();
    $segundo = $refresco->getCookie('praxis_refresh', false)->getValue();

    expect($segundo)->not->toBe($primero);

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $primero])->assertUnauthorized();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $segundo])->assertUnauthorized();
});

it('rechaza tokens de acceso expirados', function () {
    crearUsuarioLogin();
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1'])->json('access_token');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $this->travel(16)->minutes();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('restablece la contraseña con un enlace de un solo uso sin revelar si el correo existe', function () {
    Notification::fake();
    $usuario = crearUsuarioLogin();
    $mensaje = 'Si el correo se encuentra registrado, hemos enviado las instrucciones a su bandeja de entrada';

    $this->postJson('/api/v1/auth/solicitar-recuperacion', ['email' => 'nadie@ecci.edu.co'])->assertOk()->assertJsonPath('mensaje', $mensaje);
    $this->postJson('/api/v1/auth/solicitar-recuperacion', ['email' => 'tecnico@ecci.edu.co'])->assertOk()->assertJsonPath('mensaje', $mensaje);

    $token = null;
    Notification::assertSentTo($usuario, RestablecerPasswordNotification::class, function (RestablecerPasswordNotification $notificacion) use (&$token) {
        $token = $notificacion->token;

        return true;
    });
    Notification::assertCount(1);

    $this->getJson("/api/v1/auth/restablecer-password/{$token}")->assertOk()->assertJsonPath('valido', true);

    $this->postJson('/api/v1/auth/restablecer-password', ['token' => $token, 'password' => 'debil', 'password_confirmation' => 'debil'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->postJson('/api/v1/auth/restablecer-password', ['token' => $token, 'password' => 'Nueva*Clave9', 'password_confirmation' => 'Nueva*Clave9'])
        ->assertOk()
        ->assertJsonPath('redirect_to', '/login');

    expect(Hash::check('Nueva*Clave9', $usuario->refresh()->password))->toBeTrue();

    $this->postJson('/api/v1/auth/restablecer-password', ['token' => $token, 'password' => 'Otra*Clave9', 'password_confirmation' => 'Otra*Clave9'])
        ->assertStatus(410)
        ->assertJsonPath('type', 'https://praxis.ecci.edu.co/problemas/enlace-expirado')
        ->assertJsonPath('detail', 'El enlace de restablecimiento ha expirado. Por favor solicite uno nuevo');
});

it('bloquea el enlace de recuperación vencido a los 30 minutos', function () {
    Notification::fake();
    crearUsuarioLogin();
    $this->postJson('/api/v1/auth/solicitar-recuperacion', ['email' => 'tecnico@ecci.edu.co']);
    $token = Notification::sent(User::first(), RestablecerPasswordNotification::class)->first()->token;

    $this->travel(45)->minutes();

    $this->getJson("/api/v1/auth/restablecer-password/{$token}")
        ->assertStatus(410)
        ->assertJsonPath('detail', 'El enlace de restablecimiento ha expirado. Por favor solicite uno nuevo');
    expect(TokenRecuperacion::query()->value('token_hash'))->toBe(hash('sha256', $token));
});

it('permite al cliente web enviar la cookie de refresh desde su origen (CORS con credenciales)', function () {
    $origen = config('praxis.frontend_url');

    $this->call('OPTIONS', '/api/v1/auth/refresh', server: [
        'HTTP_ORIGIN' => $origen,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])
        ->assertHeader('Access-Control-Allow-Origin', $origen)
        ->assertHeader('Access-Control-Allow-Credentials', 'true');

    $ajeno = $this->call('OPTIONS', '/api/v1/auth/refresh', server: [
        'HTTP_ORIGIN' => 'https://sitio-ajeno.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    expect($ajeno->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://sitio-ajeno.com');
});

it('no guarda la contraseña enviada en el evento de auditoría del login fallido', function () {
    crearUsuarioLogin();

    $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Secreto*Enviado9'])->assertUnauthorized();

    $evento = EventoAuditoria::query()->where('tipo_evento', 'LOGIN_FALLIDO')->sole();
    expect(json_encode($evento->toArray()))->not->toContain('Secreto*Enviado9');
});

it('mantiene la cuenta bloqueada con 423 a los 14 minutos y 59 segundos del bloqueo', function () {
    $this->freezeSecond();
    crearUsuarioLogin();
    foreach (range(1, 5) as $intento) {
        $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'incorrecta'])->assertUnauthorized();
    }

    $this->travel(14 * 60 + 59)->seconds();

    $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1'])
        ->assertStatus(423)
        ->assertJsonPath('detail', 'Cuenta bloqueada temporalmente por seguridad. Intente más tarde');
});

it('permite iniciar sesión al cumplirse exactamente los 15 minutos del bloqueo', function () {
    $this->freezeSecond();
    $usuario = crearUsuarioLogin();
    foreach (range(1, 5) as $intento) {
        $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'incorrecta'])->assertUnauthorized();
    }

    $this->travel(15)->minutes();

    $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1'])->assertOk();
    expect($usuario->refresh())
        ->intentos_fallidos->toBe(0)
        ->bloqueado_hasta->toBeNull();
});

it('redirige a la ruta por defecto del rol cuando no se solicita un deep-link', function (Rol $rol, string $rutaEsperada) {
    crearUsuarioLogin($rol);

    $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1'])
        ->assertOk()
        ->assertJsonPath('redirect_to', $rutaEsperada);
})->with([
    'R01 reportante' => [Rol::Reportante, '/panel'],
    'R02 gestor' => [Rol::Gestor, '/revision'],
    'R03 coordinador' => [Rol::Coordinador, '/planificacion'],
    'R04 técnico' => [Rol::Tecnico, '/mis-tareas'],
    'R05 autorizador' => [Rol::Autorizador, '/autorizaciones'],
    'R06 verificador' => [Rol::Verificador, '/verificacion'],
    'R07 administrador' => [Rol::Administrador, '/admin/usuarios'],
]);

it('acepta el enlace de recuperación a los 29 minutos y 59 segundos de emitido', function () {
    Notification::fake();
    $this->freezeSecond();
    $token = tokenDeRecuperacionPara(crearUsuarioLogin());

    $this->travel(29 * 60 + 59)->seconds();

    $this->getJson("/api/v1/auth/restablecer-password/{$token}")
        ->assertOk()
        ->assertJsonPath('valido', true);
});

it('rechaza con 422 la nueva contraseña que incumple la política y conserva la anterior', function (string $password, string $mensaje) {
    Notification::fake();
    $usuario = crearUsuarioLogin();
    $token = tokenDeRecuperacionPara($usuario);

    $this->postJson('/api/v1/auth/restablecer-password', ['token' => $token, 'password' => $password, 'password_confirmation' => $password])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => $mensaje]);

    expect(Hash::check('Clave*Segura1', $usuario->refresh()->password))->toBeTrue();
})->with([
    '7 caracteres' => ['Ab1*xyz', 'El campo contraseña debe tener al menos 8 caracteres.'],
    'sin mayúscula' => ['clave*segura1', 'El campo contraseña debe contener al menos una letra mayúscula y una minúscula.'],
    'sin minúscula' => ['CLAVE*SEGURA1', 'El campo contraseña debe contener al menos una letra mayúscula y una minúscula.'],
    'sin número' => ['Clave*Segura', 'El campo contraseña debe contener al menos un número.'],
    'sin símbolo' => ['ClaveSegura1', 'El campo contraseña debe contener al menos un símbolo.'],
]);

it('autentica con la nueva contraseña y rechaza con 401 la anterior tras restablecerla', function () {
    Notification::fake();
    $token = tokenDeRecuperacionPara(crearUsuarioLogin());

    $this->postJson('/api/v1/auth/restablecer-password', ['token' => $token, 'password' => 'Nueva*Clave9', 'password_confirmation' => 'Nueva*Clave9'])->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1'])
        ->assertUnauthorized()
        ->assertJsonPath('detail', 'Credenciales de acceso inválidas');
    $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Nueva*Clave9'])->assertOk();
});

it('revoca el access token y el refresh token vigentes al restablecer la contraseña', function () {
    Notification::fake();
    $usuario = crearUsuarioLogin();
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@ecci.edu.co', 'password' => 'Clave*Segura1']);
    $accessToken = $login->json('access_token');
    $refreshToken = $login->getCookie('praxis_refresh', false)->getValue();
    $token = tokenDeRecuperacionPara($usuario);

    $this->postJson('/api/v1/auth/restablecer-password', ['token' => $token, 'password' => 'Nueva*Clave9', 'password_confirmation' => 'Nueva*Clave9'])->assertOk();
    app('auth')->forgetGuards();

    $this->withToken($accessToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->flushHeaders()->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])->assertUnauthorized();
});
