<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;
use UnexpectedValueException;

final class PixelforgeClient
{
    /** @return array<string, mixed> */
    public function pricing(): array
    {
        $response = Http::acceptJson()->timeout(10)->get($this->url('pricing'));

        if (! $response->successful()) {
            throw $this->apiError($response);
        }

        $data = $response->json();

        if (! is_array($data) || ! is_array($data['rates'] ?? null) || ! is_array($data['tokens'] ?? null)) {
            throw new UnexpectedValueException('Pixelforge returned invalid pricing data.');
        }

        return $data;
    }

    /**
     * Send one request attempt. The job owns bounded retries and backoff.
     * Reusing the persisted key and identical parameters is mandatory.
     *
     * @return array<string, mixed>
     */
    public function createGeneration(Generation $generation): array
    {
        $key = config('services.pixelforge.key');

        if (! is_string($key) || $key === '') {
            throw new LogicException('Pixelforge API key is not configured.');
        }

        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images ?? [],
            'metadata' => ['order_id' => $generation->id],
        ];

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($key)
            ->withHeaders(['Idempotency-Key' => $generation->idempotency_key])
            ->timeout(15)
            ->post($this->url('generations'), $payload);

        if ($response->status() !== 202) {
            throw $this->apiError($response);
        }

        $data = $response->json();

        if (! is_array($data)
            || ! is_string($data['id'] ?? null)
            || ! str_starts_with($data['id'], 'gen_')
            || ! in_array($data['status'] ?? null, ['queued', 'processing', 'succeeded', 'failed'], true)
            || ($data['metadata']['order_id'] ?? null) !== $generation->id) {
            throw new UnexpectedValueException('Pixelforge returned an invalid generation acknowledgement.');
        }

        return $data;
    }

    private function url(string $path): string
    {
        $base = config('services.pixelforge.base_url');

        if (! is_string($base) || $base === '') {
            throw new LogicException('Pixelforge base URL is not configured.');
        }

        return rtrim($base, '/').'/'.$path;
    }

    private function apiError(Response $response): PixelforgeApiException
    {
        $body = $response->json();
        $code = is_array($body) && is_string($body['error']['code'] ?? null)
            ? $body['error']['code']
            : 'provider_http_'.$response->status();

        $messages = [
            'invalid_api_key' => 'Image generation is temporarily unavailable. Please contact support.',
            'validation_failed' => 'The generation settings were rejected by Pixelforge.',
            'content_policy_violation' => 'The prompt was rejected by the image provider.',
            'idempotency_key_reused' => 'The saved generation request conflicts with a previous request.',
            'rate_limit_exceeded' => 'The image provider is rate limiting requests.',
            'concurrency_limit_exceeded' => 'The provider is processing the maximum number of images.',
            'daily_limit_exceeded' => 'The provider has reached its daily generation limit.',
            'overloaded' => 'The image provider is temporarily overloaded.',
        ];

        $retryAfter = $response->header('Retry-After');
        $seconds = ctype_digit((string) $retryAfter)
            ? min((int) $retryAfter, 3600)
            : null;

        $retryable = in_array($code, [
            'rate_limit_exceeded',
            'concurrency_limit_exceeded',
            'overloaded',
        ], true) || $response->status() >= 500;

        // Only documented 429/503 responses explicitly guarantee nothing was created.
        $uncertain = $response->status() >= 500 && $code !== 'overloaded';

        return new PixelforgeApiException(
            $code,
            $response->status(),
            $seconds,
            $retryable,
            $uncertain,
            $messages[$code] ?? 'The image provider could not process the request.',
        );
    }
}
