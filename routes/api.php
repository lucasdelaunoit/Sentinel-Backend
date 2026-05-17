<?php

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CompanyHolidayController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\OrganizationSettingController;
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

    /** ---------------------- [ PROJECTS ] ---------------------- */
    /* ----------------- SPECIALIZED ENDPOINTS ----------------- */
    Route::get('/projects/stats', [ProjectController::class, 'getProjectsStats']);

    /* ----------------- COMMON ENDPOINTS ----------------- */
    Route::get('/projects', [ProjectController::class, 'getAgileProjects']);
    Route::post('/projects', [ProjectController::class, 'createProject']);
    Route::get('/projects/{project}', [ProjectController::class, 'getProject']);
    Route::put('/projects/{project}', [ProjectController::class, 'updateProject']);
    Route::delete('/projects/{project}', [ProjectController::class, 'deleteProject']);

    /* ----------------- LIFECYCLE ACTIONS ----------------- */
    Route::post('/projects/{project}/pause', [ProjectController::class, 'pauseProject']);
    Route::post('/projects/{project}/resume', [ProjectController::class, 'resumeProject']);
    Route::post('/projects/{project}/complete', [ProjectController::class, 'completeProject']);
    Route::post('/projects/{project}/reopen', [ProjectController::class, 'reopenProject']);
    Route::post('/projects/{project}/archive', [ProjectController::class, 'archiveProject']);
    Route::post('/projects/{project}/unarchive', [ProjectController::class, 'unarchiveProject']);

    /* ----------------- PROJECT-RELATED ENDPOINTS ----------------- */
    Route::get('/projects/{project}/stats', [ProjectController::class, 'getProjectStats']);
    Route::get('/projects/{project}/coverage', [ProjectController::class, 'getProjectCoverage']);
    Route::get('/projects/{project}/metrics', [ProjectController::class, 'getProjectMetrics']);
    Route::post('/projects/{project}/users', [ProjectController::class, 'attachUserToProject']);
    Route::delete('/projects/{project}/users/{user}', [ProjectController::class, 'detachUserFromProject']);
    Route::post('/projects/{project}/skills', [ProjectController::class, 'attachSkillToProject']);
    Route::delete('/projects/{project}/skills/{skill}', [ProjectController::class, 'detachSkillFromProject']);

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

    /** ---------------------- [ ABSENCES ] ---------------------- */
    /* ----------------- COMMON ENDPOINTS ----------------- */
    Route::put('/absences/{absence}', [AbsenceController::class, 'updateAbsence']);
    Route::delete('/absences/{absence}', [AbsenceController::class, 'deleteAbsence']);

    /* ----------------- USER-RELATED ENDPOINTS ----------------- */
    Route::get('/users/{user}/absences', [AbsenceController::class, 'getAgileAbsencesForUser']);
    Route::post('/users/{user}/absences', [AbsenceController::class, 'createAbsenceForUser']);

    // Simulations
    Route::apiResource('simulations', SimulationController::class)->except(['update']);

    /** ---------------------- [ SKILLS ] ---------------------- */
    /* ----------------- COMMON ENDPOINTS ----------------- */
    Route::get('/skills', [SkillController::class, 'getAgileSkills']);
    Route::post('/skills', [SkillController::class, 'createSkill']);
    Route::put('/skills/{skill}', [SkillController::class, 'updateSkill']);
    Route::delete('/skills/{skill}', [SkillController::class, 'deleteSkill']);

    /** ---------------------- [ SKILL CATEGORIES ] ---------------------- */
    /* ----------------- COMMON ENDPOINTS ----------------- */
    Route::get('/skill-categories', [SkillCategoryController::class, 'getAgileSkillCategories']);
    Route::post('/skill-categories', [SkillCategoryController::class, 'createCategory']);
    Route::put('/skill-categories/{skillCategory}', [SkillCategoryController::class, 'updateSkillCategory']);
    Route::delete('/skill-categories/{skillCategory}', [SkillCategoryController::class, 'deleteSkillCategory']);

    /* ----------------- SKILL-CATEGORY-RELATED ENDPOINTS ----------------- */
    Route::get('/skill-categories/{skillCategory}/kci', [SkillCategoryController::class, 'getKCI']);

    // Departments
    Route::apiResource('departments', DepartmentController::class)->except(['show']);

    /** ---------------------- [ ORGANIZATION SETTINGS ] ---------------------- */
    /* ----------------- COMMON ENDPOINTS ----------------- */
    Route::get('/organization/settings', [OrganizationSettingController::class, 'getOrganizationSetting']);
    Route::put('/organization/settings', [OrganizationSettingController::class, 'updateOrganizationSetting']);

    /** ---------------------- [ CALENDAR ] ---------------------- */
    /* ----------------- SPECIALIZED ENDPOINTS ----------------- */
    Route::get('/calendar/summary', [CalendarController::class, 'getCalendarSummary']);
    Route::put('/calendar/settings', [CalendarController::class, 'updateCalendarSetting']);

    /** ---------------------- [ COMPANY HOLIDAYS ] ---------------------- */
    /* ----------------- COMMON ENDPOINTS ----------------- */
    Route::get('/company-holidays', [CompanyHolidayController::class, 'getAgileCompanyHolidays']);
    Route::post('/company-holidays', [CompanyHolidayController::class, 'createCompanyHoliday']);
    Route::put('/company-holidays/{companyHoliday}', [CompanyHolidayController::class, 'updateCompanyHoliday']);
    Route::delete('/company-holidays/{companyHoliday}', [CompanyHolidayController::class, 'deleteCompanyHoliday']);
});
