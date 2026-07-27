<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Bootstrap path for the first account, since there is no self-registration.
 * After this, accounts are created through the users screen.
 */
final class CreateUserCommand extends Command
{
    protected $signature = 'mwdeploy:create-user
                            {email : Email address to sign in with}
                            {--name= : Display name (defaults to the email local part)}
                            {--role=* : Role names to grant, e.g. --role=admin}
                            {--password= : Password; prompted for if omitted}';

    protected $description = 'Create a deploy portal account and grant it roles';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = (string) ($this->option('name') ?: strstr($email, '@', true) ?: $email);
        $password = (string) ($this->option('password') ?: $this->secret('Password (min 12 characters)'));

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'name' => ['required', 'string', 'max:150'],
                'password' => ['required', 'string', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        /** @var list<string> $roleNames */
        $roleNames = array_values(array_filter((array) $this->option('role')));

        $roles = Role::query()->whereIn('name', $roleNames)->get();

        $missing = array_diff($roleNames, $roles->pluck('name')->all());

        if ($missing !== []) {
            $this->components->error('Unknown role(s): '.implode(', ', $missing)
                .'. Run `php artisan db:seed --class=RolesAndPermissionsSeeder` first.');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $user->roles()->sync($roles->modelKeys());

        $this->components->info('Created '.$user->email.' with role(s): '
            .($roles->isEmpty() ? '(none)' : $roles->pluck('name')->implode(', ')));

        if ($user->requiresTwoFactor()) {
            $this->components->warn(
                'This account can change production, so it must enrol TOTP at /two-factor/setup before deploying.'
            );
        }

        return self::SUCCESS;
    }
}
