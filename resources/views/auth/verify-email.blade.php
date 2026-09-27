<x-guest-layout>
    <x-slot name="judul">Verifikasi email Anda</x-slot>
    <x-slot name="keterangan">
        Satu langkah lagi sebelum akun bisa dipakai.
    </x-slot>

    <p class="text-sm text-gray-600 leading-relaxed">
        Kami sudah mengirim tautan verifikasi ke alamat email yang Anda daftarkan.
        Buka email itu dan klik tautannya. Bila belum masuk, periksa folder spam,
        atau minta kami mengirimkannya sekali lagi.
    </p>

    @if (session('status') == 'verification-link-sent')
        <p class="mt-4 text-sm font-medium text-green-700 bg-green-50 border border-green-200 rounded-lg px-3 py-2">
            Tautan verifikasi baru sudah dikirim ke email Anda.
        </p>
    @endif

    <div class="mt-6 space-y-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit"
                    class="w-full inline-flex justify-center items-center px-4 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition">
                Kirim ulang email verifikasi
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                    class="w-full inline-flex justify-center items-center px-4 py-2.5 rounded-lg bg-white border border-gray-300 text-gray-700 text-sm font-semibold hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 transition">
                Keluar
            </button>
        </form>
    </div>
</x-guest-layout>
