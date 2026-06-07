<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-2xl mx-auto space-y-6">

            <div>
                <h1 class="text-[17px] font-semibold text-ink">Feedback</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Found a bug? Got an idea? Let us know — we read everything.</p>
            </div>

            @if(session('success'))
                <div class="linear-card p-4 border-l-2 border-emerald-400">
                    <p class="text-[13px] text-ink">{{ session('success') }}</p>
                </div>
            @endif

            <div class="linear-card p-6">
                <form method="POST" action="{{ route('feedback.store') }}" class="space-y-5">
                    @csrf

                    <!-- Category -->
                    <div>
                        <label for="category" class="block text-[12px] font-medium text-ink-muted mb-1.5">
                            Category <span class="text-red-400">*</span>
                        </label>
                        <select id="category" name="category"
                                class="w-full bg-surface-2 border border-line rounded-lg px-3 py-2 text-[13px] text-ink focus:outline-none focus:border-accent transition-colors
                                       {{ $errors->has('category') ? 'border-red-400' : '' }}">
                            <option value="" disabled {{ old('category') ? '' : 'selected' }}>Select a category…</option>
                            @foreach(['Bug', 'Confusing', 'Feature Idea', 'General Feedback'] as $cat)
                                <option value="{{ $cat }}" {{ old('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                            @endforeach
                        </select>
                        @error('category')
                            <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Message -->
                    <div>
                        <label for="message" class="block text-[12px] font-medium text-ink-muted mb-1.5">
                            Message <span class="text-red-400">*</span>
                        </label>
                        <textarea id="message" name="message" rows="5"
                                  placeholder="Describe what happened, what you expected, or your idea…"
                                  class="w-full bg-surface-2 border border-line rounded-lg px-3 py-2 text-[13px] text-ink placeholder-ink-subtle resize-none focus:outline-none focus:border-accent transition-colors
                                         {{ $errors->has('message') ? 'border-red-400' : '' }}">{{ old('message') }}</textarea>
                        @error('message')
                            <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Optional fields -->
                    <div class="pt-1 border-t border-line">
                        <p class="text-[11px] font-medium text-ink-subtle uppercase tracking-widest mb-3">Optional contact info</p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Email -->
                            <div>
                                <label for="email" class="block text-[12px] font-medium text-ink-muted mb-1.5">Email</label>
                                <input type="email" id="email" name="email" value="{{ old('email', auth()->user()?->email) }}"
                                       placeholder="you@example.com"
                                       class="w-full bg-surface-2 border border-line rounded-lg px-3 py-2 text-[13px] text-ink placeholder-ink-subtle focus:outline-none focus:border-accent transition-colors
                                              {{ $errors->has('email') ? 'border-red-400' : '' }}">
                                @error('email')
                                    <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Discord -->
                            <div>
                                <label for="discord" class="block text-[12px] font-medium text-ink-muted mb-1.5">Discord Username</label>
                                <input type="text" id="discord" name="discord" value="{{ old('discord') }}"
                                       placeholder="username#0000"
                                       class="w-full bg-surface-2 border border-line rounded-lg px-3 py-2 text-[13px] text-ink placeholder-ink-subtle focus:outline-none focus:border-accent transition-colors
                                              {{ $errors->has('discord') ? 'border-red-400' : '' }}">
                                @error('discord')
                                    <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Screenshot URL -->
                        <div class="mt-4">
                            <label for="screenshot_url" class="block text-[12px] font-medium text-ink-muted mb-1.5">Screenshot URL</label>
                            <input type="url" id="screenshot_url" name="screenshot_url" value="{{ old('screenshot_url') }}"
                                   placeholder="https://…"
                                   class="w-full bg-surface-2 border border-line rounded-lg px-3 py-2 text-[13px] text-ink placeholder-ink-subtle focus:outline-none focus:border-accent transition-colors
                                          {{ $errors->has('screenshot_url') ? 'border-red-400' : '' }}">
                            @error('screenshot_url')
                                <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end pt-1">
                        <button type="submit"
                                class="inline-flex items-center gap-2 px-5 py-2 text-[13px] font-semibold text-white bg-accent hover:bg-accent-hover rounded-lg transition-colors">
                            Send Feedback
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
