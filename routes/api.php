<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AlertNumberController;
use App\Http\Controllers\Api\AppSettingsController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\SpotlightController;
use App\Http\Controllers\Api\WorkLogController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\OfficeLocationController;
use App\Http\Controllers\Api\ArticleAssetController;
use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContentActivityController;
use App\Http\Controllers\Api\ContentAnalyticsController;
use App\Http\Controllers\Api\ContentDashboardController;
use App\Http\Controllers\Api\ContentReportController;
use App\Http\Controllers\Api\WhatsAppCampaignController;
use App\Http\Controllers\Api\WhatsAppDashboardController;
use App\Http\Controllers\Api\WhatsAppGroupController;
use App\Http\Controllers\Api\WhatsAppInboxController;
use App\Http\Controllers\Api\WhatsAppSettingsController;
use App\Http\Controllers\Api\WhatsAppTemplateController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FollowUpController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LeadIntakeController;
use App\Http\Controllers\Api\LeadNoteController;
use App\Http\Controllers\Api\LeadTypeController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OverviewController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\CompanyDocumentController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\SalesClientController;
use App\Http\Controllers\Api\SalesDashboardController;
use App\Http\Controllers\Api\SalesReportController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TargetController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\ViralDeliverableController;
use App\Http\Controllers\Api\ViralPackageController;
use App\Http\Controllers\Api\VisitController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // --- Public auth ---
    Route::post('auth/login', [AuthController::class, 'login']);

    // --- Public lead intake (web/Elementor forms) — throttled ---
    Route::post('public/leads', [LeadIntakeController::class, 'store'])->middleware('throttle:30,1');
    // --- Public "Get Featured" / Free Spotlight landing-page intake — throttled ---
    Route::post('public/spotlight', [SpotlightController::class, 'store'])->middleware('throttle:20,1');

    // --- WhatsApp webhook (Meta) — public, signature-verified ---
    Route::get('whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
    Route::post('whatsapp/webhook', [WhatsAppWebhookController::class, 'handle'])->middleware('throttle:120,1');

    // --- Authenticated ---
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // Executive overview dashboard (sections self-gate by permission)
        Route::get('overview', OverviewController::class);

        // Permission catalog (needed by the employee matrix UI)
        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('permission:employees.manage|roles.manage');

        // Roles
        Route::middleware('permission:roles.manage')->group(function () {
            Route::get('roles', [RoleController::class, 'index']);
            Route::post('roles', [RoleController::class, 'store']);
            Route::put('roles/{role}', [RoleController::class, 'update']);
            Route::delete('roles/{role}', [RoleController::class, 'destroy']);
        });

        // Employees
        Route::middleware('permission:employees.view|employees.manage')
            ->get('employees', [EmployeeController::class, 'index']);
        Route::middleware('permission:employees.view|employees.manage')
            ->get('employees/{employee}', [EmployeeController::class, 'show']);

        Route::middleware('permission:employees.manage')->group(function () {
            Route::post('employees', [EmployeeController::class, 'store']);
            Route::put('employees/{employee}', [EmployeeController::class, 'update']);
            Route::patch('employees/{employee}/toggle-active', [EmployeeController::class, 'toggleActive']);
            Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);
        });

        // --- Contacts ---
        Route::middleware('permission:contacts.view|contacts.view.all|contacts.manage')->group(function () {
            Route::get('contacts', [ContactController::class, 'index']);
            Route::get('contacts/duplicates', [ContactController::class, 'duplicates']);
            Route::get('contacts/stats', [ContactController::class, 'stats']);
            Route::get('contacts/{contact}', [ContactController::class, 'show']);
        });
        Route::middleware('permission:contacts.manage')->group(function () {
            Route::post('contacts', [ContactController::class, 'store']);
            Route::put('contacts/{contact}', [ContactController::class, 'update']);
            Route::delete('contacts/{contact}', [ContactController::class, 'destroy']);
        });

        // --- Lead types (catalog) ---
        Route::get('lead-types', [LeadTypeController::class, 'index']);
        Route::middleware('permission:settings.manage|leads.manage')->group(function () {
            Route::post('lead-types', [LeadTypeController::class, 'store']);
            Route::put('lead-types/{leadType}', [LeadTypeController::class, 'update']);
            Route::delete('lead-types/{leadType}', [LeadTypeController::class, 'destroy']);
        });

        // --- Leads ---
        Route::middleware('permission:leads.view|leads.view.all|leads.manage')->group(function () {
            Route::get('leads/catalog', [LeadController::class, 'catalog']);
            Route::get('leads/stats', [LeadController::class, 'stats']);
            Route::get('leads/dashboard', [LeadController::class, 'dashboard']);
            Route::get('leads', [LeadController::class, 'index']);
            // Static paths must precede the {lead} wildcard. Both gated on leads.manage.
            Route::get('leads/import-template', [LeadController::class, 'importTemplate'])->middleware('permission:leads.manage');
            Route::post('leads/import', [LeadController::class, 'import'])->middleware('permission:leads.manage');
            Route::get('leads/{lead}', [LeadController::class, 'show']);
        });
        Route::middleware('permission:leads.export|leads.view.all|leads.manage')->get('leads-export', [LeadController::class, 'export']);

        // WhatsApp alert numbers + settings
        Route::middleware('permission:leads.manage')->group(function () {
            Route::get('whatsapp-numbers', [AlertNumberController::class, 'index']);
            Route::post('whatsapp-numbers', [AlertNumberController::class, 'store']);
            Route::put('whatsapp-numbers/{whatsappNumber}', [AlertNumberController::class, 'update']);
            Route::delete('whatsapp-numbers/{whatsappNumber}', [AlertNumberController::class, 'destroy']);
        });
        Route::middleware('permission:integrations.manage|settings.manage')->group(function () {
            Route::get('whatsapp/settings', [WhatsAppSettingsController::class, 'show']);
            Route::put('whatsapp/settings', [WhatsAppSettingsController::class, 'update']);
            Route::post('whatsapp/settings/test', [WhatsAppSettingsController::class, 'test']);
        });

        // --- WhatsApp Module: dashboard, inbox, templates, audiences, campaigns ---
        Route::middleware('permission:whatsapp.view|whatsapp.send|whatsapp.manage')->group(function () {
            Route::get('whatsapp/dashboard', WhatsAppDashboardController::class);
            Route::get('whatsapp/inbox', [WhatsAppInboxController::class, 'threads']);
            Route::get('whatsapp/inbox/{phone}', [WhatsAppInboxController::class, 'show']);
            Route::get('whatsapp/templates', [WhatsAppTemplateController::class, 'index']);
            Route::get('whatsapp/groups', [WhatsAppGroupController::class, 'index']);
            Route::get('whatsapp/groups/{group}', [WhatsAppGroupController::class, 'show']);
            Route::get('whatsapp/contacts', [WhatsAppGroupController::class, 'contactOptions']);
            Route::get('whatsapp/campaigns', [WhatsAppCampaignController::class, 'index']);
            Route::get('whatsapp/campaigns/{campaign}', [WhatsAppCampaignController::class, 'show']);
        });
        Route::middleware('permission:whatsapp.send|whatsapp.manage')->group(function () {
            Route::post('whatsapp/inbox/{phone}/reply', [WhatsAppInboxController::class, 'reply']);
            Route::post('whatsapp/inbox/{phone}/read', [WhatsAppInboxController::class, 'markRead']);
            Route::delete('whatsapp/messages/{message}', [WhatsAppInboxController::class, 'destroyMessage']);
            Route::post('whatsapp/campaigns/{campaign}/send', [WhatsAppCampaignController::class, 'send']);
        });
        Route::middleware('permission:whatsapp.manage')->group(function () {
            Route::post('whatsapp/templates/sync', [WhatsAppTemplateController::class, 'sync']);
            Route::post('whatsapp/templates', [WhatsAppTemplateController::class, 'store']);
            Route::put('whatsapp/templates/{template}', [WhatsAppTemplateController::class, 'update']);
            Route::delete('whatsapp/templates/{template}', [WhatsAppTemplateController::class, 'destroy']);
            Route::post('whatsapp/groups', [WhatsAppGroupController::class, 'store']);
            Route::put('whatsapp/groups/{group}', [WhatsAppGroupController::class, 'update']);
            Route::delete('whatsapp/groups/{group}', [WhatsAppGroupController::class, 'destroy']);
            Route::post('whatsapp/groups/{group}/members', [WhatsAppGroupController::class, 'addMembers']);
            Route::delete('whatsapp/group-members/{member}', [WhatsAppGroupController::class, 'removeMember']);
            Route::post('whatsapp/campaigns', [WhatsAppCampaignController::class, 'store']);
            Route::put('whatsapp/campaigns/{campaign}', [WhatsAppCampaignController::class, 'update']);
            Route::delete('whatsapp/campaigns/{campaign}', [WhatsAppCampaignController::class, 'destroy']);
        });
        Route::middleware('permission:leads.manage')->group(function () {
            Route::post('leads', [LeadController::class, 'store']);
            Route::put('leads/{lead}', [LeadController::class, 'update']);
            Route::patch('leads/{lead}/stage', [LeadController::class, 'updateStage']);
            Route::delete('leads/{lead}', [LeadController::class, 'destroy']);
            Route::post('leads/{lead}/notes', [LeadNoteController::class, 'store']);
            Route::delete('lead-notes/{note}', [LeadNoteController::class, 'destroy']);
        });

        // --- Field Sales ---
        Route::get('sales/dashboard', [SalesDashboardController::class, 'index'])
            ->middleware('permission:sales.reports.view|sales.reports.view.all|sales.visits.log');

        Route::middleware('permission:sales.visits.log|sales.reports.view')->get('visits', [VisitController::class, 'index']);
        Route::middleware('permission:sales.visits.log')->post('visits', [VisitController::class, 'store']);

        Route::middleware('permission:sales.followups.manage|sales.reports.view')->group(function () {
            Route::get('follow-ups', [FollowUpController::class, 'index']);
            Route::get('follow-ups/badge', [FollowUpController::class, 'badge']);
        });
        Route::middleware('permission:sales.followups.manage')->group(function () {
            Route::post('follow-ups', [FollowUpController::class, 'store']);
            Route::patch('follow-ups/{followUp}/complete', [FollowUpController::class, 'complete']);
            Route::patch('follow-ups/{followUp}/reopen', [FollowUpController::class, 'reopen']);
        });

        Route::middleware('permission:sales.deals.manage|sales.reports.view')->get('deals', [DealController::class, 'index']);
        Route::middleware('permission:sales.deals.manage')->post('deals', [DealController::class, 'store']);

        Route::middleware('permission:sales.targets.manage|sales.reports.view')->get('targets', [TargetController::class, 'index']);
        Route::middleware('permission:sales.targets.manage')->post('targets', [TargetController::class, 'upsert']);

        // Sales clients (leads with visit history)
        Route::middleware('permission:sales.clients.manage|sales.reports.view|sales.visits.log')->group(function () {
            Route::get('sales/clients', [SalesClientController::class, 'index']);
            Route::get('sales/clients/stats', [SalesClientController::class, 'stats']);
            Route::get('sales/clients/export', [SalesClientController::class, 'export']);
            Route::get('sales/clients/template', [SalesClientController::class, 'template']);
            Route::get('sales/clients/{lead}', [SalesClientController::class, 'show']);
        });
        Route::middleware('permission:sales.clients.manage')->post('sales/clients/import', [SalesClientController::class, 'import']);
        // Sales reports
        Route::middleware('permission:sales.reports.view|sales.reports.view.all')->group(function () {
            Route::get('sales/report', [SalesReportController::class, 'index']);
            Route::get('sales/report/export', [SalesReportController::class, 'export']);
        });

        // --- Collateral: Portfolio + Company documents ---
        Route::middleware('permission:portfolio.view|portfolio.manage')->get('portfolio', [PortfolioController::class, 'index']);
        Route::middleware('permission:portfolio.manage')->group(function () {
            Route::post('portfolio/preview', [PortfolioController::class, 'preview']);
            Route::post('portfolio', [PortfolioController::class, 'store']);
            Route::put('portfolio/{portfolioItem}', [PortfolioController::class, 'update']);
            Route::delete('portfolio/{portfolioItem}', [PortfolioController::class, 'destroy']);
            Route::post('portfolio/{portfolioItem}/images', [PortfolioController::class, 'addImage']);
            Route::delete('portfolio-images/{image}', [PortfolioController::class, 'removeImage']);
        });
        Route::middleware('permission:documents.view|documents.manage')->get('company-documents', [CompanyDocumentController::class, 'index']);
        Route::middleware('permission:documents.manage')->group(function () {
            Route::post('company-documents', [CompanyDocumentController::class, 'store']);
            Route::put('company-documents/{companyDocument}', [CompanyDocumentController::class, 'update']);
            Route::delete('company-documents/{companyDocument}', [CompanyDocumentController::class, 'destroy']);
        });

        // --- Global search ---
        Route::get('search', \App\Http\Controllers\Api\SearchController::class);

        // --- Attendance (self check-in/out for every employee) ---
        Route::post('geocode', GeocodeController::class);
        Route::get('attendance/today', [AttendanceController::class, 'today']);
        Route::get('attendance/mine', [AttendanceController::class, 'mine']);
        Route::get('attendance/summary', [AttendanceController::class, 'summary']);
        Route::post('attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('attendance/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('office-locations', [OfficeLocationController::class, 'index']);

        // --- Work status (hourly self-logging for every employee) ---
        Route::get('work/today', [WorkLogController::class, 'today']);
        Route::get('work/logs', [WorkLogController::class, 'index']);
        Route::post('work/logs', [WorkLogController::class, 'store'])->middleware('permission:work.log.submit');
        Route::get('work/link-options', [WorkLogController::class, 'linkOptions'])->middleware('permission:work.log.submit');
        Route::middleware('permission:work.logs.view.all')->group(function () {
            Route::get('work/board', [WorkLogController::class, 'board']);
            Route::get('work/report', [WorkLogController::class, 'report']);
            Route::get('work/report/export', [WorkLogController::class, 'export']);
        });
        // Edit/delete own entries (managers can touch anyone's — enforced in the controller).
        Route::middleware('permission:work.log.submit')->group(function () {
            Route::patch('work/logs/{workLog}', [WorkLogController::class, 'update']);
            Route::delete('work/logs/{workLog}', [WorkLogController::class, 'destroy']);
        });

        // Leave — self-service (every employee)
        Route::get('leaves/catalog', [LeaveController::class, 'catalog']);
        Route::get('leaves/mine', [LeaveController::class, 'mine']);
        Route::post('leaves', [LeaveController::class, 'store']);
        Route::patch('leaves/{leave}/cancel', [LeaveController::class, 'cancel']);
        // Leave — approvers (HR / managers)
        Route::middleware('permission:hrms.leave.approve|hrms.leave.manage|hrms.view')->get('leaves', [LeaveController::class, 'index']);
        Route::middleware('permission:hrms.leave.approve|hrms.leave.manage')->group(function () {
            Route::patch('leaves/{leave}/approve', [LeaveController::class, 'approve']);
            Route::patch('leaves/{leave}/reject', [LeaveController::class, 'reject']);
        });

        // Manager: team register + geofence management
        Route::middleware('permission:hrms.attendance.manage|hrms.view')->group(function () {
            Route::get('attendance/register', [AttendanceController::class, 'register']);
            Route::get('attendance/team', [AttendanceController::class, 'team']);
            Route::get('attendance/team/export', [AttendanceController::class, 'export']);
        });
        Route::middleware('permission:hrms.attendance.manage')->group(function () {
            Route::post('office-locations', [OfficeLocationController::class, 'store']);
            Route::put('office-locations/{officeLocation}', [OfficeLocationController::class, 'update']);
            Route::delete('office-locations/{officeLocation}', [OfficeLocationController::class, 'destroy']);
        });

        // --- Notifications (any authenticated user) ---
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead']);

        // --- Content · Articles ---

        // Content admin dashboards & reports
        Route::middleware('permission:content.articles.view')->group(function () {
            Route::get('content/dashboard', ContentDashboardController::class);
            Route::get('content/analytics', ContentAnalyticsController::class);
            Route::get('content/activity', ContentActivityController::class);
            Route::get('content/report', [ContentReportController::class, 'index']);
            Route::get('content/report/export', [ContentReportController::class, 'export']);
        });

        // App settings (branding + thresholds)
        Route::get('settings', [AppSettingsController::class, 'show'])->middleware('permission:settings.manage');
        Route::put('settings', [AppSettingsController::class, 'update'])->middleware('permission:settings.manage');

        // Activity log (audit trail)
        Route::middleware('permission:audit.view')->group(function () {
            Route::get('activity-log/catalog', [ActivityLogController::class, 'catalog']);
            Route::get('activity-log', [ActivityLogController::class, 'index']);
        });

        Route::middleware('permission:content.articles.view')->group(function () {
            Route::get('articles/catalog', [ArticleController::class, 'catalog']);
            Route::get('articles/stats', [ArticleController::class, 'stats']);
            Route::get('articles', [ArticleController::class, 'index']);
            Route::get('articles/{article}', [ArticleController::class, 'show']);
            Route::post('articles/{article}/comments', [ArticleController::class, 'comment']);
        });

        Route::middleware('permission:content.articles.submit')->post('articles', [ArticleController::class, 'store']);
        Route::middleware('permission:content.articles.submit|content.articles.assign')->put('articles/{article}', [ArticleController::class, 'update']);

        // Writer actions
        Route::middleware('permission:content.articles.write')->group(function () {
            Route::post('articles/{article}/self-assign', [ArticleController::class, 'selfAssign']);
            Route::post('articles/{article}/start', [ArticleController::class, 'start']);
            Route::post('articles/{article}/submit-review', [ArticleController::class, 'submitReview']);
        });
        // Assign a writer
        Route::middleware('permission:content.articles.assign|content.articles.submit|content.articles.write')->get('article-writers', [ArticleController::class, 'writers']);
        Route::middleware('permission:content.articles.assign')->post('articles/{article}/assign', [ArticleController::class, 'assign']);
        // Sales review actions
        Route::middleware('permission:content.articles.review')->group(function () {
            Route::post('articles/{article}/request-revision', [ArticleController::class, 'requestRevision']);
            Route::post('articles/{article}/revoke-revision', [ArticleController::class, 'revokeRevision']);
            Route::post('articles/{article}/client-approved', [ArticleController::class, 'clientApproved']);
        });
        Route::middleware('permission:content.articles.publish')->post('articles/{article}/publish', [ArticleController::class, 'publish']);

        // Assets
        Route::middleware('permission:content.articles.submit|content.articles.write')->post('articles/{article}/assets', [ArticleAssetController::class, 'store']);
        Route::middleware('permission:content.articles.submit|content.articles.write')->delete('article-assets/{asset}', [ArticleAssetController::class, 'destroy']);

        Route::middleware('permission:content.articles.assign')->delete('articles/{article}', [ArticleController::class, 'destroy']);

        // --- Content · Viral Packages ---
        Route::middleware('permission:viral.view|viral.manage|viral.deliverables.work|viral.approve')->group(function () {
            Route::get('viral/catalog', [ViralPackageController::class, 'catalog']);
            Route::get('viral/stats', [ViralPackageController::class, 'stats']);
            Route::get('viral/team-options', [ViralPackageController::class, 'teamOptions']);
            Route::get('viral', [ViralPackageController::class, 'index']);
            Route::get('viral/{viralPackage}', [ViralPackageController::class, 'show']);
        });
        // Sales create packages for their clients; managers manage them.
        Route::middleware('permission:viral.manage|viral.approve')->post('viral', [ViralPackageController::class, 'store']);
        // Manage packages
        Route::middleware('permission:viral.manage')->group(function () {
            Route::post('viral/{viralPackage}/deliverables', [ViralPackageController::class, 'addDeliverable']);
            Route::delete('viral-deliverables/{deliverable}', [ViralPackageController::class, 'removeDeliverable']);
            Route::post('viral/{viralPackage}/mark-delivered', [ViralPackageController::class, 'markDelivered']);
            Route::post('viral/{viralPackage}/reassign', [ViralPackageController::class, 'reassign']);
            Route::post('viral/{viralPackage}/reopen', [ViralPackageController::class, 'reopen']);
            Route::delete('viral/{viralPackage}', [ViralPackageController::class, 'destroy']);
        });
        // Work on deliverables
        Route::middleware('permission:viral.deliverables.work')->group(function () {
            Route::post('viral-deliverables/{deliverable}/pick-up', [ViralDeliverableController::class, 'pickUp']);
            Route::post('viral-deliverables/{deliverable}/submit', [ViralDeliverableController::class, 'submit']);
        });
        // Approve / correct
        Route::middleware('permission:viral.approve')->group(function () {
            Route::post('viral-deliverables/{deliverable}/approve', [ViralDeliverableController::class, 'approve']);
            Route::post('viral-deliverables/{deliverable}/request-correction', [ViralDeliverableController::class, 'requestCorrection']);
        });

        // --- Support Desk ---
        Route::middleware('permission:support.view|support.handle')->group(function () {
            Route::get('support/catalog', [SupportTicketController::class, 'catalog']);
            Route::get('support/stats', [SupportTicketController::class, 'stats']);
            Route::get('support', [SupportTicketController::class, 'index']);
            Route::get('support/{ticket}', [SupportTicketController::class, 'show']);
            Route::post('support', [SupportTicketController::class, 'store']);          // raise a ticket
            Route::post('support/{ticket}/reply', [SupportTicketController::class, 'reply']);
        });
        // Handler actions
        Route::middleware('permission:support.handle')->group(function () {
            Route::patch('support/{ticket}/status', [SupportTicketController::class, 'updateStatus']);
            Route::patch('support/{ticket}/priority', [SupportTicketController::class, 'updatePriority']);
            Route::delete('support/{ticket}', [SupportTicketController::class, 'destroy']);
        });
        // Assign
        Route::middleware('permission:support.assign')->group(function () {
            Route::get('support-agents', [SupportTicketController::class, 'agents']);
            Route::patch('support/{ticket}/assign', [SupportTicketController::class, 'assign']);
        });

        // --- Invoicing & Payments ---
        Route::get('products', [ProductController::class, 'index']);
        Route::middleware('permission:invoicing.manage')->group(function () {
            Route::post('products', [ProductController::class, 'store']);
            Route::put('products/{product}', [ProductController::class, 'update']);
            Route::delete('products/{product}', [ProductController::class, 'destroy']);
        });

        Route::middleware('permission:invoicing.view|invoicing.manage|invoicing.reports.view')->group(function () {
            Route::get('invoices/stats', [InvoiceController::class, 'stats']);
            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
        });
        Route::middleware('permission:invoicing.manage')->group(function () {
            Route::post('invoices', [InvoiceController::class, 'store']);
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update']);
            Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send']);
            Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
            Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy']);
        });
        Route::middleware('permission:invoicing.payments.manage|invoicing.manage')->group(function () {
            Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store']);
            Route::delete('payments/{payment}', [PaymentController::class, 'destroy']);
        });

        // --- Tasks (any authenticated user with tasks.view) ---
        Route::middleware('permission:tasks.view')->group(function () {
            Route::get('tasks/catalog', [TaskController::class, 'catalog']);
            Route::get('tasks/stats', [TaskController::class, 'stats']);
            Route::get('tasks/badge', [TaskController::class, 'badge']);
            Route::get('tasks', [TaskController::class, 'index']);
            Route::get('tasks/{task}', [TaskController::class, 'show']);
        });
        Route::middleware('permission:tasks.assign')->get('task-assignees', [TaskController::class, 'assignees']);
        Route::middleware('permission:tasks.manage')->group(function () {
            Route::post('tasks', [TaskController::class, 'store']);
            Route::put('tasks/{task}', [TaskController::class, 'update']);
            Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus']);
            Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
        });
    });
});
