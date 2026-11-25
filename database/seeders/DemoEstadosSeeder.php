<?php

namespace Database\Seeders;

use App\Enums\CategoriaEvento;
use App\Enums\EstadoIncidencia;
use App\Enums\EstadoSolicitudRecursos;
use App\Models\ActividadMantenimiento;
use App\Models\AsignacionIncidencia;
use App\Models\CategoriaIncidencia;
use App\Models\DetalleSolicitudRecursos;
use App\Models\Diagnostico;
use App\Models\EspacioFisico;
use App\Models\EventoAuditoria;
use App\Models\Evidencia;
use App\Models\Incidencia;
use App\Models\MaterialActividad;
use App\Models\PlanIntervencion;
use App\Models\Prioridad;
use App\Models\SolicitudAclaracion;
use App\Models\SolicitudRecursos;
use App\Models\Suspension;
use App\Models\User;
use App\Models\Verificacion;
use App\Support\ResultadoCierre;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Un ticket de demostración por cada estado (E01–E18) con historial y entidades
 * relacionadas, para recorrer consolas y fichas sin armar el flujo a mano.
 *
 * No se incluye en DatabaseSeeder. Ejecutar aparte:
 *   php artisan db:seed --class=DemoEstadosSeeder
 *   php artisan db:seed --class=DemoEstadosSeeder --force   (Railway / prod)
 */
class DemoEstadosSeeder extends Seeder
{
    /** Prefijo de consecutivos demo: TKT-{año}-99xxxx (no choca con el generador normal). */
    public const PREFIJO_CONSECUTIVO = 990000;

    /**
     * Cadena de estados públicos para la línea de tiempo hasta el destino.
     *
     * @var array<string, list<string>>
     */
    private const RUTAS = [
        'E01' => ['E01'],
        'E02' => ['E01', 'E03', 'E02'],
        'E03' => ['E01', 'E03'],
        'E04' => ['E01', 'E03', 'E04'],
        'E05' => ['E01', 'E03', 'E04', 'E05'],
        'E06' => ['E01', 'E03', 'E04', 'E05', 'E06'],
        'E07' => ['E01', 'E03', 'E04', 'E05', 'E06', 'E07'],
        'E08' => ['E01', 'E03', 'E04', 'E05', 'E06', 'E07', 'E08'],
        'E09' => ['E01', 'E03', 'E04', 'E05', 'E06', 'E07', 'E08', 'E09'],
        'E10' => ['E01', 'E03', 'E04', 'E05', 'E06', 'E07', 'E10'],
        'E11' => ['E01', 'E03', 'E04', 'E05', 'E06', 'E07', 'E10', 'E11'],
        'E12' => ['E01', 'E03', 'E04', 'E05', 'E06', 'E07', 'E10', 'E11', 'E12'],
        'E13' => ['E01', 'E03', 'E04', 'E05', 'E06', 'E07', 'E10', 'E11', 'E12', 'E13'],
        'E14' => ['E01', 'E03', 'E04', 'E05', 'E06', 'E07', 'E10', 'E11', 'E12', 'E14'],
        'E15' => ['E01', 'E03', 'E04', 'E05', 'E06', 'E07', 'E10', 'E11', 'E12', 'E14', 'E15'],
        'E16' => ['E01', 'E03', 'E16'],
        'E17' => ['E01', 'E17'],
        'E18' => ['E01', 'E03', 'E04', 'E05', 'E06', 'E07', 'E10', 'E18'],
    ];

    public function run(): void
    {
        $reportante = User::query()->where('email', 'reportante@praxis.test')->firstOrFail();
        $gestor = User::query()->where('email', 'gestor@praxis.test')->firstOrFail();
        $coordinador = User::query()->where('email', 'coordinador@praxis.test')->firstOrFail();
        $tecnico = User::query()->where('email', 'cerrajero@praxis.test')->firstOrFail();
        $autorizador = User::query()->where('email', 'autorizador@praxis.test')->firstOrFail();
        $verificador = User::query()->where('email', 'verificador@praxis.test')->firstOrFail();

        $espacio = EspacioFisico::query()->with('piso.edificio')->where('codigo', 'A-101')->firstOrFail();
        $categoria = CategoriaIncidencia::query()->where('codigo', 'CERRAJERIA')->firstOrFail();
        $prioridadAlta = Prioridad::query()->where('codigo', 'ALTA')->firstOrFail();
        $prioridadMedia = Prioridad::query()->where('codigo', 'MEDIA')->firstOrFail();

        $actores = compact('reportante', 'gestor', 'coordinador', 'tecnico', 'autorizador', 'verificador');
        $contexto = compact('espacio', 'categoria', 'prioridadAlta', 'prioridadMedia');

        $creados = 0;
        $omitidos = 0;

        foreach (EstadoIncidencia::cases() as $estado) {
            $codigo = $this->codigoTicket($estado);
            if (Incidencia::query()->where('codigo_ticket', $codigo)->exists()) {
                $this->command?->warn("Omitido {$codigo}: ya existe.");
                $omitidos++;

                continue;
            }

            DB::transaction(function () use ($estado, $codigo, $actores, $contexto, &$creados): void {
                $this->crearTicketDemo($estado, $codigo, $actores, $contexto);
                $creados++;
            });

            $this->command?->info("Creado {$codigo} — {$estado->nombre()}");
        }

        $this->command?->info("DemoEstadosSeeder: {$creados} creados, {$omitidos} omitidos.");
    }

    /**
     * @param  array{reportante: User, gestor: User, coordinador: User, tecnico: User, autorizador: User, verificador: User}  $actores
     * @param  array{espacio: EspacioFisico, categoria: CategoriaIncidencia, prioridadAlta: Prioridad, prioridadMedia: Prioridad}  $contexto
     */
    private function crearTicketDemo(EstadoIncidencia $destino, string $codigo, array $actores, array $contexto): void
    {
        $ruta = self::RUTAS[$destino->value];
        $alcanza = fn (string ...$codigos): bool => collect($ruta)->intersect($codigos)->isNotEmpty();
        $pasoClasificado = $alcanza('E05', 'E06', 'E07', 'E08', 'E09', 'E10', 'E11', 'E12', 'E13', 'E14', 'E15', 'E18');
        $pasoAsignado = $alcanza('E06', 'E07', 'E08', 'E09', 'E10', 'E11', 'E12', 'E13', 'E14', 'E15', 'E18');

        $espacio = $contexto['espacio'];
        $prioridad = in_array($destino, [EstadoIncidencia::PendienteAutorizacion, EstadoIncidencia::PendienteRecursos, EstadoIncidencia::Suspendida], true)
            ? $contexto['prioridadAlta']
            : $contexto['prioridadMedia'];

        $attrs = [
            'codigo_ticket' => $codigo,
            'resumen' => "DEMO {$destino->value} — {$destino->nombre()}",
            'descripcion' => $this->descripcion($destino),
            'canal_radicacion' => 'WEB',
            'sede_id' => $espacio->piso->edificio->sede_id,
            'edificio_id' => $espacio->piso->edificio_id,
            'piso_id' => $espacio->piso_id,
            'espacio_fisico_id' => $espacio->id,
            'categoria_id' => $contexto['categoria']->id,
            'estado_codigo' => $destino,
            'usuario_creador_id' => $actores['reportante']->id,
            'fecha_ultimo_cambio_estado' => now(),
        ];

        if ($pasoClasificado) {
            $attrs['impacto'] = 'MEDIO';
            $attrs['urgencia'] = $prioridad->codigo === 'ALTA' ? 'ALTA' : 'MEDIA';
            $attrs['prioridad_id'] = $prioridad->id;
            $attrs['fecha_primera_respuesta'] = now()->subDays(3);
            $attrs['fecha_limite_respuesta'] = now()->subDays(2);
            $attrs['fecha_limite_resolucion'] = now()->addDays(5);
            $attrs['sla_respuesta_cumplido'] = true;
        }

        if ($pasoAsignado) {
            $attrs['tecnico_asignado_id'] = $actores['tecnico']->id;
        }

        if ($destino === EstadoIncidencia::NoProcedente) {
            $attrs['resultado_final_cierre'] = ResultadoCierre::NO_PROCEDENTE;
            $attrs['notas_cierre'] = 'Fuera del alcance de mantenimiento: corresponde a adecuación de infraestructura.';
            $attrs['fecha_cierre'] = now()->subDay();
            $attrs['usuario_cierre_id'] = $actores['gestor']->id;
        }

        if ($destino === EstadoIncidencia::Cancelada) {
            $attrs['motivo_cancelacion'] = 'El reportante indicó que el problema se resolvió por su cuenta.';
            $attrs['usuario_cancelacion_id'] = $actores['reportante']->id;
            $attrs['resultado_final_cierre'] = ResultadoCierre::CANCELADA_POR_REPORTANTE;
            $attrs['fecha_cierre'] = now()->subHours(6);
        }

        if ($destino === EstadoIncidencia::Cerrada) {
            $attrs['calificacion_servicio'] = 5;
            $attrs['comentario_conformidad'] = 'Reparación conforme a lo esperado.';
            $attrs['fecha_confirmacion'] = now()->subHour();
            $attrs['resultado_final_cierre'] = ResultadoCierre::SOLUCION_CONFIRMADA;
            $attrs['fecha_cierre'] = now()->subHour();
            $attrs['usuario_cierre_id'] = $actores['reportante']->id;
            $attrs['sla_resolucion_cumplido'] = true;
        }

        if ($destino === EstadoIncidencia::PendienteConfirmacion) {
            $attrs['sla_resolucion_cumplido'] = true;
        }

        $incidencia = Incidencia::create($attrs);

        $this->registrarTimeline($incidencia, $ruta, $actores);

        if ($destino === EstadoIncidencia::PendienteInformacion) {
            SolicitudAclaracion::create([
                'incidencia_id' => $incidencia->id,
                'gestor_solicitante_id' => $actores['gestor']->id,
                'pregunta_gestor' => '¿La cerradura afecta solo esta aula o también el acceso al pasillo?',
                'fecha_solicitud' => now()->subDay(),
                'fecha_limite_respuesta' => now()->addDays(2),
                'estado' => SolicitudAclaracion::PENDIENTE,
            ]);
        }

        if ($pasoAsignado) {
            AsignacionIncidencia::create([
                'incidencia_id' => $incidencia->id,
                'tecnico_asignado_id' => $actores['tecnico']->id,
                'asignado_por_id' => $actores['coordinador']->id,
                'es_vigente' => true,
                'instrucciones' => 'Revisar mecanismo de la puerta principal del aula.',
                'fecha_visita_tentativa' => now()->subDays(2),
                'created_at' => now()->subDays(2),
            ]);
        }

        $diagnostico = null;
        $necesitaDiagnostico = $alcanza('E07', 'E08', 'E09', 'E10', 'E11', 'E12', 'E13', 'E14', 'E15', 'E18');
        if ($necesitaDiagnostico) {
            $estadoDiag = match (true) {
                $alcanza('E10', 'E11', 'E12', 'E13', 'E14', 'E15', 'E18') => Diagnostico::APROBADO,
                default => Diagnostico::REGISTRADO,
            };
            $requiereRecursos = $alcanza('E08', 'E09');
            $diagnostico = Diagnostico::create([
                'incidencia_id' => $incidencia->id,
                'tecnico_id' => $actores['tecnico']->id,
                'fecha_inicio_inspeccion' => now()->subDays(2)->subHour(),
                'fecha_fin_diagnostico' => now()->subDays(2),
                'incidencia_confirmada' => true,
                'hallazgos' => 'Cilindro de la cerradura desgastado; llave gira con holgura.',
                'descripcion_problema' => 'Desgaste mecánico del cilindro por uso intensivo.',
                'intervencion_propuesta' => 'Reemplazo del cilindro y lubricación del mecanismo.',
                'alcance' => 'Puerta principal del aula A-101.',
                'requiere_recursos' => $requiereRecursos,
                'requiere_autorizacion' => $requiereRecursos,
                'estado' => $estadoDiag,
                'revisado_por_id' => $estadoDiag === Diagnostico::APROBADO ? $actores['coordinador']->id : null,
                'fecha_revision' => $estadoDiag === Diagnostico::APROBADO ? now()->subDay() : null,
            ]);
        }

        if ($alcanza('E08', 'E09')) {
            $solicitudEstado = $destino === EstadoIncidencia::PendienteRecursos
                ? EstadoSolicitudRecursos::PendienteRecursos
                : EstadoSolicitudRecursos::PendienteAutorizacion;

            $solicitud = SolicitudRecursos::create([
                'incidencia_id' => $incidencia->id,
                'diagnostico_id' => $diagnostico?->id,
                'solicitante_id' => $actores['coordinador']->id,
                'decision_requerida' => 'Autorizar compra de cilindro de seguridad y kit de montaje.',
                'requiere_autorizacion' => true,
                'es_emergencia' => false,
                'estado_solicitud' => $solicitudEstado,
                'autorizador_id' => $solicitudEstado === EstadoSolicitudRecursos::PendienteRecursos ? $actores['autorizador']->id : null,
                'resultado_dictamen' => $solicitudEstado === EstadoSolicitudRecursos::PendienteRecursos ? 'APROBADO' : null,
                'justificacion_dictamen' => $solicitudEstado === EstadoSolicitudRecursos::PendienteRecursos
                    ? 'Aprobado. Pendiente ingreso a inventario.'
                    : null,
                'recursos_disponibles' => $solicitudEstado === EstadoSolicitudRecursos::PendienteRecursos ? false : null,
                'fecha_dictamen' => $solicitudEstado === EstadoSolicitudRecursos::PendienteRecursos ? now()->subHours(12) : null,
            ]);

            DetalleSolicitudRecursos::create([
                'solicitud_recurso_id' => $solicitud->id,
                'recurso_nombre' => 'Cilindro de seguridad',
                'cantidad' => 1,
                'unidad' => 'und',
                'costo_estimado' => 185000,
                'disponible' => false,
            ]);
            DetalleSolicitudRecursos::create([
                'solicitud_recurso_id' => $solicitud->id,
                'recurso_nombre' => 'Kit de montaje',
                'cantidad' => 1,
                'unidad' => 'und',
                'costo_estimado' => 45000,
                'disponible' => false,
            ]);
        }

        $plan = null;
        if ($alcanza('E10', 'E11', 'E12', 'E13', 'E14', 'E15', 'E18')) {
            $plan = PlanIntervencion::create([
                'incidencia_id' => $incidencia->id,
                'diagnostico_id' => $diagnostico?->id,
                'programado_por_id' => $actores['coordinador']->id,
                'fecha_programada' => $destino === EstadoIncidencia::Programada
                    ? now()->addDay()->toDateString()
                    : now()->subDay()->toDateString(),
                'hora_inicio' => '08:00',
                'hora_fin' => '11:00',
                'estado_plan' => $alcanza('E12', 'E13', 'E14', 'E15') && $destino !== EstadoIncidencia::EnCorreccion
                    ? PlanIntervencion::EJECUTADO
                    : PlanIntervencion::VIGENTE,
                'observaciones' => 'Intervención demo de cerrajería.',
            ]);
        }

        $actividad = null;
        if ($alcanza('E11', 'E12', 'E13', 'E14', 'E15')) {
            $cerrada = $alcanza('E12', 'E13', 'E14', 'E15');
            $actividad = ActividadMantenimiento::create([
                'incidencia_id' => $incidencia->id,
                'plan_intervencion_id' => $plan?->id,
                'tecnico_ejecutor_id' => $actores['tecnico']->id,
                'fecha_inicio' => now()->subHours($cerrada ? 5 : 1),
                'fecha_fin' => $cerrada ? now()->subHours(3) : null,
                'tiempo_invertido_minutos' => $cerrada ? 90 : null,
                'actividades_realizadas' => $cerrada
                    ? 'Se reemplazó el cilindro, se lubricó el mecanismo y se verificó el cierre.'
                    : null,
            ]);

            if ($cerrada) {
                MaterialActividad::create([
                    'actividad_mantenimiento_id' => $actividad->id,
                    'nombre' => 'Cilindro de seguridad',
                    'cantidad' => 1,
                    'unidad' => 'und',
                ]);
                Evidencia::create([
                    'incidencia_id' => $incidencia->id,
                    'etapa' => 'EJECUCION',
                    'referencia_tipo' => 'actividad_mantenimiento',
                    'referencia_id' => $actividad->id,
                    'nombre_original' => 'intervencion-final.png',
                    'disco' => 'evidencias',
                    'ruta_storage' => "demo/{$codigo}/intervencion-final.png",
                    'mime_type' => 'image/png',
                    'tamano_bytes' => 1024,
                    'hash_sha256' => hash('sha256', "demo-{$codigo}"),
                    'usuario_carga_id' => $actores['tecnico']->id,
                    'created_at' => now()->subHours(3),
                ]);
            }
        }

        if ($destino === EstadoIncidencia::EnCorreccion) {
            $verificacion = Verificacion::create([
                'incidencia_id' => $incidencia->id,
                'actividad_mantenimiento_id' => $actividad?->id,
                'verificador_id' => $actores['verificador']->id,
                'resultado_conforme' => false,
                'criterios_evaluados' => [
                    ['criterio' => 'La puerta cierra correctamente', 'cumple' => false],
                    ['criterio' => 'La llave opera sin holgura', 'cumple' => false],
                ],
                'observaciones' => 'El pestillo no engrana de forma consistente.',
                'motivo_rechazo' => 'Cierre intermitente tras el reemplazo del cilindro.',
                'correccion_requerida' => 'Ajustar alineación del pestillo y volver a lubricar.',
                'created_at' => now()->subHours(2),
            ]);

            PlanIntervencion::create([
                'incidencia_id' => $incidencia->id,
                'diagnostico_id' => $diagnostico?->id,
                'verificacion_rechazada_id' => $verificacion->id,
                'programado_por_id' => $actores['coordinador']->id,
                'fecha_programada' => now()->addDay()->toDateString(),
                'hora_inicio' => '09:00',
                'hora_fin' => '11:00',
                'estado_plan' => PlanIntervencion::VIGENTE,
                'observaciones' => 'Plan de corrección tras verificación no conforme.',
            ]);

            if ($plan !== null) {
                $plan->update(['estado_plan' => PlanIntervencion::REEMPLAZADO]);
            }
        }

        if (in_array($destino, [EstadoIncidencia::PendienteConfirmacion, EstadoIncidencia::Cerrada], true)) {
            Verificacion::create([
                'incidencia_id' => $incidencia->id,
                'actividad_mantenimiento_id' => $actividad?->id,
                'verificador_id' => $actores['verificador']->id,
                'resultado_conforme' => true,
                'criterios_evaluados' => [
                    ['criterio' => 'La puerta cierra correctamente', 'cumple' => true],
                    ['criterio' => 'La llave opera sin holgura', 'cumple' => true],
                ],
                'observaciones' => 'Intervención conforme.',
                'created_at' => now()->subHours(2),
            ]);
        }

        if ($destino === EstadoIncidencia::Suspendida) {
            Suspension::create([
                'incidencia_id' => $incidencia->id,
                'causal' => 'ACCESO_RESTRINGIDO',
                'motivo_detallado' => 'El aula está ocupada por un evento académico hasta nuevo aviso.',
                'condiciones_reanudacion' => 'Liberación del aula por coordinación académica.',
                'estado_origen' => EstadoIncidencia::Programada->value,
                'usuario_suspende_id' => $actores['coordinador']->id,
                'fecha_suspension' => now()->subHours(4),
            ]);
        }
    }

    /**
     * @param  list<string>  $ruta
     * @param  array{reportante: User, gestor: User, coordinador: User, tecnico: User, autorizador: User, verificador: User}  $actores
     */
    private function registrarTimeline(Incidencia $incidencia, array $ruta, array $actores): void
    {
        $base = now()->subDays(max(count($ruta), 1))->startOfHour();

        foreach ($ruta as $indice => $codigoNuevo) {
            $anterior = $indice === 0 ? null : $ruta[$indice - 1];
            $usuario = match ($codigoNuevo) {
                'E01' => $actores['reportante'],
                'E02', 'E03', 'E04', 'E05', 'E16' => $actores['gestor'],
                'E06', 'E10', 'E13', 'E18' => $actores['coordinador'],
                'E07', 'E11', 'E12' => $actores['tecnico'],
                'E08' => $actores['coordinador'],
                'E09' => $actores['autorizador'],
                'E14' => $actores['verificador'],
                'E15', 'E17' => $actores['reportante'],
                default => $actores['gestor'],
            };
            $rol = match ($codigoNuevo) {
                'E01', 'E15', 'E17' => 'R01',
                'E02', 'E03', 'E04', 'E05', 'E16' => 'R02',
                'E06', 'E08', 'E10', 'E13', 'E18' => 'R03',
                'E07', 'E11', 'E12' => 'R04',
                'E09' => 'R05',
                'E14' => 'R06',
                default => 'R02',
            };

            EventoAuditoria::create([
                'incidencia_id' => $incidencia->id,
                'tipo_evento' => $indice === 0 ? 'CREACION' : 'CAMBIO_ESTADO',
                'categoria' => CategoriaEvento::Estado->value,
                'visibilidad' => EventoAuditoria::PUBLICO,
                'fecha_hora' => $base->copy()->addHours($indice * 6),
                'usuario_id' => $usuario->id,
                'rol_codigo' => $rol,
                'estado_anterior' => $anterior,
                'estado_nuevo' => $codigoNuevo,
                'motivo' => $indice === 0
                    ? 'Radicación de demostración.'
                    : "Transición demo a {$codigoNuevo}.",
            ]);
        }
    }

    private function codigoTicket(EstadoIncidencia $estado): string
    {
        $numero = self::PREFIJO_CONSECUTIVO + (int) substr($estado->value, 1);

        return sprintf('TKT-%d-%06d', now()->year, $numero);
    }

    private function descripcion(EstadoIncidencia $estado): string
    {
        return match ($estado) {
            EstadoIncidencia::Registrada => 'Ticket recién radicado: la cerradura del aula no gira con normalidad.',
            EstadoIncidencia::PendienteInformacion => 'Se solicitó aclaración al reportante sobre el alcance del acceso afectado.',
            EstadoIncidencia::EnRevision => 'El gestor está revisando la solicitud y posibles duplicados.',
            EstadoIncidencia::EnClasificacion => 'En clasificación de impacto y urgencia según matriz ITIL.',
            EstadoIncidencia::PendienteAsignacion => 'Clasificada con prioridad; pendiente de asignación a técnico.',
            EstadoIncidencia::PendienteInspeccion => 'Asignada al cerrajero; pendiente de iniciar inspección en sitio.',
            EstadoIncidencia::EnDiagnostico => 'El técnico inició la inspección y registra el diagnóstico.',
            EstadoIncidencia::PendienteAutorizacion => 'El diagnóstico requiere compra de cilindro; pendiente de autorización.',
            EstadoIncidencia::PendienteRecursos => 'Autorización aprobada; materiales aún no disponibles en inventario.',
            EstadoIncidencia::Programada => 'Diagnóstico aprobado y plan de intervención vigente.',
            EstadoIncidencia::EnAtencion => 'El técnico está ejecutando la intervención programada.',
            EstadoIncidencia::PendienteVerificacion => 'Intervención registrada; pendiente de verificación de calidad.',
            EstadoIncidencia::EnCorreccion => 'Verificación no conforme; plan de corrección programado.',
            EstadoIncidencia::PendienteConfirmacion => 'Verificada conforme; pendiente de confirmación del reportante.',
            EstadoIncidencia::Cerrada => 'Ciclo completo: solución confirmada por el reportante.',
            EstadoIncidencia::NoProcedente => 'Declarada no procedente: fuera del alcance de mantenimiento.',
            EstadoIncidencia::Cancelada => 'Cancelada por el reportante antes de avanzar en el proceso.',
            EstadoIncidencia::Suspendida => 'Suspendida por acceso restringido al aula durante evento académico.',
        };
    }
}
