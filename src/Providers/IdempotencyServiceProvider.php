<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Providers;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Override;
use WendellAdriel\Idempotency\Console\Commands\ForgetCommand;
use WendellAdriel\Idempotency\Console\Commands\ListCommand;
use WendellAdriel\Idempotency\Http\Middleware\Idempotent;
use WendellAdriel\Idempotency\Idempotency;
use WendellAdriel\Idempotency\Support\IdempotencyCache;
use WendellAdriel\Idempotency\Support\IdempotencyIndex;

final class IdempotencyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes(
            [
                __DIR__ . '/../../config/idempotency.php' => base_path('config/idempotency.php'),
            ],
            ['idempotency', 'idempotency-config']
        );

        Blade::directive('idempotency', fn (string $expression): string => sprintf(
            '<?php echo \\%s::field(%s); ?>',
            Idempotency::class,
            $expression,
        ));

        $this->app->make(Router::class)->aliasMiddleware('idempotent', Idempotent::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ForgetCommand::class,
                ListCommand::class,
            ]);
        }
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/idempotency.php', 'idempotency');

        $this->app->singleton(IdempotencyCache::class, function (Application $app): IdempotencyCache {
            /** @var Repository $cache */
            $cache = $app->make('cache.store');

            return new IdempotencyCache($cache);
        });

        $this->app->singleton(IdempotencyIndex::class, function (Application $app): IdempotencyIndex {
            /** @var Repository $cache */
            $cache = $app->make('cache.store');

            return new IdempotencyIndex($cache);
        });
    }
}
