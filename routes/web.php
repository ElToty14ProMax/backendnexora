<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'ok' => true,
    'message' => 'nexora-backend-laravel',
]));
