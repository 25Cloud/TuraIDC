<?php

use App\Http\Controllers\Open\V2\OpenProductController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.key:products,read'])->group(function (): void {
    Route::get('/products', [OpenProductController::class, 'index']);
    Route::get('/products/{product}', [OpenProductController::class, 'show']);
    Route::get('/products/{product}/quotes', [OpenProductController::class, 'quotes']);
});
