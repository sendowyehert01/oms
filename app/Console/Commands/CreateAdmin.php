<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\UserRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

#[Signature('oms:create-admin {--from-env : Read the first admin details from OMS_ADMIN_* environment settings}')]
#[Description('Create the first OMS admin without public registration')]
class CreateAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CreateNewUser $createNewUser): int
    {
        if (User::query()->where('role', UserRole::Admin)->exists()) {
            $this->info('An admin account already exists. No account was changed.');

            return self::SUCCESS;
        }

        if ($this->option('from-env')) {
            $input = config('auth.bootstrap_admin');
            $input['password_confirmation'] = $input['password'];
        } elseif ($this->input->isInteractive()) {
            $input = [
                'name' => $this->ask('Admin name'),
                'email' => $this->ask('Admin email'),
                'password' => $this->secret('Admin password (12 to 72 characters)'),
                'password_confirmation' => $this->secret('Confirm admin password'),
            ];
        } else {
            $this->error('Run interactively or provide OMS_ADMIN_NAME, OMS_ADMIN_EMAIL, and OMS_ADMIN_PASSWORD with --from-env.');

            return self::FAILURE;
        }

        try {
            $createNewUser->create($input, UserRole::Admin);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        } catch (UniqueConstraintViolationException $exception) {
            if (! User::query()->where('role', UserRole::Admin)->exists()) {
                throw $exception;
            }

            $this->info('An admin account already exists. No account was changed.');

            return self::SUCCESS;
        }

        $this->info('The first admin account was created. Sign in at the OMS base URL.');

        return self::SUCCESS;
    }
}
