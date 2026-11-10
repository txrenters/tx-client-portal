<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{-- The house-and-person badge: the tab icon,
             the home-screen icon on iPhone and Android, and the browser chrome colour. --}}
        {{-- ?v= changes whenever the icons do: browsers keep favicons for weeks
             and only fetch a new one when its address changes. --}}
        <link rel="icon" href="/favicon.ico?v=3" sizes="any">
        <link rel="icon" href="/icon-192.png?v=3" type="image/png" sizes="192x192">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=3">
        <link rel="manifest" href="/site.webmanifest?v=3">
        <meta name="theme-color" content="#183c90">

        @fonts

        {{-- Search and link-preview tags, rendered here because production runs
             without SSR: crawlers and chat apps never see what <Head> adds.
             Only the public pages are indexed; everything else is behind a login. --}}
        @php
            $publicPages = [
                'Welcome' => [
                    'title' => 'The app for your rental',
                    'description' => 'The TexasRenters App for residents and owners: work orders, messages, documents, leases and payments. Free on the App Store for iPhone and on Google Play for Android.',
                ],
                'Privacy' => [
                    'title' => 'Privacy policy',
                    'description' => 'How the TexasRenters.com client portal collects, uses and protects your information.',
                ],
                'Support' => [
                    'title' => 'Support',
                    'description' => 'Get help with the TexasRenters.com client portal.',
                ],
            ];
            $seo = $publicPages[$page['component']] ?? null;
            $appName = config('app.name', 'Laravel');
        @endphp

        @if ($seo)
            <meta name="description" content="{{ $seo['description'] }}">
            <meta name="apple-itunes-app" content="app-id={{ config('mobile.ios.app_store_id') }}">
            <link rel="canonical" href="{{ url()->current() }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="{{ $appName }}">
            <meta property="og:title" content="{{ $seo['title'] }} - {{ $appName }}">
            <meta property="og:description" content="{{ $seo['description'] }}">
            <meta property="og:url" content="{{ url()->current() }}">
            <meta property="og:image" content="{{ asset('images/share/portal.jpg') }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta name="twitter:card" content="summary_large_image">
        @else
            <meta name="robots" content="noindex">
        @endif

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ $seo ? "{$seo['title']} - {$appName}" : $appName }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
