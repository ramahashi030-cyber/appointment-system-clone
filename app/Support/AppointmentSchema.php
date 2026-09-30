<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class AppointmentSchema
{
    private static bool $isReady = false;

    public static function ensureCompatibleColumns(): void
    {
        if (self::$isReady || ! Schema::hasTable('appointments')) {
            return;
        }

        $missing = [
            'consultation_reason' => ! Schema::hasColumn('appointments', 'consultation_reason'),
            'symptoms' => ! Schema::hasColumn('appointments', 'symptoms'),
            'complaint_details' => ! Schema::hasColumn('appointments', 'complaint_details'),
            'qr_code_token' => ! Schema::hasColumn('appointments', 'qr_code_token'),
        ];

        if (! in_array(true, $missing, true)) {
            self::$isReady = true;

            return;
        }

        try {
            Schema::table('appointments', function (Blueprint $table) use ($missing): void {
                if ($missing['consultation_reason']) {
                    $table->string('consultation_reason', 100)->nullable();
                }

                if ($missing['symptoms']) {
                    $table->json('symptoms')->nullable();
                }

                if ($missing['complaint_details']) {
                    $table->text('complaint_details')->nullable();
                }

                if ($missing['qr_code_token']) {
                    $table->char('qr_code_token', 64)->nullable()->unique();
                }
            });
        } catch (QueryException $exception) {
            if (! self::hasAllColumns()) {
                throw $exception;
            }
        }

        self::$isReady = true;
    }

    private static function hasAllColumns(): bool
    {
        return collect([
            'consultation_reason',
            'symptoms',
            'complaint_details',
            'qr_code_token',
        ])->every(fn (string $column): bool => Schema::hasColumn('appointments', $column));
    }
}
