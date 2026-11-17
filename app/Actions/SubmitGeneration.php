<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\PixelforgeClient;

final readonly class SubmitGeneration
{
    public function __construct(
        private PixelforgeClient $provider,
        private PersistGeneration $persist,
    ) {}

    /**
     * Create one local request before dispatching any provider submission.
     *
     * @param array{prompt:string,size:string,quality:string,reference_images:array<int,string>} $input
     */
    public function execute(User $user, array $input): Generation
    {
        $pricing = $this->provider->pricing();
        $generation = $this->persist->execute($user, $input, $pricing);

        SubmitPixelforgeGeneration::dispatch($generation->id)->afterCommit();

        return $generation;
    }
}
