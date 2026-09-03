<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
     * Check API key status & remaining quota
     */
    public function checkApiStatus()
    {
        $apiKey = config('services.youtube.key');

        if (! $apiKey) {
            return response()->json([
                'status' => 'no_key',
                'message' => 'YouTube API Key belum dikonfigurasi',
                'fallback_available' => $this->isYtDlpAvailable(),
            ]);
        }

        try {
            // Make a cheap API call to test the key
            $response = Http::timeout(10)->get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'id',
                'id' => 'dQw4w9WgXcQ', // A known video ID for testing
                'key' => $apiKey,
            ]);

            if ($response->successful()) {
                return response()->json([
                    'status' => 'active',
                    'message' => 'API Key aktif',
                    'fallback_available' => $this->isYtDlpAvailable(),
                ]);
            }

            $errorData = $response->json();
            $errorReason = $errorData['error']['errors'][0]['reason'] ?? 'unknown';

            if ($errorReason === 'quotaExceeded' || $errorReason === 'dailyLimitExceeded' || $errorReason === 'rateLimitExceeded') {
                return response()->json([
                    'status' => 'quota_exceeded',
                    'message' => 'Kuota API habis. Menggunakan mode fallback jika tersedia.',
                    'fallback_available' => $this->isYtDlpAvailable(),
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'API Key error: '.($errorData['error']['message'] ?? 'Unknown error'),
                'fallback_available' => $this->isYtDlpAvailable(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memeriksa status API: '.$e->getMessage(),
                'fallback_available' => $this->isYtDlpAvailable(),
            ]);
        }
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
            'publishedAfter' => 'nullable|string',
            'pageToken' => 'nullable|string',
        ]);

        $apiKey = config('services.youtube.key');

        if (! $apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'YouTube API Key belum dikonfigurasi. Silakan tambahkan YOUTUBE_API_KEY di file .env',
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

            // Handle upload date presets
            if ($request->publishedAfter) {
                $publishedAfter = $this->resolvePublishedAfter($request->publishedAfter);
                if ($publishedAfter) {
                    $params['publishedAfter'] = $publishedAfter;
                }
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
                $errorData = $response->json();
                $errorReason = $errorData['error']['errors'][0]['reason'] ?? 'unknown';

                // Detect quota exceeded
                if ($errorReason === 'quotaExceeded' || $errorReason === 'dailyLimitExceeded') {
                    return response()->json([
                        'success' => false,
                        'quota_exceeded' => true,
                        'message' => 'Kuota YouTube API habis untuk hari ini. Gunakan tab "Link Langsung" untuk tetap mengambil komentar.',
                    ], 429);
                }

                Log::error('YouTube API Request Failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('YouTube API request failed: '.$response->status());
            }

            $data = $response->json();

            // Log response untuk debugging
            Log::info('YouTube Search Response', [
                'total_results' => $data['pageInfo']['totalResults'] ?? 0,
                'items_count' => count($data['items'] ?? []),
            ]);

            // Validasi response
            if (! isset($data['items']) || empty($data['items'])) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'nextPageToken' => null,
                    'totalResults' => 0,
                    'message' => 'Tidak ada hasil ditemukan',
                ]);
            }

            // Get video IDs dengan validasi
            $videoIds = collect($data['items'])
                ->filter(function ($item) {
                    // Pastikan ini video dan ada videoId
                    return isset($item['id']['videoId']) && ! empty($item['id']['videoId']);
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
                    'message' => 'Tidak ada video ditemukan dalam hasil pencarian',
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
                    'status' => $detailsResponse->status(),
                ]);
            }

            $videoDetails = $detailsResponse->successful() ? $detailsResponse->json() : ['items' => []];

            // Combine search results with details
            $videos = [];
            foreach ($data['items'] as $item) {
                // Skip jika bukan video atau tidak ada videoId
                if (! isset($item['id']['videoId']) || empty($item['id']['videoId'])) {
                    Log::debug('Skipping non-video item', [
                        'kind' => $item['id']['kind'] ?? 'unknown',
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
                    'thumbnailHigh' => $item['snippet']['thumbnails']['high']['url'] ??
                                      $item['snippet']['thumbnails']['medium']['url'] ?? '',
                    'publishedAt' => $item['snippet']['publishedAt'] ?? '',
                    'duration' => isset($details['contentDetails']['duration'])
                                  ? $this->formatDuration($details['contentDetails']['duration'])
                                  : '0:00',
                    'viewCount' => isset($details['statistics']['viewCount'])
                                   ? $this->formatNumber($details['statistics']['viewCount'])
                                   : '0',
                    'viewCountRaw' => $details['statistics']['viewCount'] ?? 0,
                    'likeCount' => isset($details['statistics']['likeCount'])
                                   ? $this->formatNumber($details['statistics']['likeCount'])
                                   : '0',
                    'commentCount' => $details['statistics']['commentCount'] ?? 0,
                    'url' => 'https://www.youtube.com/watch?v='.$videoId,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $videos,
                'nextPageToken' => $data['nextPageToken'] ?? null,
                'prevPageToken' => $data['prevPageToken'] ?? null,
                'totalResults' => $data['pageInfo']['totalResults'] ?? 0,
            ]);

        } catch (\Exception $e) {
            Log::error('YouTube API Error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data dari YouTube: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve publishedAfter preset to ISO 8601 date
     */
    private function resolvePublishedAfter($value)
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        switch ($value) {
            case 'last_hour':
                $now->modify('-1 hour');

                return $now->format('c');
            case 'today':
                $now->setTime(0, 0, 0);

                return $now->format('c');
            case 'this_week':
                $now->modify('-7 days');

                return $now->format('c');
            case 'this_month':
                $now->modify('-30 days');

                return $now->format('c');
            case 'this_year':
                $now->modify('-365 days');

                return $now->format('c');
            default:
                // Try parsing as a date string
                try {
                    $date = new \DateTime($value);

                    return $date->format('c');
                } catch (\Exception $e) {
                    return null;
                }
        }
    }

    /**
     * Get video info by URL or ID - supports direct link input
     */
    public function getVideoInfo(Request $request)
    {
        $request->validate([
            'video_id' => 'nullable|string',
            'url' => 'nullable|string',
        ]);

        $videoId = $request->video_id;

        // If URL is provided, extract video ID
        if (! $videoId && $request->url) {
            $videoId = $this->extractVideoId($request->url);
            if (! $videoId) {
                return response()->json([
                    'success' => false,
                    'message' => 'URL YouTube tidak valid. Format yang didukung: youtube.com/watch?v=xxx, youtu.be/xxx, youtube.com/shorts/xxx',
                ], 400);
            }
        }

        if (! $videoId) {
            return response()->json([
                'success' => false,
                'message' => 'Video ID atau URL diperlukan',
            ], 400);
        }

        $apiKey = config('services.youtube.key');

        // Try API first
        if ($apiKey) {
            try {
                $response = Http::timeout(30)->get('https://www.googleapis.com/youtube/v3/videos', [
                    'part' => 'snippet,contentDetails,statistics',
                    'id' => $videoId,
                    'key' => $apiKey,
                ]);

                if ($response->successful()) {
                    $data = $response->json();

                    if (empty($data['items'])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Video tidak ditemukan',
                        ], 404);
                    }

                    $video = $data['items'][0];

                    return response()->json([
                        'success' => true,
                        'source' => 'api',
                        'data' => [
                            'id' => $video['id'],
                            'title' => $video['snippet']['title'] ?? '',
                            'description' => $video['snippet']['description'] ?? '',
                            'channel' => $video['snippet']['channelTitle'] ?? '',
                            'channelId' => $video['snippet']['channelId'] ?? '',
                            'thumbnail' => $video['snippet']['thumbnails']['high']['url'] ??
                                          $video['snippet']['thumbnails']['medium']['url'] ?? '',
                            'duration' => $this->formatDuration($video['contentDetails']['duration'] ?? 'PT0S'),
                            'viewCount' => $this->formatNumber($video['statistics']['viewCount'] ?? 0),
                            'viewCountRaw' => $video['statistics']['viewCount'] ?? 0,
                            'likeCount' => $this->formatNumber($video['statistics']['likeCount'] ?? 0),
                            'commentCount' => $video['statistics']['commentCount'] ?? 0,
                            'publishedAt' => $video['snippet']['publishedAt'] ?? '',
                            'url' => 'https://www.youtube.com/watch?v='.$video['id'],
                        ],
                    ]);
                }

                // Check if quota exceeded — fall through to yt-dlp
                $errorData = $response->json();
                $errorReason = $errorData['error']['errors'][0]['reason'] ?? '';
                if ($errorReason !== 'quotaExceeded' && $errorReason !== 'dailyLimitExceeded') {
                    throw new \Exception($errorData['error']['message'] ?? 'API Error');
                }

                Log::warning('YouTube API quota exceeded, trying yt-dlp fallback for video info');
            } catch (\Exception $e) {
                Log::warning('YouTube API failed for video info, trying fallback: '.$e->getMessage());
            }
        }

        // Fallback: use yt-dlp to get video info
        if ($this->isYtDlpAvailable()) {
            try {
                $info = $this->getVideoInfoViaYtDlp($videoId);
                if ($info) {
                    return response()->json([
                        'success' => true,
                        'source' => 'ytdlp',
                        'data' => $info,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('yt-dlp video info failed: '.$e->getMessage());
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil informasi video. API key tidak tersedia atau kuota habis, dan fallback yt-dlp tidak tersedia.',
        ], 500);
    }

    /**
     * Scrape comments dari video YouTube — via URL langsung
     */
    public function scrapeByUrl(Request $request)
    {
        $request->validate([
            'urls' => 'required|array|min:1',
            'urls.*' => 'required|string',
            'comment_limit' => 'required|string',
            'custom_limit' => 'nullable|integer|min:1',
            'include_replies' => 'nullable|boolean',
            'output_format' => 'required|string|in:csv,xlsx,txt,json',
        ]);

        $videoIds = [];
        $invalidUrls = [];

        foreach ($request->urls as $url) {
            $url = trim($url);
            if (empty($url)) {
                continue;
            }

            $videoId = $this->extractVideoId($url);
            if ($videoId) {
                $videoIds[] = $videoId;
            } else {
                $invalidUrls[] = $url;
            }
        }

        if (empty($videoIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada URL YouTube yang valid ditemukan.',
                'invalid_urls' => $invalidUrls,
            ], 400);
        }

        // Reuse the scrapeComments logic
        $fakeRequest = new Request([
            'video_ids' => $videoIds,
            'comment_limit' => $request->comment_limit,
            'custom_limit' => $request->custom_limit,
            'include_replies' => $request->include_replies,
            'output_format' => $request->output_format,
        ]);

        $result = $this->scrapeComments($fakeRequest);
        $responseData = json_decode($result->getContent(), true);

        // Append invalid URLs info
        if (! empty($invalidUrls)) {
            $responseData['invalid_urls'] = $invalidUrls;
            if ($responseData['success'] ?? false) {
                $responseData['message'] .= ' '.count($invalidUrls).' URL tidak valid diabaikan.';
            }
        }

        return response()->json($responseData, $result->getStatusCode());
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

        $apiKey = config('services.youtube.key');
        $useYtDlp = false;

        // If no API key, check if yt-dlp is available
        if (! $apiKey) {
            if ($this->isYtDlpAvailable()) {
                $useYtDlp = true;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'YouTube API Key belum dikonfigurasi dan yt-dlp tidak tersedia.',
                ], 500);
            }
        }

        try {
            $allComments = [];
            $totalVideos = count($request->video_ids);
            $processedVideos = 0;
            $failedVideos = [];
            $usedFallback = false;

            foreach ($request->video_ids as $videoId) {
                try {
                    $comments = [];

                    if ($useYtDlp) {
                        // Use yt-dlp directly
                        $comments = $this->getCommentsViaYtDlp(
                            $videoId,
                            $request->comment_limit,
                            $request->custom_limit
                        );
                        $usedFallback = true;
                    } else {
                        // Try API first
                        try {
                            $comments = $this->getVideoComments(
                                $videoId,
                                $request->comment_limit,
                                $request->custom_limit,
                                $request->include_replies ?? false,
                                $apiKey
                            );
                        } catch (\Exception $e) {
                            // Check if quota exceeded → fallback to yt-dlp
                            if (str_contains($e->getMessage(), 'quotaExceeded') ||
                                str_contains($e->getMessage(), 'dailyLimitExceeded') ||
                                str_contains($e->getMessage(), 'rateLimitExceeded')) {

                                Log::warning("API quota exceeded for video {$videoId}, switching to yt-dlp fallback");

                                if ($this->isYtDlpAvailable()) {
                                    $comments = $this->getCommentsViaYtDlp(
                                        $videoId,
                                        $request->comment_limit,
                                        $request->custom_limit
                                    );
                                    $useYtDlp = true; // Switch to yt-dlp for remaining videos
                                    $usedFallback = true;
                                } else {
                                    throw new \Exception('Kuota API habis dan yt-dlp tidak tersedia.');
                                }
                            } else {
                                throw $e;
                            }
                        }
                    }

                    $allComments = array_merge($allComments, $comments);
                    $processedVideos++;

                    Log::info("Processed video {$videoId}: ".count($comments).' comments'.($usedFallback ? ' (via yt-dlp)' : ''));

                } catch (\Exception $e) {
                    $failedVideos[] = ['id' => $videoId, 'error' => $e->getMessage()];
                    Log::error("Failed to get comments for video {$videoId}: ".$e->getMessage());
                }
            }

            if (empty($allComments)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada komentar yang berhasil diambil. Kemungkinan komentar dinonaktifkan pada video tersebut.',
                    'failed_videos' => $failedVideos,
                ], 404);
            }

            // Save or export comments
            $filename = $this->exportComments($allComments, $request->output_format);

            $message = 'Berhasil mengambil '.count($allComments).' komentar dari '.$processedVideos.' video';
            if ($usedFallback) {
                $message .= ' (menggunakan mode fallback)';
            }
            if (! empty($failedVideos)) {
                $message .= '. Gagal mengambil komentar dari '.count($failedVideos).' video.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'total_comments' => count($allComments),
                    'total_videos' => $totalVideos,
                    'processed_videos' => $processedVideos,
                    'failed_videos' => count($failedVideos),
                    'failed_details' => $failedVideos,
                    'used_fallback' => $usedFallback,
                    'filename' => $filename,
                    'download_url' => route('youtube.download', ['filename' => $filename]),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Scraping Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil komentar: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get comments dari single video via API
     */
    private function getVideoComments($videoId, $limitType, $customLimit, $includeReplies, $apiKey)
    {
        $comments = [];
        $pageToken = null;

        // Tentukan limit
        $maxComments = match ($limitType) {
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
                    $errorReason = $errorData['error']['errors'][0]['reason'] ?? '';

                    // Jika komentar disabled, throw exception
                    if (str_contains($errorMessage, 'disabled')) {
                        throw new \Exception('Komentar dinonaktifkan untuk video ini');
                    }

                    // Propagate quota errors for fallback handling
                    if ($errorReason === 'quotaExceeded' || $errorReason === 'dailyLimitExceeded' || $errorReason === 'rateLimitExceeded') {
                        throw new \Exception($errorReason.': '.$errorMessage);
                    }

                    throw new \Exception('API Error: '.$errorMessage);
                }

                $data = $response->json();

                if (! isset($data['items'])) {
                    break;
                }

                foreach ($data['items'] ?? [] as $item) {
                    if (! isset($item['snippet']['topLevelComment']['snippet'])) {
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
                // Don't retry on quota exceeded — propagate immediately
                if (str_contains($e->getMessage(), 'quotaExceeded') ||
                    str_contains($e->getMessage(), 'dailyLimitExceeded') ||
                    str_contains($e->getMessage(), 'rateLimitExceeded')) {
                    throw $e;
                }

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
            Log::warning("Failed to get replies for comment {$commentId}: ".$e->getMessage());
        }

        return $replies;
    }

    // ─────────────────────────────────────────────────────────────
    // yt-dlp Fallback Methods
    // ─────────────────────────────────────────────────────────────

    /**
     * Check if yt-dlp is installed and available
     */
    private function isYtDlpAvailable()
    {
        try {
            $result = shell_exec('yt-dlp --version 2>&1');

            return ! empty($result) && ! str_contains($result, 'not recognized') && ! str_contains($result, 'not found');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get video info via yt-dlp (fallback)
     */
    private function getVideoInfoViaYtDlp($videoId)
    {
        $url = "https://www.youtube.com/watch?v={$videoId}";
        $command = 'yt-dlp --dump-json --no-download --no-playlist '.escapeshellarg($url).' 2>&1';

        $output = shell_exec($command);

        if (! $output) {
            return null;
        }

        $data = json_decode($output, true);
        if (! $data) {
            return null;
        }

        return [
            'id' => $videoId,
            'title' => $data['title'] ?? 'Unknown',
            'description' => $data['description'] ?? '',
            'channel' => $data['uploader'] ?? $data['channel'] ?? 'Unknown',
            'channelId' => $data['channel_id'] ?? '',
            'thumbnail' => $data['thumbnail'] ?? '',
            'duration' => $this->formatDuration('PT'.intval($data['duration'] ?? 0).'S'),
            'viewCount' => $this->formatNumber($data['view_count'] ?? 0),
            'viewCountRaw' => $data['view_count'] ?? 0,
            'likeCount' => $this->formatNumber($data['like_count'] ?? 0),
            'commentCount' => $data['comment_count'] ?? 0,
            'publishedAt' => isset($data['upload_date'])
                ? substr($data['upload_date'], 0, 4).'-'.substr($data['upload_date'], 4, 2).'-'.substr($data['upload_date'], 6, 2).'T00:00:00Z'
                : '',
            'url' => $url,
        ];
    }

    /**
     * Get comments via yt-dlp (fallback when API quota is exceeded)
     */
    private function getCommentsViaYtDlp($videoId, $limitType = '100', $customLimit = null)
    {
        $maxComments = match ($limitType) {
            '100' => 100,
            '500' => 500,
            'all' => 0, // 0 means no limit in yt-dlp
            'custom' => $customLimit ?? 100,
            default => 100,
        };

        $url = "https://www.youtube.com/watch?v={$videoId}";

        $command = 'yt-dlp --write-comments --skip-download --no-write-info-json --dump-json --no-playlist';

        if ($maxComments > 0) {
            $command .= ' --extractor-args "youtube:max_comments='.$maxComments.'"';
        }

        $command .= ' '.escapeshellarg($url).' 2>&1';

        Log::info("Running yt-dlp command for video {$videoId}");

        $output = shell_exec($command);

        if (! $output) {
            throw new \Exception("yt-dlp tidak mengembalikan output untuk video {$videoId}");
        }

        $data = json_decode($output, true);

        if (! $data || ! isset($data['comments'])) {
            // Try to check if comments are disabled
            if ($data && empty($data['comments'])) {
                return [];
            }
            throw new \Exception("Gagal mem-parse output yt-dlp untuk video {$videoId}");
        }

        $comments = [];
        foreach ($data['comments'] as $comment) {
            $comments[] = [
                'video_id' => $videoId,
                'comment_id' => $comment['id'] ?? '',
                'author' => $comment['author'] ?? 'Unknown',
                'author_channel_url' => isset($comment['author_id'])
                    ? 'https://www.youtube.com/channel/'.$comment['author_id']
                    : '',
                'text' => $comment['text'] ?? '',
                'like_count' => $comment['like_count'] ?? 0,
                'published_at' => isset($comment['timestamp'])
                    ? date('c', $comment['timestamp'])
                    : '',
                'updated_at' => '',
                'reply_count' => 0,
            ];
        }

        if ($maxComments > 0) {
            $comments = array_slice($comments, 0, $maxComments);
        }

        return $comments;
    }

    // ─────────────────────────────────────────────────────────────
    // Helper Methods
    // ─────────────────────────────────────────────────────────────

    /**
     * Extract video ID from various YouTube URL formats
     */
    private function extractVideoId($url)
    {
        $url = trim($url);

        // If it's already just a video ID (11 chars, alphanumeric + dash + underscore)
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) {
            return $url;
        }

        $patterns = [
            // Standard: youtube.com/watch?v=VIDEO_ID
            '/(?:youtube\.com\/watch\?(?:.*&)?v=)([a-zA-Z0-9_-]{11})/',
            // Short: youtu.be/VIDEO_ID
            '/(?:youtu\.be\/)([a-zA-Z0-9_-]{11})/',
            // Embed: youtube.com/embed/VIDEO_ID
            '/(?:youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
            // Shorts: youtube.com/shorts/VIDEO_ID
            '/(?:youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/',
            // Live: youtube.com/live/VIDEO_ID
            '/(?:youtube\.com\/live\/)([a-zA-Z0-9_-]{11})/',
            // v/ format: youtube.com/v/VIDEO_ID
            '/(?:youtube\.com\/v\/)([a-zA-Z0-9_-]{11})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Export comments to file
     */
    private function exportComments($comments, $format)
    {
        $filename = 'youtube_comments_'.date('YmdHis').'.'.$format;
        $path = storage_path('app/public/exports/'.$filename);

        // Pastikan direktori exists
        if (! file_exists(dirname($path))) {
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
            'Reply Count',
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
        $spreadsheet = new Spreadsheet;
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
            'Reply Count',
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
        $content .= 'Total: '.count($comments)." comments\n";
        $content .= 'Generated: '.date('Y-m-d H:i:s')."\n";
        $content .= "==============================================\n\n";

        foreach ($comments as $index => $comment) {
            $content .= 'Comment #'.($index + 1)."\n";
            $content .= str_repeat('-', 80)."\n";
            $content .= 'Video ID    : '.($comment['video_id'] ?? '')."\n";
            $content .= 'Author      : '.($comment['author'] ?? '')."\n";
            $content .= 'Likes       : '.($comment['like_count'] ?? 0)."\n";
            $content .= 'Published   : '.($comment['published_at'] ?? '')."\n";
            $content .= 'Replies     : '.($comment['reply_count'] ?? 0)."\n";
            $content .= "\nComment:\n";
            $content .= ($comment['text'] ?? '')."\n";
            $content .= "\n".str_repeat('=', 80)."\n\n";
        }

        file_put_contents($path, $content);
    }

    /**
     * Download file hasil scraping
     */
    /**
     * Unduh berkas hasil scraping.
     *
     * Nama berkas datang dari URL, jadi harus dibatasi ketat: sebelumnya ia
     * disambung langsung ke path sehingga nama seperti '..%2F..%2F.env' bisa
     * keluar dari direktori exports. Karena respons memakai
     * deleteFileAfterSend(), celah itu bukan hanya membocorkan berkas tetapi
     * juga menghapusnya.
     */
    public function download($filename)
    {
        $direktori = storage_path('app/public/exports');

        // basename() membuang komponen path apa pun, lalu polanya dibatasi
        // pada nama yang memang dihasilkan exportComments().
        $filename = basename($filename);

        if (! preg_match('/^[A-Za-z0-9._-]+\.(csv|xlsx|txt)$/', $filename)) {
            abort(404, 'File tidak ditemukan');
        }

        $path = $direktori.DIRECTORY_SEPARATOR.$filename;
        $realPath = realpath($path);

        // Penjagaan terakhir: pastikan berkas yang diselesaikan benar-benar
        // berada di dalam direktori exports (mis. bila ada symlink).
        if ($realPath === false || ! str_starts_with($realPath, realpath($direktori).DIRECTORY_SEPARATOR)) {
            abort(404, 'File tidak ditemukan');
        }

        return response()->download($realPath)->deleteFileAfterSend(true);
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
            return round($number / 1000000000, 1).'B';
        }
        if ($number >= 1000000) {
            return round($number / 1000000, 1).'M';
        }
        if ($number >= 1000) {
            return round($number / 1000, 1).'K';
        }

        return $number;
    }
}
