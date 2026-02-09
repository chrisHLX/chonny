{{-- resources/views/welcome.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mindcollector</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-950 text-white">

<div class="min-h-screen flex flex-col items-center justify-center px-6">

    <!-- Hero -->
    <div class="text-center max-w-4xl">
        <h1 class="text-5xl sm:text-7xl font-extrabold mb-6 tracking-tight">
            Mindcollector
        </h1>

        <p class="text-xl sm:text-2xl text-gray-300 mb-10 leading-relaxed">
            A new way to learn, train, and master complex concepts.  
            Collect knowledge as cards, build proficiency, and watch your understanding evolve.
        </p>

        <!-- CTA -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ route('register') }}"
               class="px-8 py-4 bg-cyan-600 rounded-xl hover:bg-cyan-700 transition text-lg font-semibold shadow-lg">
                Start Collecting
            </a>

            <a href="{{ route('login') }}"
               class="px-8 py-4 bg-gray-800 rounded-xl hover:bg-gray-700 transition text-lg font-semibold">
                Log In
            </a>
        </div>
    </div>

    <!-- Divider -->
    <div class="w-full max-w-6xl my-20 border-t border-gray-800"></div>

    <!-- What Is It -->
    <div class="max-w-5xl text-center mb-20">
        <h2 class="text-3xl sm:text-4xl font-bold mb-6">
            What is Mindcollector?
        </h2>
        <p class="text-gray-400 text-lg leading-relaxed">
            Mindcollector turns learning into a progression system.
            Instead of passively consuming content, you actively collect and upgrade
            conceptual “minds” through quizzes, challenges, and applied understanding.
        </p>
    </div>

    <!-- Features -->
    <div class="grid gap-10 sm:grid-cols-3 max-w-6xl text-center">

        <div class="bg-gray-900 rounded-2xl p-6 shadow-lg border border-gray-800">
            <h3 class="text-xl font-semibold mb-3 text-cyan-400">
                🧠 Concept Cards
            </h3>
            <p class="text-gray-400">
                Every idea becomes a collectible card.
                Concepts are broken down, ranked, and trained individually.
            </p>
        </div>

        <div class="bg-gray-900 rounded-2xl p-6 shadow-lg border border-gray-800">
            <h3 class="text-xl font-semibold mb-3 text-emerald-400">
                📈 Proficiency Progression
            </h3>
            <p class="text-gray-400">
                Improve cards through multiple proficiency tiers.
                Visual upgrades reflect real understanding, not grind.
            </p>
        </div>

        <div class="bg-gray-900 rounded-2xl p-6 shadow-lg border border-gray-800">
            <h3 class="text-xl font-semibold mb-3 text-yellow-400">
                🎯 Targeted Training
            </h3>
            <p class="text-gray-400">
                Weak areas surface automatically.
                You train what matters, when it matters.
            </p>
        </div>

    </div>

    <!-- Use Case -->
    <div class="max-w-5xl text-center mt-24">
        <h2 class="text-3xl sm:text-4xl font-bold mb-6">
            Built for mastery
        </h2>
        <p class="text-gray-400 text-lg leading-relaxed">
            Whether you're learning a competitive game, a technical skill,
            or a complex subject — Mindcollector helps you
            turn scattered knowledge into structured mastery.
        </p>
    </div>

    <!-- Footer CTA -->
    <div class="mt-20 mb-10 text-center">
        <a href="{{ route('register') }}"
           class="inline-block px-10 py-4 bg-gradient-to-r from-cyan-500 to-emerald-500
                  rounded-2xl text-lg font-bold text-gray-900 hover:opacity-90 transition shadow-xl">
            Begin Your Collection
        </a>
    </div>

</div>

</body>
</html>
