<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover lets the layout paint under the iOS home indicator,
             which the safe-area padding then accounts for. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#0a0e18">
        <meta name="description" content="Statistical match predictions for the Premier League, La Liga, Serie A, Bundesliga and Ligue 1 — goals, corners, cards, shots on target and value against the bookmaker market, with every pick scored publicly.">
        <meta name="robots" content="index, follow">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Africode Football AI">
        <meta property="og:title" content="Africode Football AI — data-driven football predictions">
        <meta property="og:description" content="Model probabilities for goals, corners, cards and match results across 12 European leagues, with a public accuracy record.">
        <meta name="twitter:card" content="summary">

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/favicon.svg">

        <title inertia>{{ config('app.name', 'Africode Football AI') }}</title>
        @vite('resources/js/app.js')
        @inertiaHead
    </head>
    <body class="min-h-screen bg-ink-950 bg-pitch-glow bg-no-repeat font-sans text-ink-100 antialiased">
        @inertia
    </body>
</html>
