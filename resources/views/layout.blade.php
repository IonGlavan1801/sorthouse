<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Sorthouse')</title>
    <meta name="description" content="@yield('description', 'Human-in-the-loop AI triage for email and documents.')">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%230b0e13'/%3E%3Cpath d='M6 11l10-5 10 5v12l-10 5-10-5z' fill='none' stroke='%23f2b84b' stroke-width='2.4' stroke-linejoin='round'/%3E%3Cpath d='M6 11l10 5 10-5M16 16v12' fill='none' stroke='%23f2b84b' stroke-width='2.4' stroke-linejoin='round'/%3E%3C/svg%3E">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@yield('body')
</body>
</html>
