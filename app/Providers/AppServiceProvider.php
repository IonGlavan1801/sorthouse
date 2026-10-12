<?php

namespace App\Providers;

use App\Application\ActionExecution\ActionExecutor;
use App\Application\ActionExecution\SimulatedActionExecutor;
use App\Application\Pipeline\AttachmentProcessor;
use App\Infrastructure\AI\Contracts\EvidenceResolver;
use App\Infrastructure\AI\Contracts\MalwareScanner;
use App\Infrastructure\AI\ProviderGateway;
use App\Infrastructure\AI\QuoteEvidenceResolver;
use App\Infrastructure\Documents\FixtureOcrExtractor;
use App\Infrastructure\Documents\PdfTextExtractor;
use App\Infrastructure\Security\SimulatedMalwareScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EvidenceResolver::class, QuoteEvidenceResolver::class);
        $this->app->bind(MalwareScanner::class, SimulatedMalwareScanner::class);
        $this->app->bind(ActionExecutor::class, SimulatedActionExecutor::class);
        $this->app->bind(AttachmentProcessor::class, fn ($app) => new AttachmentProcessor([$app->make(PdfTextExtractor::class), $app->make(FixtureOcrExtractor::class)], $app->make(ProviderGateway::class)));
    }

    public function boot(): void
    {
        if (app()->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            // behind Cloudflare/Traefik the internal port must not leak into asset URLs
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        // separate limits for session creation, ingestion, reprocessing/AI-heavy work and failure simulation
        $key = fn (Request $r) => $r->session()->getId() ?: $r->ip();
        RateLimiter::for('demo-launch', fn (Request $r) => Limit::perMinute(config('sorthouse.limits.launch_per_minute'))->by($r->ip()));
        RateLimiter::for('ingest', fn (Request $r) => Limit::perMinute(30)->by($key($r)));
        RateLimiter::for('reprocess', fn (Request $r) => Limit::perMinute(12)->by($key($r)));
        RateLimiter::for('failure-sim', fn (Request $r) => Limit::perMinute(20)->by($key($r)));
        RateLimiter::for('review', fn (Request $r) => Limit::perMinute(90)->by($key($r)));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($key($r)));
    }
}
