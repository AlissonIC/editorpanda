<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('entrar', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('entrar', [AuthenticatedSessionController::class, 'store']);

    Route::get('cadastrar', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('cadastrar', [RegisteredUserController::class, 'store']);
    Route::get('cadastro/aguardando', fn () => view('auth.cadastro-aguardando'))->name('cadastro.aguardando');

    Route::get('esqueci-a-senha', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('esqueci-a-senha', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('redefinir-senha/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('redefinir-senha', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verificar-email', EmailVerificationPromptController::class)->name('verification.notice');

    Route::get('verificar-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/notificacao-verificacao', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirmar-senha', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirmar-senha', [ConfirmablePasswordController::class, 'store']);

    Route::put('senha', [PasswordController::class, 'update'])->name('password.update');

    Route::post('sair', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
