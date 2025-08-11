{{-- resources/views/welcome.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StarCraft 2 AI Coach</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-900 text-white">

    <div class="min-h-screen flex flex-col items-center justify-center px-6">
        
        <!-- Hero Section -->
        <div class="text-center max-w-3xl">
            <h1 class="text-4xl sm:text-6xl font-bold mb-6">
                StarCraft 2 AI Coach
            </h1>
            <p class="text-lg sm:text-xl text-gray-300 mb-10">
                Master your gameplay with AI-powered coaching.  
                Get personalized feedback based on your matches,  
                improve your build orders, and climb the ladder faster.
            </p>

            <!-- Call to Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('register') }}" 
                   class="px-6 py-3 bg-blue-600 rounded-lg hover:bg-blue-700 transition text-lg font-semibold">
                    Get Started
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
                <h3 class="text-xl font-semibold mb-3">🎯 Personalized Coaching</h3>
                <p class="text-gray-400">AI analyses your replays and gives specific tips tailored to your playstyle.</p>
            </div>
            <div>
                <h3 class="text-xl font-semibold mb-3">📊 Progress Tracking</h3>
                <p class="text-gray-400">Monitor your improvement over time with detailed performance insights.</p>
            </div>
            <div>
                <h3 class="text-xl font-semibold mb-3">🧠 Concept Mastery</h3>
                <p class="text-gray-400">Learn game concepts deeply with targeted questions and training modules.</p>
            </div>
        </div>

    </div>

</body>
</html>
