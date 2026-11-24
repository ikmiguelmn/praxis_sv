<?php

namespace App\Providers;

use App\Mail\MailjetApiTransport;
use App\Support\ContextoRol;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ContextoRol::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('recuperacion', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));

        Mail::extend('mailjet', function (array $config = []): MailjetApiTransport {
            return new MailjetApiTransport(
                apiKey: (string) ($config['key'] ?? config('mail.mailers.smtp.username') ?? ''),
                secretKey: (string) ($config['secret'] ?? config('mail.mailers.smtp.password') ?? ''),
            );
        });
    }
}
