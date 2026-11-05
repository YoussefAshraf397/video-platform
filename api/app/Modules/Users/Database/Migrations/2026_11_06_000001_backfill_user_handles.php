<?php

use App\Modules\Users\Services\Handles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $taken = DB::table('users')->whereNotNull('handle')->pluck('handle')
            ->mapWithKeys(fn (string $handle) => [strtolower($handle) => true])->all();

        // Read the whole ordered list up front: paging with each()/chunk() over a column
        // we are updating would skip rows as the matching set shrinks.
        $users = DB::table('users')->whereNull('handle')->orderBy('created_at')->orderBy('id')
            ->get(['id', 'display_name']);

        foreach ($users as $user) {
            foreach (Handles::candidates($user->display_name) as $handle) {
                if (isset($taken[$handle])) {
                    continue;
                }
                $taken[$handle] = true;
                DB::table('users')->where('id', $user->id)->update(['handle' => $handle]);

                break;
            }
        }

        DB::statement('ALTER TABLE users ALTER COLUMN handle SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN handle DROP NOT NULL');
    }
};
