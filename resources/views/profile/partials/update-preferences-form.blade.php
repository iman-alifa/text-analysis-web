{{--
    Preferensi analisis.

    Satu-satunya pengaturan di halaman ini yang mengubah perilaku aplikasi,
    bukan data akun. Diletakkan paling atas karena itulah yang dicari orang
    ketika membuka menu "Pengaturan".
--}}
<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">Preferensi Analisis</h2>

        <p class="mt-1 text-sm text-gray-600">
            Pilihan yang sudah terisi setiap kali Anda membuat analisis baru. Tetap bisa diubah
            per analisis saat mengisi formulir.
        </p>
    </header>

    <form method="post" action="{{ route('profile.preferences') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="preprocessing_config_id" :value="'Konfigurasi preprocessing bawaan'" />

            <select id="preprocessing_config_id"
                    name="preprocessing_config_id"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                <option value="">Ikuti bawaan sistem</option>
                @foreach($preprocessingConfigs as $config)
                    <option value="{{ $config->id }}"
                            @selected(old('preprocessing_config_id', $user->preprocessing_config_id) == $config->id)>
                        {{ $config->name }}@if($config->is_default) (bawaan sistem)@endif
                    </option>
                @endforeach
            </select>

            <p class="mt-2 text-sm text-gray-500">
                Preprocessing adalah pembersihan teks sebelum dianalisis: case folding, pembuangan tanda
                baca, stopword removal, dan stemming. Pilihan yang
                berbeda menghasilkan angka yang berbeda, jadi gunakan konfigurasi yang sama untuk
                analisis yang akan dibandingkan satu sama lain.
            </p>

            <x-input-error class="mt-2" :messages="$errors->get('preprocessing_config_id')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>Simpan</x-primary-button>

            @if (session('status') === 'preferences-updated')
                <p x-data="{ show: true }"
                   x-show="show"
                   x-transition
                   x-init="setTimeout(() => show = false, 2000)"
                   class="text-sm text-gray-600">Tersimpan.</p>
            @endif
        </div>
    </form>
</section>
