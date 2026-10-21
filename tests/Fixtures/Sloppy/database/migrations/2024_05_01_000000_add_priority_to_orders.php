<?php

declare(strict_types=1);

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SL215: no down(). SL216: a backfill through the model. SL217: an
// assignee column that accepts any id at all.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('priority')->default('normal');
            $table->foreignId('assignee_id')->nullable();
        });

        Order::query()->where('total', '>', 1000)->update(['priority' => 'high']);
    }
};
