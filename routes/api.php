<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\SkillCategoryController;
use App\Http\Controllers\SkillController;
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
    Route::post('/projects/{project}/employees', [ProjectController::class, 'attachEmployee']);
    Route::delete('/projects/{project}/employees/{employee}', [ProjectController::class, 'detachEmployee']);
    Route::post('/projects/{project}/skills', [ProjectController::class, 'attachSkill']);
    Route::delete('/projects/{project}/skills/{skill}', [ProjectController::class, 'detachSkill']);

    // Employees
    Route::get('/employees/today', [EmployeeController::class, 'today']);
    Route::apiResource('employees', EmployeeController::class);
    Route::get('/employees/{employee}/skills', [EmployeeController::class, 'skills']);
    Route::post('/employees/{employee}/skills', [EmployeeController::class, 'attachSkill']);
    Route::put('/employees/{employee}/skills/{skill}', [EmployeeController::class, 'updateSkill']);
    Route::delete('/employees/{employee}/skills/{skill}', [EmployeeController::class, 'detachSkill']);
    Route::get('/employees/{employee}/criticality', [EmployeeController::class, 'criticality']);

    // Leaves (scoped under employee for create/list, standalone for edit/delete)
    Route::get('/employees/{employee}/leaves', [LeaveController::class, 'index']);
    Route::post('/employees/{employee}/leaves', [LeaveController::class, 'store']);
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

