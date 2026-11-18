<?php

namespace App\Providers;

use App\Services\Azure\Security\TokenCipher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TokenCipher::class, fn () => new TokenCipher(
            config('azure.token_encryption.keys'),
            config('azure.token_encryption.current'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
