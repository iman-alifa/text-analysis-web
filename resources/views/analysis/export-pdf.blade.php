<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $analysis->title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .muted { color: #6b7280; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #e5e7eb; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; }
        td.num { text-align: right; white-space: nowrap; }
        .summary { background: #f9fafb; border: 1px solid #e5e7eb; padding: 8px; margin-top: 6px; }
    </style>
</head>
<body>
    <h1>{{ $analysis->title }}</h1>
    <div class="muted">
        Jenis analisis: {{ ucfirst($analysis->analysis_type) }} &middot;
        {{ $analysis->total_records }} teks &middot;
        Dibuat {{ $analysis->created_at->format('d M Y H:i') }}
        @if($analysis->duration) &middot; Durasi proses {{ $analysis->duration }} @endif
    </div>

    @if($analysis->description)
        <p>{{ $analysis->description }}</p>
    @endif

    @if($result->summary)
        <h2>Ringkasan</h2>
        <div class="summary">{{ $result->summary }}</div>
    @endif

    {{-- Narasi AI ikut diekspor supaya bisa dikutip di naskah, lengkap dengan
         asal-usulnya. Tanpa keterangan model dan tanggal, kalimat buatan mesin
         tidak bisa dipertanggungjawabkan sebagai kutipan. --}}
    @php
        $judulAi = [
            'overview' => 'Ringkasan Eksekutif (AI)',
            'sentiment' => 'Interpretasi Sentimen (AI)',
            'aspect' => 'Interpretasi Aspek (AI)',
            'association' => 'Interpretasi Asosiasi (AI)',
        ];
    @endphp

    @foreach($judulAi as $bagian => $judul)
        @php $narasi = $result->ai_interpretations[$bagian] ?? null; @endphp
        @if(! empty($narasi['narrative']))
            <h2>{{ $judul }}</h2>
            <div class="summary">
                <p style="margin: 0 0 6px 0;">{{ $narasi['narrative'] }}</p>

                @if(! empty($narasi['highlights']))
                    <ul style="margin: 0 0 6px 16px; padding: 0;">
                        @foreach($narasi['highlights'] as $poin)
                            <li>{{ $poin }}</li>
                        @endforeach
                    </ul>
                @endif

                <p style="margin: 0; font-size: 9px; color: #6b7280;">
                    Ditulis oleh {{ $narasi['model'] ?? 'AI' }}
                    @if(! empty($narasi['generated_at']))
                        pada {{ \Carbon\Carbon::parse($narasi['generated_at'])->translatedFormat('d M Y, H:i') }}
                    @endif
                    &mdash; seluruh angka berasal dari hasil analisis, bukan dari AI.
                </p>
            </div>
        @endif
    @endforeach

    @if($result->sentiment_distribution)
        <h2>Distribusi Sentimen</h2>
        <table>
            <thead><tr><th>Sentimen</th><th>Persentase</th></tr></thead>
            <tbody>
                @foreach($result->sentiment_distribution as $label => $percentage)
                    <tr>
                        <td>{{ ucfirst($label) }}</td>
                        <td class="num">{{ $percentage }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($result->aspect_results)
        <h2>Aspek</h2>
        <table>
            <thead>
                <tr><th>Aspek</th><th>Mentions</th><th>Positif</th><th>Netral</th><th>Negatif</th></tr>
            </thead>
            <tbody>
                @foreach($result->aspect_results as $aspect)
                    <tr>
                        <td>{{ ucfirst($aspect['aspect'] ?? '-') }}</td>
                        <td class="num">{{ $aspect['count'] ?? 0 }}</td>
                        <td class="num">{{ $aspect['sentiments']['positive'] ?? 0 }}%</td>
                        <td class="num">{{ $aspect['sentiments']['neutral'] ?? 0 }}%</td>
                        <td class="num">{{ $aspect['sentiments']['negative'] ?? 0 }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($result->topic_results && !empty($result->topic_results['topics']))
        <h2>Topik</h2>
        <table>
            <thead><tr><th>#</th><th>Label</th><th>Kata kunci</th><th>Dokumen</th></tr></thead>
            <tbody>
                @foreach($result->topic_results['topics'] as $index => $topic)
                    @php $interpretation = $result->topic_results['interpretation'][$topic['topic_id'] ?? $index] ?? null; @endphp
                    <tr>
                        <td class="num">{{ $topic['topic_id'] ?? $index }}</td>
                        <td>{{ $interpretation['label'] ?? ($topic['topic_label'] ?? '-') }}</td>
                        <td>{{ implode(', ', array_slice($topic['words'] ?? $topic['keywords'] ?? [], 0, 10)) }}</td>
                        <td class="num">{{ $topic['size'] ?? $topic['document_count'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($result->association_results && !empty($result->association_results['pmi_top_associations']))
        <h2>Asosiasi Aspek &ndash; Topik (PMI)</h2>
        <table>
            <thead><tr><th>Aspek</th><th>Topik</th><th>PMI</th><th>Ko-okurensi</th></tr></thead>
            <tbody>
                @foreach(array_slice($result->association_results['pmi_top_associations'], 0, 15) as $row)
                    <tr>
                        <td>{{ $row['aspect'] }}</td>
                        <td class="num">{{ $row['topic_id'] }}</td>
                        <td class="num">{{ $row['pmi'] }}</td>
                        <td class="num">{{ $row['co_occurrences'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @php $predictions = $result->predictions ?? []; @endphp
    @if(!empty($predictions))
        <h2>Detail Prediksi</h2>
        <p class="muted">
            Menampilkan {{ min(100, count($predictions)) }} dari {{ count($predictions) }} teks.
            Data lengkap tersedia lewat Export CSV.
        </p>
        <table>
            <thead><tr><th>#</th><th>Teks</th><th>Sentimen</th><th>Conf.</th><th>Aspek</th></tr></thead>
            <tbody>
                @foreach(array_slice($predictions, 0, 100) as $index => $prediction)
                    @php
                        $sentiment = $prediction['sentiment'] ?? null;
                        $sentiment = is_array($sentiment) ? ($sentiment['label'] ?? null) : $sentiment;
                    @endphp
                    <tr>
                        <td class="num">{{ $index + 1 }}</td>
                        <td>{{ Str::limit($prediction['original_text'] ?? $prediction['text'] ?? '', 180) }}</td>
                        <td>{{ $sentiment ? ucfirst($sentiment) : '-' }}</td>
                        <td class="num">{{ isset($prediction['confidence']) ? round($prediction['confidence'] * 100, 1) . '%' : '-' }}</td>
                        <td>{{ implode(', ', (array) ($prediction['aspects'] ?? [])) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
