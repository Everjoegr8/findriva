<?php

use App\Http\Controllers\Api\V1\ActionController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessChildController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\CoverageController;
use App\Http\Controllers\Api\V1\DigitalAssetController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('workspaces', [WorkspaceController::class, 'index']);
        Route::post('workspaces', [WorkspaceController::class, 'store']);

        // Everything below is tenant-scoped to the X-Workspace-Id workspace.
        Route::middleware('workspace')->group(function () {
            Route::get('workspace', [WorkspaceController::class, 'show']);
            Route::patch('workspace', [WorkspaceController::class, 'update']);
            Route::get('workspace/members', [WorkspaceController::class, 'members']);
            Route::post('workspace/members', [WorkspaceController::class, 'addMember']);
            Route::patch('workspace/members/{membership}', [WorkspaceController::class, 'updateMember']);
            Route::delete('workspace/members/{membership}', [WorkspaceController::class, 'removeMember']);
            Route::get('workspace/activity', [WorkspaceController::class, 'activity']);
            Route::get('coverage', [CoverageController::class, 'show']);

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
