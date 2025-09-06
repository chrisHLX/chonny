<x-app-layout>
    
    <h2>Generated Questions</h2>

    <ul>
        @foreach ($questions as $question)
            <li>
                <strong>{{ $question['question'] }}</strong><br>
                Answer: {{ is_array($question['answer']) ? implode(', ', $question['answer']) : $question['answer'] }}
            </li>
        @endforeach
    </ul>

</x-app-layout>