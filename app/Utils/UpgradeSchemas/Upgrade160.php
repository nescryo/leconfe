<?php

namespace App\Utils\UpgradeSchemas;

use App\Models\Permission;
use Illuminate\Support\Facades\Artisan;

class Upgrade160 extends UpgradeBase
{
    public function run(): void
    {
        Artisan::call('migrate', [
            '--force' => true,
        ]);

        $this->addUserMergePermission();
    }

    /**
     * Without this row, Spatie throws PermissionDoesNotExist and `can('User:merge')`
     * is false for Conference Managers on upgraded installations.
     */
    private function addUserMergePermission(): void
    {
        Permission::firstOrCreate([
            'name' => 'User:merge',
            'guard_name' => 'web',
        ]);
    }
}
