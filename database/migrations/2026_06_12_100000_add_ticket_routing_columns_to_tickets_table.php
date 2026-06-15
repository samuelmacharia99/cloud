<?php

use App\Enums\TicketHandledBy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('reseller_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('handled_by', 20)->default(TicketHandledBy::Platform->value)->after('reseller_id');
            $table->timestamp('escalated_at')->nullable()->after('resolved_at');
            $table->foreignId('escalated_by')->nullable()->after('escalated_at')->constrained('users')->nullOnDelete();
            $table->text('escalation_note')->nullable()->after('escalated_by');

            $table->index('reseller_id');
            $table->index('handled_by');
            $table->index('escalated_at');
        });

        $this->backfillExistingTickets();
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('escalated_by');
            $table->dropConstrainedForeignId('reseller_id');
            $table->dropColumn(['handled_by', 'escalated_at', 'escalation_note']);
        });
    }

    private function backfillExistingTickets(): void
    {
        $tickets = DB::table('tickets')
            ->join('users', 'tickets.user_id', '=', 'users.id')
            ->select('tickets.id', 'users.reseller_id', 'users.is_reseller')
            ->get();

        foreach ($tickets as $row) {
            if ($row->is_reseller) {
                DB::table('tickets')->where('id', $row->id)->update([
                    'reseller_id' => null,
                    'handled_by' => TicketHandledBy::Platform->value,
                ]);

                continue;
            }

            if ($row->reseller_id) {
                DB::table('tickets')->where('id', $row->id)->update([
                    'reseller_id' => $row->reseller_id,
                    'handled_by' => TicketHandledBy::Reseller->value,
                ]);

                continue;
            }

            DB::table('tickets')->where('id', $row->id)->update([
                'reseller_id' => null,
                'handled_by' => TicketHandledBy::Platform->value,
            ]);
        }
    }
};
