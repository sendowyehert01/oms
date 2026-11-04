<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Requests\StoreStaffRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(): View
    {
        return view('staff.index', ['users' => User::query()->select('id', 'name', 'email', 'role', 'created_at')->orderBy('name')->orderBy('id')->paginate(20)]);
    }

    public function store(StoreStaffRequest $request, CreateNewUser $createNewUser): RedirectResponse
    {
        $createNewUser->create($request->validated());

        return to_route('staff.index')->with('success', 'Staff account created. Share the login details with your staff member.');
    }
}
