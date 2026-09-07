<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Fallback for the grid's PATCH when the XSRF-TOKEN cookie is unavailable. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Le préfixe sous lequel l'application est montée, vide à la racine d'un domaine. Le PHP
         n'en a pas besoin - url(), route() et asset() le déduisent de la requête - mais le JS
         si : un chemin écrit en dur dans un fetch part sinon à la racine du domaine. Voir
         resources/js/basePath.js. --}}
    <meta name="base-path" content="{{ rtrim(request()->getBaseUrl(), '/') }}">
    <title inertia>{{ config('app.name') }}</title>

    {{-- Ziggy: Breeze's pages resolve their routes with route(). --}}
    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full bg-sand-100 font-sans antialiased">
    @inertia
</body>
</html>
