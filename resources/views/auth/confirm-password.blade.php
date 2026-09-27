<x-guest-layout>
    <x-slot name="judul">Konfirmasi kata sandi</x-slot>
    <x-slot name="keterangan">
        Bagian ini dilindungi. Masukkan kata sandi Anda sekali lagi untuk melanjutkan.
    </x-slot>

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="password" value="Kata sandi" />

            <x-text-input id="password" class="block mt-1 w-full"
                          type="password" name="password"
                          required autocomplete="current-password" autofocus />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <button type="submit"
                class="w-full inline-flex justify-center items-center px-4 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition">
            Konfirmasi
        </button>
    </form>
</x-guest-layout>
