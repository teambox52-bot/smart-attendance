<?php

namespace App\Console\Commands;

use App\Services\FacePlusPlusService;
use Illuminate\Console\Command;

class FaceProviderCheck extends Command
{
    protected $signature = 'face:provider-check {image : Path to a local jpg/png face image}';

    protected $description = 'Safely checks Face++ detect API without printing secrets.';

    public function handle(FacePlusPlusService $faceService): int
    {
        $imagePath = (string) $this->argument('image');
        $apiKeyPresent = filled(env('FACEPP_API_KEY'));
        $apiSecretPresent = filled(env('FACEPP_API_SECRET'));
        $baseUrlPresent = filled(env('FACEPP_BASE_URL'));
        $imagePresent = is_file($imagePath) && is_readable($imagePath);

        $this->line('Face++ provider check');
        $this->line('FACEPP_API_KEY: ' . ($apiKeyPresent ? 'present' : 'missing'));
        $this->line('FACEPP_API_SECRET: ' . ($apiSecretPresent ? 'present' : 'missing'));
        $this->line('FACEPP_BASE_URL: ' . ($baseUrlPresent ? 'present' : 'missing'));
        $this->line('Image file: ' . ($imagePresent ? 'present' : 'missing/unreadable'));

        if (!$apiKeyPresent || !$apiSecretPresent || !$baseUrlPresent || !$imagePresent) {
            return self::FAILURE;
        }

        $result = $faceService->detectDetailed($imagePath);
        $json = $result['json'] ?? [];

        $this->line('Endpoint: ' . ($result['endpoint'] ?? 'unknown'));
        $this->line('Provider HTTP status: ' . (($result['status'] ?? null) ?: 'none'));
        $this->line('Provider success: ' . ($result['ok'] ? 'yes' : 'no'));

        if (!empty($result['error_code']) || !empty($result['error_message'])) {
            $this->line('Provider error code: ' . ($result['error_code'] ?: 'none'));
            $this->line('Provider error message: ' . ($result['error_message'] ?: 'none'));
        }

        $this->line('Faces detected: ' . count($json['faces'] ?? []));
        $this->line('Face token returned: ' . (isset($json['faces'][0]['face_token']) ? 'yes' : 'no'));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
