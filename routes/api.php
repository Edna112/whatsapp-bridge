<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebhookController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

 

// This handles the GET request Meta sends to verify your server
Route::get('/webhook', [WebhookController::class, 'verify']);
// This handles the POST request Meta sends when a message arrives
Route::post('/webhook', [WebhookController::class, 'receive']);
