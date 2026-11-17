<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowRight, ImagePlus, LoaderCircle, RefreshCw, Sparkles } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';

type Generation = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: string;
    estimated_customer_usd: string;
    minimum_customer_usd: string;
    maximum_customer_usd: string;
    provider_cost_usd: string | null;
    customer_cost_usd: string | null;
    image_url: string | null;
    image_expired: boolean;
    error_code: string | null;
    error_message: string | null;
    created_at: string | null;
    completed_at: string | null;
};

type Estimate = {
    estimated_usd: string;
    minimum_usd: string;
    maximum_usd: string;
};

const props = defineProps<{ generations: Generation[] }>();
const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});
const referenceText = ref('');
const quote = ref<Estimate | null>(null);
const quotePending = ref(false);
const quoteError = ref('');
const quoteMatchesForm = ref(false);
const submitError = ref('');
const refreshInProgress = ref(false);
let quoteTimer: ReturnType<typeof setTimeout> | undefined;
let pollTimer: ReturnType<typeof setInterval> | undefined;
let quoteRequest: AbortController | undefined;

const references = computed(() =>
    referenceText.value.split(/\r?\n/).map((url) => url.trim()).filter(Boolean),
);
const hasPending = computed(() => props.generations.some((generation) =>
    ['submitting', 'queued', 'processing', 'submission_unknown'].includes(generation.status),
));
const canEstimate = computed(() =>
    form.prompt.trim().length > 0
    && form.prompt.length <= 4000
    && references.value.length <= 4,
);

function currency(value: string | null): string {
    if (value === null) return '—';
    return '$' + Number(value).toFixed(5);
}

function dateLabel(value: string | null): string {
    if (!value) return '';
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function csrfToken(): string {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.substring('XSRF-TOKEN='.length)) : '';
}

async function fetchQuote(): Promise<void> {
    quoteRequest?.abort();
    quoteMatchesForm.value = false;
    if (!canEstimate.value) {
        quote.value = null;
        quoteError.value = '';
        quotePending.value = false;
        return;
    }

    const controller = new AbortController();
    quoteRequest = controller;
    quotePending.value = true;
    quoteError.value = '';

    try {
        const response = await fetch('/generations/estimate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: references.value,
            }),
            signal: controller.signal,
        });
        if (!response.ok) {
            throw new Error(response.status === 422
                ? 'Check your prompt and reference image URLs.'
                : 'Pricing is unavailable. Please try again.');
        }
        const result = await response.json() as Estimate;
        if (!controller.signal.aborted) {
            quote.value = result;
            quoteMatchesForm.value = true;
        }
    } catch (error) {
        if (!controller.signal.aborted) {
            quote.value = null;
            quoteError.value = error instanceof Error ? error.message : 'Unable to estimate the cost.';
        }
    } finally {
        if (!controller.signal.aborted) {
            quotePending.value = false;
        }
    }
}

watch([() => form.prompt, () => form.size, () => form.quality, referenceText], () => {
    if (quoteTimer) clearTimeout(quoteTimer);
    quoteRequest?.abort();
    quotePending.value = false;
    quoteMatchesForm.value = false;
    quote.value = null;
    quoteError.value = '';
    quoteTimer = setTimeout(() => void fetchQuote(), 450);
});

function submit(): void {
    if (!quoteMatchesForm.value || !quote.value || form.processing) return;
    submitError.value = '';
    form.reference_images = [...references.value];
    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            referenceText.value = '';
            quote.value = null;
            quoteMatchesForm.value = false;
        },
        onError: () => {
            submitError.value = 'Please correct the highlighted fields and try again.';
        },
    });
}

function refreshHistory(): void {
    if (refreshInProgress.value) return;
    refreshInProgress.value = true;
    router.reload({
        only: ['generations'],
        onFinish: () => { refreshInProgress.value = false; },
    });
}

watch(hasPending, (pending) => {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = pending ? setInterval(refreshHistory, 5000) : undefined;
}, { immediate: true });

onBeforeUnmount(() => {
    if (quoteTimer) clearTimeout(quoteTimer);
    if (pollTimer) clearInterval(pollTimer);
    quoteRequest?.abort();
});

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Image Studio', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Image Studio" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-8 px-4 py-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="mb-2 flex items-center gap-2 text-sm font-medium text-muted-foreground">
                    <Sparkles :size="16" /> PIXELFORGE STUDIO
                </p>
                <h1 class="text-3xl font-semibold tracking-tight md:text-4xl">Create something remarkable.</h1>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                    Describe your image, preview the estimated price, and track your generations in one place.
                </p>
            </div>
            <span class="rounded-full border border-border bg-card px-3 py-1.5 text-xs text-muted-foreground">
                {{ props.generations.length }} recent generations
            </span>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(290px,380px)]">
            <section class="rounded-2xl border border-border bg-card p-5 shadow-sm md:p-7" aria-labelledby="create-heading">
                <div class="mb-6 flex items-center gap-3">
                    <div class="rounded-xl bg-primary/10 p-2.5"><ImagePlus :size="21" /></div>
                    <div>
                        <h2 id="create-heading" class="text-lg font-semibold">New generation</h2>
                        <p class="text-xs text-muted-foreground">Select your image settings and preview the price.</p>
                    </div>
                </div>
                <form class="space-y-6" @submit.prevent="submit">
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-3">
                            <Label for="prompt">Describe your image</Label>
                            <span class="text-xs text-muted-foreground">{{ form.prompt.length }} / 4,000</span>
                        </div>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            maxlength="4000"
                            required
                            rows="5"
                            placeholder="A cinematic photograph of a quiet coastal village at golden hour, warm light, intricate details..."
                            class="w-full resize-y rounded-xl border border-input bg-transparent px-4 py-3 text-sm leading-relaxed outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/20"
                        />
                        <p v-if="form.errors.prompt" class="text-xs text-destructive">{{ form.errors.prompt }}</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="quality">Quality</Label>
                            <select id="quality" v-model="form.quality" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm">
                                <option value="low">Low · Fast</option>
                                <option value="medium">Medium · Balanced</option>
                                <option value="high">High · Detailed</option>
                            </select>
                            <p v-if="form.errors.quality" class="text-xs text-destructive">{{ form.errors.quality }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="size">Canvas size</Label>
                            <select id="size" v-model="form.size" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm">
                                <option value="1024x1024">Square · 1024 × 1024</option>
                                <option value="1536x1024">Landscape · 1536 × 1024</option>
                                <option value="1024x1536">Portrait · 1024 × 1536</option>
                            </select>
                            <p v-if="form.errors.size" class="text-xs text-destructive">{{ form.errors.size }}</p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label for="references">Reference images <span class="font-normal text-muted-foreground">(optional)</span></Label>
                        <textarea
                            id="references"
                            v-model="referenceText"
                            rows="3"
                            placeholder="https://example.com/reference.jpg"
                            class="w-full resize-y rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/20"
                        />
                        <p class="text-xs text-muted-foreground">One public HTTPS image URL per line; up to four references.</p>
                        <p v-if="references.length > 4" class="text-xs text-destructive">Use no more than four reference images.</p>
                        <p v-if="form.errors.reference_images" class="text-xs text-destructive">{{ form.errors.reference_images }}</p>
                    </div>

                    <div class="rounded-xl border border-border bg-muted/40 p-4" aria-live="polite">
                        <div class="mb-3 flex items-center justify-between">
                            <span class="text-sm font-medium">Estimated customer price</span>
                            <LoaderCircle v-if="quotePending" :size="16" class="animate-spin text-muted-foreground" />
                        </div>
                        <template v-if="quote && quoteMatchesForm">
                            <p class="text-3xl font-semibold tabular-nums">{{ currency(quote.estimated_usd) }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Possible range {{ currency(quote.minimum_usd) }} – {{ currency(quote.maximum_usd) }}
                            </p>
                            <p class="mt-2 text-xs text-muted-foreground">
                                Includes the 25% markup. Your actual charge is calculated from token usage on success; failed generations cost $0.
                            </p>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            {{ quoteError || (quotePending ? 'Calculating from Pixelforge pricing…' : 'Enter a prompt to see an estimated price.') }}
                        </p>
                    </div>

                    <p v-if="submitError" role="alert" class="text-sm text-destructive">{{ submitError }}</p>
                    <Button type="submit" class="h-11 w-full gap-2" :disabled="!quoteMatchesForm || !quote || !canEstimate || form.processing || quotePending">
                        <LoaderCircle v-if="form.processing" :size="16" class="animate-spin" />
                        <Sparkles v-else :size="16" />
                        {{ form.processing ? 'Submitting…' : 'Generate image' }}
                        <ArrowRight v-if="!form.processing" :size="16" />
                    </Button>
                </form>
            </section>

            <aside class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm" aria-label="Generation preview">
                <div class="border-b border-border p-5">
                    <h2 class="font-semibold">Latest creation</h2>
                    <p class="mt-1 text-xs text-muted-foreground">New images appear here as processing completes.</p>
                </div>
                <div class="flex aspect-square items-center justify-center bg-muted/50">
                    <img v-if="props.generations[0]?.image_url" :src="props.generations[0].image_url" :alt="props.generations[0].prompt" class="h-full w-full object-contain" />
                    <div v-else class="flex flex-col items-center gap-3 px-8 text-center text-muted-foreground">
                        <ImagePlus :size="36" :stroke-width="1.25" />
                        <p class="text-sm">{{ props.generations.length ? 'The latest image is not available yet.' : 'Your first creation starts with a prompt.' }}</p>
                    </div>
                </div>
                <div v-if="props.generations[0]" class="space-y-2 p-5">
                    <p class="line-clamp-2 text-sm font-medium">{{ props.generations[0].prompt }}</p>
                    <p class="text-xs capitalize text-muted-foreground">{{ props.generations[0].status.replace(/_/g, ' ') }}</p>
                </div>
            </aside>
        </div>

        <section aria-labelledby="history-heading" class="space-y-4">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 id="history-heading" class="text-xl font-semibold">Your generations</h2>
                    <p class="mt-1 text-sm text-muted-foreground">The latest 50 requests. In-progress images refresh automatically.</p>
                </div>
                <Button variant="outline" size="sm" class="gap-2" :disabled="refreshInProgress" @click="refreshHistory">
                    <RefreshCw :size="15" :class="{ 'animate-spin': refreshInProgress }" /> Refresh
                </Button>
            </div>

            <div v-if="props.generations.length === 0" class="rounded-2xl border border-dashed border-border px-6 py-14 text-center text-muted-foreground">
                <ImagePlus :size="28" class="mx-auto mb-3" />
                <p class="text-sm font-medium text-foreground">No generations yet</p>
                <p class="mt-1 text-sm">Your finished images and cost details will appear here.</p>
            </div>
            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article v-for="generation in props.generations" :key="generation.id" class="overflow-hidden rounded-xl border border-border bg-card">
                    <div class="relative flex aspect-[4/3] items-center justify-center bg-muted/50">
                        <img v-if="generation.image_url" :src="generation.image_url" :alt="generation.prompt" class="h-full w-full object-contain" loading="lazy" />
                        <div v-else class="flex flex-col items-center gap-2 px-6 text-center text-muted-foreground">
                            <LoaderCircle v-if="['submitting', 'queued', 'processing', 'submission_unknown'].includes(generation.status)" :size="26" class="animate-spin" />
                            <ImagePlus v-else :size="26" />
                            <p class="text-xs">{{ generation.image_expired ? 'Image link expired' : generation.status === 'failed' ? 'Generation failed' : generation.status === 'rejected' ? 'Request rejected' : 'Image not ready' }}</p>
                        </div>
                        <span class="absolute right-3 top-3 rounded-full border border-border bg-background/90 px-2.5 py-1 text-xs font-medium capitalize shadow-sm">
                            {{ generation.status.replace(/_/g, ' ') }}
                        </span>
                    </div>
                    <div class="space-y-3 p-4">
                        <p class="line-clamp-2 min-h-10 text-sm font-medium">{{ generation.prompt }}</p>
                        <div class="flex items-center justify-between text-xs text-muted-foreground">
                            <span>{{ generation.quality }} · {{ generation.size }}</span>
                            <time :datetime="generation.created_at ?? undefined">{{ dateLabel(generation.created_at) }}</time>
                        </div>
                        <div class="flex items-baseline justify-between border-t border-border pt-3 text-sm">
                            <span class="text-muted-foreground">{{ generation.status === 'succeeded' ? 'Final charge' : generation.status === 'failed' ? 'No charge' : 'Estimated price' }}</span>
                            <strong class="tabular-nums">{{ generation.status === 'succeeded' || generation.status === 'failed' ? currency(generation.customer_cost_usd) : currency(generation.estimated_customer_usd) }}</strong>
                        </div>
                        <div v-if="generation.status === 'succeeded'" class="text-xs text-muted-foreground">
                            Provider cost {{ currency(generation.provider_cost_usd) }} · Markup 25%
                        </div>
                        <p v-if="generation.error_message" class="text-xs text-destructive" role="status">{{ generation.error_message }}</p>
                        <p v-else-if="generation.status === 'submission_unknown'" class="text-xs text-muted-foreground">
                            Confirming submission with Pixelforge. Please don't resubmit this request.
                        </p>
                    </div>
                </article>
            </div>
        </section>
    </div>
</template>
