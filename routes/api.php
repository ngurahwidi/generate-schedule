<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use \App\Http\Controllers\ScheduleController;

Route::post('/generate-schedule', [ScheduleController::class, 'generateSchedule']);
Route::get('/schedules', [ScheduleController::class, 'getSchedules']);
Route::get('/schedules-by-date', [ScheduleController::class, 'getSchedulesByDate']);
