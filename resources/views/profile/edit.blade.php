<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10">
        <div class="max-w-2xl mx-auto space-y-6">

            <div>
                <h1 class="text-[17px] font-semibold text-ink">Profile</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Manage your account settings.</p>
            </div>

            <div class="linear-card p-6">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="linear-card p-6">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="linear-card p-6">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
