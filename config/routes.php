<?php

declare(strict_types=1);

use App\Controllers\AdminClientsController;
use App\Controllers\AdminController;
use App\Controllers\AdminMigrationsController;
use App\Controllers\AuthController;
use App\Controllers\AvisController;
use App\Controllers\CatalogueController;
use App\Controllers\DiagnosticController;
use App\Controllers\InstallController;
use App\Controllers\CategoriesController;
use App\Controllers\CategoryDetailController;
use App\Controllers\ControleController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\ProductDetailController;
use App\Controllers\ProductsController;
use App\Controllers\SettingsController;

/**
 * Table de routage du Hub.
 *
 * Format : [méthode HTTP, chemin (regex friendly avec {param}), [Controller, action], 'auth' (true|false|'super-admin')]
 */
return [
    // Page d'accueil — redirige vers /dashboard ou /login
    ['GET', '/', [HomeController::class, 'index'], false],

    // Installation one-shot (token-protégée via INSTALL_TOKEN dans .env)
    ['GET',  '/install', [InstallController::class, 'show'], false],
    ['POST', '/install', [InstallController::class, 'run'], false],

    // Diagnostic (config DB + test connexion + extensions PHP) — token-protégée
    ['GET',  '/diag', [DiagnosticController::class, 'show'], false],
    ['GET',  '/diag-mail', [DiagnosticController::class, 'mail'], false],

    // Auth (toutes publiques)
    ['GET', '/login', [AuthController::class, 'showLogin'], false],
    ['POST', '/login', [AuthController::class, 'login'], false],
    ['POST', '/logout', [AuthController::class, 'logout'], true],
    ['GET', '/forgot-password', [AuthController::class, 'showForgotPassword'], false],
    ['POST', '/forgot-password', [AuthController::class, 'sendResetLink'], false],
    ['GET', '/reset-password', [AuthController::class, 'showResetPassword'], false],
    ['POST', '/reset-password', [AuthController::class, 'resetPassword'], false],
    ['GET', '/set-password', [AuthController::class, 'showSetPassword'], false],
    ['POST', '/set-password', [AuthController::class, 'setPassword'], false],

    // Dashboard
    ['GET', '/dashboard', [DashboardController::class, 'index'], true],

    // Settings (5 onglets)
    ['GET',  '/settings', [SettingsController::class, 'show'], true],
    ['POST', '/settings/prestashop', [SettingsController::class, 'savePrestashop'], true],
    ['POST', '/settings/prestashop/test', [SettingsController::class, 'testPrestashopConnection'], true],
    ['GET',  '/settings/prestashop/download-reviews-api', [SettingsController::class, 'downloadReviewsApiFile'], true],
    ['POST', '/settings/account', [SettingsController::class, 'saveAccount'], true],
    ['POST', '/settings/users/invite', [SettingsController::class, 'inviteUser'], true],
    ['POST', '/settings/users/{userId}/unlink', [SettingsController::class, 'unlinkUser'], true],
    ['POST', '/settings/ai-preferences', [SettingsController::class, 'savePreferences'], true],
    ['POST', '/settings/api-keys', [SettingsController::class, 'saveApiKey'], true],
    ['POST', '/settings/api-keys/{provider}/delete', [SettingsController::class, 'deleteApiKey'], true],
    ['POST', '/settings/editorial', [SettingsController::class, 'saveEditorial'], true],
    ['POST', '/settings/nutriweb', [SettingsController::class, 'saveNutriweb'], true],
    ['POST', '/settings/attributes', [SettingsController::class, 'saveAttributes'], true],
    ['POST', '/settings/field-instructions', [SettingsController::class, 'saveFieldInstructions'], true],

    // Catégories
    ['GET',  '/categories', [CategoriesController::class, 'index'], true],
    ['POST', '/categories/sync', [CategoriesController::class, 'sync'], true],
    ['POST', '/categories/test-connection', [CategoriesController::class, 'testConnection'], true],
    ['GET',  '/categories/{id}', [CategoryDetailController::class, 'show'], true],
    ['POST', '/categories/{id}/save', [CategoryDetailController::class, 'saveOptimized'], true],
    ['POST', '/categories/{id}/push', [CategoryDetailController::class, 'push'], true],
    ['POST', '/categories/{id}/generate', [CategoryDetailController::class, 'generate'], true],

    // Produits
    ['GET',  '/produits', [ProductsController::class, 'index'], true],
    ['POST', '/produits/sync', [ProductsController::class, 'sync'], true],
    ['GET',  '/produits/{id}', [ProductDetailController::class, 'show'], true],
    ['POST', '/produits/{id}/save', [ProductDetailController::class, 'saveOptimized'], true],
    ['POST', '/produits/{id}/push', [ProductDetailController::class, 'push'], true],
    ['POST', '/produits/{id}/generate', [ProductDetailController::class, 'generate'], true],
    ['POST', '/produits/{id}/gallery/reorder', [ProductDetailController::class, 'reorderGallery'], true],

    // Generation d'images IA (Kie.AI) par produit
    ['POST', '/produits/{id}/images/generate', [ProductDetailController::class, 'generateImage'], true],
    ['GET',  '/produits/{id}/images/{generationId}/status', [ProductDetailController::class, 'imageStatus'], true],
    ['POST', '/produits/{id}/images/{generationId}/refine', [ProductDetailController::class, 'refineImage'], true],
    ['POST', '/produits/{id}/images/{generationId}/add-to-gallery', [ProductDetailController::class, 'addImageToGallery'], true],
    ['POST', '/produits/{id}/images/{generationId}/delete', [ProductDetailController::class, 'deleteImage'], true],

    // Catalogue Nutriweb
    ['GET',  '/catalogue', [CatalogueController::class, 'index'], true],
    ['POST', '/catalogue/sync', [CatalogueController::class, 'sync'], true],
    ['GET',  '/catalogue/search-presta-products', [CatalogueController::class, 'searchPrestaProducts'], true],
    ['GET',  '/catalogue/create', [CatalogueController::class, 'showCreate'], true],
    ['POST', '/catalogue/create', [CatalogueController::class, 'create'], true],

    ['GET',  '/controle', [ControleController::class, 'index'], true],
    ['POST', '/controle/fix-supplier-ref', [ControleController::class, 'fixSupplierRef'], true],
    ['POST', '/controle/clear-sql', [ControleController::class, 'clearSqlQueue'], true],

    // Avis Produit (module ws_productreviews via api_reviews.php)
    ['GET',  '/avis', [AvisController::class, 'index'], true],
    ['GET',  '/avis/{id}', [AvisController::class, 'product'], true],
    ['POST', '/avis/{id}/generate', [AvisController::class, 'generateReviews'], true],
    ['POST', '/avis/{productId}/review/{reviewId}/update', [AvisController::class, 'updateReview'], true],
    ['POST', '/avis/{productId}/review/{reviewId}/delete', [AvisController::class, 'deleteReview'], true],

    // Admin (super-admin uniquement)
    ['GET',  '/admin', [AdminController::class, 'index'], 'super-admin'],
    ['POST', '/admin/clear-client', [AdminController::class, 'clearClient'], 'super-admin'],
    ['POST', '/admin/super-admins', [AdminController::class, 'addSuperAdmin'], 'super-admin'],
    ['POST', '/admin/super-admins/{userId}/remove', [AdminController::class, 'removeSuperAdmin'], 'super-admin'],

    // Admin — clients
    ['GET',  '/admin/clients/new', [AdminClientsController::class, 'showNew'], 'super-admin'],
    ['POST', '/admin/clients', [AdminClientsController::class, 'create'], 'super-admin'],
    ['GET',  '/admin/clients/{id}', [AdminClientsController::class, 'showEdit'], 'super-admin'],
    ['POST', '/admin/clients/{id}', [AdminClientsController::class, 'update'], 'super-admin'],
    ['POST', '/admin/clients/{id}/delete', [AdminClientsController::class, 'delete'], 'super-admin'],
    ['POST', '/admin/clients/{id}/switch', [AdminController::class, 'switchClient'], 'super-admin'],
    ['GET',  '/admin/clients/{id}/usage', [AdminClientsController::class, 'showUsage'], 'super-admin'],
    ['POST', '/admin/clients/{clientId}/users/{userId}/reset-password', [AdminClientsController::class, 'resetUserPassword'], 'super-admin'],

    // Admin — migrations DB
    ['GET',  '/admin/migrations', [AdminMigrationsController::class, 'index'], 'super-admin'],
    ['POST', '/admin/migrations/run', [AdminMigrationsController::class, 'run'], 'super-admin'],
    ['POST', '/admin/migrations/mark-applied/{name}', [AdminMigrationsController::class, 'markApplied'], 'super-admin'],
];
