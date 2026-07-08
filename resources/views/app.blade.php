<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name', 'Africode Football AI') }}</title>
        @vite('resources/js/app.js')
        @inertiaHead
    </head>
    <body class="min-h-screen bg-slate-950 font-sans text-slate-100 antialiased">
        @inertia
    </body>
</html>
