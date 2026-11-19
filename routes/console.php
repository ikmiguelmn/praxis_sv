<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('praxis:vencer-aclaraciones')->dailyAt('06:00')->timezone(config('praxis.zona_horaria'))->withoutOverlapping();
Schedule::command('praxis:limpiar-evidencias-huerfanas')->dailyAt('03:00')->timezone(config('praxis.zona_horaria'))->withoutOverlapping();
