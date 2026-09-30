<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AppointmentController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DoctorController;
use App\Http\Controllers\Admin\PatientController;
use App\Http\Controllers\Admin\RecordController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TriagerAccountController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin routes
|--------------------------------------------------------------------------
| Admin-only navigation lives in its own route file so the admin area can
| evolve independently from the patient portal.
*/

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('auth:admin')->group(function (): void {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/patients', [PatientController::class, 'index'])->name('patients');

        Route::get('/patients', [PatientController::class, 'index'])->name('patients');
        Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');   // <-- new
        Route::get('/patients/{patient}/edit', [PatientController::class, 'edit'])->name('patients.edit');

        Route::get('/patients/{patient}/edit', [PatientController::class, 'edit'])->name('patients.edit');
        Route::put('/patients/{patient}', [PatientController::class, 'update'])->name('patients.update');
        Route::patch('/patients/{patient}/status', [PatientController::class, 'toggleStatus'])->name('patients.status');
        Route::post('/patients/{patient}/reset-password', [PatientController::class, 'resetPassword'])->name('patients.reset-password');
        Route::get('/patients/{patient}/appointments', [PatientController::class, 'appointments'])->name('patients.appointments');
        Route::get('/patients/{patient}/history', [PatientController::class, 'history'])->name('patients.history');
        Route::get('/patients/{patient}/records', [PatientController::class, 'records'])->name('patients.records');
        Route::get('/patients/{patient}/visits', [PatientController::class, 'visits'])->name('patients.visits');
        Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show');
        Route::get('/doctors-staff', [DoctorController::class, 'index'])->name('doctors');
        Route::get('/doctors/create', [DoctorController::class, 'create'])->name('doctors.create');
        Route::post('/doctors', [DoctorController::class, 'store'])->name('doctors.store');
        Route::post('/doctors/{staff}/appointments/assign', [DoctorController::class, 'assignAppointment'])->name('doctors.appointments.assign');
        Route::get('/doctors/{staff}/appointments', [DoctorController::class, 'appointments'])->name('doctors.appointments');
        Route::get('/doctors/{staff}/history', [DoctorController::class, 'history'])->name('doctors.history');
        Route::get('/doctors/{staff}/edit', [DoctorController::class, 'edit'])->name('doctors.edit');
        Route::put('/doctors/{staff}', [DoctorController::class, 'update'])->name('doctors.update');
        Route::patch('/doctors/{staff}/status', [DoctorController::class, 'toggleStatus'])->name('doctors.status');
        Route::get('/doctors/{staff}', [DoctorController::class, 'show'])->name('doctors.show');
        Route::get('/triagers', [TriagerAccountController::class, 'index'])->name('triagers');
        Route::post('/triagers', [TriagerAccountController::class, 'store'])->name('triagers.store');
        Route::put('/triagers/{admin}', [TriagerAccountController::class, 'update'])->name('triagers.update');
        Route::delete('/triagers/{admin}', [TriagerAccountController::class, 'destroy'])->name('triagers.destroy');
        Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments');
        Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::get('/appointments/{id}', [AppointmentController::class, 'show'])->whereNumber('id')->name('appointments.show');
        Route::put('/appointments/{id}', [AppointmentController::class, 'update'])->whereNumber('id')->name('appointments.update');
        Route::delete('/appointments/{id}', [AppointmentController::class, 'destroy'])->whereNumber('id')->name('appointments.destroy');
        Route::post('/appointments/{id}/{action}', [AppointmentController::class, 'action'])->name('appointments.action');
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs');
        Route::get('/records', [RecordController::class, 'index'])->name('records');
        Route::post('/records', [RecordController::class, 'store'])->name('records.store');
        Route::delete('/records/{record}', [RecordController::class, 'destroy'])->name('records.destroy');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::post('/settings', [SettingsController::class, 'store'])->name('settings.store');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/logout', [LoginController::class, 'adminLogout'])->name('logout');
    });
});