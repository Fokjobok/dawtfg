<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

class SetUserRole extends Command
{
    protected $signature = 'users:set-role {email} {role}';

    protected $description = 'Assign a global role to an existing user from the server console';

    public function handle(): int
    {
        $role = UserRole::tryFrom($this->argument('role'));
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();

        if (! $role || ! $user) {
            $this->error('Provide an existing email and one of Administrator, Coach or Student.');

            return self::FAILURE;
        }

        $user->role = $role;
        $user->save();
        $this->info('User role updated.');

        return self::SUCCESS;
    }
}
