<?php

namespace App\Console\Commands;

use App\Services\PaymentReceipts;
use Illuminate\Console\Command;

class SendPaymentReceipts extends Command
{
    protected $signature = 'payments:send-receipts {--dry-run : Inspect eligible committed receipts without email or claims}
        {--payment= : Select exactly one historical payment receipt for an explicit backfill or rejected-email retry}
        {--reason= : Explain the single-receipt backfill or rejected-email retry in 10–1,000 characters}
        {--retry-rejected : Retry only a definitively rejected email, never an accepted, interrupted or uncertain send}';

    protected $description = 'Send store-issued receipts for verified online payments through Brevo, independently of payment callbacks';

    public function handle(PaymentReceipts $receipts): int
    {
        $id = $this->option('payment');
        if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('Select one valid payment receipt ID.');

            return self::FAILURE;
        }
        try {
            $result = $receipts->run($id === null ? null : (int) $id, (bool) $this->option('dry-run'), $this->option('reason'), (bool) $this->option('retry-rejected'));
        } catch (\Throwable) {
            $this->error('Receipt email processing stopped. Review saved claims before retrying; payments were not changed.');

            return self::FAILURE;
        }
        if (! $result['configured']) {
            $this->warn('Store receipt emails need the enabled flag, a valid activation cutoff and configured Brevo sender. No email sent.');

            return self::SUCCESS;
        }
        if ($result['dry_run']) {
            $this->info('Dry run: '.$result['eligible'].' receipt(s) eligible. No email sent or claim saved.');

            return self::SUCCESS;
        }
        $this->info('Store receipts: '.$result['accepted'].' accepted by Brevo, '.$result['failed'].' rejected, '.$result['unknown'].' uncertain, '.$result['skipped'].' skipped. Acceptance does not confirm inbox delivery.');
        if ($result['review_required']) {
            $this->warn($result['review_required'].' receipt email outcome(s) require review. Accepted or uncertain sends are never automatically repeated.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
