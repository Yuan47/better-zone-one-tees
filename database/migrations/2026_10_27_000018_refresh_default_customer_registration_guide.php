<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $question = 'How do I create a customer account?';
        $previous = "1. Open Sign in, then choose Create an account.\n2. Enter your name, email, and a password of at least 10 characters; enter the same password again.\n3. Choose Create account. If email verification is required, enter the latest code on the verification page.\n4. Keep your password and personal verification code private. The assistant never needs them.";
        $updated = "1. Open Sign in, then choose Create an account.\n2. Enter your full name, a unique username, email, and a password of at least 10 characters; enter the same password again.\n3. Choose Create account. When email verification is required, enter the latest emailed code only on the verification page.\n4. Continue with Google, when shown, also creates a customer account using your verified Google email. Staff and owner accounts use their separate setup. Keep passwords and verification codes private; the assistant never needs them.";

        DB::transaction(function () use ($question, $previous, $updated) {
            $faq = DB::table('chatbot_faqs')->where('question', $question)->lockForUpdate()->first();
            // Strict comparison preserves owner edits, including changes in letter case.
            if ($faq && $faq->question === $question && $faq->answer === $previous) {
                DB::table('chatbot_faqs')->where('id', $faq->id)->update(['answer' => $updated]);
            }
        });
    }

    public function down(): void
    {
        // Keep the corrected guidance and any later owner edits.
    }
};
