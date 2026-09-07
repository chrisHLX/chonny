<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A public handle, so a shared guide can live at /g/{username}/{slug}.
 *
 * `name` could not do this job: it is free text, not unique, and is a display name people expect to
 * be able to change without breaking links other people have saved. A username is a separate,
 * stable, unique identifier, and a URL built on it namespaces every author's guide slugs.
 *
 * Nullable rather than required, because every existing account predates this column and no signup
 * flow collects one yet. The backfill below derives a unique handle from each account's name so no
 * existing user is left unable to share; User::resolveUsername() does the same for new accounts on
 * first use. Nullable also means a future "pick your own username" step can exist without a
 * migration that has to invent one for everybody first.
 *
 * The email local-part is deliberately NOT used as a fallback source — it would leak part of an
 * address into a public URL for anyone whose display name happens to slugify to nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });

        User::query()->whereNull('username')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $base = Str::slug($user->name ?? '') ?: 'player';
                $candidate = $base;
                $n = 1;

                while (User::where('username', $candidate)->exists()) {
                    $candidate = $base.'-'.$n++;
                }

                $user->forceFill(['username' => $candidate])->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
