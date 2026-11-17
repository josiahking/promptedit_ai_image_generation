<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUpRight,
    Check,
    CircleDollarSign,
    Clock3,
    Fingerprint,
    Image as ImageIcon,
    Layers3,
    ShieldCheck,
    Sparkles,
    WandSparkles,
} from '@lucide/vue';
import { computed } from 'vue';
import { dashboard, login, register } from '@/routes';

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth.user));
const studioUrl = computed(() => signedIn.value ? dashboard() : register());
</script>

<template>
    <Head title="Create images with clarity">
        <meta
            name="description"
            content="PromptEdit Studio turns prompts into images with transparent estimates, asynchronous generation and an organized private history."
        />
    </Head>

    <div class="min-h-screen overflow-hidden bg-[#080c13] text-[#f3f4ef]">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[900px] overflow-hidden" aria-hidden="true">
            <div class="absolute -right-44 top-0 h-[620px] w-[620px] rounded-full bg-[#a7ff6b]/8 blur-[125px]"></div>
            <div class="absolute left-[-280px] top-40 h-[560px] w-[560px] rounded-full bg-[#7397f2]/10 blur-[125px]"></div>
        </div>

        <div class="relative mx-auto max-w-7xl px-5 sm:px-8 lg:px-12">
            <header class="flex h-20 items-center justify-between border-b border-white/10">
                <Link :href="'/'" class="flex items-center gap-3" aria-label="PromptEdit Studio home">
                    <span class="grid size-10 place-items-center rounded-xl bg-[#c6ff83] text-[#111b12]">
                        <WandSparkles :size="21" :stroke-width="2.3" />
                    </span>
                    <span class="text-[17px] font-bold tracking-tight">promptedit<span class="text-[#c6ff83]">.</span><span class="font-normal text-white/65">studio</span></span>
                </Link>
                <nav class="flex items-center gap-3 sm:gap-6" aria-label="Main navigation">
                    <a href="#how-it-works" class="hidden text-sm text-white/65 transition hover:text-white sm:block">How it works</a>
                    <a href="#pricing" class="hidden text-sm text-white/65 transition hover:text-white md:block">Pricing</a>
                    <Link v-if="!signedIn" :href="login()" class="rounded-lg px-3 py-2 text-sm font-medium text-white/80 transition hover:text-white">Log in</Link>
                    <Link :href="studioUrl" class="inline-flex items-center gap-2 rounded-full bg-[#c6ff83] px-4 py-2.5 text-sm font-semibold text-[#152014] transition hover:bg-[#ddffaa]">
                        {{ signedIn ? 'Open studio' : 'Start creating' }}
                        <ArrowUpRight :size="16" />
                    </Link>
                </nav>
            </header>

            <main>
                <section class="grid items-center gap-12 py-20 lg:grid-cols-[.91fr_1.09fr] lg:gap-16 lg:py-28">
                    <div>
                        <div class="mb-7 inline-flex items-center gap-2 rounded-full border border-[#c6ff83]/25 bg-[#c6ff83]/8 px-3.5 py-1.5 text-xs font-medium tracking-wide text-[#c6ff83]">
                            <span class="size-1.5 rounded-full bg-[#c6ff83]"></span>
                            IMAGE GENERATION, MADE CLEAR
                        </div>
                        <h1 class="max-w-xl text-[clamp(3.1rem,6.1vw,5.9rem)] font-semibold leading-[1.045] tracking-[-0.06em]">
                            Imagine it.<br />
                            <span class="text-[#c6ff83]">Make it real.</span>
                        </h1>
                        <p class="mt-7 max-w-lg text-base leading-8 text-[#b0b9c5] sm:text-lg">
                            From your first idea to your final image. Write a prompt, choose your style and quality, and see your estimated cost before you create.
                        </p>
                        <div class="mt-9 flex flex-wrap items-center gap-4">
                            <Link :href="studioUrl" class="inline-flex min-h-13 items-center gap-3 rounded-xl bg-[#c6ff83] px-6 py-3.5 text-sm font-semibold text-[#101a12] transition hover:bg-[#ddffaa]">
                                {{ signedIn ? 'Go to your studio' : 'Create your first image' }}
                                <ArrowRight :size="17" />
                            </Link>
                            <a href="#how-it-works" class="inline-flex min-h-13 items-center gap-2 rounded-xl border border-white/20 px-5 py-3.5 text-sm font-semibold text-white transition hover:bg-white/10">
                                Explore the workflow <ArrowUpRight :size="16" />
                            </a>
                        </div>
                        <div class="mt-11 flex flex-wrap gap-x-7 gap-y-3 text-xs text-white/60">
                            <span class="inline-flex items-center gap-2"><Check :size="15" class="text-[#c6ff83]" /> Pricing before you generate</span>
                            <span class="inline-flex items-center gap-2"><Check :size="15" class="text-[#c6ff83]" /> Your history, in one place</span>
                        </div>
                    </div>

                    <div class="relative" aria-label="Illustration of an image generation workspace">
                        <div class="absolute -inset-4 rotate-2 rounded-[2rem] border border-[#c6ff83]/15 bg-[#c6ff83]/5"></div>
                        <div class="relative overflow-hidden rounded-[1.8rem] border border-white/15 bg-[#111923] p-3 shadow-[0_25px_110px_rgba(0,0,0,.45)] sm:p-4">
                            <div class="flex items-center justify-between border-b border-white/10 px-2 pb-4 pt-1">
                                <div class="flex items-center gap-2 text-xs font-medium text-white/75">
                                    <span class="grid size-7 place-items-center rounded-lg bg-[#c6ff83]/15 text-[#c6ff83]"><Layers3 :size="15" /></span>
                                    Canvas preview
                                </div>
                                <span class="flex items-center gap-2 rounded-full border border-white/10 px-2.5 py-1 text-[11px] text-white/60"><span class="size-1.5 rounded-full bg-[#c6ff83]"></span> Your imagination, your canvas</span>
                            </div>
                            <div class="relative mt-3 aspect-[1.26] overflow-hidden rounded-2xl bg-[#233b3d]">
                                <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_10%,#ffe7b0_0%,#f8ad70_12%,#9c7688_28%,#3a6472_57%,#172b39_100%)]"></div>
                                <div class="absolute bottom-[-18%] left-[-20%] size-[75%] rotate-[-15deg] rounded-[48%] bg-[#1c3944] shadow-[40px_-22px_48px_#223b4b]"></div>
                                <div class="absolute bottom-[-33%] right-[-28%] size-[100%] rotate-[25deg] rounded-[45%] bg-[#142c38] shadow-[-30px_-35px_54px_#2c5262]"></div>
                                <div class="absolute bottom-[19%] left-[27%] h-[1px] w-[44%] rounded-full bg-[#eebf9f]/80 blur-[1px]"></div>
                                <div class="absolute left-4 top-4 rounded-full border border-white/25 bg-[#0a111a]/45 px-3 py-1.5 text-[11px] text-white/85 backdrop-blur-sm">Concept illustration</div>
                                <div class="absolute inset-x-4 bottom-4 flex items-center justify-between rounded-xl border border-white/20 bg-[#0b1420]/65 px-4 py-3 text-xs backdrop-blur-lg">
                                    <div class="flex items-center gap-2"><Sparkles :size="15" class="text-[#c6ff83]" /> Art direction</div>
                                    <span class="text-white/60">Landscape · High quality</span>
                                </div>
                            </div>
                            <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto]">
                                <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                                    <p class="text-[10px] uppercase tracking-[0.18em] text-[#c6ff83]">Prompt example</p>
                                    <p class="mt-1 text-xs leading-5 text-white/80">A quiet coastline at sunset, soft cinematic lighting, detailed atmosphere...</p>
                                </div>
                                <div class="flex min-w-30 flex-col justify-center rounded-xl border border-white/10 bg-white/5 p-3">
                                    <span class="text-[10px] uppercase tracking-[0.16em] text-white/50">Before you create</span>
                                    <span class="mt-1 text-sm font-semibold text-[#c6ff83]">Cost preview</span>
                                    <span class="mt-1 text-[11px] text-white/55">No surprises</span>
                                </div>
                            </div>
                        </div>
                        <span class="absolute -bottom-5 -left-5 hidden items-center gap-2 rounded-xl border border-white/15 bg-[#1a2632] px-4 py-3 text-xs font-medium text-white shadow-xl sm:inline-flex">
                            <ShieldCheck :size="18" class="text-[#c6ff83]" /> Private generation history
                        </span>
                    </div>
                </section>

                <section class="border-y border-white/10 py-8" aria-label="Key capabilities">
                    <div class="grid gap-5 sm:grid-cols-3 sm:gap-8">
                        <div class="flex items-start gap-3"><CircleDollarSign class="mt-0.5 shrink-0 text-[#c6ff83]" :size="22" /><div><h2 class="text-sm font-semibold">Know your estimate</h2><p class="mt-1 text-xs leading-5 text-white/50">Understand the estimated range before submitting.</p></div></div>
                        <div class="flex items-start gap-3"><Clock3 class="mt-0.5 shrink-0 text-[#c6ff83]" :size="22" /><div><h2 class="text-sm font-semibold">Create asynchronously</h2><p class="mt-1 text-xs leading-5 text-white/50">Track queued, processing, and completed requests.</p></div></div>
                        <div class="flex items-start gap-3"><Fingerprint class="mt-0.5 shrink-0 text-[#c6ff83]" :size="22" /><div><h2 class="text-sm font-semibold">Keep it yours</h2><p class="mt-1 text-xs leading-5 text-white/50">Your account keeps your generations separate.</p></div></div>
                    </div>
                </section>

                <section id="how-it-works" class="py-20 lg:py-24">
                    <div class="mb-10 max-w-2xl">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-[#c6ff83]">THE WORKFLOW</p>
                        <h2 class="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">From prompt to picture, without the guesswork.</h2>
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        <div v-for="step in [
                            { number: '01', title: 'Describe your idea', detail: 'Write a prompt and optionally add up to four image references.', icon: ImageIcon },
                            { number: '02', title: 'Choose and estimate', detail: 'Select canvas size and quality. See the estimated price range.', icon: CircleDollarSign },
                            { number: '03', title: 'Create and follow', detail: 'Submit, watch your status and view the completed image and actual charge.', icon: Sparkles },
                        ]" :key="step.number" class="rounded-2xl border border-white/10 bg-[#111822] p-6">
                            <div class="mb-9 flex items-center justify-between"><span class="text-xs font-semibold tracking-[.2em] text-[#c6ff83]">{{ step.number }}</span><component :is="step.icon" :size="22" class="text-white/50" /></div>
                            <h3 class="text-lg font-semibold">{{ step.title }}</h3>
                            <p class="mt-2 text-sm leading-6 text-white/55">{{ step.detail }}</p>
                        </div>
                    </div>
                </section>

                <section id="pricing" class="mb-20 overflow-hidden rounded-[1.8rem] border border-[#c6ff83]/20 bg-[#15211b] p-7 sm:p-10">
                    <div class="flex flex-col justify-between gap-7 md:flex-row md:items-center">
                        <div class="max-w-xl">
                            <p class="mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-[#c6ff83]">TRANSPARENT PRICING</p>
                            <h2 class="text-2xl font-semibold tracking-[-0.035em] sm:text-3xl">A clear estimate. An exact final charge.</h2>
                            <p class="mt-3 text-sm leading-7 text-[#bccfc2]">See a range before creating. Successful generations are charged at exact token-based provider cost plus 25%. Failed generations cost $0.</p>
                        </div>
                        <Link :href="studioUrl" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-[#c6ff83] px-6 py-3.5 text-sm font-semibold text-[#152014] transition hover:bg-[#ddffaa]">
                            {{ signedIn ? 'Open the studio' : 'Get started' }} <ArrowRight :size="16" />
                        </Link>
                    </div>
                    <p class="mt-6 text-xs text-[#b2c2b5]">This assessment uses the simulated Pixelforge provider; no real image-generation charges are incurred.</p>
                </section>
            </main>

            <footer class="flex flex-col justify-between gap-4 border-t border-white/10 py-8 text-xs text-white/45 sm:flex-row sm:items-center">
                <span>© {{ new Date().getFullYear() }} PromptEdit Studio · Built with Laravel, Vue and Inertia.</span>
                <span>Powered by the Pixelforge simulated image API.</span>
            </footer>
        </div>
    </div>
</template>
