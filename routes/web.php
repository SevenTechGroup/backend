<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Sahel Signal backend is running',
        'status' => 'ok',
    ]);
});
