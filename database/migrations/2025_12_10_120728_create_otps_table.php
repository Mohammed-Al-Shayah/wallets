<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    Schema::create('otps', function (Blueprint $table) {
        $table->id();

        $table->string('phone');              // رقم الجوال
        $table->string('code');               // كود الـ OTP
        $table->string('purpose');            // register / reset_password / ...
        $table->timestamp('expires_at');      // وقت الانتهاء

        $table->timestamps();

        // اختياري: نمنع وجود أكثر من كود نشط لنفس الجوال ونفس الغرض
        $table->index(['phone', 'purpose']);
    });
}

    /**
     * Reverse the migrations.
     */
public function down(): void
{
    Schema::dropIfExists('otps');
}

};
