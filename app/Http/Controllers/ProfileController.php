<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\PreprocessingConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'preprocessingConfigs' => PreprocessingConfig::orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Simpan preferensi analisis.
     *
     * Terpisah dari `update()` karena keduanya menjawab hal yang berbeda: yang
     * satu data akun, yang ini perilaku aplikasi. Menggabungkannya akan membuat
     * satu formulir gagal menimpa isi formulir lain yang tidak ikut dikirim.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preprocessing_config_id' => 'nullable|exists:preprocessing_configs,id',
        ]);

        $request->user()->forceFill([
            // String kosong dari <select> disimpan sebagai null, bukan 0:
            // artinya "ikuti bawaan sistem", dan itulah yang dibaca resolver.
            'preprocessing_config_id' => $validated['preprocessing_config_id'] ?: null,
        ])->save();

        return Redirect::route('profile.edit')->with('status', 'preferences-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
