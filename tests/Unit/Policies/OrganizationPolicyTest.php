<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\Role;
use App\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Tests\TestCaseWithDatabase;

class OrganizationPolicyTest extends TestCaseWithDatabase
{
    public function test_owner_can_create_organizations(): void
    {
        $data = $this->createUserWithRole(Role::Owner);

        $this->assertTrue(Gate::forUser($data->user)->allows('create', Organization::class));
    }

    public function test_admin_can_create_organizations(): void
    {
        $data = $this->createUserWithRole(Role::Admin);

        $this->assertTrue(Gate::forUser($data->user)->allows('create', Organization::class));
    }

    public function test_manager_can_not_create_organizations(): void
    {
        $data = $this->createUserWithRole(Role::Manager);

        $this->assertFalse(Gate::forUser($data->user)->allows('create', Organization::class));
    }

    public function test_employee_can_not_create_organizations(): void
    {
        $data = $this->createUserWithRole(Role::Employee);

        $this->assertFalse(Gate::forUser($data->user)->allows('create', Organization::class));
    }
}
