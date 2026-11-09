<?php

use App\Http\Controllers\Api\V1\AnonymousSignatureController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CertificateInspectionController;
use App\Http\Controllers\Api\V1\DeletionConsentController;
use App\Http\Controllers\Api\V1\DeletionRequestController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentEventController;
use App\Http\Controllers\Api\V1\DocumentFileController;
use App\Http\Controllers\Api\V1\DocumentSignatureController;
use App\Http\Controllers\Api\V1\DocumentVerificationController;
use App\Http\Controllers\Api\V1\OpenApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    /*
    |--------------------------------------------------------------------------
    | Anonymous signing (no account)
    |--------------------------------------------------------------------------
    */

    Route::post('signatures', AnonymousSignatureController::class)
        ->middleware('throttle:anonymous-signing')
        ->name('api.v1.signatures.store');

    Route::post('certificates/inspect', CertificateInspectionController::class)
        ->middleware('throttle:anonymous-signing')
        ->name('api.v1.certificates.inspect');

    /*
    |--------------------------------------------------------------------------
    | API description
    |--------------------------------------------------------------------------
    */

    Route::get('openapi.yaml', [OpenApiController::class, 'specification'])
        ->name('api.v1.openapi.yaml');
    Route::get('docs', [OpenApiController::class, 'documentation'])
        ->name('api.v1.docs');

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth')
        ->name('api.v1.auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth')
        ->name('api.v1.auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])
            ->name('api.v1.auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])
            ->name('api.v1.auth.me');

        /*
        |----------------------------------------------------------------------
        | Documents
        |----------------------------------------------------------------------
        */

        Route::get('documents', [DocumentController::class, 'index'])
            ->name('api.v1.documents.index');
        Route::post('documents', [DocumentController::class, 'store'])
            ->name('api.v1.documents.store');
        Route::get('documents/{document}', [DocumentController::class, 'show'])
            ->name('api.v1.documents.show');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])
            ->name('api.v1.documents.destroy');

        Route::get('documents/{document}/file', DocumentFileController::class)
            ->name('api.v1.documents.file');
        Route::get('documents/{document}/verification', DocumentVerificationController::class)
            ->name('api.v1.documents.verification');
        Route::get('documents/{document}/events', DocumentEventController::class)
            ->name('api.v1.documents.events');

        Route::get('documents/{document}/signatures', [DocumentSignatureController::class, 'index'])
            ->name('api.v1.documents.signatures.index');
        Route::post('documents/{document}/signatures', [DocumentSignatureController::class, 'store'])
            ->name('api.v1.documents.signatures.store');

        /*
        |----------------------------------------------------------------------
        | Deletion consent workflow
        |----------------------------------------------------------------------
        */

        Route::post('documents/{document}/deletion-requests', [DeletionRequestController::class, 'store'])
            ->name('api.v1.documents.deletion-requests.store');
        Route::get('documents/{document}/deletion-requests/{deletionRequest}', [DeletionRequestController::class, 'show'])
            ->whereNumber('deletionRequest')
            ->name('api.v1.documents.deletion-requests.show');
        Route::post(
            'documents/{document}/deletion-requests/{deletionRequest}/consents',
            DeletionConsentController::class
        )
            ->whereNumber('deletionRequest')
            ->name('api.v1.documents.deletion-requests.consents.store');
    });
});
