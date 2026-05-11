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

    /** ---------------------- [ USERS ] ---------------------- */
    /* ----------------- SPECIALIZED ENDPOINTS ----------------- */
    Route::get('/users/today', [UserController::class, 'getUsersTodayStatus']);

    /* ----------------- COMMON ENDPOINTS ----------------- */
    Route::get('/users', [UserController::class, 'getAgileUsers']);
    Route::post('/users', [UserController::class, 'createUser']);
    Route::get('/users/{user}', [UserController::class, 'getUser']);
    Route::put('/users/{user}', [UserController::class, 'updateUser']);
    Route::delete('/users/{user}', [UserController::class, 'deleteUser']);

    /* ----------------- USER-RELATED ENDPOINTS ----------------- */
    Route::get('/users/{user}/stats', [UserController::class, 'getUserStats']);
    Route::get('/users/{user}/criticality', [UserController::class, 'getUserCriticality']);
    Route::get('/users/{user}/skills', [SkillController::class, 'getAgileSkillsForUser']);
    Route::post('/users/{user}/skills', [UserController::class, 'attachSkillToUser']);
    Route::put('/users/{user}/skills/{skill}', [UserController::class, 'updateUserSkill']);
    Route::delete('/users/{user}/skills/{skill}', [UserController::class, 'detachSkillFromUser']);

    // Leaves (scoped under user for create/list, standalone for edit/delete)
    Route::get('/users/{user}/leaves', [LeaveController::class, 'index']);
    Route::post('/users/{user}/leaves', [LeaveController::class, 'store']);
    Route::put('/leaves/{leave}', [LeaveController::class, 'update']);
    Route::delete('/leaves/{leave}', [LeaveController::class, 'destroy']);

    // Simulations
    Route::apiResource('simulations', SimulationController::class)->except(['update']);

    /** ---------------------- [ SKILLS ] ---------------------- */
    /* ----------------- COMMON ENDPOINTS ----------------- */
    Route::get('/skills', [SkillController::class, 'listSkills']);
    Route::post('/skills', [SkillController::class, 'createSkill']);
    Route::put('/skills/{skill}', [SkillController::class, 'updateSkill']);
    Route::delete('/skills/{skill}', [SkillController::class, 'deleteSkill']);

    /** ---------------------- [ SKILL CATEGORIES ] ---------------------- */
    /* ----------------- COMMON ENDPOINTS ----------------- */
    Route::get('/skill-categories', [SkillCategoryController::class, 'getAgileSkillCategories']);
    Route::post('/skill-categories', [SkillCategoryController::class, 'createCategory']);
    Route::delete('/skill-categories/{skillCategory}', [SkillCategoryController::class, 'deleteCategory']);

    /* ----------------- SKILL-CATEGORY-RELATED ENDPOINTS ----------------- */
    Route::get('/skill-categories/{skillCategory}/kci', [SkillCategoryController::class, 'getKCI']);

    // Departments
    Route::apiResource('departments', DepartmentController::class)->except(['show']);
});
