<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Exception;

class FileProcessingService
{
    /**
     * Process uploaded file dan extract text
     */
    public function processFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match($extension) {
            'csv' => $this->processCsv($file),
            'txt' => $this->processTxt($file),
            'xlsx', 'xls' => $this->processExcel($file),
            default => throw new Exception('Unsupported file type')
        };
    }

    /**
     * Process CSV file
     */
    private function processCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle);
        
        $texts = [];
        $rowCount = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) > 0 && !empty(trim($row[0]))) {
                $texts[] = trim($row[0]); // Ambil kolom pertama
                $rowCount++;
            }
            
            // Limit untuk mencegah memory issue
            if ($rowCount >= 10000) {
                break;
            }
        }
        
        fclose($handle);

        return [
            'texts' => $texts,
            'total' => count($texts),
            'headers' => $headers,
            'type' => 'csv'
        ];
    }

    /**
     * Process TXT file
     */
    private function processTxt(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        
        // Handle different line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        
        $lines = explode("\n", $content);
        $texts = array_filter(array_map('trim', $lines), fn($line) => !empty($line));

        return [
            'texts' => array_values($texts),
            'total' => count($texts),
            'type' => 'txt'
        ];
    }

    /**
     * Process Excel file
     */
    private function processExcel(UploadedFile $file): array
    {
        $data = Excel::toArray([], $file)[0];
        
        if (empty($data)) {
            throw new Exception('File Excel kosong');
        }
        
        $headers = array_shift($data);
        
        $texts = [];
        foreach ($data as $row) {
            if (count($row) > 0 && !empty(trim($row[0]))) {
                $texts[] = trim($row[0]);
            }
            
            // Limit untuk mencegah memory issue
            if (count($texts) >= 10000) {
                break;
            }
        }

        return [
            'texts' => $texts,
            'total' => count($texts),
            'headers' => $headers,
            'type' => 'xlsx'
        ];
    }

    /**
     * Save uploaded file
     */
    public function saveFile(UploadedFile $file, string $directory = 'uploads'): array
    {
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file->getClientOriginalName());
        $path = $file->storeAs($directory, $filename, 'public');

        return [
            'path' => $path,
            'filename' => $filename,
            'size' => $file->getSize(),
            'type' => $file->getClientOriginalExtension()
        ];
    }
}