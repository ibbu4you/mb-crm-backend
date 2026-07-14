<?php

namespace App\Support;

/**
 * The single source of truth for the platform's permission catalog,
 * grouped by module. Consumed by PermissionSeeder and the /permissions API
 * (which drives the employee permission-matrix UI).
 *
 * Naming convention: `module.action` (+ `.all` unlocks cross-team data scope).
 */
class Permissions
{
    /** @return array<string, array{label: string, permissions: array<string, string>}> */
    public static function catalog(): array
    {
        return [
            'employees' => [
                'label' => 'Employees & Access',
                'permissions' => [
                    'employees.view' => 'View employees',
                    'employees.manage' => 'Create / edit / deactivate employees',
                    'roles.manage' => 'Manage roles & permissions',
                ],
            ],
            'hrms' => [
                'label' => 'HRMS',
                'permissions' => [
                    'hrms.view' => 'View HR records',
                    'hrms.attendance.manage' => 'Manage attendance',
                    'hrms.leave.manage' => 'Manage leave requests',
                    'hrms.leave.approve' => 'Approve / reject leave',
                    'hrms.payroll.manage' => 'Manage payroll & payslips',
                ],
            ],
            'tasks' => [
                'label' => 'Tasks',
                'permissions' => [
                    'tasks.view' => 'View tasks',
                    'tasks.manage' => 'Create / edit tasks',
                    'tasks.assign' => 'Assign tasks to others',
                ],
            ],
            'contacts' => [
                'label' => 'Contacts',
                'permissions' => [
                    'contacts.view' => 'View own contacts',
                    'contacts.view.all' => 'View all contacts',
                    'contacts.manage' => 'Create / edit / merge contacts',
                ],
            ],
            'leads' => [
                'label' => 'Leads',
                'permissions' => [
                    'leads.view' => 'View own leads',
                    'leads.view.all' => 'View all leads',
                    'leads.manage' => 'Create / edit / triage leads',
                    'leads.export' => 'Export leads',
                    'leads.import' => 'Import leads',
                ],
            ],
            'sales' => [
                'label' => 'Field Sales',
                'permissions' => [
                    'sales.visits.log' => 'Log visits',
                    'sales.clients.manage' => 'Manage clients',
                    'sales.pipeline.manage' => 'Move pipeline stages',
                    'sales.followups.manage' => 'Manage follow-ups',
                    'sales.deals.manage' => 'Close deals',
                    'sales.targets.manage' => 'Set targets',
                    'sales.reports.view' => 'View own reports',
                    'sales.reports.view.all' => 'View team reports',
                ],
            ],
            'content' => [
                'label' => 'Content · Articles',
                'permissions' => [
                    'content.articles.view' => 'View articles',
                    'content.articles.submit' => 'Submit articles',
                    'content.articles.write' => 'Write / edit articles',
                    'content.articles.review' => 'Review / request revisions',
                    'content.articles.assign' => 'Assign writers',
                    'content.articles.publish' => 'Publish articles',
                ],
            ],
            'viral' => [
                'label' => 'Content · Viral Packages',
                'permissions' => [
                    'viral.view' => 'View viral packages',
                    'viral.manage' => 'Create / manage packages',
                    'viral.deliverables.work' => 'Work on deliverables',
                    'viral.approve' => 'Approve deliverables',
                ],
            ],
            'support' => [
                'label' => 'Support Desk',
                'permissions' => [
                    'support.view' => 'View tickets',
                    'support.handle' => 'Reply / resolve tickets',
                    'support.assign' => 'Assign tickets',
                ],
            ],
            'invoicing' => [
                'label' => 'Invoicing & Payments',
                'permissions' => [
                    'invoicing.view' => 'View invoices',
                    'invoicing.manage' => 'Create / send invoices',
                    'invoicing.payments.manage' => 'Record payments',
                    'invoicing.reports.view' => 'View revenue reports',
                ],
            ],
            'whatsapp' => [
                'label' => 'WhatsApp',
                'permissions' => [
                    'whatsapp.view' => 'View WhatsApp dashboard & inbox',
                    'whatsapp.send' => 'Send messages & run campaigns',
                    'whatsapp.manage' => 'Manage templates, audiences & campaigns',
                ],
            ],
            'collateral' => [
                'label' => 'Collateral',
                'permissions' => [
                    'portfolio.view' => 'View portfolio',
                    'portfolio.manage' => 'Manage portfolio',
                    'documents.view' => 'View company documents',
                    'documents.manage' => 'Manage company documents',
                ],
            ],
            'settings' => [
                'label' => 'Settings',
                'permissions' => [
                    'settings.manage' => 'Manage settings & branding',
                    'integrations.manage' => 'Manage integrations (Drive, WhatsApp)',
                ],
            ],
            'audit' => [
                'label' => 'Audit',
                'permissions' => [
                    'audit.view' => 'View activity log',
                ],
            ],
        ];
    }

    /** Flat list of every permission name. @return list<string> */
    public static function all(): array
    {
        $names = [];
        foreach (self::catalog() as $group) {
            foreach ($group['permissions'] as $name => $label) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
