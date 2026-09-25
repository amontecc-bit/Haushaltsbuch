<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\ForecastController;
use App\Controllers\ImportController;
use App\Controllers\LoanController;
use App\Controllers\PurchaseController;
use App\Controllers\RecurringController;
use App\Controllers\ReportController;
use App\Controllers\RuleController;
use App\Controllers\SettingsController;
use App\Controllers\TransactionController;
use App\Controllers\UserController;

/** @var \App\Core\Router $router */

// Anmeldung & Ersteinrichtung
$router->get('/login', [AuthController::class, 'showLogin'], 'guest');
$router->post('/login', [AuthController::class, 'login'], 'guest');
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/setup', [AuthController::class, 'showSetup'], 'guest');
$router->post('/setup', [AuthController::class, 'setup'], 'guest');

$router->get('/', [DashboardController::class, 'index']);

// Konten
$router->get('/accounts', [AccountController::class, 'index']);
$router->get('/accounts/new', [AccountController::class, 'create'], 'admin');
$router->post('/accounts', [AccountController::class, 'store'], 'admin');
$router->get('/accounts/{id}/edit', [AccountController::class, 'edit'], 'admin');
$router->post('/accounts/{id}', [AccountController::class, 'update'], 'admin');
$router->post('/accounts/{id}/delete', [AccountController::class, 'delete'], 'admin');

// Buchungen
$router->get('/transactions', [TransactionController::class, 'index']);
$router->get('/transactions/new', [TransactionController::class, 'create']);
$router->post('/transactions', [TransactionController::class, 'store']);
$router->get('/transactions/{id}/edit', [TransactionController::class, 'edit']);
$router->post('/transactions/{id}', [TransactionController::class, 'update']);
$router->post('/transactions/{id}/delete', [TransactionController::class, 'delete']);
$router->post('/transactions/{id}/category', [TransactionController::class, 'setCategory']);
$router->post('/transactions/bulk', [TransactionController::class, 'bulk']);
$router->post('/transactions/suggest', [TransactionController::class, 'suggest']);

// Daueraufträge / Fixkosten
$router->get('/recurring', [RecurringController::class, 'index']);
$router->get('/recurring/new', [RecurringController::class, 'create']);
$router->post('/recurring', [RecurringController::class, 'store']);
$router->get('/recurring/{id}/edit', [RecurringController::class, 'edit']);
$router->post('/recurring/{id}', [RecurringController::class, 'update']);
$router->post('/recurring/{id}/delete', [RecurringController::class, 'delete']);

// CSV-Import
$router->get('/import', [ImportController::class, 'index']);
$router->post('/import/upload', [ImportController::class, 'upload']);
$router->get('/import/preview', [ImportController::class, 'preview']);
$router->post('/import/preview', [ImportController::class, 'remap']);
$router->post('/import/commit', [ImportController::class, 'commit']);

// Einkäufe
$router->get('/purchases', [PurchaseController::class, 'index']);
$router->get('/purchases/new', [PurchaseController::class, 'create']);
$router->post('/purchases', [PurchaseController::class, 'store']);
$router->get('/purchases/{id}', [PurchaseController::class, 'show']);
$router->get('/purchases/{id}/edit', [PurchaseController::class, 'edit']);
$router->post('/purchases/{id}', [PurchaseController::class, 'update']);
$router->post('/purchases/{id}/delete', [PurchaseController::class, 'delete']);
$router->get('/purchases/{id}/file', [PurchaseController::class, 'file']);
$router->post('/purchases/recognize', [PurchaseController::class, 'recognize']);
$router->post('/purchases/suggest', [PurchaseController::class, 'suggest']);
$router->post('/purchases/candidates', [PurchaseController::class, 'candidates']);

// Auswertungen & Prognose
$router->get('/reports', [ReportController::class, 'index']);
$router->get('/reports/items', [ReportController::class, 'items']);
$router->get('/reports/product', [ReportController::class, 'product']);
$router->get('/reports/export', [ReportController::class, 'export']);
$router->get('/forecast', [ForecastController::class, 'index']);

// Kredite
$router->get('/loans', [LoanController::class, 'index']);
$router->get('/loans/new', [LoanController::class, 'create']);
$router->post('/loans', [LoanController::class, 'store']);
$router->get('/loans/{id}', [LoanController::class, 'show']);
$router->get('/loans/{id}/edit', [LoanController::class, 'edit']);
$router->post('/loans/{id}', [LoanController::class, 'update']);
$router->post('/loans/{id}/delete', [LoanController::class, 'delete']);
$router->post('/loans/{id}/special', [LoanController::class, 'addSpecial']);
$router->post('/loans/{id}/special/{sid}/delete', [LoanController::class, 'deleteSpecial']);
$router->post('/loans/{id}/change', [LoanController::class, 'addChange']);
$router->post('/loans/{id}/change/{cid}/delete', [LoanController::class, 'deleteChange']);
$router->post('/loans/{id}/recurring', [LoanController::class, 'createRecurring']);

// Kategorien & Regeln
$router->get('/categories', [CategoryController::class, 'index']);
$router->post('/categories', [CategoryController::class, 'store'], 'admin');
$router->post('/categories/{id}', [CategoryController::class, 'update'], 'admin');
$router->post('/categories/{id}/delete', [CategoryController::class, 'delete'], 'admin');
$router->get('/rules', [RuleController::class, 'index']);
$router->post('/rules', [RuleController::class, 'store']);
$router->post('/rules/{id}/delete', [RuleController::class, 'delete']);
$router->post('/rules/apply', [RuleController::class, 'apply']);

// Benutzer & Einstellungen
$router->get('/users', [UserController::class, 'index'], 'admin');
$router->get('/users/new', [UserController::class, 'create'], 'admin');
$router->post('/users', [UserController::class, 'store'], 'admin');
$router->get('/users/{id}/edit', [UserController::class, 'edit'], 'admin');
$router->post('/users/{id}', [UserController::class, 'update'], 'admin');
$router->get('/settings', [SettingsController::class, 'index']);
$router->post('/settings', [SettingsController::class, 'update'], 'admin');
$router->post('/settings/password', [SettingsController::class, 'password']);
$router->get('/more', [SettingsController::class, 'more']);
