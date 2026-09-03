{{--
    Daftar prediksi untuk satu halaman.

    Dikembalikan apa adanya oleh endpoint AJAX maupun disisipkan saat render
    pertama, sehingga hasilnya identik dengan atau tanpa JavaScript.

    @param array $items  Baris dari PredictionQueryService::paginate()
    @param array $meta   Informasi paginasi
--}}
@forelse($items as $row)
    @include('analysis.partials.prediction-card', [
        'prediction' => $row['prediction'],
        'position' => $row['position'],
        'index' => $row['index'],
        'reviewRank' => $row['review_rank'],
    ])
@empty
    <div class="py-12 text-center">
        <p class="text-gray-500">Tidak ada prediksi yang cocok dengan penyaringan ini.</p>
        @if($meta['search'] !== '')
        <p class="mt-1 text-sm text-gray-400">Kata kunci: &ldquo;{{ $meta['search'] }}&rdquo;</p>
        @endif
    </div>
@endforelse
