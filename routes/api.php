<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\SkillCategoryController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ─── Public ──────────────────────────────────────────────────────────────────

Route::post('/auth/login', [AuthController::class, 'login']);

// ─── Protected ───────────────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/stats/projects-at-risk', [DashboardController::class, 'projectsAtRiskDetail']);
    Route::get('/dashboard/stats/knowledge-coverage', [DashboardController::class, 'knowledgeCoverageDetail']);
    Route::get('/dashboard/stats/team-availability', [DashboardController::class, 'teamAvailabilityDetail']);
    Route::get('/dashboard/stats/absence-impact', [DashboardController::class, 'absenceImpactDetail']);

    // Projects
    Route::apiResource('projects', ProjectController::class);
    Route::get('/projects/{project}/coverage', [ProjectController::class, 'coverage']);
    Route::get('/projects/{project}/metrics', [ProjectController::class, 'metrics']);
    Route::post('/projects/{project}/users', [ProjectController::class, 'attachUser']);
    Route::delete('/projects/{project}/users/{user}', [ProjectController::class, 'detachUser']);
    Route::post('/projects/{project}/skills', [ProjectController::class, 'attachSkill']);
    Route::delete('/projects/{project}/skills/{skill}', [ProjectController::class, 'detachSkill']);

    // Users
    Route::get('/users/today', [UserController::class, 'today']);
    Route::get('/users/stats', [UserController::class, 'stats']);
    Route::apiResource('users', UserController::class);
    Route::get('/users/{user}/skills', [UserController::class, 'skills']);
    Route::post('/users/{user}/skills', [UserController::class, 'attachSkill']);
    Route::put('/users/{user}/skills/{skill}', [UserController::class, 'updateSkill']);
    Route::delete('/users/{user}/skills/{skill}', [UserController::class, 'detachSkill']);
    Route::get('/users/{user}/criticality', [UserController::class, 'criticality']);

    // Leaves (scoped under user for create/list, standalone for edit/delete)
    Route::get('/users/{user}/leaves', [LeaveController::class, 'index']);
    Route::post('/users/{user}/leaves', [LeaveController::class, 'store']);
    Route::put('/leaves/{leave}', [LeaveController::class, 'update']);
    Route::delete('/leaves/{leave}', [LeaveController::class, 'destroy']);

    // Simulations
    Route::apiResource('simulations', SimulationController::class)->except(['update']);

    // Skills
    Route::apiResource('skills', SkillController::class)->except(['show']);

    // Skill Categories
    Route::apiResource('skill-categories', SkillCategoryController::class)->except(['show']);
    Route::get('/skill-categories/{skillCategory}/kci', [SkillCategoryController::class, 'kci']);

    // Departments
    Route::apiResource('departments', DepartmentController::class)->except(['show']);
});
