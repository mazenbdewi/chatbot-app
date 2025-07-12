<?php

use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ProjectController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

// Route::apiGet('index', [ChatbotController::class, 'index']);
// Route::post('chat', [ChatbotController::class, 'chat']);

Route::apiResource('chat', ProjectController::class);
