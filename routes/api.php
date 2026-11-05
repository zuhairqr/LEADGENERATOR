<?php
use App\Http\Controllers\ImportController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\OutreachController;
use Illuminate\Support\Facades\Route;

Route::post('/leads/import', ImportController::class)->middleware(['import.token', 'throttle:imports']);
Route::middleware(['import.token', 'throttle:imports'])->group(function () {
    Route::post('/collection/business-tick',[\App\Http\Controllers\BusinessController::class,'tick']);
    Route::post('/sales/inbox-sync',[\App\Http\Controllers\InboxController::class,'tick']);
    Route::post('/sales/channel-receipt',[\App\Http\Controllers\ChannelController::class,'receipt']);
    Route::post('/sales/gmail-envelope',[\App\Http\Controllers\OutreachController::class,'gmailEnvelope']);
    Route::post('/sales/tick', [OutreachController::class, 'tick']);
    Route::post('/sales/receipt', [OutreachController::class, 'receipt']);
    Route::get('/collection/sources', [CollectionController::class, 'sources']);
    Route::post('/collection/{source}/claim', [CollectionController::class, 'claim']);
    Route::post('/collection/runs/{run}/complete', [CollectionController::class, 'complete']);
});
