<?php

namespace App\Providers;

use App\Services\ConfigLoader;
use App\Services\LayerResolver;
use App\Services\Methods\MethodExecutor;
use App\Services\Methods\MethodRegistry;
use App\Services\Packs\PackManager;
use App\Services\Tasks\BuiltIn\CodeRabbitReviewTask;
use App\Services\Tasks\BuiltIn\TestsAutoTask;
use App\Services\Tasks\BuiltIn\WorkspaceTempTask;
use App\Services\Tasks\Files\FilesWriteMdTask;
use App\Services\Tasks\Files\FilesWriteTask;
use App\Services\Tasks\Git\GitCloneTask;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowRunner;
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
        $this->app->singleton(LayerResolver::class);

        // Register ConfigLoader
        $this->app->singleton(ConfigLoader::class);

        // Register PackManager with paths
        $this->app->singleton(PackManager::class, function ($app) {
            $layerResolver = $app->make(LayerResolver::class);
            $globalSir = $layerResolver->getGlobalSirPath();

            return new PackManager(
                $globalSir.'/packs',
                $globalSir.'/packs.lock.json'
            );
        });

        // Register MethodRegistry
        $this->app->singleton(MethodRegistry::class);

        // Register MethodExecutor
        $this->app->singleton(MethodExecutor::class);

        // Register WorkflowLoader
        $this->app->singleton(WorkflowLoader::class);

        // Register TaskRegistry and register built-in tasks
        $this->app->singleton(TaskRegistry::class, function ($app) {
            $registry = new TaskRegistry;

            // Register built-in tasks
            $registry->register('workspace.temp', new WorkspaceTempTask);
            $registry->register('files.write', new FilesWriteTask);
            $registry->register('files.writeMd', new FilesWriteMdTask);
            $registry->register('git.clone', new GitCloneTask);
            $registry->register('tests.auto', new TestsAutoTask);
            $registry->register('coderabbit.review', new CodeRabbitReviewTask);

            return $registry;
        });

        // Register WorkflowRunner with OutputStyle
        $this->app->bind(WorkflowRunner::class, function ($app) {
            return new WorkflowRunner(
                $app->make(MethodRegistry::class),
                $app->make(MethodExecutor::class),
                $app->make(TaskRegistry::class),
                null // OutputStyle will be set by commands
            );
        });
    }
}
