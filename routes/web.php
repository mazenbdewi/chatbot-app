<?php

use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/chat', 'chat');

Route::post('/chat', [ChatbotController::class, 'chat']);
