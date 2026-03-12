<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class YouTubeScraperController extends Controller
{
    /**
     * Tampilkan halaman YouTube Scraper
     */
    public function index()
    {
        return view('youtube.scraper');
    }

    /**
     * Search YouTube videos menggunakan YouTube Data API
     */
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:255',
            'maxResults' => 'nullable|integer|min:1|max:50',
            'order' => 'nullable|string|in:date,rating,relevance,title,viewCount',
            'videoDuration' => 'nullable|string|in:short,medium,long',
            'publishedAfter' => 'nullable|date',
        ]);

        $apiKey = env('YOUTUBE_API_KEY');
        
        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube API Key belum dikonfigurasi. Silakan tambahkan YOUTUBE_API_KEY di file .env'
            ], 500);
        }

        try {
            // Build API URL
            $params = [
                'part' => 'snippet',
                'q' => $request->input('query'),
                'type' => 'video',
                'maxResults' => $request->maxResults ?? 12,
                'order' => $request->order ?? 'relevance',
                'key' => $apiKey,
            ];

            // Optional filters
            if ($request->videoDuration) {
                $params['videoDuration'] = $request->videoDuration;
            }

            if ($request->publishedAfter) {
                $params['publishedAfter'] = date('c', strtotime($request->publishedAfter));
            }

            if ($request->pageToken) {
                $params['pageToken'] = $request->pageToken;
            }

            // Call YouTube API
            $response = Http::timeout(60)->get(
                'https://www.googleapis.com/youtube/v3/search',
                $params
            );

            if ($response->failed()) {
                Log::error('YouTube API Request Failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new \Exception('YouTube API request failed: ' . $response->status());
            }

            $data = $response->json();

            // Log response untuk debugging
            Log::info('YouTube Search Response', [
                'total_results' => $data['pageInfo']['totalResults'] ?? 0,
                'items_count' => count($data['items'] ?? [])
            ]);

            // Validasi response
            if (!isset($data['items']) || empty($data['items'])) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'nextPageToken' => null,
                    'totalResults' => 0,
                    'message' => 'Tidak ada hasil ditemukan'
                ]);
            }

            // Get video IDs dengan validasi
            $videoIds = collect($data['items'])
                ->filter(function($item) {
                    // Pastikan ini video dan ada videoId
                    return isset($item['id']['videoId']) && !empty($item['id']['videoId']);
                })
                ->pluck('id.videoId')
                ->unique()
                ->values()
                ->implode(',');

            // Jika tidak ada video ID yang valid
            if (empty($videoIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'nextPageToken' => null,
                    'totalResults' => 0,
                    'message' => 'Tidak ada video ditemukan dalam hasil pencarian'
                ]);
            }

            // Get video details (duration, view count, etc)
            $detailsResponse = Http::timeout(60)->get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'contentDetails,statistics,snippet',
                'id' => $videoIds,
                'key' => $apiKey,
            ]);

            if ($detailsResponse->failed()) {
                Log::warning('Failed to get video details', [
                    'status' => $detailsResponse->status()
                ]);
            }

            $videoDetails = $detailsResponse->successful() ? $detailsResponse->json() : ['items' => []];

            // Combine search results with details
            $videos = [];
            foreach ($data['items'] as $item) {
                // Skip jika bukan video atau tidak ada videoId
                if (!isset($item['id']['videoId']) || empty($item['id']['videoId'])) {
                    Log::debug('Skipping non-video item', [
                        'kind' => $item['id']['kind'] ?? 'unknown'
                    ]);
                    continue;
                }

                $videoId = $item['id']['videoId'];
                
                // Find matching details
                $details = collect($videoDetails['items'] ?? [])->firstWhere('id', $videoId);

                $videos[] = [
                    'id' => $videoId,
                    'title' => $item['snippet']['title'] ?? 'No Title',
                    'description' => $item['snippet']['description'] ?? '',
                    'channel' => $item['snippet']['channelTitle'] ?? 'Unknown',
                    'channelId' => $item['snippet']['channelId'] ?? '',
                    'thumbnail' => $item['snippet']['thumbnails']['medium']['url'] ?? 
                                  $item['snippet']['thumbnails']['default']['url'] ?? 
                                  'https://via.placeholder.com/320x180?text=No+Thumbnail',
                    'publishedAt' => $item['snippet']['publishedAt'] ?? '',
                    'duration' => isset($details['contentDetails']['duration']) 
                                  ? $this->formatDuration($details['contentDetails']['duration']) 
                                  : '0:00',
                    'viewCount' => isset($details['statistics']['viewCount']) 
                                   ? $this->formatNumber($details['statistics']['viewCount']) 
                                   : '0',
                    'likeCount' => isset($details['statistics']['likeCount']) 
                                   ? $this->formatNumber($details['statistics']['likeCount']) 
                                   : '0',
                    'commentCount' => $details['statistics']['commentCount'] ?? 0,
                    'url' => 'https://www.youtube.com/watch?v=' . $videoId,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $videos,
                'nextPageToken' => $data['nextPageToken'] ?? null,
                'totalResults' => $data['pageInfo']['totalResults'] ?? 0,
            ]);

        } catch (\Exception $e) {
            Log::error('YouTube API Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data dari YouTube: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Scrape comments dari video YouTube
     */
    public function scrapeComments(Request $request)
    {
        $request->validate([
            'video_ids' => 'required|array',
            'video_ids.*' => 'required|string',
            'comment_limit' => 'required|string',
            'custom_limit' => 'nullable|integer|min:1',
            'include_replies' => 'nullable|boolean',
            'output_format' => 'required|string|in:csv,xlsx,txt,json',
        ]);

        $apiKey = env('YOUTUBE_API_KEY');
        
        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube API Key belum dikonfigurasi'
            ], 500);
        }

        try {
            $allComments = [];
            $totalVideos = count($request->video_ids);
            $processedVideos = 0;
            $failedVideos = [];

            foreach ($request->video_ids as $videoId) {
                try {
                    $comments = $this->getVideoComments(
                        $videoId, 
                        $request->comment_limit,
                        $request->custom_limit,
                        $request->include_replies ?? false,
                        $apiKey
                    );

                    $allComments = array_merge($allComments, $comments);
                    $processedVideos++;

                    Log::info("Processed video {$videoId}: " . count($comments) . " comments");

                } catch (\Exception $e) {
                    $failedVideos[] = $videoId;
                    Log::error("Failed to get comments for video {$videoId}: " . $e->getMessage());
                }
            }

            if (empty($allComments)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada komentar yang berhasil diambil. Kemungkinan komentar dinonaktifkan pada video tersebut.'
                ], 404);
            }

            // Save or export comments
            $filename = $this->exportComments($allComments, $request->output_format);

            $message = 'Berhasil mengambil ' . count($allComments) . ' komentar dari ' . $processedVideos . ' video';
            if (!empty($failedVideos)) {
                $message .= '. Gagal mengambil komentar dari ' . count($failedVideos) . ' video.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'total_comments' => count($allComments),
                    'total_videos' => $totalVideos,
                    'processed_videos' => $processedVideos,
                    'failed_videos' => count($failedVideos),
                    'filename' => $filename,
                    'download_url' => route('youtube.download', ['filename' => $filename]),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Scraping Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil komentar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get comments dari single video
     */
    private function getVideoComments($videoId, $limitType, $customLimit, $includeReplies, $apiKey)
    {
        $comments = [];
        $pageToken = null;
        
        // Tentukan limit
        $maxComments = match($limitType) {
            '100' => 100,
            '500' => 500,
            'all' => PHP_INT_MAX,
            'custom' => $customLimit ?? 100,
            default => 100,
        };

        $retries = 0;
        $maxRetries = 3;

        do {
            try {
                $params = [
                    'part' => 'snippet',
                    'videoId' => $videoId,
                    'maxResults' => min(100, $maxComments - count($comments)), // API max 100 per request
                    'order' => 'relevance',
                    'textFormat' => 'plainText',
                    'key' => $apiKey,
                ];

                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $response = Http::timeout(30)->get('https://www.googleapis.com/youtube/v3/commentThreads', $params);

                if ($response->failed()) {
                    $errorData = $response->json();
                    $errorMessage = $errorData['error']['message'] ?? 'Unknown error';
                    
                    // Jika komentar disabled, throw exception
                    if (str_contains($errorMessage, 'disabled')) {
                        throw new \Exception('Komentar dinonaktifkan untuk video ini');
                    }
                    
                    throw new \Exception('API Error: ' . $errorMessage);
                }

                $data = $response->json();

                if (!isset($data['items'])) {
                    break;
                }

                foreach ($data['items'] ?? [] as $item) {
                    if (!isset($item['snippet']['topLevelComment']['snippet'])) {
                        continue;
                    }

                    $topComment = $item['snippet']['topLevelComment']['snippet'];
                    
                    $comments[] = [
                        'video_id' => $videoId,
                        'comment_id' => $item['id'] ?? '',
                        'author' => $topComment['authorDisplayName'] ?? 'Unknown',
                        'author_channel_url' => $topComment['authorChannelUrl'] ?? '',
                        'text' => $topComment['textDisplay'] ?? '',
                        'like_count' => $topComment['likeCount'] ?? 0,
                        'published_at' => $topComment['publishedAt'] ?? '',
                        'updated_at' => $topComment['updatedAt'] ?? '',
                        'reply_count' => $item['snippet']['totalReplyCount'] ?? 0,
                    ];

                    // Get replies if needed
                    if ($includeReplies && ($item['snippet']['totalReplyCount'] ?? 0) > 0) {
                        $replies = $this->getCommentReplies($item['id'], $apiKey);
                        $comments = array_merge($comments, $replies);
                    }
                }

                $pageToken = $data['nextPageToken'] ?? null;
                $retries = 0; // Reset retries on success

            } catch (\Exception $e) {
                $retries++;
                
                if ($retries >= $maxRetries) {
                    throw $e;
                }
                
                // Wait before retry
                sleep(2);
                Log::warning("Retry {$retries}/{$maxRetries} for video {$videoId}");
            }

        } while ($pageToken && count($comments) < $maxComments);

        return array_slice($comments, 0, $maxComments);
    }

    /**
     * Get replies untuk comment tertentu
     */
    private function getCommentReplies($commentId, $apiKey)
    {
        $replies = [];
        
        try {
            $response = Http::timeout(30)->get('https://www.googleapis.com/youtube/v3/comments', [
                'part' => 'snippet',
                'parentId' => $commentId,
                'maxResults' => 100,
                'textFormat' => 'plainText',
                'key' => $apiKey,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                foreach ($data['items'] ?? [] as $item) {
                    $snippet = $item['snippet'] ?? [];
                    
                    $replies[] = [
                        'video_id' => $snippet['videoId'] ?? '',
                        'comment_id' => $item['id'] ?? '',
                        'parent_id' => $commentId,
                        'author' => $snippet['authorDisplayName'] ?? 'Unknown',
                        'author_channel_url' => $snippet['authorChannelUrl'] ?? '',
                        'text' => $snippet['textDisplay'] ?? '',
                        'like_count' => $snippet['likeCount'] ?? 0,
                        'published_at' => $snippet['publishedAt'] ?? '',
                        'updated_at' => $snippet['updatedAt'] ?? '',
                        'reply_count' => 0,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to get replies for comment {$commentId}: " . $e->getMessage());
        }

        return $replies;
    }

    /**
     * Export comments to file
     */
    private function exportComments($comments, $format)
    {
        $filename = 'youtube_comments_' . date('YmdHis') . '.' . $format;
        $path = storage_path('app/public/exports/' . $filename);

        // Pastikan direktori exists
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        switch ($format) {
            case 'csv':
                $this->exportToCsv($comments, $path);
                break;
            case 'xlsx':
                $this->exportToExcel($comments, $path);
                break;
            case 'txt':
                $this->exportToTxt($comments, $path);
                break;
            case 'json':
                file_put_contents($path, json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                break;
        }

        return $filename;
    }

    private function exportToCsv($comments, $path)
    {
        $file = fopen($path, 'w');
        
        // Add BOM untuk support UTF-8 di Excel
        fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Header
        fputcsv($file, [
            'No', 
            'Video ID', 
            'Comment ID',
            'Author', 
            'Comment', 
            'Likes', 
            'Published At', 
            'Updated At',
            'Reply Count'
        ]);
        
        // Data
        foreach ($comments as $index => $comment) {
            fputcsv($file, [
                $index + 1,
                $comment['video_id'] ?? '',
                $comment['comment_id'] ?? '',
                $comment['author'] ?? '',
                $comment['text'] ?? '',
                $comment['like_count'] ?? 0,
                $comment['published_at'] ?? '',
                $comment['updated_at'] ?? '',
                $comment['reply_count'] ?? 0,
            ]);
        }
        
        fclose($file);
    }

    private function exportToExcel($comments, $path)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('YouTube Comments');

        // Header
        $headers = [
            'No',
            'Video ID',
            'Comment ID',
            'Author',
            'Comment',
            'Likes',
            'Published At',
            'Updated At',
            'Reply Count'
        ];

        $sheet->fromArray($headers, null, 'A1');

        // Styling header
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4F46E5'], // Indigo
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Set row height untuk header
        $sheet->getRowDimension(1)->setRowHeight(25);

        // Data
        $row = 2;
        foreach ($comments as $index => $comment) {
            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValue("B{$row}", $comment['video_id'] ?? '');
            $sheet->setCellValue("C{$row}", $comment['comment_id'] ?? '');
            $sheet->setCellValue("D{$row}", $comment['author'] ?? '');
            $sheet->setCellValue("E{$row}", $comment['text'] ?? '');
            $sheet->setCellValue("F{$row}", $comment['like_count'] ?? 0);
            $sheet->setCellValue("G{$row}", $comment['published_at'] ?? '');
            $sheet->setCellValue("H{$row}", $comment['updated_at'] ?? '');
            $sheet->setCellValue("I{$row}", $comment['reply_count'] ?? 0);
            $row++;
        }

        // Auto size kolom
        foreach (range('A', 'I') as $col) {
            if ($col === 'E') {
                // Kolom comment diberi width fixed yang lebih lebar
                $sheet->getColumnDimension($col)->setWidth(60);
            } else {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        // Wrap text untuk kolom komentar
        $sheet->getStyle("E2:E{$row}")
            ->getAlignment()
            ->setWrapText(true);

        // Center alignment untuk kolom angka
        $sheet->getStyle("A2:A{$row}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->getStyle("F2:F{$row}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->getStyle("I2:I{$row}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Freeze pane pada header
        $sheet->freezePane('A2');

        // Save file
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
    }

    private function exportToTxt($comments, $path)
    {
        $content = "==============================================\n";
        $content .= "       YouTube Comments Export\n";
        $content .= "==============================================\n";
        $content .= "Total: " . count($comments) . " comments\n";
        $content .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $content .= "==============================================\n\n";

        foreach ($comments as $index => $comment) {
            $content .= "Comment #" . ($index + 1) . "\n";
            $content .= str_repeat("-", 80) . "\n";
            $content .= "Video ID    : " . ($comment['video_id'] ?? '') . "\n";
            $content .= "Author      : " . ($comment['author'] ?? '') . "\n";
            $content .= "Likes       : " . ($comment['like_count'] ?? 0) . "\n";
            $content .= "Published   : " . ($comment['published_at'] ?? '') . "\n";
            $content .= "Replies     : " . ($comment['reply_count'] ?? 0) . "\n";
            $content .= "\nComment:\n";
            $content .= ($comment['text'] ?? '') . "\n";
            $content .= "\n" . str_repeat("=", 80) . "\n\n";
        }

        file_put_contents($path, $content);
    }

    /**
     * Download file hasil scraping
     */
    public function download($filename)
    {
        $path = storage_path('app/public/exports/' . $filename);

        if (!file_exists($path)) {
            abort(404, 'File tidak ditemukan');
        }

        return response()->download($path)->deleteFileAfterSend(true);
    }

    /**
     * Helper: Format duration dari ISO 8601
     */
    private function formatDuration($duration)
    {
        try {
            preg_match('/PT(\d+H)?(\d+M)?(\d+S)?/', $duration, $matches);
            
            $hours = isset($matches[1]) ? rtrim($matches[1], 'H') : 0;
            $minutes = isset($matches[2]) ? rtrim($matches[2], 'M') : 0;
            $seconds = isset($matches[3]) ? rtrim($matches[3], 'S') : 0;

            if ($hours > 0) {
                return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
            }
            return sprintf('%d:%02d', $minutes, $seconds);
        } catch (\Exception $e) {
            return '0:00';
        }
    }

    /**
     * Helper: Format angka
     */
    private function formatNumber($number)
    {
        $number = intval($number);
        
        if ($number >= 1000000000) {
            return round($number / 1000000000, 1) . 'B';
        }
        if ($number >= 1000000) {
            return round($number / 1000000, 1) . 'M';
        }
        if ($number >= 1000) {
            return round($number / 1000, 1) . 'K';
        }
        return $number;
    }

    /**
     * Get video info by ID
     */
    public function getVideoInfo(Request $request)
    {
        $request->validate([
            'video_id' => 'required|string',
        ]);

        $apiKey = env('YOUTUBE_API_KEY');
        
        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube API Key belum dikonfigurasi'
            ], 500);
        }

        try {
            $response = Http::timeout(30)->get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'snippet,contentDetails,statistics',
                'id' => $request->video_id,
                'key' => $apiKey,
            ]);

            if ($response->failed()) {
                throw new \Exception('Failed to get video info');
            }

            $data = $response->json();

            if (empty($data['items'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video tidak ditemukan'
                ], 404);
            }

            $video = $data['items'][0];

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $video['id'],
                    'title' => $video['snippet']['title'] ?? '',
                    'description' => $video['snippet']['description'] ?? '',
                    'channel' => $video['snippet']['channelTitle'] ?? '',
                    'thumbnail' => $video['snippet']['thumbnails']['high']['url'] ?? '',
                    'duration' => $this->formatDuration($video['contentDetails']['duration'] ?? 'PT0S'),
                    'viewCount' => $video['statistics']['viewCount'] ?? 0,
                    'likeCount' => $video['statistics']['likeCount'] ?? 0,
                    'commentCount' => $video['statistics']['commentCount'] ?? 0,
                    'publishedAt' => $video['snippet']['publishedAt'] ?? '',
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get Video Info Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil informasi video: ' . $e->getMessage()
            ], 500);
        }
    }
}