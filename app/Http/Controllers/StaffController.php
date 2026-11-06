<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\AuditRecorder;
use App\Http\Requests\StoreStaffRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(): View
    {
        return view('staff.index', ['users' => User::query()->select('id', 'name', 'email', 'role', 'created_at')->orderBy('name')->orderBy('id')->paginate(20)]);
    }

    public function store(StoreStaffRequest $request, CreateNewUser $createNewUser, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $createNewUser, $audit): void {
            $staff = $createNewUser->create($request->validated());
            $audit->staffCreated($request->user(), $staff);
        });

        return to_route('staff.index')->with('success', 'Staff account created. Share the login details with your staff member.');
    }
}
