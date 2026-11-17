<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\WebhookEvent;
use App\Services\PixelforgeSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, PixelforgeSignatureVerifier $signatures): Response
    {
        if (! $signatures->valid($request)) {
            return response('Invalid Pixelforge signature.', 400);
        }

        $event = json_decode($request->getContent(), true);

        if (! is_array($event)
            || ! is_string($event['id'] ?? null) || ! str_starts_with($event['id'], 'evt_')
            || ! is_string($event['type'] ?? null)
            || ! in_array($event['type'], ['ping', 'generation.processing', 'generation.succeeded', 'generation.failed'], true)
            || $request->header('Pixelforge-Event-Id') !== $event['id']
            || $request->header('Pixelforge-Event-Type') !== $event['type']) {
            return response('Invalid Pixelforge event.', 400);
        }

        $snapshot = $event['data']['object'] ?? null;

        if ($event['type'] !== 'ping'
            && (! is_array($snapshot)
                || ! is_string($snapshot['id'] ?? null)
                || ! is_string($snapshot['metadata']['order_id'] ?? null)
                || ! in_array($snapshot['status'] ?? null, ['processing', 'succeeded', 'failed'], true)
                || $event['type'] !== 'generation.'.$snapshot['status'])) {
            return response('Invalid Pixelforge generation event.', 400);
        }

        DB::transaction(function () use ($event, $snapshot): void {
            WebhookEvent::query()->insertOrIgnore([
                'id' => $event['id'],
                'type' => $event['type'],
                'generation_id' => $event['type'] === 'ping' ? null : $snapshot['metadata']['order_id'],
                'provider_generation_id' => $event['type'] === 'ping' ? null : $snapshot['id'],
                'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($event['type'] !== 'ping') {
                ProcessPixelforgeWebhook::dispatch($event['id'])->afterCommit();
            }
        });

        return response()->noContent();
    }
}
