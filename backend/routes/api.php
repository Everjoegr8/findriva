<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ActionController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\AiAnswerController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BusinessChildController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessProfileController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\CoverageController;
use App\Http\Controllers\Api\V1\DigitalAssetController;
use App\Http\Controllers\Api\V1\GrowthController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\LocalGridController;
use App\Http\Controllers\Api\V1\RankingController;
use App\Http\Controllers\Api\V1\ResultsController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('mfa/challenge', [AuthController::class, 'mfaChallenge']);
        Route::post('password/forgot', [AuthController::class, 'forgotPassword']);
        Route::post('password/reset', [AuthController::class, 'resetPassword']);
        Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware('signed')->name('verification.verify');
    });

    // Paystack server-to-server notifications (signature checked in the controller).
    Route::post('billing/paystack/webhook', [BillingController::class, 'webhook'])->middleware('throttle:120,1');

    // Google sends the browser back here after consent. Authenticated by the one-time state value.
    Route::get('integrations/google/callback', [IntegrationController::class, 'googleCallback'])->middleware('throttle:auth');
    Route::get('integrations/bing/callback', [IntegrationController::class, 'bingCallback'])->middleware('throttle:auth');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:6,60');
        Route::get('account/security', [AccountController::class, 'security']);
        Route::post('account/mfa/setup', [AccountController::class, 'beginMfa']);
        Route::post('account/mfa/confirm', [AccountController::class, 'confirmMfa'])->middleware('throttle:auth');
        Route::post('account/mfa/disable', [AccountController::class, 'disableMfa'])->middleware('throttle:auth');

        Route::get('workspaces', [WorkspaceController::class, 'index']);
        Route::post('workspaces', [WorkspaceController::class, 'store']);

        // Everything below is tenant-scoped to the X-Workspace-Id workspace.
        Route::middleware('workspace')->group(function () {
            Route::get('workspace', [WorkspaceController::class, 'show']);
            Route::patch('workspace', [WorkspaceController::class, 'update']);
            Route::get('workspace/members', [WorkspaceController::class, 'members']);
            Route::post('workspace/members', [WorkspaceController::class, 'addMember'])->middleware('verified');
            Route::patch('workspace/members/{membership}', [WorkspaceController::class, 'updateMember']);
            Route::delete('workspace/members/{membership}', [WorkspaceController::class, 'removeMember']);
            Route::get('workspace/activity', [WorkspaceController::class, 'activity']);
            Route::get('coverage', [CoverageController::class, 'show']);
            Route::get('workspace/billing', [BillingController::class, 'show']);
            Route::post('workspace/billing/checkout', [BillingController::class, 'checkout'])->middleware('verified');
            Route::post('workspace/billing/confirm', [BillingController::class, 'confirm']);

            Route::get('businesses', [BusinessController::class, 'index']);
            Route::post('businesses', [BusinessController::class, 'store']);
            Route::prefix('businesses/{business}')->group(function () {
                Route::get('/', [BusinessController::class, 'show']);
                Route::patch('/', [BusinessController::class, 'update']);
                Route::delete('/', [BusinessController::class, 'destroy']);
                Route::get('overview', [BusinessController::class, 'overview']);

                Route::get('assets', [DigitalAssetController::class, 'index']);
                Route::post('assets', [DigitalAssetController::class, 'store']);
                Route::patch('assets/{asset}', [DigitalAssetController::class, 'update']);
                Route::delete('assets/{asset}', [DigitalAssetController::class, 'destroy']);
                Route::post('assets/{asset}/scan', [DigitalAssetController::class, 'rescan']);

                Route::get('audits', [AuditController::class, 'index']);
                Route::post('audits', [AuditController::class, 'store'])->middleware('throttle:audits');
                Route::get('audits/{run}', [AuditController::class, 'show']);
                Route::post('audits/{run}/cancel', [AuditController::class, 'cancel']);
                Route::get('issues', [AuditController::class, 'issues']);
                Route::patch('issues/{issue}', [AuditController::class, 'updateIssue']);

                Route::get('actions', [ActionController::class, 'index']);
                Route::post('actions', [ActionController::class, 'store']);
                Route::patch('actions/{actionTask}', [ActionController::class, 'update']);
                Route::post('actions/{actionTask}/request-approval', [ActionController::class, 'requestApproval']);
                Route::post('actions/{actionTask}/decision', [ActionController::class, 'approve']);

                Route::get('fixes', [GrowthController::class, 'fixes']);
                Route::get('public-page', [GrowthController::class, 'publication']);
                Route::post('public-page', [GrowthController::class, 'publish'])->middleware('verified');
                Route::delete('public-page', [GrowthController::class, 'unpublish']);

                Route::get('keywords', [GrowthController::class, 'keywords']);
                Route::post('keywords/research', [GrowthController::class, 'research'])->middleware('throttle:research');
                Route::patch('keywords/{keyword}', [GrowthController::class, 'updateKeyword']);

                Route::get('content', [ContentController::class, 'index']);
                Route::post('content', [ContentController::class, 'store'])->middleware('throttle:generate');
                Route::get('content/{content}', [ContentController::class, 'show']);
                Route::patch('content/{content}', [ContentController::class, 'update']);
                Route::delete('content/{content}', [ContentController::class, 'destroy']);
                Route::post('content/{content}/regenerate', [ContentController::class, 'regenerate'])->middleware('throttle:generate');
                Route::post('content/{content}/submit', [ContentController::class, 'submit']);
                Route::post('content/{content}/decision', [ContentController::class, 'decide']);
                Route::post('content/{content}/apply', [ContentController::class, 'apply']);

                Route::get('integrations', [IntegrationController::class, 'index']);
                Route::post('integrations/google/connect', [IntegrationController::class, 'connectGoogle'])->middleware('verified');
                Route::post('integrations/google-business/connect', [IntegrationController::class, 'connectBusinessProfile'])->middleware('verified');
                Route::post('integrations/bing/connect', [IntegrationController::class, 'connectBing'])->middleware('verified');
                Route::get('bing-performance', [ResultsController::class, 'bing']);
                Route::patch('integrations/{connection}', [IntegrationController::class, 'selectProperty']);
                Route::post('integrations/{connection}/sync', [IntegrationController::class, 'sync'])->middleware('throttle:research');
                Route::delete('integrations/{connection}', [IntegrationController::class, 'disconnect']);
                Route::post('indexnow/key', [IntegrationController::class, 'indexNowKey']);
                Route::post('indexnow/verify', [IntegrationController::class, 'indexNowVerify'])->middleware('throttle:research');
                Route::post('indexnow/submit', [IntegrationController::class, 'indexNowSubmit'])->middleware('throttle:research');

                Route::get('google-profile', [BusinessProfileController::class, 'show']);
                Route::get('reviews', [BusinessProfileController::class, 'reviews']);
                Route::get('reviews/{review}/suggestion', [BusinessProfileController::class, 'suggestion']);
                Route::patch('reviews/{review}', [BusinessProfileController::class, 'saveReply']);
                Route::post('reviews/{review}/submit', [BusinessProfileController::class, 'submitReply']);
                Route::post('reviews/{review}/decision', [BusinessProfileController::class, 'decideReply'])->middleware('verified');

                Route::get('ai-answers', [AiAnswerController::class, 'index']);
                Route::post('ai-answers/check', [AiAnswerController::class, 'check'])->middleware('throttle:research');
                Route::post('ai-questions/suggest', [AiAnswerController::class, 'suggest']);
                Route::post('ai-questions', [AiAnswerController::class, 'store']);
                Route::patch('ai-questions/{question}', [AiAnswerController::class, 'update']);
                Route::delete('ai-questions/{question}', [AiAnswerController::class, 'destroy']);

                Route::get('local-grid', [LocalGridController::class, 'index']);
                Route::post('local-grid', [LocalGridController::class, 'store'])->middleware('throttle:research');
                Route::get('local-grid/{scan}', [LocalGridController::class, 'show']);

                Route::get('rankings', [RankingController::class, 'index']);
                Route::post('rankings/check', [RankingController::class, 'check'])->middleware('throttle:research');
                Route::get('competitors', [RankingController::class, 'competitors']);
                Route::post('competitors', [RankingController::class, 'store']);
                Route::patch('competitors/{competitor}', [RankingController::class, 'update']);

                Route::get('performance', [ResultsController::class, 'performance']);
                Route::get('report', [ResultsController::class, 'report']);

                Route::get('{type}', [BusinessChildController::class, 'index'])->whereIn('type', ['aliases', 'locations', 'contacts', 'services', 'products']);
                Route::post('{type}', [BusinessChildController::class, 'store'])->whereIn('type', ['aliases', 'locations', 'contacts', 'services', 'products']);
                Route::patch('{type}/{id}', [BusinessChildController::class, 'update'])->whereIn('type', ['aliases', 'locations', 'contacts', 'services', 'products'])->whereNumber('id');
                Route::delete('{type}/{id}', [BusinessChildController::class, 'destroy'])->whereIn('type', ['aliases', 'locations', 'contacts', 'services', 'products'])->whereNumber('id');
            });
        });

        Route::prefix('admin')->middleware('platform.admin')->group(function () {
            Route::get('stats', [AdminController::class, 'stats']);
            Route::get('workspaces', [AdminController::class, 'workspaces']);
            Route::patch('workspaces/{workspace}', [AdminController::class, 'updateWorkspace']);
            Route::get('crawl-runs', [AdminController::class, 'crawlRuns']);
            Route::get('audit-logs', [AdminController::class, 'auditLogs']);
        });
    });
});
