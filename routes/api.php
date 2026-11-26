<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/branches', [CatalogController::class, 'branches']);
    Route::get('/categories', [CatalogController::class, 'categories']);
    Route::get('/sellers', [CatalogController::class, 'sellers']);
    Route::get('/products', [CatalogController::class, 'products']);

    Route::get('/sales', [SaleController::class, 'index']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::get('/sales/{sale}', [SaleController::class, 'show']);
    Route::post('/sales/{sale}/cancel', [SaleController::class, 'cancel']);

    Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/reports/monthly-sales', [ReportController::class, 'monthlySales']);
    Route::get('/reports/top-products', [ReportController::class, 'topProducts']);
    Route::get('/reports/sellers', [ReportController::class, 'sellers']);
    Route::get('/reports/branch-category', [ReportController::class, 'branchCategory']);
    Route::get('/reports/margins', [ReportController::class, 'margins']);
    Route::get('/reports/{report}/export', [ReportController::class, 'export']);
});
