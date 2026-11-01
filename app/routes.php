<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CalendarController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\ForecastController;
use App\Controllers\ImportController;
use App\Controllers\InventoryController;
use App\Controllers\LoanController;
use App\Controllers\McpController;
use App\Controllers\OAuthController;
use App\Controllers\PurchaseController;
use App\Controllers\RecipeController;
use App\Controllers\RecurringController;
use App\Controllers\ReportController;
use App\Controllers\RuleController;
use App\Controllers\SettingsController;
use App\Controllers\ShoppingController;
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
$router->post('/transactions/{id}/recurring', [TransactionController::class, 'makeRecurring']);
$router->post('/transactions/bulk', [TransactionController::class, 'bulk']);
$router->post('/transactions/suggest', [TransactionController::class, 'suggest']);

// Daueraufträge / Fixkosten
$router->get('/recurring', [RecurringController::class, 'index']);
$router->get('/recurring/new', [RecurringController::class, 'create']);
$router->post('/recurring', [RecurringController::class, 'store']);
$router->get('/recurring/{id}/edit', [RecurringController::class, 'edit']);
$router->post('/recurring/{id}', [RecurringController::class, 'update']);
$router->post('/recurring/{id}/delete', [RecurringController::class, 'delete']);
$router->post('/recurring/bulk', [RecurringController::class, 'bulk']);

// CSV-Import
$router->get('/import', [ImportController::class, 'index']);
$router->post('/import/upload', [ImportController::class, 'upload']);
$router->get('/import/preview', [ImportController::class, 'preview']);
$router->post('/import/preview', [ImportController::class, 'remap']);
$router->post('/import/rule', [ImportController::class, 'rule']);
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

// Einkaufslisten & virtuelle Posten
$router->get('/shopping', [ShoppingController::class, 'index']);
$router->post('/shopping', [ShoppingController::class, 'store']);
$router->get('/shopping/items', [ShoppingController::class, 'items']);
$router->post('/shopping/items', [ShoppingController::class, 'storeItem']);
$router->get('/shopping/assign', [ShoppingController::class, 'assign']);
$router->post('/shopping/assign', [ShoppingController::class, 'move']);
$router->post('/shopping/items/{id}', [ShoppingController::class, 'updateItem']);
$router->post('/shopping/items/{id}/delete', [ShoppingController::class, 'deleteItem']);
$router->post('/shopping/items/{id}/products', [ShoppingController::class, 'linkProduct']);
$router->post('/shopping/items/{id}/products/{pid}/delete', [ShoppingController::class, 'unlinkProduct']);
$router->post('/shopping/items/{id}/products/{pid}', [ShoppingController::class, 'updateLink']);
$router->get('/shopping/{id}', [ShoppingController::class, 'show']);
$router->post('/shopping/{id}', [ShoppingController::class, 'update']);
$router->post('/shopping/{id}/delete', [ShoppingController::class, 'delete']);
$router->get('/shopping/{id}/print', [ShoppingController::class, 'print']);
$router->post('/shopping/{id}/add', [ShoppingController::class, 'add']);
$router->post('/shopping/{id}/match', [ShoppingController::class, 'match']);
$router->post('/shopping/{id}/clear-done', [ShoppingController::class, 'clearDone']);
$router->post('/shopping/{id}/entries/{eid}', [ShoppingController::class, 'updateEntry']);
$router->post('/shopping/{id}/entries/{eid}/delete', [ShoppingController::class, 'deleteEntry']);

// Vorrat & Rezepte
$router->get('/inventory', [InventoryController::class, 'index']);
$router->post('/inventory/rebuild', [InventoryController::class, 'rebuild']);
$router->post('/inventory/lots/{id}', [InventoryController::class, 'updateLot']);
$router->post('/inventory/lots/{id}/delete', [InventoryController::class, 'deleteLot']);
$router->post('/inventory/{id}', [InventoryController::class, 'adjust']);
$router->get('/recipes', [RecipeController::class, 'index']);
$router->post('/recipes', [RecipeController::class, 'store']);
$router->get('/recipes/create', [RecipeController::class, 'create']);
$router->get('/recipes/suggestions', [RecipeController::class, 'suggestions']);
$router->get('/recipes/classes', [RecipeController::class, 'classes']);
$router->post('/recipes/classes', [RecipeController::class, 'saveClasses']);
$router->get('/recipes/{id}', [RecipeController::class, 'show']);
$router->get('/recipes/{id}/edit', [RecipeController::class, 'edit']);
$router->post('/recipes/{id}', [RecipeController::class, 'update']);
$router->post('/recipes/{id}/delete', [RecipeController::class, 'delete']);
$router->post('/recipes/{id}/cook', [RecipeController::class, 'cook']);
$router->post('/recipes/{id}/shopping', [RecipeController::class, 'toShoppingList']);

// Auswertungen & Prognose
$router->get('/reports', [ReportController::class, 'index']);
$router->get('/reports/items', [ReportController::class, 'items']);
$router->post('/reports/items/category', [ReportController::class, 'recategorize']);
$router->get('/reports/product', [ReportController::class, 'product']);
$router->get('/reports/export', [ReportController::class, 'export']);
$router->get('/forecast', [ForecastController::class, 'index']);
$router->get('/forecast/scenarios/new', [ForecastController::class, 'createScenario']);
$router->post('/forecast/scenarios', [ForecastController::class, 'storeScenario']);
$router->get('/forecast/scenarios/{id}/edit', [ForecastController::class, 'editScenario']);
$router->post('/forecast/scenarios/{id}', [ForecastController::class, 'updateScenario']);
$router->post('/forecast/scenarios/{id}/delete', [ForecastController::class, 'deleteScenario']);

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

// Kalender
$router->get('/calendar', [CalendarController::class, 'index']);
$router->get('/calendar/new', [CalendarController::class, 'create']);
$router->post('/calendar', [CalendarController::class, 'store']);
$router->get('/calendar/templates', [CalendarController::class, 'templates']);
$router->post('/calendar/templates', [CalendarController::class, 'storeTemplate']);
$router->post('/calendar/templates/{id}', [CalendarController::class, 'updateTemplate']);
$router->post('/calendar/templates/{id}/delete', [CalendarController::class, 'deleteTemplate']);
$router->get('/calendar/{id}', [CalendarController::class, 'show']);
$router->get('/calendar/{id}/edit', [CalendarController::class, 'edit']);
$router->post('/calendar/{id}', [CalendarController::class, 'update']);
$router->post('/calendar/{id}/delete', [CalendarController::class, 'delete']);
$router->post('/calendar/{id}/move', [CalendarController::class, 'move']);
$router->post('/calendar/{id}/budget', [CalendarController::class, 'budget']);
$router->get('/calendar/{id}/book', [CalendarController::class, 'bookForm']);
$router->post('/calendar/{id}/book', [CalendarController::class, 'book']);

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
$router->post('/settings/preferences', [SettingsController::class, 'preferences']);
$router->post('/settings/password', [SettingsController::class, 'password']);
$router->get('/more', [SettingsController::class, 'more']);
$router->get('/settings/claude', [SettingsController::class, 'claude']);
$router->post('/settings/claude', [SettingsController::class, 'claudeToggle'], 'admin');
$router->post('/settings/claude/budgets', [SettingsController::class, 'claudeBudgets']);
$router->post('/settings/claude/{id}/revoke', [SettingsController::class, 'claudeRevoke']);

// Claude (MCP-Server) mit OAuth – 'api' = ohne Sitzung/CSRF, die Actions prüfen selbst
$router->get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource'], 'api');
$router->get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResource'], 'api');
$router->get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer'], 'api');
$router->get('/.well-known/openid-configuration', [OAuthController::class, 'authorizationServer'], 'api');
$router->post('/oauth/register', [OAuthController::class, 'register'], 'api');
$router->post('/oauth/token', [OAuthController::class, 'token'], 'api');
$router->get('/oauth/authorize', [OAuthController::class, 'authorize'], 'guest');
$router->post('/oauth/authorize', [OAuthController::class, 'decide']);
$router->post('/mcp', [McpController::class, 'handle'], 'api');
$router->get('/mcp', [McpController::class, 'stream'], 'api');
