<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmitGeneration;
use App\Exceptions\PixelforgeApiException;
use App\Http\Requests\GenerationRequest;
use App\Models\Generation;
use App\Services\GenerationPresenter;
use App\Services\PixelforgeClient;
use App\Services\PricingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class GenerationController extends Controller
{
    public function index(Request $request, GenerationPresenter $presenter): Response
    {
        return Inertia::render('Dashboard', [
            'generations' => Generation::query()
                ->where('user_id', $request->user()?->getAuthIdentifier())
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (Generation $generation): array => $presenter->toArray($generation)),
        ]);
    }

    public function estimate(
        GenerationRequest $request,
        PixelforgeClient $client,
        PricingService $pricing,
    ): JsonResponse {
        $input = $request->generationInput();

        try {
            $quote = $pricing->estimate(
                $input['prompt'],
                $input['size'],
                $input['quality'],
                count($input['reference_images']),
                $client->pricing(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Pricing is temporarily unavailable. Please try again.'], 503);
        }

        return response()->json($quote);
    }

    public function store(GenerationRequest $request, SubmitGeneration $submission): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        try {
            $submission->execute($user, $request->generationInput());
        } catch (PixelforgeApiException|ConnectionException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'prompt' => 'The price service is temporarily unavailable. Please try again.',
            ]);
        }

        return to_route('dashboard');
    }

    public function status(Request $request, string $id, GenerationPresenter $presenter): JsonResponse
    {
        $generation = Generation::query()
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->findOrFail($id);

        return response()->json($presenter->toArray($generation));
    }
}
