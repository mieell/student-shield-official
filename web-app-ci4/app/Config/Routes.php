<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->setAutoRoute(false);

$routes->get('/', 'AuthController::studentLoginView');
$routes->get('/login', 'AuthController::studentLoginView');
$routes->match(['GET', 'POST'], '/login/authenticate', 'AuthController::authenticateStudent');

$routes->get('/register', 'AuthController::studentRegisterView');
$routes->post('/register/process', 'AuthController::processStudentRegistration');

// Counselor/Admin Portals
$routes->get('/admin/login', 'AuthController::adminLoginView');
$routes->match(['GET', 'POST'], '/admin/login/authenticate', 'AuthController::authenticateAdmin');
$routes->get('/admin/otp', 'AuthController::otpView');
$routes->post('/admin/verify-otp', 'AuthController::verifyOtp');
$routes->post('/admin/resend-otp', 'AuthController::resendOtp');
$routes->get('/session/ping', 'AuthController::pingSession');
$routes->match(['GET', 'POST'], '/logout', 'AuthController::logout');
$routes->match(['GET', 'POST'], '/admin/logout', 'AuthController::logout');

// Counselor alias — /counselor/dashboard redirects to the unified admin dashboard
$routes->get('/counselor/dashboard', static function () {
    return redirect()->to('/admin/dashboard');
}, ['filter' => 'auth']);
$routes->get('/counselor/(:any)', static function ($path) {
    return redirect()->to('/admin/' . $path);
}, ['filter' => 'auth']);

// Protected Routes
$routes->group('', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/staff/settings', 'StaffAccountController::settings');
    $routes->get('/staff/profile', 'StaffAccountController::profile');
    $routes->post('/staff/profile', 'StaffAccountController::updateProfile');
    $routes->get('/admin/dashboard', 'AdminController::dashboard');
    foreach (['vents', 'incidents', 'sessions', 'alerts', 'analytics'] as $page) {
        $routes->get('/admin/' . $page, 'AdminController::dashboard/' . $page);
    }
    $routes->post('/admin/vent/resolve/(:num)', 'AdminController::resolveVent/$1');
    $routes->post('/admin/vent/delete/(:num)', 'AdminController::deleteVent/$1');
    $routes->post('/admin/vent/delete-multiple', 'AdminController::deleteMultipleVents');
    $routes->post('/admin/vent/restore/(:num)', 'AdminController::restoreVent/$1');
    $routes->post('/admin/vent/restore-multiple', 'AdminController::restoreMultipleVents');

    $routes->get('/admin/api/pending-meetings', 'AdminController::getPendingMeetings');
    $routes->get('/admin/api/vents', 'AdminController::fetchVents');
    $routes->get('/admin/api/incidents', 'AdminController::fetchIncidents');
    $routes->get('/admin/api/alerts', 'AdminController::fetchAlertLogs');
    $routes->get('/admin/api/student-profile/(:num)', 'AdminController::getStudentProfile/$1');
    $routes->get('/admin/evidence/(:segment)', 'AdminController::evidence/$1');

    // Case Summary Report Module
    $routes->get('/admin/case-summary/(:num)', 'CaseSummaryController::index/$1');
    $routes->get('/admin/api/case-summary/(:num)', 'CaseSummaryController::apiData/$1');
    $routes->post('/admin/case-summary/(:num)/note', 'CaseSummaryController::addNote/$1');
    $routes->post('/admin/case-summary/(:num)/status', 'CaseSummaryController::updateStatus/$1');
    $routes->post('/admin/case-summary/note/delete/(:num)', 'CaseSummaryController::deleteNote/$1');
    $routes->post('/admin/case-summary/(:num)/meeting/start', 'VirtualMeetingController::start/$1');
    $routes->post('/admin/case-summary/(:num)/meeting/cancel', 'VirtualMeetingController::end/$1');
    $routes->get('/admin/api/case-summary/(:num)/meeting/pending', 'VirtualMeetingController::getPendingRequest/$1');
    $routes->get('/student/api/meeting/status', 'VirtualMeetingController::getActiveMeeting');
    $routes->post('/student/meeting/request', 'VirtualMeetingController::requestMeeting');
    $routes->post('/student/meeting/cancel-request', 'VirtualMeetingController::cancelRequest');
    $routes->get('/meeting/join/(:num)', 'VirtualMeetingController::join/$1');

    $routes->post('/admin/incident/resolve/(:num)', 'AdminController::resolveIncident/$1');
    $routes->post('/admin/incident/verify/(:num)', 'AdminController::verifyIncident/$1');
    $routes->post('/admin/incident/reject/(:num)', 'AdminController::rejectIncident/$1');
    $routes->post('/admin/incident/undo/(:num)', 'AdminController::undoIncident/$1');
    $routes->post('/admin/incident/delete/(:num)', 'AdminController::deleteIncident/$1');
    $routes->post('/admin/incident/delete-multiple', 'AdminController::deleteMultipleIncidents');
    $routes->post('/admin/incident/restore/(:num)', 'AdminController::restoreIncident/$1');
    $routes->post('/admin/incident/restore-multiple', 'AdminController::restoreMultipleIncidents');

    // Super Admin — User Management
    $routes->get('/admin/users', 'UserController::index');
    $routes->get('/admin/audit-logs', 'AdminController::auditLogs');
    $routes->post('/admin/users/create', 'UserController::create');
    $routes->post('/admin/users/update/(:num)', 'UserController::update/$1');
    $routes->post('/admin/users/delete/(:num)', 'UserController::delete/$1');
    $routes->post('/admin/users/unlock/(:num)', 'UserController::unlock/$1');

    // Registration Approval Routes
    $routes->get('/admin/api/pending-registrations', 'AdminController::pendingRegistrationCount');
    $routes->get('/admin/pending-registrations', 'AdminController::pendingRegistrations');
    $routes->post('/admin/approve-student/(:num)', 'AdminController::approveStudent/$1');
    $routes->post('/admin/reject-student/(:num)', 'AdminController::rejectStudent/$1');

    $routes->get('/report/bullying', 'BullyReportController::index');
    $routes->post('/report/bullying/submit', 'BullyReportController::submit');
    $routes->get('/report-bullying', 'BullyReportController::index');
    $routes->post('/report-bullying/submit', 'BullyReportController::submit');

    // Executive analytics returns aggregates only; auth also checks the role by route.
    $routes->get('/principal/dashboard', 'PrincipalController::index');
    $routes->get('/principal/api/analytics', 'PrincipalController::analytics');
    $routes->get('/principal/reports/export', 'PrincipalController::export');
    $routes->post('/admin/users/access/(:num)', 'UserController::access/$1');

    // Student Routes
    $routes->get('/student/dashboard', 'StudentDashboardController::index');
    $routes->get('/student/vent', 'VentController::index');
    $routes->post('/student/vent/submit', 'VentController::submit');
});
