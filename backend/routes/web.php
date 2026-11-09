<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'api' => url('/api/v1'),
        'openapi' => url('/api/v1/openapi.yaml'),
        'docs' => url('/api/v1/docs'),
    ]);
});
