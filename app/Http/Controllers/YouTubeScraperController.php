<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
                'q' => $request->query,
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
            $response = Http::get('https://www.googleapis.com/youtube/v3/search', $params);

            if ($response->failed()) {
                throw new \Exception('YouTube API request failed');
            }

            $data = $response->json();

            // Get video IDs untuk mengambil detail tambahan
            $videoIds = collect($data['items'])->pluck('id.videoId')->implode(',');

            // Get video details (duration, view count, etc)
            $detailsResponse = Http::get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'contentDetails,statistics,snippet',
                'id' => $videoIds,
                'key' => $apiKey,
            ]);

            $videoDetails = $detailsResponse->json();

            // Combine search results with details
            $videos = [];
            foreach ($data['items'] as $item) {
                $videoId = $item['id']['videoId'];
                
                // Find matching details
                $details = collect($videoDetails['items'] ?? [])->firstWhere('id', $videoId);

                $videos[] = [
                    'id' => $videoId,
                    'title' => $item['snippet']['title'],
                    'description' => $item['snippet']['description'],
                    'channel' => $item['snippet']['channelTitle'],
                    'channelId' => $item['snippet']['channelId'],
                    'thumbnail' => $item['snippet']['thumbnails']['medium']['url'] ?? $item['snippet']['thumbnails']['default']['url'],
                    'publishedAt' => $item['snippet']['publishedAt'],
                    'duration' => $this->formatDuration($details['contentDetails']['duration'] ?? 'PT0S'),
                    'viewCount' => $this->formatNumber($details['statistics']['viewCount'] ?? 0),
                    'likeCount' => $this->formatNumber($details['statistics']['likeCount'] ?? 0),
                    'commentCount' => $details['statistics']['commentCount'] ?? 0,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $videos,
                'nextPageToken' => $data['nextPageToken'] ?? null,
                'totalResults' => $data['pageInfo']['totalResults'] ?? 0,
            ]);

        } catch (\Exception $e) {
            Log::error('YouTube API Error: ' . $e->getMessage());
            
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

            foreach ($request->video_ids as $videoId) {
                $comments = $this->getVideoComments(
                    $videoId, 
                    $request->comment_limit,
                    $request->custom_limit,
                    $request->include_replies ?? false,
                    $apiKey
                );

                $allComments = array_merge($allComments, $comments);
                $processedVideos++;

                // Broadcast progress (optional, bisa pakai Laravel Broadcasting)
                // event(new ScrapingProgress($processedVideos, $totalVideos));
            }

            // Save or export comments
            $filename = $this->exportComments($allComments, $request->output_format);

            return response()->json([
                'success' => true,
                'message' => 'Berhasil mengambil ' . count($allComments) . ' komentar dari ' . $totalVideos . ' video',
                'data' => [
                    'total_comments' => count($allComments),
                    'total_videos' => $totalVideos,
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

        do {
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

            $response = Http::get('https://www.googleapis.com/youtube/v3/commentThreads', $params);

            if ($response->failed()) {
                break;
            }

            $data = $response->json();

            foreach ($data['items'] ?? [] as $item) {
                $topComment = $item['snippet']['topLevelComment']['snippet'];
                
                $comments[] = [
                    'video_id' => $videoId,
                    'comment_id' => $item['id'],
                    'author' => $topComment['authorDisplayName'],
                    'text' => $topComment['textDisplay'],
                    'like_count' => $topComment['likeCount'],
                    'published_at' => $topComment['publishedAt'],
                    'updated_at' => $topComment['updatedAt'],
                    'reply_count' => $item['snippet']['totalReplyCount'] ?? 0,
                ];

                // Get replies if needed
                if ($includeReplies && ($item['snippet']['totalReplyCount'] ?? 0) > 0) {
                    // Implement reply fetching if needed
                }
            }

            $pageToken = $data['nextPageToken'] ?? null;

        } while ($pageToken && count($comments) < $maxComments);

        return array_slice($comments, 0, $maxComments);
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
        
        // Header
        fputcsv($file, ['No', 'Video ID', 'Author', 'Comment', 'Likes', 'Published At', 'Reply Count']);
        
        // Data
        foreach ($comments as $index => $comment) {
            fputcsv($file, [
                $index + 1,
                $comment['video_id'],
                $comment['author'],
                $comment['text'],
                $comment['like_count'],
                $comment['published_at'],
                $comment['reply_count'],
            ]);
        }
        
        fclose($file);
    }

    private function exportToExcel($comments, $path)
    {
        // Jika Anda pakai package seperti PhpSpreadsheet atau Laravel Excel
        // Untuk sekarang, kita export sebagai CSV dulu
        $this->exportToCsv($comments, $path);
    }

    private function exportToTxt($comments, $path)
    {
        $content = "YouTube Comments Export\n";
        $content .= "Total: " . count($comments) . " comments\n";
        $content .= str_repeat("=", 80) . "\n\n";

        foreach ($comments as $index => $comment) {
            $content .= "[" . ($index + 1) . "] " . $comment['author'] . "\n";
            $content .= "Likes: " . $comment['like_count'] . " | Published: " . $comment['published_at'] . "\n";
            $content .= $comment['text'] . "\n";
            $content .= str_repeat("-", 80) . "\n\n";
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
        preg_match('/PT(\d+H)?(\d+M)?(\d+S)?/', $duration, $matches);
        
        $hours = isset($matches[1]) ? rtrim($matches[1], 'H') : 0;
        $minutes = isset($matches[2]) ? rtrim($matches[2], 'M') : 0;
        $seconds = isset($matches[3]) ? rtrim($matches[3], 'S') : 0;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }
        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Helper: Format angka
     */
    private function formatNumber($number)
    {
        if ($number >= 1000000) {
            return round($number / 1000000, 1) . 'M';
        }
        if ($number >= 1000) {
            return round($number / 1000, 1) . 'K';
        }
        return $number;
    }
}