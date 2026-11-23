<?php

namespace App\Console\Commands;

use App\Services\EvidenciaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Elimina del disco y de la base de datos las evidencias huérfanas (sin incidencia) con más de 24 horas.
 */
#[Signature('praxis:limpiar-evidencias-huerfanas')]
#[Description('Elimina las evidencias sin incidencia vinculada creadas hace más de 24 horas')]
class LimpiarEvidenciasHuerfanasCommand extends Command
{
    public function handle(EvidenciaService $evidencias): int
    {
        $eliminadas = $evidencias->limpiarHuerfanas(24);

        $this->info("Evidencias huérfanas eliminadas: {$eliminadas}");

        return self::SUCCESS;
    }
}
