<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;

class FileProcessingService
{
    /**
     * Save uploaded file to storage
     */
    public function saveFile(UploadedFile $file, $directory = 'uploads')
    {
        $filename = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs($directory, $filename, 'public');
        
        return [
            'path' => $path,
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'extension' => $file->getClientOriginalExtension(),
        ];
    }

    /**
     * Process file for preview (used in AJAX upload)
     * Returns basic information about the file
     */
    public function processFile(UploadedFile $file)
    {
        $extension = strtolower($file->getClientOriginalExtension());
        
        switch ($extension) {
            case 'xlsx':
            case 'xls':
                return $this->previewExcelFile($file->getRealPath());
                
            case 'csv':
                return $this->previewCsvFile($file->getRealPath());
                
            case 'txt':
                return $this->previewTxtFile($file->getRealPath());
                
            default:
                throw new \Exception('Unsupported file type');
        }
    }

    /**
     * Process file with configuration (used in final submission)
     * Returns array of text strings ready for analysis
     */
    public function processFileWithConfig(UploadedFile $file, array $config)
    {
        $extension = strtolower($file->getClientOriginalExtension());
        
        switch ($extension) {
            case 'xlsx':
            case 'xls':
                return $this->processExcelWithConfig($file->getRealPath(), $config);
                
            case 'csv':
                return $this->processCsvWithConfig($file->getRealPath(), $config);
                
            case 'txt':
                return $this->processTxtWithConfig($file->getRealPath(), $config);
                
            default:
                throw new \Exception('Unsupported file type');
        }
    }

    /**
     * Preview Excel file - return headers and sample data
     */
    private function previewExcelFile($filePath)
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheets = [];

        // Get all sheet names
        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $sheets[] = $sheetName;
        }

        // Get data from first sheet
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();

        // Remove empty rows
        $data = array_filter($data, function($row) {
            return !empty(array_filter($row));
        });
        $data = array_values($data);

        $headers = [];
        $sample = [];
        $total = count($data);

        if ($total > 0) {
            // First row as headers
            $headers = array_values($data[0]);

            // Get sample data (skip header, take 5 rows)
            $sampleData = array_slice($data, 1, 5);
            foreach ($sampleData as $row) {
                $rowData = [];
                foreach ($headers as $index => $header) {
                    $rowData[$header] = $row[$index] ?? '';
                }
                $sample[] = $rowData;
            }
        }

        $suggested = $this->suggestTextColumn($headers, $data, 1);

        return [
            'total' => max(0, $total - 1), // Exclude header
            'valid' => max(0, $total - 1),
            'headers' => $headers,
            'sample' => $sample,
            'sheets' => $sheets,
            'suggested_column' => $suggested,
        ];
    }

    /**
     * Preview CSV file
     */
    private function previewCsvFile($filePath, $delimiter = ',')
    {
        $reader = new CsvReader();
        $reader->setDelimiter($delimiter);
        $reader->setEnclosure('"');
        $reader->setSheetIndex(0);

        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();

        // Remove empty rows
        $data = array_filter($data, function($row) {
            return !empty(array_filter($row));
        });
        $data = array_values($data);

        $headers = [];
        $sample = [];
        $total = count($data);

        if ($total > 0) {
            $headers = array_values($data[0]);

            $sampleData = array_slice($data, 1, 5);
            foreach ($sampleData as $row) {
                $rowData = [];
                foreach ($headers as $index => $header) {
                    $rowData[$header] = $row[$index] ?? '';
                }
                $sample[] = $rowData;
            }
        }

        $suggested = $this->suggestTextColumn($headers, $data, 1);

        return [
            'total' => max(0, $total - 1),
            'valid' => max(0, $total - 1),
            'headers' => $headers,
            'sample' => $sample,
            'suggested_column' => $suggested,
        ];
    }

    /**
     * Suggest the most likely text column from headers and data.
     * Returns ['name' => ..., 'index' => ...] or null.
     */
    private function suggestTextColumn(array $headers, array $data, int $headerRow = 0): ?array
    {
        if (empty($headers)) {
            return null;
        }

        $samples = array_slice($data, $headerRow + 1, 0, 50);
        $samples = array_values(array_filter($samples, function ($row) {
            return !empty(array_filter($row, fn ($v) => $v !== null && $v !== ''));
        }));

        $bestIndex = null;
        $bestScore = -INF;
        $bestName = null;

        foreach ($headers as $idx => $name) {
            $nameStr = strtolower(trim((string) $name));

            // Heuristik 1: nama kolom mengandung kata "text/teks/komentar/comment/ulasan/review/isi"
            $keywordHit = preg_match('/(text|teks|komentar|comment|ulasan|review|isi|pesan|message|content|deskripsi|description)/i', $nameStr);

            // Heuristik 2: nama kolom tampak ID (id, _id, uuid, kode, code) → skip
            $looksLikeId = preg_match('/(^id$|_id$|uuid|^kode$|^code$|comment[_-]?id|user[_-]?id|video[_-]?id)/i', $nameStr);

            // Heuristik 3: skor berdasarkan karakteristik data
            $values = array_map(fn ($row) => $row[$idx] ?? '', $samples);
            $nonEmpty = array_filter($values, fn ($v) => $v !== null && $v !== '');

            if (count($samples) === 0) {
                $avgLen = 0;
                $uniqueRatio = 0;
            } else {
                $lengths = array_map(fn ($v) => mb_strlen((string) $v), $nonEmpty);
                $avgLen = count($lengths) ? array_sum($lengths) / max(1, count($lengths)) : 0;
                $uniqueRatio = count($nonEmpty) ? count(array_unique(array_map('strval', $nonEmpty))) / count($nonEmpty) : 0;
            }

            $score = 0;
            $score += $keywordHit ? 100 : 0;
            $score += $looksLikeId ? -200 : 0;
            $score += min(50, $avgLen / 2); // longer texts score higher
            $score += $uniqueRatio * 20;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $idx;
                $bestName = $name;
            }
        }

        if ($bestIndex === null || $bestScore < 0) {
            return null;
        }

        return [
            'name' => (string) $bestName,
            'index' => $bestIndex,
            'score' => $bestScore,
        ];
    }

    /**
     * Preview TXT file
     */
    private function previewTxtFile($filePath)
    {
        $content = file_get_contents($filePath);
        
        // Split by newline for preview
        $lines = explode("\n", $content);
        $lines = array_filter(array_map('trim', $lines));
        $lines = array_values($lines);
        
        $sample = array_slice($lines, 0, 5);
        
        return [
            'total' => count($lines),
            'valid' => count($lines),
            'sample' => $sample,
        ];
    }

    /**
     * Process Excel file with configuration
     */
    private function processExcelWithConfig($filePath, array $config)
    {
        $spreadsheet = IOFactory::load($filePath);
        
        // Select sheet
        $sheetIndex = $config['excel_sheet'] ?? 0;
        $spreadsheet->setActiveSheetIndex((int)$sheetIndex);
        $worksheet = $spreadsheet->getActiveSheet();
        
        $data = $worksheet->toArray();
        
        // Remove empty rows
        $data = array_filter($data, function($row) {
            return !empty(array_filter($row));
        });
        $data = array_values($data);
        
        $texts = [];
        $hasHeader = isset($config['file_has_header']) && $config['file_has_header'] == 'on';
        
        if ($hasHeader) {
            // Use column name
            $columnName = $config['text_column_name'] ?? null;
            
            if (empty($data) || !$columnName) {
                return ['texts' => [], 'total' => 0];
            }
            
            $headers = $data[0];
            $columnIndex = array_search($columnName, $headers);
            
            if ($columnIndex === false) {
                throw new \Exception("Column '$columnName' not found");
            }
            
            // Extract text from column (skip header)
            for ($i = 1; $i < count($data); $i++) {
                $text = $data[$i][$columnIndex] ?? '';
                $text = trim($text);
                if (!empty($text)) {
                    $texts[] = $text;
                }
            }
            
        } else {
            // Use column index
            $columnIndex = isset($config['text_column_index']) 
                ? (int)$config['text_column_index'] - 1  // Convert to 0-based
                : 0;
            
            // Extract text from column
            foreach ($data as $row) {
                $text = $row[$columnIndex] ?? '';
                $text = trim($text);
                if (!empty($text)) {
                    $texts[] = $text;
                }
            }
        }
        
        return [
            'texts' => $texts,
            'total' => count($texts),
        ];
    }

    /**
     * Process CSV file with configuration
     */
    private function processCsvWithConfig($filePath, array $config)
    {
        // Get delimiter
        $delimiter = $config['csv_delimiter'] ?? ',';
        
        // Handle special characters
        if ($delimiter === '\t') {
            $delimiter = "\t";
        }
        
        $reader = new CsvReader();
        $reader->setDelimiter($delimiter);
        $reader->setEnclosure('"');
        $reader->setSheetIndex(0);
        
        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();
        
        // Remove empty rows
        $data = array_filter($data, function($row) {
            return !empty(array_filter($row));
        });
        $data = array_values($data);
        
        $texts = [];
        $hasHeader = isset($config['file_has_header']) && $config['file_has_header'] == 'on';
        
        if ($hasHeader) {
            // Use column name
            $columnName = $config['text_column_name'] ?? null;
            
            if (empty($data) || !$columnName) {
                return ['texts' => [], 'total' => 0];
            }
            
            $headers = $data[0];
            $columnIndex = array_search($columnName, $headers);
            
            if ($columnIndex === false) {
                throw new \Exception("Column '$columnName' not found");
            }
            
            // Extract text (skip header)
            for ($i = 1; $i < count($data); $i++) {
                $text = $data[$i][$columnIndex] ?? '';
                $text = trim($text);
                if (!empty($text)) {
                    $texts[] = $text;
                }
            }
            
        } else {
            // Use column index
            $columnIndex = isset($config['text_column_index']) 
                ? (int)$config['text_column_index'] - 1
                : 0;
            
            foreach ($data as $row) {
                $text = $row[$columnIndex] ?? '';
                $text = trim($text);
                if (!empty($text)) {
                    $texts[] = $text;
                }
            }
        }
        
        return [
            'texts' => $texts,
            'total' => count($texts),
        ];
    }

    /**
     * Process TXT file with configuration
     */
    private function processTxtWithConfig($filePath, array $config)
    {
        // Get encoding
        $encoding = $config['txt_encoding'] ?? 'utf-8';
        
        // Read file
        $content = file_get_contents($filePath);
        
        // Convert encoding if needed
        if (strtolower($encoding) !== 'utf-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        
        // Get separator
        $separator = $config['txt_separator'] ?? 'newline';
        
        $texts = [];
        
        switch ($separator) {
            case 'newline':
                $texts = explode("\n", $content);
                break;
                
            case 'period':
                $texts = explode('.', $content);
                break;
                
            case 'double_newline':
                $texts = preg_split('/\n\s*\n/', $content);
                break;
                
            case 'custom':
                $customSeparator = $config['txt_custom_separator'] ?? "\n";
                $texts = explode($customSeparator, $content);
                break;
                
            default:
                $texts = explode("\n", $content);
        }
        
        // Clean up
        $texts = array_filter(array_map('trim', $texts), function($text) {
            return !empty($text);
        });
        $texts = array_values($texts);
        
        return [
            'texts' => $texts,
            'total' => count($texts),
        ];
    }
}