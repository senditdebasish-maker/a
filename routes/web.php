<?php

declare(strict_types=1);

use App\Controllers\Admin\AdmissionController;
use App\Controllers\Admin\ApplicationController as AdminApplicationController;
use App\Controllers\Admin\CmsController;
use App\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Controllers\Admin\ReportController;
use App\Controllers\Admin\SystemController;
use App\Controllers\Admin\SupportController;
use App\Controllers\ApplicantController;
use App\Controllers\AuthController;
use App\Controllers\FileController;
use App\Controllers\GeneratedDocumentController;
use App\Controllers\PublicController;

$router = $app->router();

// Public website
$router->get('/', [PublicController::class, 'home']);
$router->get('/about', fn () => (new PublicController())->page('about'));
$router->get('/programs', [PublicController::class, 'programs']);
$router->get('/programs/{slug}', [PublicController::class, 'program']);
$router->get('/admissions', [PublicController::class, 'admissions']);
$router->get('/admissions/{slug}', [PublicController::class, 'admission']);
$router->get('/admissions/{slug}/prospectus', [PublicController::class, 'admissionProspectus']);
$router->get('/facilities', [PublicController::class, 'facilities']);
$router->get('/faculty', [PublicController::class, 'faculty']);
$router->get('/notices', [PublicController::class, 'notices']);
$router->get('/notices/{slug}', [PublicController::class, 'notice']);
$router->get('/gallery', [PublicController::class, 'gallery']);
$router->get('/faq', [PublicController::class, 'faq']);
$router->get('/contact', [PublicController::class, 'contact']);
$router->post('/contact', [PublicController::class, 'submitContact']);
$router->get('/privacy', fn () => (new PublicController())->page('privacy'));
$router->get('/terms', fn () => (new PublicController())->page('terms'));
$router->get('/language/{locale}', [PublicController::class, 'language']);

// Authentication and account recovery
$router->get('/login', [AuthController::class, 'login'], ['guest']);
$router->post('/login', [AuthController::class, 'authenticate'], ['guest']);
$router->get('/mfa', [AuthController::class, 'mfa']);
$router->post('/mfa', [AuthController::class, 'verifyMfa']);
$router->post('/mfa/resend', [AuthController::class, 'resendMfa']);
$router->get('/register', [AuthController::class, 'register'], ['guest']);
$router->post('/register', [AuthController::class, 'storeRegistration'], ['guest']);
$router->get('/verify-email/{token}', [AuthController::class, 'verifyEmail'], ['guest']);
$router->get('/forgot-password', [AuthController::class, 'forgot'], ['guest']);
$router->post('/forgot-password', [AuthController::class, 'sendReset'], ['guest']);
$router->get('/reset-password/{token}', [AuthController::class, 'reset'], ['guest']);
$router->post('/reset-password/{token}', [AuthController::class, 'updatePassword'], ['guest']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth']);

// Applicant and admitted-student portal
$student = ['auth', 'role:applicant'];
$router->get('/student/dashboard', [ApplicantController::class, 'dashboard'], $student);
$router->get('/admissions/{slug}/apply', [ApplicantController::class, 'startApplication'], $student);
$router->get('/student/application', [ApplicantController::class, 'application'], $student);
$router->post('/student/application/save', [ApplicantController::class, 'saveApplication'], $student);
$router->post('/student/application/identity', [ApplicantController::class, 'saveIdentity'], $student);
$router->post('/student/application/submit', [ApplicantController::class, 'submitApplication'], $student);
$router->post('/student/application/document', [ApplicantController::class, 'uploadDocument'], $student);
$router->get('/student/application/print', [ApplicantController::class, 'printApplication'], $student);
$router->get('/student/payments', [ApplicantController::class, 'payments'], $student);
$router->post('/student/payments', [ApplicantController::class, 'submitPayment'], $student);
$router->get('/student/messages', [ApplicantController::class, 'messages'], $student);
$router->get('/student/support', [ApplicantController::class, 'tickets'], $student);
$router->post('/student/support', [ApplicantController::class, 'createTicket'], $student);
$router->get('/student/support/{id}', [ApplicantController::class, 'showTicket'], $student);
$router->post('/student/support/{id}', [ApplicantController::class, 'replyTicket'], $student);
$router->get('/student/documents/{kind}', [GeneratedDocumentController::class, 'student'], $student);
$router->get('/student/receipts/{id}', [GeneratedDocumentController::class, 'receipt'], $student);

// Protected file delivery
$router->get('/files/document/{id}', [FileController::class, 'document'], ['auth']);
$router->get('/files/document-version/{id}', [FileController::class, 'documentVersion'], ['auth']);
$router->get('/files/payment/{id}', [FileController::class, 'payment'], ['auth']);

// Staff administration
$router->get('/admin/dashboard', [AdminDashboardController::class, 'index'], ['auth', 'permission:dashboard.view']);
$router->get('/admin/admissions', [AdmissionController::class, 'index'], ['auth', 'permission:admissions.view']);
$router->get('/admin/admissions/create', [AdmissionController::class, 'create'], ['auth', 'permission:admissions.manage']);
$router->post('/admin/admissions/programs', [AdmissionController::class, 'createProgram'], ['auth', 'permission:admissions.manage']);
$router->post('/admin/admissions', [AdmissionController::class, 'store'], ['auth', 'permission:admissions.manage']);
$router->get('/admin/admissions/{id}', [AdmissionController::class, 'show'], ['auth', 'permission:admissions.view']);
$router->get('/admin/admissions/{id}/preview', [AdmissionController::class, 'preview'], ['auth', 'permission:admissions.view']);
$router->post('/admin/admissions/{id}', [AdmissionController::class, 'update'], ['auth', 'permission:admissions.manage']);
$router->post('/admin/admissions/{id}/publish', [AdmissionController::class, 'publish'], ['auth', 'permission:admissions.publish']);
$router->post('/admin/admissions/{id}/close', [AdmissionController::class, 'close'], ['auth', 'permission:admissions.manage']);
$router->post('/admin/admissions/{id}/archive', [AdmissionController::class, 'archive'], ['auth', 'permission:admissions.manage']);
$router->post('/admin/admissions/{id}/duplicate', [AdmissionController::class, 'duplicate'], ['auth', 'permission:admissions.duplicate']);
$router->post('/admin/admissions/{id}/programs', [AdmissionController::class, 'addProgram'], ['auth', 'permission:admissions.manage']);
$router->post('/admin/admissions/{id}/programs/{programId}/seats', [AdmissionController::class, 'saveSeats'], ['auth', 'permission:admission_seats.manage']);
$router->post('/admin/admissions/{id}/programs/{programId}/eligibility', [AdmissionController::class, 'saveEligibility'], ['auth', 'permission:admissions.manage']);
$router->post('/admin/admissions/{id}/programs/{programId}/fees', [AdmissionController::class, 'saveFee'], ['auth', 'permission:admission_fees.manage']);
$router->post('/admin/admissions/{id}/form-sections', [AdmissionController::class, 'saveSection'], ['auth', 'permission:admission_forms.manage']);
$router->post('/admin/admissions/{id}/form-fields', [AdmissionController::class, 'saveField'], ['auth', 'permission:admission_forms.manage']);
$router->post('/admin/admissions/{id}/documents', [AdmissionController::class, 'saveDocument'], ['auth', 'permission:admission_documents.manage']);
$router->get('/admin/applications', [AdminApplicationController::class, 'index'], ['auth', 'permission:applications.view']);
$router->get('/admin/applications/export', [AdminApplicationController::class, 'export'], ['auth', 'permission:reports.export']);
$router->get('/admin/applications/{id}', [AdminApplicationController::class, 'show'], ['auth', 'permission:applications.view']);
$router->get('/admin/applications/{id}/generated/{kind}', [GeneratedDocumentController::class, 'admin'], ['auth', 'permission:applications.view']);
$router->get('/admin/payments/{id}/receipt', [GeneratedDocumentController::class, 'receipt'], ['auth', 'permission:payments.view']);
$router->post('/admin/applications/{id}/eligibility', [AdminApplicationController::class, 'evaluateEligibility'], ['auth', 'permission:applications.review']);
$router->post('/admin/applications/{id}/status', [AdminApplicationController::class, 'status'], ['auth', 'permission:applications.decide']);
$router->post('/admin/applications/{id}/corrections', [AdminApplicationController::class, 'requestCorrection'], ['auth', 'permission:applications.correct']);
$router->post('/admin/applications/{id}/assign', [AdminApplicationController::class, 'assign'], ['auth', 'permission:applications.assign']);
$router->post('/admin/applications/{id}/notes', [AdminApplicationController::class, 'note'], ['auth', 'permission:applications.review']);
$router->post('/admin/applications/{id}/documents/{documentId}', [AdminApplicationController::class, 'reviewDocument'], ['auth', 'permission:documents.verify']);
$router->post('/admin/applications/{id}/payments/{paymentId}', [AdminApplicationController::class, 'verifyPayment'], ['auth', 'permission:payments.verify']);
$router->get('/admin/reports', [ReportController::class, 'index'], ['auth', 'permission:reports.view']);
$router->get('/admin/enquiries', [SystemController::class, 'enquiries'], ['auth', 'permission:support.view']);
$router->post('/admin/enquiries/{id}', [SystemController::class, 'updateEnquiry'], ['auth', 'permission:support.reply']);
$router->post('/admin/enquiries/{id}/reply', [SystemController::class, 'replyEnquiry'], ['auth', 'permission:support.reply']);
$router->get('/admin/support', [SupportController::class, 'index'], ['auth', 'permission:support.view']);
$router->get('/admin/support/{id}', [SupportController::class, 'show'], ['auth', 'permission:support.view']);
$router->post('/admin/support/{id}/reply', [SupportController::class, 'reply'], ['auth', 'permission:support.reply']);
$router->get('/admin/cms', [CmsController::class, 'index'], ['auth', 'permission:cms.view']);
$router->get('/admin/cms/pages/{id}', [CmsController::class, 'editPage'], ['auth', 'permission:cms.edit']);
$router->post('/admin/cms/pages/{id}', [CmsController::class, 'updatePage'], ['auth', 'permission:cms.edit']);
$router->get('/admin/cms/{module}', [CmsController::class, 'module'], ['auth', 'permission:cms.view']);
$router->post('/admin/cms/{module}', [CmsController::class, 'storeModule'], ['auth', 'permission:cms.edit']);
$router->post('/admin/cms/{module}/{id}', [CmsController::class, 'updateModule'], ['auth', 'permission:cms.edit']);
$router->post('/admin/cms/{module}/{id}/delete', [CmsController::class, 'deleteModule'], ['auth', 'permission:cms.edit']);
$router->get('/admin/users', [SystemController::class, 'users'], ['auth', 'permission:users.view']);
$router->post('/admin/users', [SystemController::class, 'createUser'], ['auth', 'permission:users.manage']);
$router->post('/admin/users/{id}', [SystemController::class, 'updateUser'], ['auth', 'permission:users.manage']);
$router->get('/admin/roles', [SystemController::class, 'roles'], ['auth', 'permission:roles.manage']);
$router->post('/admin/roles/{id}', [SystemController::class, 'updateRole'], ['auth', 'permission:roles.manage']);
$router->get('/admin/settings', [SystemController::class, 'settings'], ['auth', 'permission:settings.view']);
$router->post('/admin/settings', [SystemController::class, 'updateSettings'], ['auth', 'permission:settings.edit']);
$router->get('/admin/audit', [SystemController::class, 'audit'], ['auth', 'permission:audit.view']);
$router->get('/admin/mail-log', [SystemController::class, 'mailLog'], ['auth', 'permission:settings.view']);
$router->get('/admin/backups', [SystemController::class, 'backups'], ['auth', 'permission:backups.manage']);
$router->post('/admin/backups', [SystemController::class, 'createBackup'], ['auth', 'permission:backups.manage']);
$router->get('/admin/backups/{id}/download', [SystemController::class, 'downloadBackup'], ['auth', 'permission:backups.manage']);
