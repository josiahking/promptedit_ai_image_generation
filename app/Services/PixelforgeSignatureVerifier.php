<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use RuntimeException;

final class PixelforgeSignatureVerifier
{
    public function valid(Request $request): bool
    {
        $secret = config('services.pixelforge.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Pixelforge webhook secret is not configured.');
        }

        $parts = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$name] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! preg_match('/^[0-9]{10,12}$/D', $timestamp)
            || ! preg_match('/^[0-9a-f]{64}$/D', $signature)
            || abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
