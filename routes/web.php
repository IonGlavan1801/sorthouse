<?php

use App\Http\Controllers\CaseController;
use App\Http\Controllers\MetaController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('landing');
Route::view('/app', 'app')->name('app');
Route::get('/api/health', [MetaController::class, 'health']);
Route::get('/api/capabilities', [MetaController::class, 'capabilities']);
Route::get('/api/evaluation', [MetaController::class, 'evaluation']);
Route::get('/api/demo/scenarios', [SessionController::class, 'scenarios']);

Route::prefix('api')->group(function () {
    Route::post('/demo/sessions', [SessionController::class, 'launch'])->middleware('throttle:demo-launch');

    Route::middleware(['demo.session', 'throttle:api'])->group(function () {
        Route::get('/demo/sessions/current', [SessionController::class, 'current']);
        Route::delete('/demo/sessions/current', [SessionController::class, 'destroy']);
        Route::get('/demo/sessions/{id}/inbox', [SessionController::class, 'inbox']);
        Route::get('/demo/sessions/{id}/events', [SessionController::class, 'events']);
        Route::post('/demo/sessions/{id}/fixtures/{fixture}/inject', [SessionController::class, 'inject'])->middleware('throttle:ingest');
        Route::post('/demo/scenarios/reset', [SessionController::class, 'scenarioReset'])->middleware('throttle:failure-sim');
        Route::post('/demo/scenarios/{scenario}', [SessionController::class, 'scenario'])->middleware('throttle:failure-sim');
        Route::get('/usage', [MetaController::class, 'usage']);

        Route::prefix('triage-cases/{id}')->group(function () {
            Route::get('/', [CaseController::class, 'show']);
            Route::get('/trace', [CaseController::class, 'trace']);
            Route::post('/reprocess', [CaseController::class, 'reprocess'])->middleware('throttle:reprocess');
            Route::post('/reconcile', [CaseController::class, 'reconcile'])->middleware('throttle:review');
            Route::post('/review/approve', [CaseController::class, 'approve'])->middleware('throttle:review');
            Route::post('/review/correct', [CaseController::class, 'correct'])->middleware('throttle:review');
            Route::post('/review/reject', [CaseController::class, 'reject'])->middleware('throttle:review');
            Route::get('/attachments/{attachment}', [CaseController::class, 'attachment']);
            Route::get('/evidence/{evidence}', [CaseController::class, 'evidence']);
        });
    });
});
