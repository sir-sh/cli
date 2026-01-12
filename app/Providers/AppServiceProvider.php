<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register LayerResolver
        $this->app->singleton(\App\Services\LayerResolver::class);

        // Register ConfigLoader
        $this->app->singleton(\App\Services\ConfigLoader::class);

        // Register PackManager with paths
        $this->app->singleton(\App\Services\Packs\PackManager::class, function ($app) {
            $layerResolver = $app->make(\App\Services\LayerResolver::class);
            $globalSir = $layerResolver->getGlobalSirPath();

            return new \App\Services\Packs\PackManager(
                $globalSir.'/packs',
                $globalSir.'/packs.lock.json'
            );
        });

        // Register MethodRegistry
        $this->app->singleton(\App\Services\Methods\MethodRegistry::class);

        // Register MethodExecutor
        $this->app->singleton(\App\Services\Methods\MethodExecutor::class);

        // Register WorkflowLoader
        $this->app->singleton(\App\Services\Workflows\WorkflowLoader::class);

        // Register TaskRegistry and register built-in tasks
        $this->app->singleton(\App\Services\Tasks\TaskRegistry::class, function ($app) {
            $registry = new \App\Services\Tasks\TaskRegistry;

            // Register built-in tasks
            $registry->register('workspace.temp', new \App\Services\Tasks\BuiltIn\WorkspaceTempTask);
            $registry->register('files.write', new \App\Services\Tasks\Files\FilesWriteTask);
            $registry->register('files.writeMd', new \App\Services\Tasks\Files\FilesWriteMdTask);
            $registry->register('git.clone', new \App\Services\Tasks\Git\GitCloneTask);
            $registry->register('tests.auto', new \App\Services\Tasks\BuiltIn\TestsAutoTask);
            $registry->register('coderabbit.review', new \App\Services\Tasks\BuiltIn\CodeRabbitReviewTask);

            return $registry;
        });

        // Register WorkflowRunner with OutputStyle
        $this->app->bind(\App\Services\Workflows\WorkflowRunner::class, function ($app) {
            return new \App\Services\Workflows\WorkflowRunner(
                $app->make(\App\Services\Methods\MethodRegistry::class),
                $app->make(\App\Services\Methods\MethodExecutor::class),
                $app->make(\App\Services\Tasks\TaskRegistry::class),
                null // OutputStyle will be set by commands
            );
        });
    }
}
