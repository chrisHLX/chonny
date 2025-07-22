{{-- resources/views/questions/question-card.blade.php --}}

<div class="p-4 bg-white rounded shadow-md">
    <h2 class="text-lg font-semibold">{{ $question->question }}</h2>

    @if ($question->type === 'mcq')
        <form method="POST" action="{{ route('questions.answer', $question) }}">
            @csrf
            @foreach ($question->answer['options'] as $option)
                <div>
                    <label>
                        <input type="radio" name="answer" value="{{ $option }}">
                        {{ $option }}
                    </label>
                </div>
            @endforeach
            <button type="submit" class="mt-2 px-4 py-2 bg-blue-500 text-white rounded">Submit</button>
        </form>
    @elseif ($question->type === 'true_false')
        <form method="POST" action="{{ route('questions.answer', $question) }}">
            @csrf
            <label><input type="radio" name="answer" value="true"> True</label><br>
            <label><input type="radio" name="answer" value="false"> False</label><br>
            <button type="submit" class="mt-2 px-4 py-2 bg-blue-500 text-white rounded">Submit</button>
        </form>
    @elseif ($question->type === 'open')
        <form method="POST" action="{{ route('questions.answer', $question) }}">
            @csrf
            <textarea name="answer" class="w-full border rounded p-2" rows="3"></textarea>
            <button type="submit" class="mt-2 px-4 py-2 bg-blue-500 text-white rounded">Submit</button>
        </form>
    @endif
</div>
