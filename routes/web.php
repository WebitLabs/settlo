<?php

use App\Http\Controllers\ExpenseReceiptController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

// Authorised download of a private receipt file (policy-checked in the controller).
Route::middleware('auth')
    ->get('/receipts/{expense}', ExpenseReceiptController::class)
    ->name('receipts.show');
