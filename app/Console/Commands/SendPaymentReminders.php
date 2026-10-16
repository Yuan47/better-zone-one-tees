<?php

namespace App\Console\Commands;

use App\Services\InvoiceReminders;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'invoices:send-reminders {--dry-run : Count eligible reminders without sending email or saving claims}';

    protected $description = 'Send enabled Brevo reminders three days and one day before unpaid invoice deadlines';

    public function handle(InvoiceReminders $reminders): int
    {
        try {
            $result = $reminders->run(dryRun: (bool) $this->option('dry-run'));
        } catch (\Throwable $exception) {
            $this->error('The reminder run could not complete. Review saved reminder claims before retrying; no interrupted claim is automatically resent.');

            return self::FAILURE;
        }
        if ($result['dry_run']) {
            $this->info('Dry run: '.$result['eligible'].' reminder(s) eligible. No email sent and no reminder claim saved.');

            return self::SUCCESS;
        }
        if (! $result['enabled'] || ! $result['configured']) {
            $this->warn('Payment reminders are disabled or Brevo is not configured. No email sent.');

            return self::SUCCESS;
        }
        $this->info('Payment reminders: '.$result['sent'].' sent, '.$result['skipped'].' skipped, '.$result['failed'].' failed, '.$result['unknown'].' uncertain.');
        if ($result['failed'] || $result['unknown']) {
            $this->warn('Failed or uncertain reminders require review. A later run will not automatically resend them.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
