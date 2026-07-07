<?php

use think\facade\Route;

Route::group('api/ai-dev', function () {
    Route::get('requirements', 'AiDev.RequirementController/index');
    Route::post('requirements', 'AiDev.RequirementController/save');
    Route::get('requirements/:id', 'AiDev.RequirementController/read');
    Route::put('requirements/:id', 'AiDev.RequirementController/update');
    Route::post('requirements/:id/close', 'AiDev.RequirementController/close');
    Route::post('requirements/:id/load-doc', 'AiDev.RequirementController/loadDoc');
    Route::post('requirements/:id/generate-breakdown', 'AiDev.RequirementController/generateBreakdown');
    Route::put('requirements/:id/breakdown', 'AiDev.RequirementController/saveBreakdown');
    Route::post('requirements/:id/confirm-breakdown', 'AiDev.RequirementController/confirmBreakdown');
    Route::get('requirements/:id/tasks', 'AiDev.RequirementController/tasks');

    Route::get('tasks', 'AiDev.TaskController/index');
    Route::post('tasks', 'AiDev.TaskController/save');
    Route::get('tasks/:id', 'AiDev.TaskController/read');
    Route::put('tasks/:id', 'AiDev.TaskController/update');
    Route::post('tasks/:id/terminate', 'AiDev.TaskController/terminate');

    Route::post('tasks/:id/generate-branch', 'AiDev.TaskController/generateBranch');
    Route::post('tasks/:id/check-branch', 'AiDev.TaskController/checkBranch');
    Route::post('tasks/:id/generate-plan', 'AiDev.TaskController/generatePlan');
    Route::put('tasks/:id/plan', 'AiDev.TaskController/savePlan');
    Route::post('tasks/:id/confirm-plan', 'AiDev.TaskController/confirmPlan');

    Route::post('tasks/:id/execute', 'AiDev.TaskController/execute');
    Route::get('tasks/:id/runs', 'AiDev.RunController/index');
    Route::get('runs/:runId', 'AiDev.RunController/read');
    Route::get('runs/:runId/logs', 'AiDev.RunController/logs');
    Route::post('runs/:runId/cancel', 'AiDev.RunController/cancel');

    Route::post('tasks/:id/review', 'AiDev.TaskController/review');
    Route::post('tasks/:id/fix', 'AiDev.TaskController/fix');
    Route::post('tasks/:id/generate-commit-message', 'AiDev.TaskController/generateCommitMessage');
    Route::post('tasks/:id/commit', 'AiDev.TaskController/commit');
    Route::post('tasks/:id/push', 'AiDev.TaskController/push');
    Route::post('tasks/:id/retrospect', 'AiDev.TaskController/retrospect');
    Route::get('tasks/:id/retrospective', 'AiDev.TaskController/getRetrospective');
    Route::put('tasks/:id/retrospective', 'AiDev.TaskController/saveRetrospective');

    Route::get('projects', 'AiDev.ProjectController/index');
    Route::post('projects', 'AiDev.ProjectController/save');
    Route::post('projects/scan', 'AiDev.ProjectController/scan');
    Route::post('projects/describe', 'AiDev.ProjectController/describe');
    Route::put('projects/:id', 'AiDev.ProjectController/update');
    Route::delete('projects/:id', 'AiDev.ProjectController/delete');

    Route::get('workspace-config', 'AiDev.ConfigController/workspace');
    Route::put('workspace-config', 'AiDev.ConfigController/saveWorkspace');
    Route::get('model-config', 'AiDev.ConfigController/model');
    Route::put('model-config', 'AiDev.ConfigController/saveModel');
    Route::get('security-rules', 'AiDev.ConfigController/securityRules');
    Route::put('security-rules', 'AiDev.ConfigController/saveSecurityRules');
});
