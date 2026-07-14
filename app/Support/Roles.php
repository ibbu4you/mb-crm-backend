<?php

namespace App\Support;

/**
 * Default role catalog with their permission grants. Roles are data (editable
 * in-app), this only seeds sensible starting points. "Super Admin" is granted
 * everything via a Gate::before bypass (see AppServiceProvider), so it is not
 * listed with explicit permissions here.
 */
class Roles
{
    public const SUPER_ADMIN = 'Administrator';

    /** @return array<string, list<string>> role => permission names ('*' = all) */
    public static function definitions(): array
    {
        return [
            self::SUPER_ADMIN => ['*'],

            'Admin' => ['*'], // full business access (no Super Admin bypass)

            'Manager' => [
                'employees.view',
                'hrms.view', 'hrms.leave.approve',
                'tasks.view', 'tasks.manage', 'tasks.assign',
                'contacts.view', 'contacts.view.all', 'contacts.manage',
                'leads.view', 'leads.view.all', 'leads.manage', 'leads.export',
                'sales.reports.view', 'sales.reports.view.all', 'sales.targets.manage', 'sales.pipeline.manage',
                'content.articles.view', 'content.articles.assign', 'content.articles.review',
                'viral.view', 'support.view',
                'invoicing.view', 'invoicing.reports.view',
                'portfolio.view', 'documents.view',
                'whatsapp.view', 'whatsapp.send', 'whatsapp.manage',
                'audit.view',
            ],

            'Salesperson' => [
                'tasks.view', 'tasks.manage',
                'contacts.view', 'contacts.manage',
                'leads.view', 'leads.manage', 'leads.export',
                'sales.visits.log', 'sales.clients.manage', 'sales.pipeline.manage',
                'sales.followups.manage', 'sales.deals.manage', 'sales.reports.view',
                'content.articles.view', 'content.articles.submit', 'content.articles.review',
                'viral.view', 'viral.approve',
                'invoicing.view',
                'portfolio.view', 'documents.view',
            ],

            'Tech Writer' => [
                'tasks.view',
                'contacts.view',
                'content.articles.view', 'content.articles.submit', 'content.articles.write',
                'viral.view', 'viral.deliverables.work',
            ],

            'Tech Lead' => [
                'tasks.view', 'tasks.manage', 'tasks.assign',
                'contacts.view', 'contacts.view.all',
                'content.articles.view', 'content.articles.submit', 'content.articles.write',
                'content.articles.review', 'content.articles.assign', 'content.articles.publish',
                'viral.view', 'viral.manage', 'viral.deliverables.work', 'viral.approve',
            ],

            'Support Agent' => [
                'tasks.view',
                'contacts.view',
                'support.view', 'support.handle',
            ],

            'HR' => [
                'employees.view',
                'hrms.view', 'hrms.attendance.manage', 'hrms.leave.manage', 'hrms.leave.approve', 'hrms.payroll.manage',
                'tasks.view',
            ],

            'Accountant' => [
                'contacts.view', 'contacts.view.all',
                'leads.view.all',
                'sales.reports.view.all',
                'invoicing.view', 'invoicing.manage', 'invoicing.payments.manage', 'invoicing.reports.view',
            ],
        ];
    }
}
