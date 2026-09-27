<x-guest-layout>
    <x-slot name="judul">Buat kata sandi baru</x-slot>
    <x-slot name="keterangan">Kata sandi lama Anda akan langsung diganti.</x-slot>

    <form method="POST" action="{{ route('password.store') }}" class="space-y-5">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email"
                          :value="old('email', $request->email)" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" value="Kata sandi baru" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password"
                          required autocomplete="new-password" />
            <p class="mt-1 text-xs text-gray-500">Minimal 8 karakter.</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" value="Ulangi kata sandi baru" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                          type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <button type="submit"
                class="w-full inline-flex justify-center items-center px-4 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition">
            Simpan kata sandi baru
        </button>
    </form>
</x-guest-layout>
