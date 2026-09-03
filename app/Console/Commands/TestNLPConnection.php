<?php

namespace App\Console\Commands;

use App\Services\NLPApiService;
use Illuminate\Console\Command;

class TestNLPConnection extends Command
{
    protected $signature = 'nlp:test';

    protected $description = 'Test connection to NLP API';

    public function handle(NLPApiService $nlpService)
    {
        $this->info('Testing connection to NLP API...');

        $result = $nlpService->testConnection();

        if ($result['status'] === 'success') {
            $this->info('✅ Connection successful!');
            $this->line('Response: '.json_encode($result['data'], JSON_PRETTY_PRINT));
        } else {
            $this->error('❌ Connection failed!');
            $this->line('Error: '.$result['message']);
        }
    }
}
