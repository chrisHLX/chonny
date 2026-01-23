<x-app-layout>
<div>

    <h1 class="text-lg"> Hello {{ $user->name }} </h1>

    <h2 class="text-lg"> Users modules </h2>
    <ul>
        @foreach($user->modules as $modules)
            <li>{{ $modules->name }}</li>
            QUESTIONS
            <ul>
                @foreach ($modules->questions as $question)
                <li> {{ $question->question }} </li>
                @endforeach
            </ul>
        @endforeach
    </ul>

    <h2 class="text-lg">Pieplines</h2>
    <ul>
        @foreach($user->pipelines as $pipeline)
            <li>{{ $pipeline }}</li>
        @endforeach
    </ul>

    <h2 class="text-lg">Proficiencies</h2>
    <ul>
        @foreach($user->proficiencies as $proficiency)
            <li>{{ $proficiency }}</li>
        @endforeach
    </ul>

    <h2 class="text-lg">Answered Questions</h2>
    <ul>
        @foreach($user->answeredQuestions as $question)
            Name: <li>{{ $question->question }}</li>
            Attempts: <li>{{ $question->pivot->attempts }}</li>
        @endforeach
    </ul>


</div>
</x-app-layout>