<x-guest-layout>
    <x-slot name="judul">Lupa kata sandi</x-slot>
    <x-slot name="keterangan">
        Masukkan email Anda, lalu kami kirimkan tautan untuk membuat kata sandi baru.
    </x-slot>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email"
                          :value="old('email')" required autofocus placeholder="nama@contoh.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <button type="submit"
                class="w-full inline-flex justify-center items-center px-4 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition">
            Kirim tautan atur ulang
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-600">
        Ingat kata sandinya?
        <a href="{{ route('login') }}" class="font-semibold text-blue-600 hover:text-blue-700 hover:underline">
            Kembali ke halaman masuk
        </a>
    </p>
</x-guest-layout>
