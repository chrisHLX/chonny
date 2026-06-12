{{-- resources/views/questions/question-card.blade.php --}}

<div class="linear-card p-5">
    <h2 class="font-display text-[15px] font-semibold text-ink mb-4">{{ $question->question }}</h2>

    @if ($question->type === 'mcq')
        <form method="POST" action="{{ route('questions.answer', $question) }}">
            @csrf
            <div class="space-y-2 mb-4">
                @foreach ($question->answer['options'] as $option)
                    <label class="flex items-center gap-3 p-3 rounded-md bg-surface-2 border border-line hover:border-line-gold cursor-pointer transition-colors">
                        <input type="radio" name="answer" value="{{ $option }}" class="form-checkbox shrink-0">
                        <span class="text-[13px] text-ink">{{ $option }}</span>
                    </label>
                @endforeach
            </div>
            <button type="submit" class="btn-primary">Submit</button>
        </form>

    @elseif ($question->type === 'true_false')
        <form method="POST" action="{{ route('questions.answer', $question) }}">
            @csrf
            <div class="space-y-2 mb-4">
                @foreach (['true' => 'True', 'false' => 'False'] as $val => $label)
                    <label class="flex items-center gap-3 p-3 rounded-md bg-surface-2 border border-line hover:border-line-gold cursor-pointer transition-colors">
                        <input type="radio" name="answer" value="{{ $val }}" class="form-checkbox shrink-0">
                        <span class="text-[13px] text-ink">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <button type="submit" class="btn-primary">Submit</button>
        </form>

    @elseif ($question->type === 'open')
        <form method="POST" action="{{ route('questions.answer', $question) }}">
            @csrf
            <textarea name="answer" class="form-textarea mb-4" rows="3"
                      placeholder="Write your answer here…"></textarea>
            <button type="submit" class="btn-primary">Submit</button>
        </form>
    @endif
</div>
