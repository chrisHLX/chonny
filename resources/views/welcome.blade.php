{{-- resources/views/welcome.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mindcollector</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-900 text-white">

    <div class="min-h-screen flex flex-col items-center justify-center px-6">
        
        <!-- Hero Section -->
        <div class="text-center max-w-3xl">
            <h1 class="text-4xl sm:text-6xl font-bold mb-6">
                Mindcollector
            </h1>
            <h2 class="sm:text-2xl font-bold mb-6">
                StarCraft 2, League of Legends, Medicine and more!
            </h2>
            <p class="text-lg sm:text-xl text-gray-300 mb-10">
            A new way to learn, train, and master complex concepts.  
            Collect knowledge as cards, build proficiency, and evolve your understanding.
            </p>

            <!-- Call to Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('register') }}" 
                   class="px-6 py-3 bg-blue-600 rounded-lg hover:bg-blue-700 transition text-lg font-semibold">
                    Start Collecting
                </a>
                <a href="{{ route('login') }}" 
                   class="px-6 py-3 bg-gray-700 rounded-lg hover:bg-gray-800 transition text-lg font-semibold">
                    Log In
                </a>
            </div>
        </div>


        <!-- Features Section -->
        <div class="mt-20 grid gap-10 sm:grid-cols-3 max-w-5xl text-center">
            <div>
                <h3 class="text-xl font-semibold mb-3">🧠 Concept Cards</h3>
                <p class="text-gray-400">Every idea becomes a collectible card.
                Concepts are broken down, ranked, and trained individually.</p>
            </div>
            <div>
                <h3 class="text-xl font-semibold mb-3">📈 Proficiency Progression</h3>
                <p class="text-gray-400">Improve understanding through multiple proficiency tiers.
                </p>
            </div>
            <div>
                <h3 class="text-xl font-semibold mb-3">🎯 Targeted Training</h3>
                <p class="text-gray-400">Weak areas surface automatically.
                Modules and quizes arise to target weak areas.</p>
            </div>
        </div>

    </div>

</body>
</html>
