<?php

use App\Http\Controllers\NexoraController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [NexoraController::class, 'health']);

Route::prefix('auth')->middleware('throttle:12,1')->group(function () {
    Route::post('/register', [NexoraController::class, 'register'])->middleware('throttle:5,5');
    Route::post('/resend-verification', [NexoraController::class, 'resendVerification'])->middleware('throttle:3,5');
    Route::post('/recover-password', [NexoraController::class, 'recoverPassword'])->middleware('throttle:3,5');
    Route::post('/reset-password', [NexoraController::class, 'resetPassword'])->middleware('throttle:6,5');
    Route::post('/verify-email', [NexoraController::class, 'verifyEmail'])->middleware('throttle:8,5');
    Route::post('/login', [NexoraController::class, 'login']);
});

Route::get('/me', [NexoraController::class, 'me']);
Route::get('/dashboard', [NexoraController::class, 'dashboard']);
Route::get('/community', [NexoraController::class, 'community']);

Route::prefix('support-requests')->group(function () {
    Route::get('/mine', [NexoraController::class, 'myRequests']);
    Route::get('/contributions/mine', [NexoraController::class, 'myContributions']);
    Route::post('/', [NexoraController::class, 'createSupportRequest']);
    Route::post('/contributions/auto-split', [NexoraController::class, 'createContributionBatch']);
    Route::post('/{id}/contributions', [NexoraController::class, 'createContribution']);
    Route::post('/contributions/{id}/receipt', [NexoraController::class, 'submitReceipt']);
});

Route::post('/receipts/analyze', [NexoraController::class, 'analyzeReceipt']);

Route::prefix('admin')->group(function () {
    Route::get('/overview', [NexoraController::class, 'adminOverview']);
    Route::get('/audit-logs', [NexoraController::class, 'auditLogs']);
    Route::get('/users', [NexoraController::class, 'adminUsers']);
    Route::post('/users/{id}/approve', [NexoraController::class, 'adminApproveUser']);
    Route::post('/users/{id}/block', [NexoraController::class, 'adminBlockUser']);
    Route::post('/users/{id}/unblock', [NexoraController::class, 'adminUnblockUser']);
    Route::post('/users/{id}/confirm-admin-fee', [NexoraController::class, 'adminConfirmFee']);
    Route::post('/users/{id}/role', [NexoraController::class, 'adminUpdateRole']);
    Route::post('/users/{id}/reputation', [NexoraController::class, 'adminUpdateReputation']);
    Route::post('/system/reset-database', [NexoraController::class, 'adminResetDatabase']);
    Route::get('/support-requests', [NexoraController::class, 'adminSupportRequests']);
    Route::post('/support-requests/{id}/approve', [NexoraController::class, 'adminApproveRequest']);
    Route::post('/support-requests/{id}/reject', [NexoraController::class, 'adminRejectRequest']);
    Route::post('/support-requests/{id}/confirm-return', [NexoraController::class, 'adminConfirmReturn']);
    Route::get('/contributions', [NexoraController::class, 'adminContributions']);
    Route::post('/contributions/{id}/confirm', [NexoraController::class, 'adminConfirmContribution']);
    Route::post('/contributions/{id}/reject', [NexoraController::class, 'adminRejectContribution']);
    Route::post('/contributions/{id}/deactivate', [NexoraController::class, 'adminDeactivateContribution']);
    Route::post('/contributions/{id}/activate', [NexoraController::class, 'adminActivateContribution']);
});

Route::post('/system/migrate', [NexoraController::class, 'runMigrations']);
Route::post('/system/check-expired', [NexoraController::class, 'checkExpiredContributions']);
