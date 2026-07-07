<?php

use think\facade\Route;

require __DIR__ . '/ai_dev.php';

Route::get('/', function () {
    return json([
        'code' => 0,
        'message' => 'AI Dev Workbench API',
    ]);
});
