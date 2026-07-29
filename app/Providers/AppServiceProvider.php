<?php

namespace App\Providers;

use App\Apps\AppRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * The installed apps, resolved once. Registered here rather than resolved
         * ad hoc because routes/api.php asks for it while the route table is
         * still being built, and everything afterwards — the launcher, the nav,
         * the access screen, the app-access middleware — reads the same list.
         */
        $this->app->singleton(AppRegistry::class, fn (): AppRegistry => new AppRegistry);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
