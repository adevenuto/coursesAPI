<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetUserRole extends Command
{
    protected $signature = 'user:role {email : The user\'s email} {role : user|editor|admin}';

    protected $description = 'Set a user\'s account role (user, editor, or admin)';

    private const ROLES = ['user', 'editor', 'admin'];

    public function handle(): int
    {
        $role = strtolower((string) $this->argument('role'));

        if (! in_array($role, self::ROLES, true)) {
            $this->error('Role must be one of: '.implode(', ', self::ROLES));

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user found with email '.$this->argument('email'));

            return self::FAILURE;
        }

        $user->forceFill(['role' => $role])->save();

        $this->info("{$user->email} is now '{$role}'".($user->canEditCourses() ? ' (can edit courses)' : ''));

        return self::SUCCESS;
    }
}
