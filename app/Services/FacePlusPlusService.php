<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class FacePlusPlusService
{
    private $key;
    private $secret;
    private $baseUrl;
    private bool $verifySsl;

    public function __construct()
    {
        $this->key = env('FACEPP_API_KEY');
        $this->secret = env('FACEPP_API_SECRET');
        $this->baseUrl = env('FACEPP_BASE_URL');
        $this->verifySsl = filter_var(env('FACEPP_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) $this->baseUrl, '/') . $path;
    }

    private function imagePart(string $name, string $imagePath, ?string $filename = null, ?string $mimeType = null): array
    {
        $part = [
            'name' => $name,
            'contents' => fopen($imagePath, 'r'),
            'filename' => $filename ?: basename($imagePath) ?: 'face.jpg',
        ];

        if ($mimeType) {
            $part['headers'] = [
                'Content-Type' => $mimeType,
            ];
        }

        return $part;
    }

    private function postMultipart(string $path, array $parts): array
    {
        $endpoint = $this->endpoint($path);

        try {
            $request = Http::asMultipart()
                ->timeout(20)
                ->when(!$this->verifySsl, fn ($http) => $http->withoutVerifying());

            $response = $request->post($endpoint, array_merge([
                    [
                        'name' => 'api_key',
                        'contents' => $this->key,
                    ],
                    [
                        'name' => 'api_secret',
                        'contents' => $this->secret,
                    ],
                ], $parts));
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'endpoint' => $path,
                'status' => null,
                'json' => null,
                'error_code' => 'http_exception',
                'error_message' => $exception->getMessage(),
            ];
        }

        $json = $response->json();
        $errorCode = is_array($json) ? ($json['error_code'] ?? null) : null;
        $errorMessage = is_array($json) ? ($json['error_message'] ?? null) : null;

        return [
            'ok' => $response->successful() && !$errorCode,
            'endpoint' => $path,
            'status' => $response->status(),
            'json' => is_array($json) ? $json : null,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
    }

    public function detectDetailed($imagePath, ?string $filename = null, ?string $mimeType = null): array
    {
        return $this->postMultipart('/facepp/v3/detect', [
            $this->imagePart('image_file', $imagePath, $filename, $mimeType),
        ]);
    }

    public function compareDetailed($image1, $image2): array
    {
        return $this->postMultipart('/facepp/v3/compare', [
            $this->imagePart('image_file1', $image1),
            $this->imagePart('image_file2', $image2),
        ]);
    }

    public function compareFaceTokenDetailed($imagePath, $faceToken, ?string $filename = null, ?string $mimeType = null): array
    {
        return $this->postMultipart('/facepp/v3/compare', [
            $this->imagePart('image_file1', $imagePath, $filename, $mimeType),
            [
                'name' => 'face_token2',
                'contents' => $faceToken,
            ],
        ]);
    }

    public function detect($imagePath)
    {
        return $this->detectDetailed($imagePath)['json'];
    }

    public function compare($image1, $image2)
    {
        return $this->compareDetailed($image1, $image2)['json'];
    }

    public function compareFaceToken($imagePath, $faceToken)
    {
        return $this->compareFaceTokenDetailed($imagePath, $faceToken)['json'];
    }
}
