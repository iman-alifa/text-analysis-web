<x-guest-layout>
    <x-slot name="judul">Masuk ke akun Anda</x-slot>
    <x-slot name="keterangan">Gunakan email dan kata sandi yang Anda daftarkan.</x-slot>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email"
                          :value="old('email')" required autofocus autocomplete="username"
                          placeholder="nama@contoh.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <div class="flex items-center justify-between">
                <x-input-label for="password" value="Kata sandi" />

                @if (Route::has('password.request'))
                    <a class="text-sm text-blue-600 hover:text-blue-700 hover:underline rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                       href="{{ route('password.request') }}">
                        Lupa kata sandi?
                    </a>
                @endif
            </div>

            <x-text-input id="password" class="block mt-1 w-full"
                          type="password" name="password"
                          required autocomplete="current-password"
                          placeholder="Kata sandi Anda" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="flex items-center">
            <input id="remember_me" type="checkbox"
                   class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500" name="remember">
            <span class="ms-2 text-sm text-gray-600">Ingat saya di perangkat ini</span>
        </label>

        <button type="submit"
                class="w-full inline-flex justify-center items-center px-4 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition">
            Masuk
        </button>
    </form>

    @if (Route::has('register'))
        <p class="mt-6 text-center text-sm text-gray-600">
            Belum punya akun?
            <a href="{{ route('register') }}" class="font-semibold text-blue-600 hover:text-blue-700 hover:underline">
                Daftar di sini
            </a>
        </p>
    @endif
</x-guest-layout>
