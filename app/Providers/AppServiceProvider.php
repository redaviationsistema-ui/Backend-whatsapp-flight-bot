<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Quotes\SqlQuoteDataRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SqlQuoteDataRepository::class, function (Application $app): SqlQuoteDataRepository {
            /** @var DatabaseManager $database */
            $database = $app->make('db');
            $config = (array) config('database.connections.quote_db', []);

            if (! $this->quoteDatabaseIsConfigured($config)) {
                return new SqlQuoteDataRepository(null, false);
            }

            return new SqlQuoteDataRepository($database->connection('quote_db'), true);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-whatsapp', fn (User $user): bool => $user->is_admin === true);
        RateLimiter::for('admin-login', fn (Request $request): array => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function quoteDatabaseIsConfigured(array $config): bool
    {
        if (($config['url'] ?? null) !== null && $config['url'] !== '') {
            return true;
        }

        return ($config['database'] ?? null) !== null
            && $config['database'] !== ''
            && ($config['username'] ?? null) !== null
            && $config['username'] !== '';
    }
}
