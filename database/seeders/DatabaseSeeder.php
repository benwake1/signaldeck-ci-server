<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'     => 'Admin User',
                'password' => Hash::make('password'),
                'role'     => 'admin',
            ]
        );

        // Create a demo PM user
        User::firstOrCreate(
            ['email' => 'pm@example.com'],
            [
                'name'     => 'Project Manager',
                'password' => Hash::make('password'),
                'role'     => 'user',
            ]
        );

        // Create demo clients
        $clientA = Client::firstOrCreate(
            ['slug' => 'acme-corp'],
            [
                'name'               => 'Acme Corp',
                'primary_colour'     => '#1e40af',
                'secondary_colour'   => '#3b82f6',
                'accent_colour'      => '#f59e0b',
                'contact_name'       => 'Jane Smith',
                'contact_email'      => 'jane@acme.com',
                'report_footer_text' => 'This report is confidential and prepared exclusively for Acme Corp.',
                'active'             => true,
            ]
        );

        $clientB = Client::firstOrCreate(
            ['slug' => 'globex'],
            [
                'name'               => 'Globex Solutions',
                'primary_colour'     => '#065f46',
                'secondary_colour'   => '#10b981',
                'accent_colour'      => '#f59e0b',
                'contact_name'       => 'Bob Johnson',
                'contact_email'      => 'bob@globex.com',
                'report_footer_text' => 'Prepared for Globex Solutions by your QA partner.',
                'active'             => true,
            ]
        );

        // Create demo projects
        $projectA = Project::firstOrCreate(
            ['slug' => 'acme-ecommerce'],
            [
                'client_id'      => $clientA->id,
                'name'           => 'Acme eCommerce',
                'description'    => 'End-to-end tests for the Acme online store.',
                'repo_url'       => 'git@github.com:your-org/your-repo.git',
                'repo_provider'  => 'github',
                'default_branch' => 'main',
                'active'         => true,
            ]
        );

        $projectB = Project::firstOrCreate(
            ['slug' => 'globex-portal'],
            [
                'client_id'      => $clientB->id,
                'name'           => 'Globex Customer Portal',
                'description'    => 'Tests for the Globex client-facing portal.',
                'repo_url'       => 'git@github.com:your-org/globex-tests.git',
                'repo_provider'  => 'github',
                'default_branch' => 'main',
                'active'         => true,
            ]
        );

        // Create demo test suites
        TestSuite::firstOrCreate(
            ['project_id' => $projectA->id, 'slug' => 'smoke-tests'],
            [
                'name'            => 'Smoke Tests',
                'description'     => 'Quick sanity checks across core journeys.',
                'spec_pattern'    => 'cypress/e2e/**/*.cy.js',
                'timeout_minutes' => 15,
                'active'          => true,
            ]
        );

        TestSuite::firstOrCreate(
            ['project_id' => $projectA->id, 'slug' => 'full-regression'],
            [
                'name'            => 'Full Regression',
                'description'     => 'Complete regression suite.',
                'spec_pattern'    => 'cypress/e2e/**/*.cy.{js,ts}',
                'timeout_minutes' => 60,
                'active'          => true,
            ]
        );

        TestSuite::firstOrCreate(
            ['project_id' => $projectB->id, 'slug' => 'portal-smoke'],
            [
                'name'            => 'Portal Smoke',
                'description'     => 'Core portal flows.',
                'spec_pattern'    => 'cypress/e2e/**/*.cy.js',
                'timeout_minutes' => 20,
                'active'          => true,
            ]
        );

        $this->command->info('✅ Seeded successfully.');
        $this->command->info('   Admin: admin@example.com / password');
        $this->command->info('   PM:    pm@example.com / password');
    }
}
